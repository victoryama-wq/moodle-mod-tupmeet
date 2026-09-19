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

namespace mod_tupmeet\local\recording;

use mod_tupmeet\local\google\recording_exception;
use mod_tupmeet\local\google\recording_service;

/**
 * Independent, durable Meet metadata reconciliation; never creates or deletes Google resources.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_manager {
    /** @var int Bounded discovery/recovery batch per five-minute scheduler run. */
    public const BATCH = 25;
    /** @var int Per-revision read budget. */
    public const MAX_ATTEMPTS = 5;
    /** @var recording_service Metadata API. */
    private recording_service $service;

    /**
     * Inject the boundary for deterministic tests.
     * @param recording_service|null $service Service
     */
    public function __construct(?recording_service $service = null) {
        $this->service = $service ?? new recording_service();
    }

    /**
     * Only canonical, provisioned activities can be read.
     * @param \stdClass $meeting Activity
     * @return bool
     */
    public static function eligible(\stdClass $meeting): bool {
        return (($meeting->provisionmode === 'meet' && $meeting->spacestatus === 'ready') ||
            $meeting->provisionmode === 'calendar') &&
            recording_service::valid_space($meeting->meetspacename ?? '') && !empty($meeting->accountid);
    }

    /**
     * Reject stale workers after an owner/space/revision change or local deletion.
     * @param \stdClass $snapshot Original worker input
     * @return bool
     */
    public static function current(\stdClass $snapshot): bool {
        global $DB;
        $current = $DB->get_record('tupmeet', ['id' => $snapshot->id]);
        return $current && self::eligible($current) &&
            $current->recordingsyncversion === $snapshot->recordingsyncversion &&
            $current->accountid == $snapshot->accountid && $current->meetspacename === $snapshot->meetspacename;
    }

    /**
     * Queue a due revision under the same lock used by meeting workers.
     * @param int $id Activity
     * @param bool $manual Explicit authenticated request
     * @return bool Accepted
     */
    public static function queue(int $id, bool $manual = false): bool {
        global $DB;
        $lock = \core\lock\lock_config::get_lock_factory('mod_tupmeet')->get_lock('meeting:' . $id, 0);
        if (!$lock) {
            return false;
        }
        try {
            $meeting = $DB->get_record('tupmeet', ['id' => $id]);
            if (!$meeting || !self::eligible($meeting)) {
                return false;
            }
            $now = time();
            $active = in_array($meeting->recordingsyncstatus, ['pending', 'syncing'], true);
            if (
                ($manual && $meeting->recordingsyncqueued > $now - 60) ||
                ($active && $meeting->recordingsnextsync > $now)
            ) {
                return false;
            }
            if (
                !$manual && !$active && $meeting->recordingsyncstatus !== 'idle' &&
                ($meeting->recordingsnextsync == 0 || $meeting->recordingsnextsync > $now) &&
                ($meeting->recordingsyncstatus !== 'ready' ||
                    $meeting->recordingscheckedversion === $meeting->syncversion)
            ) {
                return false;
            }
            $transaction = $DB->start_delegated_transaction();
            if (!$active) {
                $meeting->recordingsyncversion = bin2hex(random_bytes(16));
                $meeting->recordingsyncattempts = 0;
            }
            $meeting->recordingsyncstatus = 'pending';
            $meeting->recordingsyncqueued = $now;
            // A queue lease allows a later scheduler to recover an interrupted/deleted task without renewing its budget.
            if (!$active) {
                $meeting->recordingsnextsync = $now + 1800;
            }
            $DB->update_record('tupmeet', (object) array_intersect_key((array) $meeting, array_flip([
                'id', 'recordingsyncversion', 'recordingsyncattempts', 'recordingsyncstatus',
                'recordingsyncqueued', 'recordingsnextsync',
            ])));
            $task = new \mod_tupmeet\task\sync_recordings();
            $task->set_custom_data(['id' => $id, 'version' => $meeting->recordingsyncversion]);
            \core\task\manager::queue_adhoc_task($task, true);
            $transaction->allow_commit();
            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Select bounded due work only. No network in this scheduler.
     * @return int Queued discovery tasks
     */
    public static function dispatch(): int {
        global $DB;
        $now = time();
        $rows = $DB->get_records_select(
            'tupmeet',
            "(provisionmode = :meet AND spacestatus = :ready OR provisionmode = :calendar)
             AND meetspacename IS NOT NULL AND accountid > 0
             AND (recordingsyncstatus = :idle
                  OR (recordingsnextsync > 0 AND recordingsnextsync <= :now)
                  OR (recordingsyncstatus = :done AND recordingscheckedversion <> syncversion))",
            ['meet' => 'meet', 'ready' => 'ready', 'calendar' => 'calendar', 'idle' => 'idle', 'now' => $now, 'done' => 'ready'],
            'recordingsnextsync, id',
            'id',
            0,
            self::BATCH
        );
        $count = 0;
        foreach ($rows as $row) {
            if (self::queue((int) $row->id)) {
                $count++;
            } else {
                // Invalid canonical identities must not starve later backfill rows.
                $meeting = $DB->get_record('tupmeet', ['id' => $row->id]);
                if ($meeting && !self::eligible($meeting) && $meeting->recordingsyncstatus === 'idle') {
                    $DB->update_record('tupmeet', (object) ['id' => $row->id, 'recordingsyncstatus' => 'error']);
                }
            }
        }
        rename_manager::dispatch();
        return $count;
    }

    /**
     * Persist one complete conference and all its recording pages in a short local transaction.
     * @param \stdClass $meeting Worker snapshot
     * @param array $data Validated service result
     */
    private function persist(\stdClass $meeting, array $data): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            // Core may wrap local deletion in an outer transaction which outlives its Moodle lock.
            // This portable no-op UPDATE locks the parent until this short upsert commits.
            $DB->execute(
                'UPDATE {tupmeet} SET recordingsyncversion = :sameversion
                  WHERE id = :id AND recordingsyncversion = :version',
                ['sameversion' => $meeting->recordingsyncversion, 'id' => $meeting->id,
                    'version' => $meeting->recordingsyncversion]
            );
            if (!self::current($meeting)) {
                $transaction->allow_commit();
                return;
            }
            $now = time();
            $conference = $DB->get_record('tupmeet_conferences', ['conferencename' => $data['conferencename']]);
            if (
                $conference && ($conference->conferencename !== $data['conferencename'] ||
                (int) $conference->tupmeetid !== (int) $meeting->id)
            ) {
                throw new recording_exception();
            }
            if (!$conference) {
                $conference = (object) ['tupmeetid' => $meeting->id, 'conferencename' => $data['conferencename'],
                    'starttime' => $data['starttime'], 'endtime' => $data['endtime'],
                    'firstseen' => $now, 'lastseen' => $now, 'timecreated' => $now, 'timemodified' => $now];
                $conference->id = $DB->insert_record('tupmeet_conferences', $conference);
            } else {
                if ((int) $conference->starttime !== $data['starttime']) {
                    throw new recording_exception();
                }
                $conference->endtime = max($conference->endtime, $data['endtime']);
                $conference->lastseen = $now;
                $conference->timemodified = $now;
                $DB->update_record('tupmeet_conferences', $conference);
            }
            $ranks = ['STARTED' => 0, 'ENDED' => 1, 'FILE_GENERATED' => 2];
            foreach ($data['recordings'] as $datarecord) {
                $record = $DB->get_record('tupmeet_recordings', ['recordingname' => $datarecord['recordingname']]);
                if (
                    $record && ((int) $record->conferenceid !== (int) $conference->id ||
                    $record->recordingname !== $datarecord['recordingname'] ||
                    (int) $record->tupmeetid !== (int) $meeting->id ||
                    (int) $record->starttime !== $datarecord['starttime'] ||
                    (int) $record->startnanos !== $datarecord['startnanos'] ||
                    (!empty($record->drivefileid) && isset($datarecord['drivefileid']) &&
                        $record->drivefileid !== $datarecord['drivefileid']))
                ) {
                    throw new recording_exception();
                }
                if (!$record) {
                    // Read the current preference under the parent row lock, only on first discovery.
                    $visible = $DB->get_field('tupmeet', 'publicationmode', ['id' => $meeting->id]) === 'automatic';
                    $record = (object) ($datarecord + ['tupmeetid' => $meeting->id, 'conferenceid' => $conference->id,
                        'studentvisible' => (int) $visible,
                        'firstseen' => $now, 'lastseen' => $now, 'timecreated' => $now, 'timemodified' => $now]);
                    $record->id = $DB->insert_record('tupmeet_recordings', $record);
                } else {
                    // Eventual-consistency regressions never erase a previously generated destination.
                    if ($ranks[$datarecord['state']] >= $ranks[$record->state]) {
                        foreach ($datarecord as $key => $value) {
                            $record->{$key} = $value;
                        }
                    }
                    $record->lastseen = $now;
                    $record->timemodified = $now;
                    // Discovery never writes visibility or restores an erased user's attribution.
                    unset($record->studentvisible, $record->visibilityuserid, $record->visibilitymodified);
                    $DB->update_record('tupmeet_recordings', $record);
                }
            }
            $records = $DB->get_records(
                'tupmeet_recordings',
                ['conferenceid' => $conference->id],
                'starttime, startnanos, recordingname'
            );
            $part = 0;
            $conflict = false;
            foreach ($records as $record) {
                $part++;
                if ($record->desiredfilename !== null && (int) $record->partnumber !== $part) {
                    $conflict = true;
                }
            }
            $part = 0;
            foreach ($records as $record) {
                $part++;
                if ($record->state !== 'FILE_GENERATED' || $record->desiredfilename !== null) {
                    continue;
                }
                // A late earlier segment cannot renumber already frozen names safely.
                if ($conflict || $meeting->provisionmode === 'calendar') {
                    $DB->update_record('tupmeet_recordings', (object) ['id' => $record->id, 'renamestatus' => 'skipped']);
                    continue;
                }
                $DB->update_record('tupmeet_recordings', (object) [
                    'id' => $record->id, 'partnumber' => $part,
                    'desiredfilename' => filename::make($meeting->name, $conference->starttime, $meeting->timezone, $part),
                    'renamestatus' => 'pending', 'renameversion' => bin2hex(random_bytes(16)),
                ]);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Reconcile one revision. False requests native Moodle backoff, with a persisted finite budget.
     * @param int $id Activity ID
     * @param string $version Queued revision
     * @return bool Finished or obsolete
     */
    public function synchronize(int $id, string $version): bool {
        global $DB;
        if ($DB->is_transaction_started()) {
            throw new \coding_exception('Recording reconciliation must run after commit.');
        }
        $lock = \core\lock\lock_config::get_lock_factory('mod_tupmeet')->get_lock('meeting:' . $id, 0);
        if (!$lock) {
            return false;
        }
        try {
            $meeting = $DB->get_record('tupmeet', ['id' => $id]);
            if (
                !$meeting || !self::eligible($meeting) || $meeting->recordingsyncversion !== $version ||
                !in_array($meeting->recordingsyncstatus, ['pending', 'syncing'], true)
            ) {
                return true;
            }
            if ($meeting->recordingsyncattempts >= self::MAX_ATTEMPTS) {
                $DB->update_record('tupmeet', (object) ['id' => $id, 'recordingsyncstatus' => 'error', 'recordingsnextsync' => 0]);
                return true;
            }
            // Initial queue lease is not a delay; only actual failed attempts have a not-before boundary.
            if ($meeting->recordingsyncattempts > 0 && $meeting->recordingsnextsync > time()) {
                return false;
            }
            $meeting->recordingsyncattempts++;
            $DB->update_record('tupmeet', (object) ['id' => $id, 'recordingsyncstatus' => 'syncing',
                'recordingsyncattempts' => $meeting->recordingsyncattempts, 'recordingsnextsync' => time() + 1800]);
            try {
                $account = $DB->get_record('tupmeet_accounts', ['id' => $meeting->accountid], '*', MUST_EXIST);
                foreach ($this->service->discover($account, $meeting->meetspacename) as $conference) {
                    if (!self::current($meeting)) {
                        return true;
                    }
                    $this->persist($meeting, $conference);
                }
                if (!self::current($meeting)) {
                    return true;
                }
                $next = polling::next(
                    $meeting,
                    $DB->get_records('tupmeet_conferences', ['tupmeetid' => $id]),
                    $DB->get_records('tupmeet_recordings', ['tupmeetid' => $id]),
                    time()
                );
                $DB->update_record('tupmeet', (object) ['id' => $id, 'recordingsyncstatus' => 'ready',
                    'recordingslastsync' => time(), 'recordingsnextsync' => $next, 'recordingshttpstatus' => 0,
                    'recordingscheckedversion' => $meeting->syncversion]);
            } catch (\Throwable $e) {
                if (!self::current($meeting)) {
                    return true;
                }
                $retry = $e instanceof recording_exception && $e->retryable &&
                    $meeting->recordingsyncattempts < self::MAX_ATTEMPTS;
                $DB->update_record('tupmeet', (object) ['id' => $id, 'recordingsyncstatus' => $retry ? 'pending' : 'error',
                    'recordingsnextsync' => $retry ? time() + polling::backoff($meeting->recordingsyncattempts) : 0,
                    'recordingshttpstatus' => $e instanceof recording_exception ? $e->httpstatus : 0]);
                return !$retry;
            }
            return true;
        } finally {
            $lock->release();
            // Independent rename dispatch also recovers metadata persisted before a later conference failed.
            rename_manager::dispatch($id);
        }
    }
}
