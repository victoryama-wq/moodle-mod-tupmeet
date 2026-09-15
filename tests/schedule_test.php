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

namespace mod_tupmeet;

use mod_tupmeet\local\meeting\schedule;

/**
 * RFC3339, weekly RRULE and civil-time regressions.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(schedule::class)]
final class schedule_test extends \advanced_testcase {
    /**
     * Build a deterministic late-evening Cancun meeting.
     *
     * @return \stdClass Schedule
     */
    private function meeting(): \stdClass {
        return (object) [
            'name' => 'Synthetic class', 'intro' => '<p>Agenda</p>', 'timezone' => 'America/Cancun',
            'startdatetime' => (new \DateTimeImmutable('2026-09-14T23:00:00-05:00'))->getTimestamp(),
            'enddatetime' => (new \DateTimeImmutable('2026-09-15T00:00:00-05:00'))->getTimestamp(),
            'isrecurring' => 0, 'recurrenceinterval' => 1, 'recurrencedays' => '["mon","wed"]',
            'recurrenceuntil' => (new \DateTimeImmutable('2026-12-12T00:00:00-05:00'))->getTimestamp(),
        ];
    }

    /**
     * UTC server cannot shift the RFC3339 start or end chosen in Cancun.
     */
    public function test_simple_payload_with_utc_server(): void {
        $previous = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $payload = schedule::payload($this->meeting());
            $this->assertSame('2026-09-14T23:00:00-05:00', $payload['start']['dateTime']);
            $this->assertSame('2026-09-15T00:00:00-05:00', $payload['end']['dateTime']);
            $this->assertSame('America/Cancun', $payload['start']['timeZone']);
            $this->assertSame([], $payload['recurrence']);
            $this->assertSame('Agenda', trim($payload['description']));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    /**
     * Weekly multiple-day series has an inclusive local end date expressed in UTC.
     */
    public function test_weekly_recurrence_and_until(): void {
        $meeting = $this->meeting();
        $meeting->isrecurring = 1;
        $meeting->recurrenceinterval = 2;
        $payload = schedule::payload($meeting);
        $this->assertSame(
            ['RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE;WKST=MO;UNTIL=20261213T045959Z'],
            $payload['recurrence']
        );
    }

    /**
     * A same-local-day recurrence end is valid even when the start is tomorrow in UTC.
     */
    public function test_until_validation_uses_local_date(): void {
        $meeting = $this->meeting();
        $meeting->isrecurring = 1;
        $meeting->recurrenceuntil = (new \DateTimeImmutable('2026-09-14T00:00:00-05:00'))->getTimestamp();
        $this->assertSame([], schedule::errors($meeting));
        $meeting->recurrenceuntil -= DAYSECS;
        $this->assertArrayHasKey('recurrenceuntil', schedule::errors($meeting));
    }

    /**
     * Reject malformed recurrence and undefined DTSTART/BYDAY combinations.
     */
    public function test_recurrence_validation(): void {
        $meeting = $this->meeting();
        $meeting->isrecurring = 1;
        $meeting->recurrencedays = '["tue"]';
        $this->assertArrayHasKey('daymon', schedule::errors($meeting));
        $meeting->recurrencedays = '["invalid"]';
        $meeting->recurrenceinterval = 1000;
        $this->assertCount(2, schedule::errors($meeting));
    }

    /**
     * Next occurrence respects multiple days, alternating weeks and the end date.
     */
    public function test_next_session(): void {
        $meeting = $this->meeting();
        $meeting->isrecurring = 1;
        $meeting->recurrenceinterval = 2;
        $now = (new \DateTimeImmutable('2026-09-17T10:00:00-05:00'))->getTimestamp();
        $next = schedule::next_session($meeting, $now);
        $this->assertSame('2026-09-28T23:00:00-05:00', schedule::date($next, $meeting->timezone)->format(DATE_RFC3339));
        $this->assertNull(schedule::next_session($meeting, $meeting->recurrenceuntil + 2 * DAYSECS));
        $this->assertSame($meeting->startdatetime, schedule::next_session($meeting, $meeting->startdatetime + 60));
    }

    /**
     * IANA zones preserve wall time across daylight-saving changes.
     */
    public function test_next_session_across_dst(): void {
        $meeting = $this->meeting();
        $meeting->timezone = 'America/New_York';
        $meeting->startdatetime = (new \DateTimeImmutable('2026-10-26T10:00:00-04:00'))->getTimestamp();
        $meeting->enddatetime = $meeting->startdatetime + HOURSECS;
        $meeting->isrecurring = 1;
        $meeting->recurrencedays = '["mon"]';
        $next = schedule::next_session($meeting, $meeting->enddatetime);
        $this->assertSame('2026-11-02T10:00:00-05:00', schedule::date($next, $meeting->timezone)->format(DATE_RFC3339));
    }
}
