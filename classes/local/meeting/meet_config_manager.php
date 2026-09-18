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
use mod_tupmeet\local\google\meet_service;

/**
 * Independent, bounded Meet configuration reconciliation; Calendar readiness is never written here.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class meet_config_manager {
    /** @var int Maximum remote attempts per explicitly saved/retried revision, including the observer. */
    public const MAX_ATTEMPTS = 5;
    /** @var meet_service Meet HTTP boundary. */
    private meet_service $meet;

    /**
     * Configure Meet access.
     *
     * @param meet_service|null $meet Service
     */
    public function __construct(?meet_service $meet = null) {
        $this->meet = $meet ?? new meet_service();
    }

    /**
     * Set a new revision without changing permanent space identity.
     *
     * @param \stdClass $record Desired state
     */
    public static function prepare(\stdClass $record): void {
        $record->meetconfigversion = bin2hex(random_bytes(16));
        $record->meetconfigstatus = 'pending';
        $record->meetconfigattempts = 0;
        $record->meetconfigmodified = 0;
    }

    /**
     * Queue configuration after Space readiness, or historical Calendar readiness, within the caller's transaction.
     *
     * @param \stdClass $record Saved activity
     */
    public static function queue(\stdClass $record): void {
        if (
            !provisioning::artifacts_ready($record) ||
                !in_array($record->meetconfigstatus, ['pending', 'error'], true) ||
                $record->meetconfigattempts >= self::MAX_ATTEMPTS
        ) {
            return;
        }
        $task = new \mod_tupmeet\task\sync_meet_config();
        $task->set_component('mod_tupmeet');
        $task->set_custom_data(['id' => (int) $record->id, 'version' => $record->meetconfigversion]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Persist results only for this artifact revision and the applicable provisioning prerequisites.
     *
     * @param \stdClass $record Snapshot
     * @param array $values Internally supplied Meet fields
     */
    private function write(\stdClass $record, array $values): void {
        global $DB;
        $params = [];
        $assignments = [];
        foreach ($values as $field => $value) {
            $assignments[] = $field . ' = :' . $field;
            $params[$field] = $value;
        }
        $conditions = [];
        foreach (provisioning::conditions($record, 'meetconfigversion') as $field => $value) {
            $conditions[] = $field . ' = :where' . $field;
            $params['where' . $field] = $value;
        }
        $DB->execute('UPDATE {tupmeet} SET ' . implode(', ', $assignments) . ' WHERE ' . implode(' AND ', $conditions), $params);
    }

    /**
     * Check whether an HTTP snapshot still represents the current desired state.
     *
     * @param \stdClass $record Snapshot
     * @return bool
     */
    private function current(\stdClass $record): bool {
        global $DB;
        return $DB->record_exists('tupmeet', provisioning::conditions($record, 'meetconfigversion'));
    }

    /**
     * Resolve the same space and converge artifacts after commit, retaining the link on every failure.
     *
     * @param int $id Activity ID
     * @param string|null $version Worker revision; stale workers retire without HTTP
     * @return bool Work finished/stale/exhausted; false requests native backoff on the same task
     */
    public function synchronize(int $id, ?string $version = null): bool {
        global $DB;
        if ($DB->is_transaction_started()) {
            throw new \coding_exception('Meet configuration must run after the database commit.');
        }
        // All provisioning services share this activity lock, including Space creation.
        $lock = \core\lock\lock_config::get_lock_factory('mod_tupmeet')->get_lock('meeting:' . $id, 0);
        if (!$lock) {
            return false;
        }
        try {
            $record = $DB->get_record('tupmeet', ['id' => $id]);
            if (
                !$record || ($version !== null && $version !== $record->meetconfigversion) ||
                    !provisioning::artifacts_ready($record) ||
                    !in_array($record->meetconfigstatus, ['pending', 'error'], true)
            ) {
                return true;
            }
            if ($record->meetconfigattempts >= self::MAX_ATTEMPTS) {
                $this->write($record, ['meetconfigstatus' => 'error']);
                return true;
            }
            $record->meetconfigattempts++;
            $this->write($record, ['meetconfigattempts' => $record->meetconfigattempts]);
            try {
                if (!calendar_service::valid_meet_uri($record->meeturi ?? '')) {
                    throw new \moodle_exception('meetconfigfailed', 'mod_tupmeet');
                }
                $account = (new account_manager())->get_account((int) $record->accountid);
                $this->meet->synchronize($account, $record, function (string $name) use ($record): bool {
                    global $DB;
                    if (provisioning::is_meet($record) && $name !== $record->meetspacename) {
                        return false;
                    }
                    // Store the canonical identity before PATCH, including when PATCH will fail/timeout.
                    $this->write($record, ['meetspacename' => $name]);
                    return $this->current($record) && $DB->get_field('tupmeet', 'meetspacename', ['id' => $record->id]) === $name;
                });
                $this->write($record, ['meetconfigstatus' => 'ready', 'meetconfigmodified' => time()]);
                return true;
            } catch (\Throwable $e) {
                // Logs/UI receive only the localized fixed message, never upstream diagnostics.
                $this->write($record, ['meetconfigstatus' => 'error']);
                return !$this->current($record) || $record->meetconfigattempts >= self::MAX_ATTEMPTS;
            }
        } finally {
            $lock->release();
        }
    }
}
