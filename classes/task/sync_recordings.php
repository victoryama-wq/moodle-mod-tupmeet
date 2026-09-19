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

namespace mod_tupmeet\task;

/**
 * Bounded independent Phase 4 worker using native Moodle task backoff.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_recordings extends \core\task\adhoc_task {
    /**
     * Reconcile this exact revision, never provision or delete Google resources.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        if (!(new \mod_tupmeet\local\recording\recording_manager())->synchronize((int) $data->id, $data->version)) {
            throw new \moodle_exception('recordingfailed', 'mod_tupmeet');
        }
    }
}
