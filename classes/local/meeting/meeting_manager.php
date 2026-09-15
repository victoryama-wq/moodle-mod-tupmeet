<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_tupmeet\local\meeting;

use mod_tupmeet\local\account\account_manager;
use mod_tupmeet\local\google\calendar_service;

/**
 * Durable desired state and post-commit reconciliation with the historical owner.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class meeting_manager {
    /** @var array The only form fields allowed to change an activity. */
    private const EDITABLE = [
        'name', 'intro', 'introformat', 'startdatetime', 'enddatetime', 'isrecurring',
        'recurrenceinterval', 'recurrencedays', 'recurrenceuntil', 'autorecord', 'autotranscript', 'publicationmode',
    ];
    /** @var calendar_service Calendar HTTP boundary. */
    private calendar_service $calendar;

    /**
     * Configure Calendar access.
     *
     * @param calendar_service|null $calendar Calendar service
     */
    public function __construct(?calendar_service $calendar = null) {
        $this->calendar = $calendar ?? new calendar_service();
    }

    /**
     * Generate a request key. API callers must reuse it when retrying the same submission.
     *
     * @return string Random, nonsecret correlation key
     */
    public static function new_key(): string {
        return bin2hex(random_bytes(32));
    }

    /**
     * Save an activity and its retry task atomically; no external API calls here.
     *
     * @param \stdClass $data Submitted fields
     * @return int New activity ID
     */
    public function create(\stdClass $data): int {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            $record = (object) array_intersect_key((array) $data, array_flip(self::EDITABLE));
            $record->course = $data->course ?? 0;
            $record->timezone = schedule::timezone()->getName();
            $record->creationkey = $data->creationkey ?? self::new_key();
            if (!preg_match('/^[a-f0-9]{64}$/D', $record->creationkey)) {
                throw new \moodle_exception('invalidrequest', 'mod_tupmeet');
            }
            if ($DB->record_exists('tupmeet', ['creationkey' => $record->creationkey])) {
                throw new \moodle_exception('duplicatesubmission', 'mod_tupmeet');
            }
            $this->prepare($record);
            $record->timecreated = time();
            $record->timemodified = time();
            // Account selection and insert retain Phase 1's lock, verification and history guarantees.
            $record->id = (new account_manager())->add_activity($record);
            $stored = $DB->get_record('tupmeet', ['id' => $record->id], '*', MUST_EXIST);
            schedule::payload($stored);
            $this->queue($stored);
            $transaction->allow_commit();
            return (int) $stored->id;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Prepare stable event identity and a new desired-state revision.
     *
     * @param \stdClass $record Activity being saved
     */
    private function prepare(\stdClass $record): void {
        global $CFG;
        $record->creationkey = $record->creationkey ?: self::new_key();
        if (empty($record->calendareventid)) {
            $record->calendareventid = hash('sha256', $CFG->siteidentifier . ':' . $record->creationkey);
        }
        $record->syncversion = bin2hex(random_bytes(16));
        $record->syncstatus = 'pending';
    }

    /**
     * Update the whole desired series, never accepting account or Google IDs from the caller.
     *
     * @param \stdClass $data Submitted fields with id
     * @return bool
     */
    public function update(\stdClass $data): bool {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            $record = $DB->get_record('tupmeet', ['id' => $data->id], '*', MUST_EXIST);
            foreach (self::EDITABLE as $field) {
                if (property_exists($data, $field)) {
                    $record->{$field} = $data->{$field};
                }
            }
            schedule::payload($record);
            $this->prepare($record);
            $record->timemodified = time();
            $DB->update_record('tupmeet', $record);
            $this->queue($record);
            $transaction->allow_commit();
            return true;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Persist a worker in the same transaction as the desired state.
     *
     * @param \stdClass $record Saved activity
     */
    private function queue(\stdClass $record): void {
        $task = new \mod_tupmeet\task\sync_meeting();
        $task->set_custom_data(['id' => (int) $record->id, 'version' => $record->syncversion]);
        $task->set_component('mod_tupmeet');
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Reconcile only committed state. Failures remain visible and tasks retry safely.
     *
     * @param int $id Activity ID
     * @return bool Ready, deleted, or no work; false requests a retry
     */
    public function synchronize(int $id): bool {
        global $DB;
        if ($DB->is_transaction_started()) {
            throw new \coding_exception('Calendar synchronization must run after the database commit.');
        }
        $lock = \core\lock\lock_config::get_lock_factory('mod_tupmeet')->get_lock('meeting:' . $id, 0);
        if (!$lock) {
            return false;
        }
        try {
            $record = $DB->get_record('tupmeet', ['id' => $id]);
            if (!$record || in_array($record->syncstatus, ['ready', 'legacy'], true)) {
                return true;
            }
            try {
                $account = (new account_manager())->get_account((int) $record->accountid);
                $result = $this->calendar->synchronize($account, $record);
            } catch (\Throwable $e) {
                // Do not log exception details: even OAuth library exceptions can contain credentials.
                $result = ['syncstatus' => 'error'];
            }
            $assignments = [];
            $params = ['id' => $id, 'version' => $record->syncversion];
            foreach ($result as $field => $value) {
                $assignments[] = $field . ' = :' . $field;
                $params[$field] = $value;
            }
            // An edit made during HTTP remains pending; an old response must not mark it ready.
            $DB->execute(
                'UPDATE {tupmeet} SET ' . implode(', ', $assignments) . ' WHERE id = :id AND syncversion = :version',
                $params
            );
            return $DB->get_field('tupmeet', 'syncstatus', ['id' => $id]) === 'ready';
        } finally {
            $lock->release();
        }
    }
}
