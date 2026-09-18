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

use core\oauth2\client;
use mod_tupmeet\local\account\account_manager;
use mod_tupmeet\local\account\oauth_client_factory;
use mod_tupmeet\local\google\calendar_service;
use mod_tupmeet\local\meeting\meeting_manager;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/fixtures/issuer.php');
require_once(__DIR__ . '/../lib.php');

/**
 * Persisted ownership, transactions and idempotency with only OAuth/API boundaries simulated.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(meeting_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(calendar_service::class)]
final class meeting_manager_test extends \advanced_testcase {
    /** @var account_manager Real account state machine. */
    private account_manager $accounts;
    /** @var meeting_manager Real coordinator. */
    private meeting_manager $meetings;
    /** @var array Fake remote calendars, indexed by issuer and event ID. */
    private array $remote = [];
    /** @var array Observed API calls. */
    private array $calls = [];
    /** @var string Fault injected at the API boundary. */
    private string $fault = '';
    /** @var int Number of remote event creations. */
    private int $creates = 0;
    /** @var int Institutional owner. */
    private int $owner;
    /** @var \Closure|null Simulate an edit or DB fault while HTTP is in flight. */
    private ?\Closure $duringhttp = null;

    /**
     * Mock native client acquisition, userinfo and Calendar HTTP only.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $this->remote = [];
        $this->calls = [];
        $this->fault = '';
        $this->creates = 0;
        $this->duringhttp = null;
        $oauth = $this->getMockBuilder(oauth_client_factory::class)->onlyMethods(['get_system_client'])->getMock();
        $oauth->method('get_system_client')->willReturnCallback(function (\core\oauth2\issuer $issuer) {
            $client = $this->getMockBuilder(client::class)->disableOriginalConstructor()
                ->onlyMethods(['get_raw_userinfo', 'get_issuer'])->getMock();
            $client->method('get_issuer')->willReturn($issuer);
            $client->method('get_raw_userinfo')->willReturn((object) [
                'sub' => 'subject-' . $issuer->get('id'), 'email' => 'owner' . $issuer->get('id') . '@example.invalid',
                'email_verified' => true, 'hd' => 'example.invalid',
            ]);
            return $client;
        });
        $this->accounts = new account_manager($oauth);
        $calendar = $this->getMockBuilder(calendar_service::class)->setConstructorArgs([$oauth])
            ->onlyMethods(['request'])->getMock();
        $calendar->method('request')->willReturnCallback(function (
            client $client,
            string $method,
            string $url,
            ?array $payload = null
        ) {
            global $DB;
            $this->assertFalse($DB->is_transaction_started(), 'No HTTP before local commit');
            $issuer = (int) $client->get_issuer()->get('id');
            $this->calls[] = [$issuer, $method, $url, $payload];
            if ($this->fault === 'error') {
                return [403, null];
            }
            $id = $method === 'POST' ? $payload['id'] : basename(parse_url($url, PHP_URL_PATH));
            if ($method === 'GET') {
                return isset($this->remote[$issuer][$id]) ? [200, $this->remote[$issuer][$id]] : [404, null];
            }
            if ($method === 'POST' && isset($this->remote[$issuer][$id])) {
                return [409, null];
            }
            if ($method === 'POST') {
                $this->creates++;
            }
            $event = array_replace($this->remote[$issuer][$id] ?? [], $payload, ['id' => $id]);
            if ($this->fault === 'noconference') {
                unset($event['conferenceData']);
            } else if ($this->fault === 'pending') {
                $event['conferenceData'] = ['createRequest' => ['status' => ['statusCode' => 'pending']]];
            } else {
                $event['conferenceData'] = [
                    'conferenceId' => 'abc-defg-hij',
                    'entryPoints' => [['entryPointType' => 'video', 'uri' => 'https://meet.google.com/abc-defg-hij']],
                ];
            }
            $this->remote[$issuer][$id] = $event;
            if ($this->duringhttp) {
                $callback = $this->duringhttp;
                $this->duringhttp = null;
                $callback();
            }
            if ($this->fault === 'timeout') {
                return [0, null];
            }
            if ($this->fault === 'conflict' && $method === 'POST') {
                return [409, null];
            }
            return [200, $event];
        });
        $this->meetings = new meeting_manager($calendar);
        $this->owner = $this->account();
        $this->accounts->set_default($this->owner);
    }

    /**
     * Register and verify via the real Phase 1 state machine.
     *
     * @return int Account ID
     */
    private function account(): int {
        $issuer = \mod_tupmeet\testing\issuer::create();
        $id = $this->accounts->register('Institutional fixture', (int) $issuer->get('id'));
        $this->accounts->verify($id);
        return $id;
    }

    /**
     * Minimal valid submission with a caller-retained idempotency key.
     *
     * @return \stdClass Data
     */
    private function data(): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        return (object) [
            'course' => $course->id, 'cohostuserid' => $teacher->id,
            'name' => 'Synthetic meeting', 'startdatetime' => 1789398000, 'enddatetime' => 1789401600,
            'intro' => '<p>Synthetic agenda</p>', 'creationkey' => meeting_manager::new_key(),
        ];
    }

    /**
     * A committed activity gets a Calendar event and validated Meet link automatically on sync.
     */
    public function test_create_and_stable_identifiers(): void {
        global $DB;
        $id = $this->meetings->create($this->data());
        $this->assertSame([], $this->calls);
        $this->assertEquals(1, $DB->count_records('task_adhoc', ['component' => 'mod_tupmeet']));
        $before = $DB->get_record('tupmeet', ['id' => $id]);
        $this->assertSame('pending', $before->syncstatus);
        $this->assertTrue($this->meetings->synchronize($id));
        $after = $DB->get_record('tupmeet', ['id' => $id]);
        $this->assertEquals($this->owner, $after->accountid);
        $this->assertSame($before->calendareventid, $after->calendareventid);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $after->meeturi);
        $this->assertSame('abc-defg-hij', $after->meetingcode);
        $this->assertNull($after->meetspacename);
        $this->assertStringContainsString('conferenceDataVersion=1', $this->calls[1][2]);
        $this->assertSame('hangoutsMeet', $this->calls[1][3]['conferenceData']['createRequest']['conferenceSolutionKey']['type']);
        $this->assertTrue($this->meetings->synchronize($id));
        $this->assertSame(1, $this->creates);
    }

    /**
     * A repeated form key cannot create a second Moodle instance or remote event.
     */
    public function test_duplicate_submission(): void {
        global $DB;
        $data = $this->data();
        $id = $this->meetings->create($data);
        $this->meetings->synchronize($id);
        try {
            $this->meetings->create($data);
            $this->fail('Duplicate accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('duplicatesubmission', $e->errorcode);
        }
        $this->assertEquals(1, $DB->count_records('tupmeet'));
        $this->assertSame(1, $this->creates);
    }

    /**
     * Account switches and disabling old owners cannot redirect updates.
     */
    public function test_edit_uses_historical_account_and_event(): void {
        global $DB;
        $id = $this->meetings->create($this->data());
        $this->meetings->synchronize($id);
        $before = $DB->get_record('tupmeet', ['id' => $id]);
        $second = $this->account();
        $this->accounts->set_default($second);
        $this->accounts->set_enabled($this->owner, false);
        $this->meetings->update((object) [
            'id' => $id, 'name' => 'Edited title', 'intro' => 'Edited description',
            'accountid' => $second, 'calendareventid' => 'foreign', 'timezone' => 'Asia/Tokyo',
        ]);
        $this->assertTrue($this->meetings->synchronize($id));
        $after = $DB->get_record('tupmeet', ['id' => $id]);
        $this->assertSame($before->accountid, $after->accountid);
        $this->assertSame($before->calendareventid, $after->calendareventid);
        $this->assertSame($before->timezone, $after->timezone);
        $call = end($this->calls);
        $this->assertSame((int) $this->accounts->get_account($this->owner)->issuerid, $call[0]);
        $this->assertSame('PATCH', $call[1]);
        $this->assertSame('Edited title', $call[3]['summary']);
        $this->assertArrayNotHasKey('conferenceData', $call[3]);
        $next = $this->meetings->create($this->data());
        $this->assertEquals($second, $DB->get_field('tupmeet', 'accountid', ['id' => $next]));
        $this->assertSame(1, $this->creates);
    }

    /**
     * Provider errors remain visible and a retry succeeds without reassigning or duplicating.
     */
    public function test_calendar_error_and_retry(): void {
        global $DB;
        $id = $this->meetings->create($this->data());
        $this->fault = 'error';
        $this->assertFalse($this->meetings->synchronize($id));
        $this->assertSame('error', $DB->get_field('tupmeet', 'syncstatus', ['id' => $id]));
        $this->assertEmpty($DB->get_field('tupmeet', 'meeturi', ['id' => $id]));
        $this->fault = '';
        $this->assertTrue($this->meetings->synchronize($id));
        $this->assertSame(1, $this->creates);
    }

    /**
     * Timeout after Google inserted an event is recovered by GET of the same precommitted ID.
     */
    public function test_timeout_after_remote_creation(): void {
        $id = $this->meetings->create($this->data());
        $this->fault = 'timeout';
        $this->assertFalse($this->meetings->synchronize($id));
        $this->assertSame(1, $this->creates);
        $this->fault = '';
        $this->assertTrue($this->meetings->synchronize($id));
        $this->assertSame(1, $this->creates);
    }

    /**
     * Missing or still-pending conference data never marks a meeting functional.
     */
    public function test_missing_and_pending_conference(): void {
        global $DB;
        foreach (['noconference', 'pending'] as $fault) {
            $id = $this->meetings->create($this->data());
            $this->fault = $fault;
            $this->assertFalse($this->meetings->synchronize($id));
            $this->assertSame('pending', $DB->get_field('tupmeet', 'syncstatus', ['id' => $id]));
            $this->assertEmpty($DB->get_field('tupmeet', 'meeturi', ['id' => $id]));
            $this->fault = '';
            $this->assertTrue($this->meetings->synchronize($id));
        }
        $this->assertSame(2, $this->creates);
    }

    /**
     * Rolled-back local saves cannot make remote calls or leave retry jobs behind.
     */
    public function test_local_rollback_before_google(): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $this->meetings->create($this->data());
        try {
            $transaction->rollback(new \moodle_exception('invalidrequest', 'mod_tupmeet'));
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidrequest', $e->errorcode);
        }
        $this->assertEquals(0, $DB->count_records('tupmeet'));
        $this->assertEquals(0, $DB->count_records('task_adhoc', ['component' => 'mod_tupmeet']));
        $this->assertSame([], $this->calls);
    }

    /**
     * HTTP may never run from a module callback within a transaction.
     */
    public function test_sync_rejects_open_transaction(): void {
        global $DB;
        $id = $this->meetings->create($this->data());
        $transaction = $DB->start_delegated_transaction();
        try {
            $this->meetings->synchronize($id);
            $this->fail('HTTP allowed before commit');
        } catch (\coding_exception $e) {
            $this->assertSame([], $this->calls);
        } finally {
            $transaction->allow_commit();
        }
    }

    /**
     * An edit during HTTP remains pending until its own desired version is applied.
     */
    public function test_edit_during_sync_is_not_lost(): void {
        global $DB;
        $id = $this->meetings->create($this->data());
        $this->duringhttp = function () use ($id) {
            $this->meetings->update((object) ['id' => $id, 'name' => 'Newer revision']);
        };
        $this->assertFalse($this->meetings->synchronize($id));
        $this->assertSame('pending', $DB->get_field('tupmeet', 'syncstatus', ['id' => $id]));
        $this->assertTrue($this->meetings->synchronize($id));
        $this->assertSame('Newer revision', end($this->calls)[3]['summary']);
        $this->assertSame(1, $this->creates);
    }

    /**
     * Deleting an activity cancels local work without deleting anything in Google.
     */
    public function test_delete_is_local_only(): void {
        $id = $this->meetings->create($this->data());
        $this->meetings->synchronize($id);
        $calls = count($this->calls);
        $this->assertTrue(tupmeet_delete_instance($id));
        $this->assertTrue($this->meetings->synchronize($id));
        $this->assertCount($calls, $this->calls);
        $this->assertCount(1, $this->remote);
    }

    /**
     * Issuers outside the plugin receive no extra scopes, including through Moodle's dispatcher.
     */
    public function test_scopes_only_for_registered_issuers(): void {
        $registered = new \core\oauth2\issuer($this->accounts->get_account($this->owner)->issuerid);
        $other = \mod_tupmeet\testing\issuer::create();
        $scopes = calendar_service::SCOPE . ' ' . \mod_tupmeet\local\google\meet_service::SCOPE . ' ' .
            \mod_tupmeet\local\google\member_service::SCOPE . ' ' . \mod_tupmeet\local\google\member_service::READONLY_SCOPE;
        $this->assertSame($scopes, tupmeet_oauth2_system_scopes($registered));
        $this->assertSame('', tupmeet_oauth2_system_scopes($other));
        $this->assertStringContainsString(calendar_service::SCOPE, \core\oauth2\api::get_system_scopes_for_issuer($registered));
        $this->assertStringNotContainsString(calendar_service::SCOPE, \core\oauth2\api::get_system_scopes_for_issuer($other));
        $this->accounts->set_enabled($this->owner, false);
        $this->assertSame($scopes, tupmeet_oauth2_system_scopes($registered));
        $this->assertStringContainsString(
            \mod_tupmeet\local\google\meet_service::SCOPE,
            \core\oauth2\api::get_system_scopes_for_issuer($registered)
        );
        $this->assertStringNotContainsString(
            \mod_tupmeet\local\google\meet_service::SCOPE,
            \core\oauth2\api::get_system_scopes_for_issuer($other)
        );
        $registered->set('servicetype', 'facebook');
        $this->assertSame('', tupmeet_oauth2_system_scopes($registered));
    }

    /**
     * Links cannot send users to a foreign site or an injected URL.
     */
    public function test_join_link_validation(): void {
        $this->assertTrue(calendar_service::valid_meet_uri('https://meet.google.com/abc-defg-hij'));
        $invalid = [
            'https://meet.google.com.evil.invalid/abc-defg-hij', 'javascript:alert(1)', 'http://meet.google.com/abc-defg-hij',
        ];
        foreach ($invalid as $uri) {
            $this->assertFalse(calendar_service::valid_meet_uri($uri));
        }
    }

    /**
     * Google conflict is recovered by verifying the same event's correlation marker.
     */
    public function test_insert_conflict_recovery(): void {
        $id = $this->meetings->create($this->data());
        $this->fault = 'conflict';
        $this->assertTrue($this->meetings->synchronize($id));
        $this->assertSame(1, $this->creates);
    }

    /**
     * A real database write failure after Google creation leaves the stable identity recoverable.
     */
    public function test_local_result_write_failure_after_google(): void {
        global $DB;
        $id = $this->meetings->create($this->data());
        $table = new \xmldb_table('tupmeet');
        $field = new \xmldb_field('lastsync', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'meeturi');
        $this->duringhttp = function () use ($DB, $table, $field) {
            // Fault injection is a real disposable-schema failure, not a mocked repository.
            $DB->get_manager()->drop_field($table, $field);
        };
        try {
            $this->meetings->synchronize($id);
            $this->fail('Expected database write failure');
        } catch (\dml_write_exception $e) {
            $this->assertSame(1, $this->creates);
        } finally {
            $DB->get_manager()->add_field($table, $field);
        }
        $this->assertSame('pending', $DB->get_field('tupmeet', 'syncstatus', ['id' => $id]));
        $this->assertTrue($this->meetings->synchronize($id));
        $this->assertSame(1, $this->creates);
    }

    /**
     * A foreign event with a colliding ID is neither adopted nor overwritten.
     */
    public function test_foreign_event_is_rejected(): void {
        global $DB;
        $id = $this->meetings->create($this->data());
        $record = $DB->get_record('tupmeet', ['id' => $id]);
        $issuer = (int) $this->accounts->get_account($this->owner)->issuerid;
        $this->remote[$issuer][$record->calendareventid] = ['id' => $record->calendareventid];
        $this->assertFalse($this->meetings->synchronize($id));
        $this->assertSame(0, $this->creates);
        $this->assertCount(1, $this->calls);
    }

    /**
     * Partial Moodle updates preserve recurrence; explicit series edits replace the same event.
     */
    public function test_partial_update_and_series_change(): void {
        global $DB;
        $data = $this->data();
        $zone = \mod_tupmeet\local\meeting\schedule::timezone()->getName();
        $day = strtolower(\mod_tupmeet\local\meeting\schedule::date($data->startdatetime, $zone)->format('D'));
        $data->isrecurring = 1;
        $data->recurrencedays = json_encode([$day]);
        $data->recurrenceinterval = 1;
        $data->recurrenceuntil = $data->startdatetime + 28 * DAYSECS;
        $id = $this->meetings->create($data);
        $this->assertTrue($this->meetings->synchronize($id));
        tupmeet_update_instance((object) ['instance' => $id, 'name' => 'Name-only edit']);
        $this->assertSame($data->recurrencedays, $DB->get_field('tupmeet', 'recurrencedays', ['id' => $id]));
        $this->meetings->update((object) ['id' => $id, 'recurrenceinterval' => 2, 'enddatetime' => $data->enddatetime + HOURSECS]);
        $this->assertTrue($this->meetings->synchronize($id));
        $this->assertStringContainsString('INTERVAL=2', end($this->calls)[3]['recurrence'][0]);
        $this->meetings->update((object) ['id' => $id, 'isrecurring' => 0]);
        $this->assertTrue($this->meetings->synchronize($id));
        $this->assertSame([], end($this->calls)[3]['recurrence']);
        $this->assertSame(1, $this->creates);
    }

    /**
     * Moodle dispatches our real observer only after its outer module transaction commits.
     */
    public function test_native_observer_after_commit(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $transaction = $DB->start_delegated_transaction();
        $activity = $this->getDataGenerator()->create_module('tupmeet', ['course' => $course->id]);
        $this->assertSame('pending', $DB->get_field('tupmeet', 'syncstatus', ['id' => $activity->id]));
        $transaction->allow_commit();
        // The real observer now runs. This fixture has no native system connection, so no HTTP
        // is possible: it must leave an explicit error and the already committed retry task.
        $this->assertSame('error', $DB->get_field('tupmeet', 'syncstatus', ['id' => $activity->id]));
        $this->assertEquals(1, $DB->count_records('task_adhoc', ['component' => 'mod_tupmeet']));
    }
}
