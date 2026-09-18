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
use mod_tupmeet\local\google\space_exception;
use mod_tupmeet\local\google\space_service;

/**
 * Conservative, durable creation of exactly one intended Space per activity, never per occurrence.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class space_manager {
    /** @var space_service Native Google boundary. */
    private space_service $service;

    /**
     * Configure production transport.
     *
     * @param space_service|null $service Space service
     */
    public function __construct(?space_service $service = null) {
        $this->service = $service ?? new space_service();
    }

    /**
     * Initialize only a brand-new local activity, never reset on a schedule/preference edit.
     *
     * @param \stdClass $record New activity
     */
    public static function prepare(\stdClass $record): void {
        $record->provisionmode = 'meet';
        $record->spacestatus = 'pending';
        $record->spaceversion = bin2hex(random_bytes(16));
        $record->spaceattempts = 0;
        $record->spacemodified = 0;
        $record->spacenextattempt = 0;
        $record->spacehttpstatus = 0;
    }

    /**
     * Queue immediately unless Google explicitly imposed a quota wait. Never queue historical or uncertain work.
     *
     * @param \stdClass $record Saved desired state
     */
    public static function queue(\stdClass $record): void {
        if (!provisioning::is_meet($record) || !in_array($record->spacestatus, ['pending', 'creating'], true)) {
            return;
        }
        $task = new \mod_tupmeet\task\sync_space();
        $task->set_component('mod_tupmeet');
        $task->set_custom_data(['id' => (int) $record->id, 'version' => $record->spaceversion]);
        if ($record->spacenextattempt > time()) {
            $task->set_next_run_time((int) $record->spacenextattempt);
        }
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Conditional transition restricted to the provisioning generation and previous state.
     *
     * @param \stdClass $record Snapshot
     * @param string $from Previous state
     * @param array $values Internally constructed updates
     */
    private function write(\stdClass $record, string $from, array $values): void {
        global $DB;
        $params = ['id' => $record->id, 'version' => $record->spaceversion, 'oldstatus' => $from];
        $assignments = [];
        foreach ($values as $field => $value) {
            $assignments[] = $field . ' = :' . $field;
            $params[$field] = $value;
        }
        $DB->execute('UPDATE {tupmeet} SET ' . implode(', ', $assignments) .
            " WHERE id = :id AND spaceversion = :version AND spacestatus = :oldstatus AND provisionmode = 'meet'", $params);
    }

    /**
     * Run outside transactions under the same meeting lock used by all dependent services.
     *
     * @param int $id Activity ID
     * @param string|null $version Expected worker generation
     * @return bool Finished or manual review needed; false asks native task backoff
     */
    public function synchronize(int $id, ?string $version = null): bool {
        global $DB;
        if ($DB->is_transaction_started()) {
            throw new \coding_exception('Space synchronization must run after the database commit.');
        }
        $lock = \core\lock\lock_config::get_lock_factory('mod_tupmeet')->get_lock('meeting:' . $id, 0);
        if (!$lock) {
            return false;
        }
        try {
            $record = $DB->get_record('tupmeet', ['id' => $id]);
            if (!$record || !provisioning::is_meet($record) || ($version !== null && $version !== $record->spaceversion)) {
                return true;
            }
            if ($record->spacestatus === 'creating') {
                // The previous process lost the lock without confirming an outcome. Never issue another POST.
                $this->write($record, 'creating', ['spacestatus' => 'uncertain', 'spacemodified' => time()]);
                return true;
            }
            if ($record->spacestatus !== 'pending') {
                return true;
            }
            if (!empty($record->meetspacename) || !empty($record->meeturi) || !empty($record->meetingcode)) {
                $this->write($record, 'pending', ['spacestatus' => 'uncertain', 'spacemodified' => time()]);
                return true;
            }
            if ($record->spacenextattempt > time()) {
                return false;
            }
            $attempted = false;
            try {
                $account = (new account_manager())->get_account((int) $record->accountid);
                $identifiers = $this->service->create($account, function () use ($record, &$attempted): bool {
                    global $DB;
                    if (
                        !$DB->record_exists('tupmeet', ['id' => $record->id, 'spaceversion' => $record->spaceversion,
                            'provisionmode' => 'meet', 'spacestatus' => 'pending'])
                    ) {
                        return false;
                    }
                    $this->write($record, 'pending', ['spacestatus' => 'creating', 'spaceattempts' => $record->spaceattempts + 1,
                        'spacemodified' => time(), 'spacehttpstatus' => 0, 'spacenextattempt' => 0]);
                    $attempted = $DB->record_exists('tupmeet', ['id' => $record->id, 'spaceversion' => $record->spaceversion,
                        'provisionmode' => 'meet', 'spacestatus' => 'creating', 'spaceattempts' => $record->spaceattempts + 1]);
                    return $attempted;
                });
                if ($identifiers === null) {
                    return true;
                }
                $transaction = $DB->start_delegated_transaction();
                try {
                    $this->write($record, 'creating', $identifiers + ['spacestatus' => 'ready', 'spacemodified' => time()]);
                    $current = $DB->get_record('tupmeet', ['id' => $id]);
                    if ($current && $current->spaceversion === $record->spaceversion && provisioning::space_ready($current)) {
                        // Each reconciliation is durable and independent; no HTTP inside this transaction.
                        cohost_manager::queue($current);
                        meet_config_manager::queue($current);
                        meeting_manager::queue($current);
                    }
                    $transaction->allow_commit();
                } catch (\Throwable $e) {
                    $transaction->rollback($e);
                }
                return true;
            } catch (\Throwable $e) {
                $status = $e instanceof space_exception ? $e->httpstatus : 0;
                $state = !$attempted || ($e instanceof space_exception && $e->rejected()) ? 'error' : 'uncertain';
                $next = 0;
                if ($attempted && $status === 429) {
                    $state = 'pending';
                    $next = time() + min(3600, 30 * (2 ** min(7, (int) $record->spaceattempts))) + random_int(0, 30);
                }
                $this->write($record, $attempted ? 'creating' : 'pending', ['spacestatus' => $state,
                    'spacemodified' => time(), 'spacehttpstatus' => $status, 'spacenextattempt' => $next]);
                return $state !== 'pending';
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Explicit retry of a definitive rejection/preflight failure only. Uncertain is never reset here.
     *
     * @param int $id Activity ID
     */
    public static function retry(int $id): void {
        global $DB;
        if ($DB->is_transaction_started()) {
            throw new \coding_exception('Space retry must run outside the activity transaction.');
        }
        $lock = \core\lock\lock_config::get_lock_factory('mod_tupmeet')->get_lock('meeting:' . $id, 0);
        if (!$lock) {
            return;
        }
        try {
            $record = $DB->get_record('tupmeet', ['id' => $id], '*', MUST_EXIST);
            if (!provisioning::is_meet($record) || $record->spacestatus !== 'error' || !empty($record->meetspacename)) {
                return;
            }
            $transaction = $DB->start_delegated_transaction();
            try {
                $record->spaceversion = bin2hex(random_bytes(16));
                $record->spacestatus = 'pending';
                $record->spacehttpstatus = 0;
                $record->spacenextattempt = 0;
                $DB->update_record('tupmeet', (object) array_intersect_key((array) $record, array_flip([
                    'id', 'spaceversion', 'spacestatus', 'spacehttpstatus', 'spacenextattempt',
                ])));
                self::queue($record);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
        } finally {
            $lock->release();
        }
    }
}
