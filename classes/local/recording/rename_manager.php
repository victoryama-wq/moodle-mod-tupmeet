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

use mod_tupmeet\local\google\drive_metadata_service;
use mod_tupmeet\local\google\recording_exception;

/**
 * Durable metadata-only rename, independently retryable from discovery and meeting provisioning.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rename_manager {
    /** @var drive_metadata_service Validated Drive boundary. */
    private drive_metadata_service $service;

    /**
     * Configure boundary.
     * @param drive_metadata_service|null $service Service
     */
    public function __construct(?drive_metadata_service $service = null) {
        $this->service = $service ?? new drive_metadata_service();
    }

    /**
     * Queue an eligible rename or explicit retry without any Google request.
     * @param int $id Local recording ID, never a browser-provided Drive ID
     * @param bool $manual Explicit protected request
     * @return bool Accepted
     */
    public static function queue(int $id, bool $manual = false): bool {
        global $DB;
        $record = $DB->get_record('tupmeet_recordings', ['id' => $id]);
        if (!$record) {
            return false;
        }
        $lock = \core\lock\lock_config::get_lock_factory('mod_tupmeet')->get_lock('meeting:' . $record->tupmeetid, 0);
        if (!$lock) {
            return false;
        }
        try {
            $record = $DB->get_record('tupmeet_recordings', ['id' => $id]);
            $meeting = $record ? $DB->get_record('tupmeet', ['id' => $record->tupmeetid]) : false;
            if (
                !$meeting || !recording_manager::eligible($meeting) || $meeting->provisionmode !== 'meet' ||
                $record->state !== 'FILE_GENERATED' || empty($record->desiredfilename) ||
                $record->renamestatus === 'ready' || (!$manual && $record->renamestatus !== 'pending') ||
                ($manual && $record->renamequeued > time() - 60) ||
                (!$manual && ($record->renamenextattempt > time() || $record->renamequeued > time() - 1800))
            ) {
                return false;
            }
            $transaction = $DB->start_delegated_transaction();
            if ($manual && $record->renamestatus !== 'pending') {
                $record->renameversion = bin2hex(random_bytes(16));
                $record->renameattempts = 0;
                $record->renamenextattempt = 0;
            }
            $record->renamestatus = 'pending';
            $record->renamequeued = time();
            $DB->update_record('tupmeet_recordings', $record);
            $task = new \mod_tupmeet\task\rename_recording();
            $task->set_custom_data(['id' => $id, 'version' => $record->renameversion]);
            \core\task\manager::queue_adhoc_task($task, true);
            $transaction->allow_commit();
            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Recover a bounded batch of durable pending renames, even when discovery is dormant.
     * @param int $meetingid Optional just-discovered activity
     */
    public static function dispatch(int $meetingid = 0): void {
        global $DB;
        $where = 'renamestatus = :pending AND renamenextattempt <= :now AND renamequeued <= :lease';
        $params = ['pending' => 'pending', 'now' => time(), 'lease' => time() - 1800];
        if ($meetingid) {
            $where .= ' AND tupmeetid = :meeting';
            $params['meeting'] = $meetingid;
        }
        foreach (
            $DB->get_records_select(
                'tupmeet_recordings',
                $where,
                $params,
                'renamenextattempt, id',
                'id',
                0,
                recording_manager::BATCH
            ) as $record
        ) {
            self::queue((int) $record->id);
        }
    }

    /**
     * Check the exact immutable recording revision, destination and historical owner after HTTP.
     * @param \stdClass $record Original recording
     * @param \stdClass $meeting Original activity
     * @return bool
     */
    private static function current(\stdClass $record, \stdClass $meeting): bool {
        global $DB;
        $current = $DB->get_record('tupmeet_recordings', ['id' => $record->id]);
        $activity = $DB->get_record('tupmeet', ['id' => $meeting->id]);
        return $current && $activity && recording_manager::eligible($activity) && $activity->provisionmode === 'meet' &&
            $activity->accountid == $meeting->accountid && $activity->meetspacename === $meeting->meetspacename &&
            $current->renameversion === $record->renameversion && $current->renamestatus === 'pending' &&
            $current->drivefileid === $record->drivefileid && $current->desiredfilename === $record->desiredfilename;
    }

    /**
     * Rename a frozen destination; local ready skips even the metadata GET.
     * @param int $id Recording ID
     * @param string $version Queued revision
     * @return bool Finished, or false for native task backoff
     */
    public function synchronize(int $id, string $version): bool {
        global $DB;
        if ($DB->is_transaction_started()) {
            throw new \coding_exception('Recording rename must run after commit.');
        }
        $record = $DB->get_record('tupmeet_recordings', ['id' => $id]);
        if (!$record) {
            return true;
        }
        $lock = \core\lock\lock_config::get_lock_factory('mod_tupmeet')->get_lock('meeting:' . $record->tupmeetid, 0);
        if (!$lock) {
            return false;
        }
        try {
            $record = $DB->get_record('tupmeet_recordings', ['id' => $id]);
            $meeting = $record ? $DB->get_record('tupmeet', ['id' => $record->tupmeetid]) : false;
            if (!$record || !$meeting || $record->renameversion !== $version || !self::current($record, $meeting)) {
                return true;
            }
            if ($record->renameattempts >= recording_manager::MAX_ATTEMPTS) {
                $DB->update_record('tupmeet_recordings', (object) ['id' => $id, 'renamestatus' => 'error']);
                return true;
            }
            if ($record->renamenextattempt > time()) {
                return false;
            }
            $record->renameattempts++;
            $DB->update_record('tupmeet_recordings', (object) ['id' => $id,
                'renameattempts' => $record->renameattempts, 'renamenextattempt' => time() + 1800]);
            try {
                $account = $DB->get_record('tupmeet_accounts', ['id' => $meeting->accountid], '*', MUST_EXIST);
                $checkpoint = static function (string $original) use ($record, $meeting): bool {
                    global $DB;
                    if (!self::current($record, $meeting)) {
                        return false;
                    }
                    $values = ['id' => $record->id, 'drivefilename' => \core_text::substr($original, 0, 255)];
                    if ($record->originalfilename === null) {
                        $values['originalfilename'] = $values['drivefilename'];
                    }
                    $DB->update_record('tupmeet_recordings', (object) $values);
                    return self::current($record, $meeting);
                };
                $result = $this->service->rename($account, $record, $checkpoint);
                if (self::current($record, $meeting)) {
                    $DB->update_record('tupmeet_recordings', (object) ($result + ['id' => $id,
                        'renamedat' => $result['renamestatus'] === 'ready' ? time() : 0,
                        'renamehttpstatus' => 0, 'renamenextattempt' => 0, 'timemodified' => time()]));
                }
                return true;
            } catch (\Throwable $e) {
                if (!self::current($record, $meeting)) {
                    return true;
                }
                $retry = $e instanceof recording_exception && $e->retryable &&
                    $record->renameattempts < recording_manager::MAX_ATTEMPTS;
                $DB->update_record('tupmeet_recordings', (object) ['id' => $id,
                    'renamestatus' => $retry ? 'pending' : 'error',
                    'renamehttpstatus' => $e instanceof recording_exception ? $e->httpstatus : 0,
                    'renamenextattempt' => $retry ? time() + polling::backoff($record->renameattempts) : 0,
                    'timemodified' => time()]);
                return !$retry;
            }
        } finally {
            $lock->release();
        }
    }
}
