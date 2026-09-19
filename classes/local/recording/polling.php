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

use mod_tupmeet\local\meeting\schedule;

/**
 * Poll near actual processing or the next scheduled session, with a finite recovery window.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class polling {
    /** @var int Maximum remote retention/recovery window. */
    public const WINDOW = 30 * DAYSECS;

    /**
     * Calculate a not-before instant; zero means dormant until an edit or manual request.
     * @param \stdClass $meeting Activity
     * @param array $conferences Known local conferences
     * @param array $recordings Known local recordings
     * @param int $now Current instant
     * @return int
     */
    public static function next(\stdClass $meeting, array $conferences, array $recordings, int $now): int {
        $candidates = [];
        $next = schedule::next_session($meeting, $now);
        if ($next !== null) {
            $duration = schedule::date($meeting->startdatetime, $meeting->timezone)
                ->diff(schedule::date($meeting->enddatetime, $meeting->timezone));
            $candidates[] = schedule::date($next, $meeting->timezone)->add($duration)->getTimestamp() + 300;
        }
        // Discover even an unexpectedly unrecorded session for three days, then daily until retention expires.
        $recent = (int) $meeting->enddatetime;
        $scheduledstart = (int) $meeting->startdatetime;
        if ($meeting->isrecurring) {
            $recent = 0;
            // Use next_session to find sessions within the retention window.
            $cursor = $now - self::WINDOW;
            for ($i = 0; $i < 40; $i++) {
                $start = schedule::next_session($meeting, $cursor);
                if ($start === null || $start > $now) {
                    break;
                }
                $duration = schedule::date($meeting->startdatetime, $meeting->timezone)
                    ->diff(schedule::date($meeting->enddatetime, $meeting->timezone));
                $end = schedule::date($start, $meeting->timezone)->add($duration)->getTimestamp();
                $recent = max($recent, $end);
                $scheduledstart = $start;
                $cursor = $end;
            }
        }
        $generated = false;
        foreach ($conferences as $conference) {
            $recent = max($recent, (int) ($conference->endtime ?: $conference->starttime));
        }
        foreach ($recordings as $recording) {
            if ($recording->state === 'FILE_GENERATED') {
                // A previous week's completed MP4 does not close the new session's processing window.
                $generated = $generated || $recording->starttime >= $scheduledstart - HOURSECS;
            } else if ($now - $recording->starttime < self::WINDOW) {
                $candidates[] = $now + ($recording->state === 'STARTED' ? 300 : 600);
            }
        }
        // Completed sessions get one short settling window, then recurrence sleeps until its next end.
        $window = $generated ? DAYSECS : self::WINDOW;
        if ($recent <= $now && $recent > $now - $window) {
            $candidates[] = $now + (!$generated && $recent > $now - 3 * DAYSECS ? 600 : DAYSECS);
        }
        return $candidates ? max($now + 300, min($candidates)) : 0;
    }

    /**
     * Exponential retry lower bound, in addition to Moodle's native task backoff.
     * @param int $attempt One-based attempt
     * @return int Delay
     */
    public static function backoff(int $attempt): int {
        return min(3600, 60 * (2 ** min(6, max(0, $attempt - 1))));
    }
}
