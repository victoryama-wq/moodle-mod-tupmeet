<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_tupmeet\event;

/**
 * Event fired when a TUP Meet activity is viewed.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_module_viewed extends \core\event\course_module_viewed {
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'tupmeet';
    }

    public static function get_objectid_mapping() {
        return ['db' => 'tupmeet', 'restore' => 'tupmeet'];
    }
}
