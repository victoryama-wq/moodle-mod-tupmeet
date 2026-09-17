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

namespace mod_tupmeet\local\meeting;

/**
 * Resolve teaching identities from Moodle, never from browser-supplied email addresses.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cohost_identity {
    /**
     * Require active course enrolment, a teaching capability and a usable Moodle email.
     *
     * @param int $courseid Course
     * @param int $userid Selected Moodle user
     * @return \stdClass Authoritative user record
     */
    public static function resolve(int $courseid, int $userid): \stdClass {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid]);
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (
            !$context || !$user || $user->deleted || $user->suspended || isguestuser($user) ||
                !validate_email($user->email) || !is_enrolled($context, $user, '', true) ||
                !has_capability('moodle/course:manageactivities', $context, $user, false)
        ) {
            throw new \moodle_exception('cohostinvalid', 'mod_tupmeet');
        }
        return $user;
    }

    /**
     * Build the form's user-ID options; site administrators are not implicitly candidates.
     *
     * @param int $courseid Course
     * @return array User ID to display label
     */
    public static function options(int $courseid): array {
        $context = \context_course::instance($courseid);
        $options = [0 => get_string('choosedots')];
        foreach (get_enrolled_users($context, 'moodle/course:manageactivities', 0, 'u.*', null, 0, 0, true) as $user) {
            try {
                self::resolve($courseid, (int) $user->id);
                $options[$user->id] = fullname($user) . ' — ' . $user->email;
            } catch (\moodle_exception $e) {
                continue;
            }
        }
        return $options;
    }

    /**
     * Only the eligible current course teacher can be preselected on a new activity.
     *
     * @param int $courseid Course
     * @return int User ID or zero requiring an explicit choice
     */
    public static function default_user(int $courseid): int {
        global $USER;
        try {
            return (int) self::resolve($courseid, (int) $USER->id)->id;
        } catch (\moodle_exception $e) {
            return 0;
        }
    }
}
