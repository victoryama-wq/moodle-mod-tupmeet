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

use mod_tupmeet\local\google\calendar_service;

/**
 * Native OAuth HTTP method options, response validation and safe errors.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(calendar_service::class)]
final class calendar_transport_test extends \advanced_testcase {
    /**
     * Provider bodies and exceptions must not escape the HTTP boundary.
     */
    public function test_safe_http_errors(): void {
        $this->resetAfterTest();
        $client = $this->getMockBuilder(\core\oauth2\client::class)->disableOriginalConstructor()
            ->onlyMethods(['get', 'get_info'])->getMock();
        $client->method('get')->willReturn('synthetic private upstream detail');
        $client->method('get_info')->willReturn(['http_code' => 403]);
        try {
            (new calendar_service())->get_event($client, 'syntheticid');
            $this->fail('HTTP error accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('calendarfailed', $e->errorcode);
            $this->assertStringNotContainsString('synthetic private', $e->getMessage());
            $this->assertEmpty($e->debuginfo);
        }
    }

    /**
     * PATCH uses the native authenticated client, JSON, bounded timeouts and conferenceDataVersion.
     */
    public function test_native_patch_options_and_preserved_conference(): void {
        $this->resetAfterTest();
        $client = $this->getMockBuilder(\core\oauth2\client::class)->disableOriginalConstructor()
            ->onlyMethods(['post', 'get_info', 'setHeader'])->getMock();
        $client->expects($this->once())->method('setHeader')->with('Content-Type: application/json');
        $client->expects($this->once())->method('post')->willReturnCallback(function ($url, $json, $options) {
            $this->assertSame(
                'https://www.googleapis.com/calendar/v3/calendars/primary/events/syntheticid?conferenceDataVersion=1',
                $url
            );
            $this->assertSame('PATCH', $options['CURLOPT_CUSTOMREQUEST']);
            $this->assertSame(15, $options['CURLOPT_TIMEOUT']);
            $this->assertSame(5, $options['CURLOPT_CONNECTTIMEOUT']);
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame([], $payload['recurrence']);
            $this->assertArrayNotHasKey('conferenceData', $payload);
            $this->assertArrayNotHasKey('attendees', $payload);
            return '{"id":"syntheticid"}';
        });
        $client->method('get_info')->willReturn(['http_code' => 200]);
        $meeting = (object) [
            'name' => 'Edited', 'startdatetime' => 1789398000, 'enddatetime' => 1789401600,
            'timezone' => 'America/Cancun', 'isrecurring' => 0, 'calendareventid' => 'syntheticid',
        ];
        $existing = ['conferenceData' => ['conferenceId' => 'abc-defg-hij']];
        $result = (new calendar_service())->update_event($client, $meeting, $existing);
        $this->assertSame('syntheticid', $result['id']);
    }

    /**
     * An HTTP-layer exception is sanitized before any caller can log it.
     */
    public function test_transport_exception_is_sanitized(): void {
        $client = $this->getMockBuilder(\core\oauth2\client::class)->disableOriginalConstructor()
            ->onlyMethods(['get'])->getMock();
        $client->method('get')->willThrowException(new \RuntimeException('synthetic confidential transport context'));
        $this->expectExceptionMessage(get_string('calendarfailed', 'mod_tupmeet'));
        (new calendar_service())->get_event($client, 'syntheticid');
    }
}
