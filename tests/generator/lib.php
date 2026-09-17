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

/**
 * TUP Meet activity generator. Tests configure the institutional owner explicitly.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_tupmeet_generator extends testing_module_generator {
    /**
     * Generate an activity through Moodle's actual module lifecycle.
     *
     * @param array|stdClass|null $record Activity data
     * @param array|null $options Module options
     * @return stdClass
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object) (array) $record;
        $record->startdatetime = $record->startdatetime ?? time();
        $record->enddatetime = $record->enddatetime ?? $record->startdatetime + HOURSECS;
        if (!isset($record->cohostuserid)) {
            $teacher = $this->datagenerator->create_user();
            $this->datagenerator->enrol_user($teacher->id, $record->course, 'editingteacher');
            $record->cohostuserid = $teacher->id;
        }
        return parent::create_instance($record, $options);
    }
}
