<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_tupmeet\privacy;

/**
 * Privacy provider for the Phase 0 implementation.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements \core_privacy\local\metadata\null_provider {
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
