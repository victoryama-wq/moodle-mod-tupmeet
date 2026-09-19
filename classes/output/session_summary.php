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

namespace mod_tupmeet\output;

use mod_tupmeet\local\meeting\schedule;

/**
 * Academic session context; never exports an activity object or technical state.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class session_summary {
    /**
     * Build localized display values in the saved timezone, without Google access.
     * @param \stdClass $meeting Activity
     * @param int $now Current instant
     * @return array Minimal template data
     */
    public static function data(\stdClass $meeting, int $now): array {
        $next = schedule::next_session($meeting, $now);
        $data = ['hassession' => $next !== null, 'joinurl' => meeting_status::join_url($meeting)];
        if ($next !== null) {
            $start = schedule::date($meeting->startdatetime, $meeting->timezone);
            $duration = $start->diff(schedule::date($meeting->enddatetime, $meeting->timezone));
            $end = schedule::date($next, $meeting->timezone)->add($duration)->getTimestamp();
            $data['date'] = userdate($next, get_string('sessiondateformat', 'tupmeet'), $meeting->timezone);
            $data['hours'] = userdate($next, '%H:%M', $meeting->timezone) . ' – ' .
                userdate($end, '%H:%M', $meeting->timezone);
            if (
                schedule::date($next, $meeting->timezone)->format('Y-m-d') !==
                    schedule::date($end, $meeting->timezone)->format('Y-m-d')
            ) {
                $data['enddate'] = userdate($end, get_string('sessiondateformat', 'tupmeet'), $meeting->timezone);
            }
        }
        if ($meeting->isrecurring) {
            $days = json_decode($meeting->recurrencedays ?? '[]', true) ?: [];
            $names = ['mon' => 'monday', 'tue' => 'tuesday', 'wed' => 'wednesday', 'thu' => 'thursday',
                'fri' => 'friday', 'sat' => 'saturday', 'sun' => 'sunday'];
            $labels = [];
            foreach ($names as $key => $name) {
                if (in_array($key, $days, true)) {
                    $labels[] = get_string($name, 'calendar');
                }
            }
            $data['pattern'] = get_string(
                $meeting->recurrenceinterval == 1 ? 'sessionweekly' : 'sessionweeks',
                'tupmeet',
                (int) $meeting->recurrenceinterval
            ) . ' · ' . implode(', ', $labels);
            $data['until'] = userdate($meeting->recurrenceuntil, get_string('sessiondateformat', 'tupmeet'), $meeting->timezone);
        }
        return $data;
    }

    /**
     * Render the academic card.
     * @param \stdClass $meeting Activity
     * @return string
     */
    public static function render(\stdClass $meeting): string {
        global $OUTPUT;
        return $OUTPUT->render_from_template('mod_tupmeet/session_summary', self::data($meeting, time()));
    }
}
