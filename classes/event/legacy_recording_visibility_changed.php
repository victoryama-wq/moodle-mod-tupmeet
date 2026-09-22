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
 * Local academic visibility changed; no remote identifier is recorded.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class legacy_recording_visibility_changed extends \core\event\base {
    /**
     * Initialize the local update event.
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'tupmeet_legacy_recordings';
    }

    /**
     * Localized log name.
     * @return string
     */
    public static function get_name() {
        return get_string('eventlegacyvisibilitychanged', 'tupmeet');
    }

    /**
     * Non-sensitive audit description.
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->userid' set recording '$this->objectid' visibility to " .
            "'{$this->other['visible']}' in activity '{$this->other['activityid']}'.";
    }

    /**
     * Link back to the protected module.
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/tupmeet/view.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Validate the local event contract.
     */
    protected function validate_data() {
        parent::validate_data();
        if (
            !isset($this->other['visible'], $this->other['activityid']) ||
                !in_array($this->other['visible'], [0, 1], true)
        ) {
            throw new \coding_exception('Local visibility and activity are required.');
        }
    }

    /**
     * Backups are not supported by this module.
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'tupmeet_legacy_recordings', 'restore' => self::NOT_MAPPED];
    }

    /**
     * Local activity mapping only.
     * @return array
     */
    public static function get_other_mapping() {
        return ['activityid' => ['db' => 'tupmeet', 'restore' => self::NOT_MAPPED]];
    }
}
