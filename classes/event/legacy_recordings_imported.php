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

namespace mod_tupmeet\event;

/**
 * Count-only audit of a local CSV import; no file identities or input data.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class legacy_recordings_imported extends \core\event\base {
    /**
     * Initialize the system event.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localized name.
     * @return string
     */
    public static function get_name() {
        return get_string('eventlegacyimported', 'tupmeet');
    }

    /**
     * Counts only, without CSV data.
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->userid' imported {$this->other['importedcount']} historical references " .
            "and skipped {$this->other['duplicatecount']} duplicates.";
    }

    /**
     * No restore mappings for aggregate counts.
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
