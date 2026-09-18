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
use mod_tupmeet\local\google\cohost_exception;
use mod_tupmeet\local\google\member_service;

/**
 * Independent, bounded cohost reconciliation; Calendar and artifact readiness are never written here.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cohost_manager {
    /** @var int Maximum remote attempts per explicitly saved/retried revision, including the observer. */
    public const MAX_ATTEMPTS = 5;
    /** @var member_service Members HTTP boundary. */
    private member_service $meet;

    /**
     * Configure Meet access.
     *
     * @param member_service|null $meet Service
     */
    public function __construct(?member_service $meet = null) {
        $this->meet = $meet ?? new member_service();
    }

    /**
     * Check the Moodle identity again before granting any remote privilege.
     *
     * @param \stdClass $record Persisted identity
     */
    private static function check_identity(\stdClass $record): void {
        try {
            $user = cohost_identity::resolve((int) $record->course, (int) $record->cohostuserid);
            if ($user->email !== $record->cohostemail) {
                throw new cohost_exception('identity');
            }
        } catch (\Throwable $e) {
            throw new cohost_exception('identity');
        }
    }

    /**
     * Persist the first selection atomically; freeze it even if a later HTTP result is uncertain.
     *
     * Must be called inside the activity-save transaction. All identity data comes from Moodle.
     *
     * @param \stdClass $record Current activity
     * @param int|null $userid Submitted ID, null for unrelated edits/retries
     * @param bool $required New activities must select an eligible teacher
     * @return \stdClass Updated activity snapshot
     */
    public static function save(\stdClass $record, ?int $userid, bool $required = false): \stdClass {
        global $DB;
        if (!$DB->is_transaction_started()) {
            throw new \coding_exception('Cohost selection must be part of the activity transaction.');
        }
        if ($record->cohostlocked && $userid !== null && $userid !== (int) $record->cohostuserid) {
            throw new \moodle_exception('cohostlocked', 'mod_tupmeet');
        }
        if (!$record->cohostlocked && $userid) {
            $user = cohost_identity::resolve((int) $record->course, $userid);
            $record->cohostuserid = $user->id;
            $record->cohostemail = $user->email;
            $record->cohostlocked = 1;
        }
        if (empty($record->cohostuserid)) {
            if ($required || ($userid !== null && $userid !== 0)) {
                throw new \moodle_exception('cohostinvalid', 'mod_tupmeet');
            }
            return $record;
        }
        $previous = $record->cohostversion;
        self::prepare($record);
        $DB->execute(
            'UPDATE {tupmeet} SET cohostuserid = :userid, cohostemail = :email, cohostlocked = 1,
                cohostversion = :newversion, cohoststatus = :status, cohostattempts = 0, cohostmodified = 0,
                cohosterrorstage = :errorstage, cohosthttpstatus = 0
              WHERE id = :id AND cohostversion = :oldversion',
            ['userid' => $record->cohostuserid, 'email' => $record->cohostemail, 'newversion' => $record->cohostversion,
                'status' => 'pending', 'id' => $record->id, 'oldversion' => $previous, 'errorstage' => 'unknown']
        );
        $saved = $DB->get_record('tupmeet', ['id' => $record->id], '*', MUST_EXIST);
        if ($saved->cohostversion !== $record->cohostversion) {
            throw new \moodle_exception('cohostlocked', 'mod_tupmeet');
        }
        return $saved;
    }
    /**
     * Explicitly retry only membership without invalidating ready Calendar or artifact configuration.
     *
     * @param int $id Activity ID
     */
    public static function retry(int $id): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            $record = $DB->get_record('tupmeet', ['id' => $id], '*', MUST_EXIST);
            self::queue(self::save($record, null));
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }
    /**
     * Set a new revision without changing permanent space identity.
     *
     * @param \stdClass $record Desired state
     */
    public static function prepare(\stdClass $record): void {
        $record->cohostversion = bin2hex(random_bytes(16));
        $record->cohoststatus = 'pending';
        $record->cohostattempts = 0;
        $record->cohostmodified = 0;
        $record->cohosterrorstage = 'unknown';
        $record->cohosthttpstatus = 0;
    }

    /**
     * Queue only a ready Calendar's pending configuration, atomically with the caller's DB change.
     *
     * @param \stdClass $record Saved activity
     */
    public static function queue(\stdClass $record): void {
        if (
            $record->syncstatus !== 'ready' ||
                !in_array($record->cohoststatus, ['pending', 'error'], true) ||
                $record->cohostattempts >= self::MAX_ATTEMPTS
        ) {
            return;
        }
        $task = new \mod_tupmeet\task\sync_cohost();
        $task->set_component('mod_tupmeet');
        $task->set_custom_data(['id' => (int) $record->id, 'version' => $record->cohostversion]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Persist results only for the exact Calendar and Meet revisions that generated them.
     *
     * @param \stdClass $record Snapshot
     * @param array $values Internally supplied Meet fields
     */
    private function write(\stdClass $record, array $values): void {
        global $DB;
        $params = ['id' => $record->id, 'version' => $record->cohostversion, 'calendarversion' => $record->syncversion];
        $assignments = [];
        foreach ($values as $field => $value) {
            $assignments[] = $field . ' = :' . $field;
            $params[$field] = $value;
        }
        $DB->execute(
            'UPDATE {tupmeet} SET ' . implode(', ', $assignments) .
            " WHERE id = :id AND cohostversion = :version AND syncversion = :calendarversion AND syncstatus = 'ready'",
            $params
        );
    }

    /**
     * Check whether an HTTP snapshot still represents the current desired state.
     *
     * @param \stdClass $record Snapshot
     * @return bool
     */
    private function current(\stdClass $record): bool {
        global $DB;
        return $DB->record_exists('tupmeet', [
            'id' => $record->id, 'syncversion' => $record->syncversion,
            'cohostversion' => $record->cohostversion, 'syncstatus' => 'ready',
        ]);
    }

    /**
     * Resolve the same space and converge membership after commit, retaining the link on every failure.
     *
     * @param int $id Activity ID
     * @param string|null $version Worker revision; stale workers retire without HTTP
     * @return bool Work finished/stale/exhausted; false requests native backoff on the same task
     */
    public function synchronize(int $id, ?string $version = null): bool {
        global $DB;
        if ($DB->is_transaction_started()) {
            throw new \coding_exception('Cohost configuration must run after the database commit.');
        }
        // Share Calendar's lock so all three services cannot reconcile one activity concurrently.
        $lock = \core\lock\lock_config::get_lock_factory('mod_tupmeet')->get_lock('meeting:' . $id, 0);
        if (!$lock) {
            return false;
        }
        try {
            $record = $DB->get_record('tupmeet', ['id' => $id]);
            if (
                !$record || ($version !== null && $version !== $record->cohostversion) ||
                    $record->syncstatus !== 'ready' ||
                    !in_array($record->cohoststatus, ['pending', 'error'], true)
            ) {
                return true;
            }
            if ($record->cohostattempts >= self::MAX_ATTEMPTS) {
                $this->write($record, ['cohoststatus' => 'error']);
                return true;
            }
            $record->cohostattempts++;
            $this->write($record, ['cohostattempts' => $record->cohostattempts]);
            $stage = 'space';
            try {
                if (!calendar_service::valid_meet_uri($record->meeturi ?? '')) {
                    throw new \moodle_exception('cohostfailed', 'mod_tupmeet');
                }
                if (!$this->current($record)) {
                    return true;
                }
                self::check_identity($record);
                $stage = 'identity';
                $account = (new account_manager())->get_account((int) $record->accountid);
                $stage = 'unknown';
                $member = $this->meet->synchronize($account, $record, function (string $name) use ($record): bool {
                    global $DB;
                    // Store the canonical identity before member writes, including when a write will fail/timeout.
                    $this->write($record, ['meetspacename' => $name]);
                    return $this->current($record) && $DB->get_field('tupmeet', 'meetspacename', ['id' => $record->id]) === $name;
                }, function () use ($record): bool {
                    self::check_identity($record);
                    return $this->current($record);
                });
                self::check_identity($record);
                $this->write($record, [
                    'cohoststatus' => 'ready', 'cohostmodified' => time(), 'cohostmembername' => $member,
                    'cohosterrorstage' => 'unknown', 'cohosthttpstatus' => 0,
                ]);
                return true;
            } catch (\Throwable $e) {
                // Never persist exception text, upstream bodies, URLs, headers or credentials.
                $this->write($record, [
                    'cohoststatus' => 'error',
                    'cohosterrorstage' => $e instanceof cohost_exception ? $e->stage : $stage,
                    'cohosthttpstatus' => $e instanceof cohost_exception ? $e->httpstatus : 0,
                ]);
                return !$this->current($record) || $record->cohostattempts >= self::MAX_ATTEMPTS;
            }
        } finally {
            $lock->release();
        }
    }
}
