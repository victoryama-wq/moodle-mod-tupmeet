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
 * Retry Meet configuration independently of Calendar, with a bounded per-revision budget.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_meet_config extends \core\task\adhoc_task {
    /**
     * Retry the same task through Moodle backoff; never enqueue a retry from this worker.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        if (!(new \mod_tupmeet\local\meeting\meet_config_manager())->synchronize((int) $data->id, $data->version)) {
            throw new \moodle_exception('meetconfigfailed', 'mod_tupmeet');
        }
    }
}
