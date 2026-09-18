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
            space_manager::prepare($record);
            meet_config_manager::prepare($record);
            $record->timecreated = time();
            $record->timemodified = time();
            // Account selection and insert retain Phase 1's lock, verification and history guarantees.
            $record->id = (new account_manager())->add_activity($record);
            $stored = $DB->get_record('tupmeet', ['id' => $record->id], '*', MUST_EXIST);
            schedule::payload($stored);
            $stored = cohost_manager::save($stored, (int) ($data->cohostuserid ?? 0), true);
            space_manager::queue($stored);
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
            cohost_manager::save($record, isset($data->cohostuserid) ? (int) $data->cohostuserid : null);
            $explicitretry = !array_intersect_key((array) $data, array_flip(self::EDITABLE));
            $calendarchanged = $record->syncstatus !== 'ready' && (!provisioning::is_meet($record) || $explicitretry);
            $artifactschanged = !provisioning::is_meet($record) ||
                ($explicitretry && $record->meetconfigstatus !== 'ready');
            foreach (self::EDITABLE as $field) {
                if (property_exists($data, $field)) {
                    if (in_array($field, ['autorecord', 'autotranscript'], true) && $record->{$field} != $data->{$field}) {
                        $artifactschanged = true;
                    }
                    if (
                        !in_array($field, ['autorecord', 'autotranscript', 'publicationmode'], true) &&
                            (string) $record->{$field} !== (string) $data->{$field}
                    ) {
                        $calendarchanged = true;
                    }
                    $record->{$field} = $data->{$field};
                }
            }
            schedule::payload($record);
            if ($calendarchanged) {
                $this->prepare($record);
            }
            if ($artifactschanged) {
                meet_config_manager::prepare($record);
            }
            $record->timemodified = time();
            // Do not rewrite Google response fields from a snapshot taken before concurrent HTTP.
            $fields = array_merge(self::EDITABLE, ['id', 'timemodified']);
            if ($artifactschanged) {
                $fields = array_merge($fields, [
                    'meetconfigversion', 'meetconfigstatus', 'meetconfigattempts', 'meetconfigmodified',
                ]);
            }
            if ($calendarchanged) {
                $fields = array_merge($fields, ['creationkey', 'calendareventid', 'syncversion', 'syncstatus']);
            }
            $DB->update_record('tupmeet', (object) array_intersect_key((array) $record, array_flip($fields)));
            if ($calendarchanged) {
                self::queue($record);
            }
            if ($artifactschanged || !provisioning::is_meet($record)) {
                meet_config_manager::queue($record);
            }
            $current = $DB->get_record('tupmeet', ['id' => $record->id], '*', MUST_EXIST);
            cohost_manager::queue($current);
            space_manager::queue($current);
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
    public static function queue(\stdClass $record): void {
        if (
            !in_array($record->syncstatus, ['pending', 'error'], true) ||
                (provisioning::is_meet($record) && !provisioning::space_ready($record))
        ) {
            return;
        }
        $task = new \mod_tupmeet\task\sync_meeting();
        $task->set_custom_data(['id' => (int) $record->id, 'version' => $record->syncversion]);
        $task->set_component('mod_tupmeet');
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Reconcile only committed state. Failures remain visible and tasks retry safely.
     *
     * @param int $id Activity ID
     * @param string|null $version Worker revision; stale tasks retire before HTTP
     * @return bool Ready, deleted, or no work; false requests a retry
     */
    public function synchronize(int $id, ?string $version = null): bool {
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
            if (
                !$record || ($version !== null && $version !== $record->syncversion) ||
                    in_array($record->syncstatus, ['ready', 'legacy'], true) ||
                    (provisioning::is_meet($record) && !provisioning::space_ready($record))
            ) {
                return true;
            }
            try {
                $account = (new account_manager())->get_account((int) $record->accountid);
                if (provisioning::is_meet($record)) {
                    cohost_manager::check_identity($record);
                }
                $result = $this->calendar->synchronize($account, $record);
                if (provisioning::is_meet($record)) {
                    // Calendar may never overwrite permanent Meet-first identifiers, including on errors.
                    $result = array_intersect_key($result, array_flip(['syncstatus', 'lastsync']));
                } else if (!empty($record->meeturi) && ($result['meeturi'] ?? null) !== $record->meeturi) {
                    // Historical links are never silently replaced by a provider response or absence.
                    $result = ['syncstatus' => 'error'];
                }
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
            $transaction = $DB->start_delegated_transaction();
            try {
                $DB->execute(
                    'UPDATE {tupmeet} SET ' . implode(', ', $assignments) . ' WHERE id = :id AND syncversion = :version',
                    $params
                );
                $current = $DB->get_record('tupmeet', ['id' => $id]);
                if ($current) {
                    // Calendar readiness and durable Meet work commit together. No HTTP in this transaction.
                    meet_config_manager::queue($current);
                    cohost_manager::queue($current);
                }
                $transaction->allow_commit();
                return $current && $current->syncstatus === 'ready';
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
        } finally {
            $lock->release();
        }
    }
}
