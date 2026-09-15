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
 * Calendar payloads and local calendar arithmetic in the saved Moodle timezone.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schedule {
    /** @var array Supported weekdays in Monday-first order. */
    public const DAYS = ['mon' => 'MO', 'tue' => 'TU', 'wed' => 'WE', 'thu' => 'TH', 'fri' => 'FR', 'sat' => 'SA', 'sun' => 'SU'];

    /**
     * Resolve an explicit Moodle timezone, including Moodle's numeric offsets.
     *
     * @param string|null $timezone Saved timezone, or current user timezone
     * @return \DateTimeZone
     */
    public static function timezone(?string $timezone = null): \DateTimeZone {
        return \core_date::get_user_timezone_object($timezone ?? \core_date::get_user_timezone());
    }

    /**
     * Convert an instant without relying on PHP's default timezone.
     *
     * @param int $timestamp Unix instant
     * @param string $timezone Explicit zone
     * @return \DateTimeImmutable
     */
    public static function date(int $timestamp, string $timezone): \DateTimeImmutable {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(self::timezone($timezone));
    }

    /**
     * Validate the same schedule contract for forms and non-form callers.
     *
     * @param \stdClass $meeting Local desired state
     * @return array Field errors
     */
    public static function errors(\stdClass $meeting): array {
        $errors = [];
        if (empty($meeting->startdatetime) || ($meeting->enddatetime ?? 0) <= $meeting->startdatetime) {
            $errors['enddatetime'] = get_string('errorendbeforestart', 'tupmeet');
        }
        if (!empty($meeting->isrecurring)) {
            if (($meeting->recurrenceinterval ?? 0) < 1 || $meeting->recurrenceinterval > 999) {
                $errors['recurrenceinterval'] = get_string('errorrecurrenceinterval', 'tupmeet');
            }
            $days = json_decode($meeting->recurrencedays ?? '[]', true);
            if (!is_array($days) || !$days || array_diff($days, array_keys(self::DAYS))) {
                $errors['daymon'] = get_string('errorrecurrenceday', 'tupmeet');
            } else if (!empty($meeting->startdatetime)) {
                $startday = strtolower(self::date($meeting->startdatetime, $meeting->timezone)->format('D'));
                // RFC5545 leaves a DTSTART which does not match BYDAY undefined.
                if (!in_array($startday, $days, true)) {
                    $errors['daymon'] = get_string('errorstartweekday', 'tupmeet');
                }
            }
            if (
                empty($meeting->recurrenceuntil) ||
                    self::date((int) $meeting->recurrenceuntil, $meeting->timezone)->format('Y-m-d') <
                    self::date((int) ($meeting->startdatetime ?? 0), $meeting->timezone)->format('Y-m-d')
            ) {
                $errors['recurrenceuntil'] = get_string('errorrecurrenceuntil', 'tupmeet');
            }
        }
        return $errors;
    }

    /**
     * Produce the editable Calendar fields; conference data is managed separately.
     *
     * @param \stdClass $meeting Local desired state
     * @return array Calendar event fields
     */
    public static function payload(\stdClass $meeting): array {
        if (self::errors($meeting)) {
            throw new \moodle_exception('invalidschedule', 'mod_tupmeet');
        }
        $zone = self::timezone($meeting->timezone)->getName();
        $payload = [
            'summary' => $meeting->name,
            'description' => html_to_text($meeting->intro ?? '', 0, false),
            'start' => ['dateTime' => self::date($meeting->startdatetime, $zone)->format(DATE_RFC3339), 'timeZone' => $zone],
            'end' => ['dateTime' => self::date($meeting->enddatetime, $zone)->format(DATE_RFC3339), 'timeZone' => $zone],
            'recurrence' => [],
        ];
        if ($meeting->isrecurring) {
            $days = array_intersect_key(self::DAYS, array_flip(json_decode($meeting->recurrencedays, true)));
            $until = self::date($meeting->recurrenceuntil, $zone)->setTime(23, 59, 59)
                ->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
            $payload['recurrence'] = [
                'RRULE:FREQ=WEEKLY;INTERVAL=' . (int) $meeting->recurrenceinterval .
                    ';BYDAY=' . implode(',', $days) . ';WKST=MO;UNTIL=' . $until,
            ];
        }
        return $payload;
    }

    /**
     * Find the next local start (or ongoing session) using Monday-anchored weeks.
     *
     * @param \stdClass $meeting Stored schedule
     * @param int $now Current instant
     * @return int|null Unix start, or null when the series has ended
     */
    public static function next_session(\stdClass $meeting, int $now): ?int {
        if (self::errors($meeting)) {
            return null;
        }
        if (!$meeting->isrecurring) {
            return $meeting->enddatetime > $now ? (int) $meeting->startdatetime : null;
        }
        $start = self::date($meeting->startdatetime, $meeting->timezone);
        $end = self::date($meeting->enddatetime, $meeting->timezone);
        $duration = $start->diff($end);
        $anchor = $start->modify('monday this week')->setTime(0, 0);
        $until = self::date($meeting->recurrenceuntil, $meeting->timezone)->setTime(23, 59, 59);
        $searchstart = max($meeting->startdatetime, $now - ($meeting->enddatetime - $meeting->startdatetime));
        $cursor = self::date($searchstart, $meeting->timezone)
            ->setTime((int) $start->format('H'), (int) $start->format('i'), (int) $start->format('s'));
        $days = json_decode($meeting->recurrencedays, true);
        // At most one recurrence interval is needed once the search reaches the present.
        $limit = 7 * (int) $meeting->recurrenceinterval + 7;
        for ($i = 0; $i <= $limit && $cursor <= $until; $i++, $cursor = $cursor->modify('+1 day')) {
            $week = intdiv((int) $anchor->diff($cursor->setTime(0, 0))->format('%a'), 7);
            if (
                $cursor >= $start && $week % $meeting->recurrenceinterval === 0 &&
                    in_array(strtolower($cursor->format('D')), $days, true) && $cursor->add($duration)->getTimestamp() > $now
            ) {
                return $cursor->getTimestamp();
            }
        }
        return null;
    }
}
