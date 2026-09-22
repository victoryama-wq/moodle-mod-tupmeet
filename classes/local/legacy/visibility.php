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

namespace mod_tupmeet\local\legacy;

/**
 * Local-only historical visibility with fixed table and explicit actions.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class visibility {
    /**
     * Persist only local visibility under the same activity lock as discovery.
     * Explicit show/hide is idempotent, so a repeated POST cannot accidentally toggle twice.
     * @param \stdClass $cm Verified module
     * @param \context_module $context Verified context
     * @param int $id Local recording
     * @param bool $visible Requested action, resolved by the server
     * @return bool
     */
    public static function change(\stdClass $cm, \context_module $context, int $id, bool $visible): bool {
        global $DB, $USER;
        require_capability('moodle/course:manageactivities', $context);
        if ($context->instanceid != $cm->id || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new \moodle_exception('invalidrequest');
        }
        require_sesskey();
        $lock = \core\lock\lock_config::get_lock_factory('mod_tupmeet')->get_lock('meeting:' . $cm->instance, 0);
        if (!$lock) {
            throw new \moodle_exception('visibilitybusy', 'mod_tupmeet');
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            $record = $DB->get_record('tupmeet_legacy_recordings', ['id' => $id, 'tupmeetid' => $cm->instance], '*', MUST_EXIST);
            if ((bool) $record->studentvisible !== $visible) {
                $DB->update_record('tupmeet_legacy_recordings', (object) [
                    'id' => $id, 'studentvisible' => (int) $visible,
                    'visibilitymodified' => time(), 'visibilityuserid' => $USER->id,
                ]);
                \mod_tupmeet\event\legacy_recording_visibility_changed::create([
                    'context' => $context, 'objectid' => $id,
                    'other' => ['visible' => (int) $visible, 'activityid' => (int) $cm->instance],
                ])->trigger();
            }
            $transaction->allow_commit();
            return true;
        } catch (\Throwable $e) {
            if (isset($transaction)) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }
}
