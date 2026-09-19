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

/**
 * Server authorization shared by recording POST actions and tests.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class actions {
    /**
     * Queue only the requested subsystem; never accept a remote ID or filename.
     * @param \stdClass $cm Course module already resolved by Moodle
     * @param \context_module $context Module context
     * @param string $action sync or rename
     * @param int $recordingid Local recording ID
     * @return bool Accepted or throttled
     */
    public static function execute(\stdClass $cm, \context_module $context, string $action, int $recordingid = 0): bool {
        global $DB;
        require_capability('moodle/course:manageactivities', $context);
        if ($context->instanceid != $cm->id || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new \moodle_exception('invalidrequest');
        }
        require_sesskey();
        if ($action === 'sync') {
            return recording_manager::queue((int) $cm->instance, true);
        }
        if ($action === 'rename') {
            $DB->get_record('tupmeet_recordings', ['id' => $recordingid, 'tupmeetid' => $cm->instance], '*', MUST_EXIST);
            return rename_manager::queue($recordingid, true);
        }
        throw new \moodle_exception('invalidrequest');
    }
}
