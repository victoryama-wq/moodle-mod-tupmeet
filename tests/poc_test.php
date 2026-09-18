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
use mod_tupmeet\local\account\oauth_client_factory;
use mod_tupmeet\local\google\meet_service;
use mod_tupmeet\local\poc\experiment;
use mod_tupmeet\local\poc\failure;
use mod_tupmeet\local\poc\service;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/fixtures/issuer.php');

/**
 * Real PoC authorization, cache and operations; only native OAuth identity/HTTP are mocked.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(experiment::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(failure::class)]
final class poc_test extends \advanced_testcase {
    /** @var experiment Real controller. */
    private experiment $poc;
    /** @var \stdClass Eligible course. */
    private \stdClass $course;
    /** @var \stdClass Eligible teacher. */
    private \stdClass $teacher;
    /** @var int Default owner ID. */
    private int $owner;
    /** @var array Captured HTTP requests. */
    private array $calls = [];
    /** @var array Remote Space fixture. */
    private array $space = ['name' => 'spaces/Poc_1', 'meetingUri' => 'https://meet.google.com/abc-defg-hij',
        'meetingCode' => 'abc-defg-hij', 'config' => ['accessType' => 'TRUSTED'], 'private' => 'synthetic-private-body'];
    /** @var array Remote member fixture. */
    private array $members = [];
    /** @var array Calendar events in the synthetic provider. */
    private array $events = [];
    /** @var mixed Native HTTP response status. */
    private mixed $status = 200;
    /** @var string Exact stage to fail. */
    private string $failstage = '';
    /** @var mixed Failure status, including hostile transport metadata. */
    private mixed $failstatus = 403;
    /** @var bool Simulate timeout after a remote resource was created. */
    private bool $timeout = false;
    /** @var bool Calendar drops attempted native data. */
    private bool $dropconference = false;
    /** @var bool Calendar returns a second, different video URI. */
    private bool $othermeet = false;
    /** @var bool Simulate an unexpected conference on a fallback event. */
    private bool $fallbackconference = false;

    /**
     * Prepare local data and replace only network acquisition/transport.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        // Capture Moodle enrolment welcome messages; synthetic identities must never trigger email delivery.
        $this->redirectMessages();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_user(['email' => 'teacher@example.invalid']);
        $this->getDataGenerator()->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $issuer = testing\issuer::create();
        $this->owner = $DB->insert_record('tupmeet_accounts', (object) [
            'displayname' => 'Synthetic default', 'issuerid' => $issuer->get('id'),
            'googleemail' => 'owner@example.invalid', 'googlesub' => 'synthetic-owner',
            'enabled' => 1, 'isdefault' => 1, 'connectionstatus' => 'verified',
        ]);
        $client = $this->getMockBuilder(client::class)->disableOriginalConstructor()
            ->onlyMethods(['get', 'post', 'get_info', 'setHeader', 'get_raw_userinfo'])->getMock();
        $client->method('get_raw_userinfo')->willReturn((object) [
            'sub' => 'synthetic-owner', 'email' => 'owner@example.invalid', 'email_verified' => true, 'hd' => 'example.invalid',
        ]);
        $client->method('get_info')->willReturnCallback(fn() => ['http_code' => $this->status]);
        $client->method('get')->willReturnCallback(fn($url, $params, $options) => $this->http('GET', $url, null, $options));
        $client->method('post')->willReturnCallback(fn($url, $body, $options) =>
            $this->http($options['CURLOPT_CUSTOMREQUEST'], $url, $body, $options));
        $oauth = $this->getMockBuilder(oauth_client_factory::class)->onlyMethods(['get_system_client'])->getMock();
        $oauth->method('get_system_client')->willReturn($client);
        $this->poc = new experiment(new service($oauth));
    }

    /**
     * Start one local experiment.
     */
    private function start(): void {
        $this->poc->dispatch('start', [
            'courseid' => $this->course->id, 'userid' => $this->teacher->id, 'confirmed' => 1,
            'startdatetime' => time() + HOURSECS, 'enddatetime' => time() + 2 * HOURSECS,
            'email' => 'ignored@example.invalid', 'space' => ['name' => 'spaces/Injected'],
        ], 'POST', sesskey());
    }

    /**
     * Submit an explicit, protected operation against the current run.
     *
     * @param string $action Step
     * @param array $extra Additional form data
     */
    private function step(string $action, array $extra = []): void {
        $this->poc->dispatch($action, ['runid' => $this->poc->state()['runid']] + $extra, 'POST', sesskey());
    }

    /**
     * Complete the ordered Meet-only experiment.
     */
    private function meet_ready(): void {
        $this->start();
        foreach (['create', 'member', 'verify', 'artifacts'] as $action) {
            $this->step($action);
            $this->assertSame('PASS', $this->poc->state()['stages'][$action]['status']);
        }
    }

    /**
     * Simulated provider. Every URL, verb and payload is checked at the real native client boundary.
     *
     * @param string $method Verb
     * @param string $url Target
     * @param string|null $body Serialized JSON
     * @param array $options Native curl options
     * @return string Remote JSON
     */
    private function http(string $method, string $url, ?string $body, array $options): string {
        global $DB;
        $this->assertFalse($DB->is_transaction_started());
        $this->assertContains($method, ['GET', 'POST', 'PATCH'], 'Never DELETE');
        $this->assertFalse($options['CURLOPT_FOLLOWLOCATION']);
        $this->assertSame(15, $options['CURLOPT_TIMEOUT']);
        $this->assertSame(5, $options['CURLOPT_CONNECTTIMEOUT']);
        $payload = $body === null ? null : json_decode($body, true);
        $this->calls[] = [$method, $url, $payload];
        $calendar = str_starts_with($url, 'https://www.googleapis.com/calendar/v3/calendars/primary/events');
        if ($calendar) {
            $this->assertNotSame('PATCH', $method);
            $this->assertStringNotContainsString('createRequest', $body ?? '');
            $this->assertStringNotContainsString('signature', $body ?? '');
            if ($method === 'POST') {
                $key = isset($payload['conferenceData']) ? 'native' : 'fallback';
                $this->assertStringContainsString('conferenceDataVersion=1', $url);
                $this->assertStringContainsString('sendUpdates=' . ($key === 'native' ? 'none' : 'all'), $url);
                $this->assertSame('FAIL', $this->poc->state()['stages'][$key]['status'], 'Checkpoint before HTTP');
                if ($this->failstage === $key) {
                    $this->status = $this->failstatus;
                    return '{"error":"synthetic-private-body"}';
                }
                $event = $payload;
                if ($this->dropconference) {
                    unset($event['conferenceData']);
                }
                if ($this->othermeet) {
                    $event['conferenceData']['entryPoints'][] = ['entryPointType' => 'video',
                        'uri' => 'https://meet.google.com/xyz-abcd-efg'];
                }
                if ($key === 'fallback' && $this->fallbackconference) {
                    $event['hangoutLink'] = 'https://meet.google.com/xyz-abcd-efg';
                }
                $this->events[$payload['id']] = $event;
                if ($this->timeout) {
                    throw new \RuntimeException('synthetic-private-body');
                }
            } else {
                $event = $this->events[basename($url)];
                if ($this->failstage === 'eventget') {
                    $this->status = $this->failstatus;
                    return '{"error":"synthetic-private-body"}';
                }
            }
            $this->status = 200;
            return json_encode($event + ['private' => 'synthetic-private-body']);
        }
        $this->assertStringStartsWith('https://meet.googleapis.com/v2/spaces', $url);
        $stage = str_contains($url, '/members') ? ($method === 'POST' ? 'member' : 'verify') :
            ($method === 'POST' ? 'create' : 'artifacts');
        if ($this->failstage === $stage) {
            $this->status = $this->failstatus;
            return '{"error":"synthetic-private-body"}';
        }
        $this->status = 200;
        if ($stage === 'create') {
            $this->assertSame('https://meet.googleapis.com/v2/spaces', $url);
            $this->assertSame('{}', $body);
            $this->assertSame('FAIL', $this->poc->state()['stages']['create']['status']);
            if ($this->timeout) {
                throw new \RuntimeException('synthetic-private-body');
            }
            return json_encode($this->space);
        }
        $this->assertStringContainsString('/spaces/Poc_1', $url);
        if ($stage === 'member') {
            $this->assertSame(['email' => $this->teacher->email, 'role' => 'COHOST'], $payload);
            $this->members = [['name' => 'spaces/Poc_1/members/Teacher_1'] + $payload];
            if ($this->timeout) {
                throw new \RuntimeException('synthetic-private-body');
            }
            return json_encode($this->members[0]);
        }
        if ($stage === 'verify') {
            return json_encode(['members' => $this->members]);
        }
        if ($method === 'PATCH') {
            $this->assertSame(
                'https://meet.googleapis.com/v2/spaces/Poc_1?updateMask=' . rawurlencode(meet_service::UPDATE_MASK),
                $url
            );
            $this->assertSame(['name' => 'spaces/Poc_1', 'config' => ['artifactConfig' => [
                'recordingConfig' => ['autoRecordingGeneration' => 'ON'],
                'transcriptionConfig' => ['autoTranscriptionGeneration' => 'OFF'],
            ]]], $payload);
            $this->space['config']['artifactConfig'] = $payload['config']['artifactConfig'];
        }
        return json_encode($this->space);
    }

    /**
     * A1/A2/A3/A4 and B1 use exactly one Space and leave every normal meeting/account field unchanged.
     */
    public function test_native_experiment_and_normal_isolation(): void {
        global $DB;
        $id = $DB->insert_record('tupmeet', (object) ['name' => 'Existing normal activity', 'accountid' => $this->owner,
            'meeturi' => 'https://meet.google.com/old-abcd-xyz', 'meetspacename' => 'spaces/Normal_1',
            'syncstatus' => 'ready', 'meetconfigstatus' => 'ready', 'cohoststatus' => 'ready', 'cohostlocked' => 1]);
        $meeting = $DB->get_record('tupmeet', ['id' => $id]);
        $accounts = $DB->get_records('tupmeet_accounts');
        $this->meet_ready();
        $this->assertCount(0, $this->events, 'Meet-first stages never call Calendar');
        $this->step('native');
        $state = $this->poc->state();
        $this->assertSame('PASS', $state['stages']['native']['status']);
        $this->assertSame('spaces/Poc_1', $state['space']['name']);
        $this->assertArrayNotHasKey('private', $state['space']);
        $this->assertStringNotContainsString('synthetic-private-body', json_encode($state));
        $this->assertStringNotContainsString($this->teacher->email, json_encode($state));
        $this->assertSame('TRUSTED', $state['space']['config']['accessType']);
        $this->assertSame($state['eventids']['native'], $state['events']['native']);
        $payload = service::calendar_payload($state, $this->teacher->email, true);
        $this->assertSame(['conferenceId', 'conferenceSolution', 'entryPoints'], array_keys($payload['conferenceData']));
        $this->assertSame('abc-defg-hij', $payload['conferenceData']['conferenceId']);
        $this->assertSame($state['space']['meetingUri'], $payload['conferenceData']['entryPoints'][0]['uri']);
        $this->assertCount(1, array_filter($this->calls, fn($call) => $call[0] === 'POST' &&
            $call[1] === 'https://meet.googleapis.com/v2/spaces'));
        $this->assertEquals($meeting, $DB->get_record('tupmeet', ['id' => $id]));
        $this->assertEquals($accounts, $DB->get_records('tupmeet_accounts'));
        $this->assertEquals(1, $DB->count_records('tupmeet'));
        $this->assertEquals(0, $DB->count_records('task_adhoc', ['component' => 'mod_tupmeet']));
        $this->assertFalse($this->poc->available($state, 'fallback'));
    }

    /**
     * B1 rejection is isolated; B2 is a separate explicit invite with only the original link in location.
     */
    public function test_native_rejection_and_explicit_fallback(): void {
        $this->meet_ready();
        $this->failstage = 'native';
        $this->step('native');
        $state = $this->poc->state();
        $this->assertSame(['status' => 'FAIL', 'httpstatus' => 403], $state['stages']['native']);
        $this->assertSame('PASS', $state['stages']['artifacts']['status']);
        $this->assertSame('PASS', $state['stages']['verify']['status']);
        $this->assertSame('NOT_RUN', $state['stages']['fallback']['status']);
        $this->assertCount(0, $this->events);
        $this->step('fallback', ['confirmed' => 1]);
        $state = $this->poc->state();
        $this->assertSame('PASS', $state['stages']['fallback']['status']);
        $event = $this->events[$state['eventids']['fallback']];
        $this->assertArrayNotHasKey('conferenceData', $event);
        $this->assertSame([['email' => $this->teacher->email]], $event['attendees']);
        $this->assertSame($state['space']['meetingUri'], $event['location']);
        $this->assertStringNotContainsString('synthetic-private-body', json_encode($state));
    }

    /**
     * A successful HTTP insert alone is insufficient: dropped data or a second Meet must fail B1.
     *
     * @param bool $drop Whether Calendar removes conference data instead of adding a second video link
     * @dataProvider boolean_cases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('boolean_cases')]
    public function test_native_readback_rejects_missing_or_different_meet(bool $drop): void {
        $this->meet_ready();
        $this->dropconference = $drop;
        $this->othermeet = !$drop;
        $this->step('native');
        $state = $this->poc->state();
        $this->assertSame('FAIL', $state['stages']['native']['status']);
        $this->assertSame($state['eventids']['native'], $state['events']['native']);
        $this->assertSame('spaces/Poc_1', $state['space']['name']);
    }

    /**
     * Boolean test scenarios.
     *
     * @return array Cases
     */
    public static function boolean_cases(): array {
        return [[true], [false]];
    }

    /**
     * A provider-generated conference on fallback is not silently counted as success.
     */
    public function test_fallback_unexpected_conference_fails(): void {
        $this->meet_ready();
        $this->failstage = 'native';
        $this->step('native');
        $this->fallbackconference = true;
        $this->step('fallback', ['confirmed' => 1]);
        $this->assertSame('FAIL', $this->poc->state()['stages']['fallback']['status']);
    }

    /**
     * Safe Space validation rejects aliases, injected URLs and inconsistent response identities.
     *
     * @param string $field Response field
     * @param mixed $value Malformed value
     * @dataProvider invalid_spaces
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_spaces')]
    public function test_invalid_space(string $field, mixed $value): void {
        $this->space[$field] = $value;
        $this->start();
        $this->step('create');
        $state = $this->poc->state();
        $this->assertSame('FAIL', $state['stages']['create']['status']);
        $this->assertArrayNotHasKey('space', $state);
        $this->assertCount(1, $this->calls);
    }

    /**
     * Canonical resource and URI attacks.
     *
     * @return array Cases
     */
    public static function invalid_spaces(): array {
        return [['name', 'spaces/abc-defg-hij'], ['name', 'spaces/../Other'], ['name', 'spaces/id/members/x'],
            ['name', null], ['meetingUri', 'https://evil.invalid/abc-defg-hij'],
            ['meetingUri', 'https://meet.google.com/abc-defg-hij?token=synthetic'], ['meetingCode', 'xyz-abcd-efg']];
    }

    /**
     * Optional meetingCode can be omitted without using it as permanent identity.
     */
    public function test_optional_meeting_code(): void {
        unset($this->space['meetingCode']);
        $this->meet_ready();
        $this->step('native');
        $this->assertSame('PASS', $this->poc->state()['stages']['native']['status']);
        $this->assertArrayNotHasKey('meetingCode', $this->poc->state()['space']);
    }

    /**
     * No implicit retries after any resource-creation timeout; POST ambiguity stays visible.
     */
    public function test_timeout_cannot_repeat_space_creation(): void {
        $this->start();
        $this->timeout = true;
        $this->step('create');
        $state = $this->poc->state();
        $this->assertSame(['status' => 'FAIL', 'httpstatus' => 0], $state['stages']['create']);
        $this->assertFalse($this->poc->available($state, 'create'));
        $this->assertCount(1, $this->calls);
        try {
            $this->step('create');
            $this->fail('Expected replay guard');
        } catch (\moodle_exception $e) {
            $this->assertSame('pocnorepeat', $e->errorcode);
        }
        $this->assertCount(1, $this->calls);
    }

    /**
     * A lost member creation response can be checked by an explicit read without another member POST.
     */
    public function test_member_timeout_then_verify(): void {
        $this->start();
        $this->step('create');
        $this->timeout = true;
        $this->step('member');
        $this->assertSame('FAIL', $this->poc->state()['stages']['member']['status']);
        $this->timeout = false;
        $this->step('verify');
        $this->assertSame('PASS', $this->poc->state()['stages']['verify']['status']);
        $this->assertCount(1, array_filter($this->calls, fn($call) => $call[0] === 'POST' && str_contains($call[1], '/members')));
    }

    /**
     * Verification must match both role and the selected teacher, not merely a successful list response.
     */
    public function test_verification_requires_cohost(): void {
        $this->start();
        $this->step('create');
        $this->step('member');
        $this->members[0]['role'] = 'ROLE_UNSPECIFIED';
        $this->step('verify');
        $this->assertSame('FAIL', $this->poc->state()['stages']['verify']['status']);
        $this->assertFalse($this->poc->available($this->poc->state(), 'artifacts'));
        $this->members[0]['role'] = 'COHOST';
        $this->members[0]['email'] = 'other@example.invalid';
        $this->step('verify');
        $this->assertSame('FAIL', $this->poc->state()['stages']['verify']['status']);
    }

    /**
     * Native HTTP status is normalized; provider messages never reach cache or exception text.
     */
    public function test_safe_failures(): void {
        $this->start();
        $this->failstage = 'create';
        $this->failstatus = '403 synthetic-private-body';
        $this->step('create');
        $state = $this->poc->state();
        $this->assertSame(['status' => 'FAIL', 'httpstatus' => 0], $state['stages']['create']);
        $this->assertStringNotContainsString('synthetic-private-body', json_encode($state));
        $error = new failure('403 synthetic-private-body');
        $this->assertSame(0, $error->httpstatus);
        $this->assertNull($error->getPrevious());
        $this->assertNull($error->debuginfo);
        $this->assertStringNotContainsString('synthetic-private-body', $error->getMessage());
    }

    /**
     * GET and missing/incorrect CSRF tokens cause no state changes or HTTP, even as site admin.
     */
    public function test_post_and_sesskey_required(): void {
        $this->start();
        $before = $this->poc->state();
        foreach ([['GET', sesskey()], ['POST', ''], ['POST', 'wrong']] as [$method, $key]) {
            try {
                $this->poc->dispatch('create', ['runid' => $before['runid']], $method, $key);
                $this->fail('Expected request protection');
            } catch (\moodle_exception $e) {
                $this->assertSame('invalidrequest', $e->errorcode);
            }
            $this->assertSame($before, $this->poc->state());
        }
        $this->assertSame([], $this->calls);
    }

    /**
     * Teachers, students, guests and unauthenticated users cannot load state or run actions.
     */
    public function test_site_admin_only(): void {
        $this->start();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        foreach ([$this->teacher, $student, guest_user(), null] as $user) {
            $this->setUser($user);
            foreach (['state', 'dispatch'] as $operation) {
                try {
                    if ($operation === 'state') {
                        $this->poc->state();
                    } else {
                        $this->poc->dispatch('create', [], 'POST', 'wrong');
                    }
                    $this->fail('Expected administrator boundary');
                } catch (\moodle_exception $e) {
                    $this->assertSame('pocadminonly', $e->errorcode);
                }
            }
        }
        $this->assertSame([], $this->calls);
    }

    /**
     * Stale form/run IDs and expired or cleared cache cannot operate on supplied Google IDs.
     */
    public function test_stale_run_and_manual_clear(): void {
        $this->start();
        $this->step('create');
        $state = $this->poc->state();
        $calls = $this->calls;
        try {
            $this->poc->dispatch('member', ['runid' => 'wrong', 'space' => $state['space']], 'POST', sesskey());
            $this->fail('Expected stale form rejection');
        } catch (failure $e) {
            $this->assertSame(0, $e->httpstatus);
        }
        $this->step('clear', ['confirmed' => 1]);
        $this->assertNull($this->poc->state());
        $this->assertSame($calls, $this->calls, 'Local clearing must not delete Google resources');
        $this->expectException(failure::class);
        $this->poc->dispatch('member', ['runid' => $state['runid']], 'POST', sesskey());
    }

    /**
     * Changing the default or teacher email during the run blocks subsequent HTTP.
     */
    public function test_identity_and_owner_cannot_change(): void {
        global $DB;
        $this->start();
        $this->step('create');
        $DB->set_field('user', 'email', 'changed@example.invalid', ['id' => $this->teacher->id]);
        $calls = $this->calls;
        $this->step('member');
        $this->assertSame($calls, $this->calls);
        $this->assertSame('FAIL', $this->poc->state()['stages']['member']['status']);
        $DB->set_field('user', 'email', $this->teacher->email, ['id' => $this->teacher->id]);
        $DB->set_field('tupmeet_accounts', 'isdefault', 0, ['id' => $this->owner]);
        $this->step('verify');
        $this->assertSame($calls, $this->calls);
        $this->assertSame('FAIL', $this->poc->state()['stages']['verify']['status']);
    }

    /**
     * A lost Calendar response is not retried and retains the preselected event ID for manual inspection.
     */
    public function test_calendar_timeout_does_not_repeat_insert(): void {
        $this->meet_ready();
        $this->timeout = true;
        $this->step('native');
        $state = $this->poc->state();
        $this->assertSame(['status' => 'FAIL', 'httpstatus' => 0], $state['stages']['native']);
        $this->assertArrayHasKey($state['eventids']['native'], $this->events);
        $this->assertCount(1, $this->events);
        $this->expectExceptionMessage(get_string('pocnorepeat', 'tupmeet'));
        $this->step('native');
    }

    /**
     * Readback failures preserve confirmed insert IDs, and fallback cannot send mail without consent.
     */
    public function test_calendar_readback_failure_and_fallback_ack(): void {
        $this->meet_ready();
        $this->failstage = 'eventget';
        $this->step('native');
        $state = $this->poc->state();
        $this->assertSame(['status' => 'FAIL', 'httpstatus' => 403], $state['stages']['native']);
        $this->assertSame($state['eventids']['native'], $state['events']['native']);
        $calls = $this->calls;
        try {
            $this->step('fallback');
            $this->fail('Expected explicit invite acknowledgment');
        } catch (failure $e) {
            $this->assertSame(0, $e->httpstatus);
        }
        $this->assertSame($calls, $this->calls);
        $this->assertSame('NOT_RUN', $this->poc->state()['stages']['fallback']['status']);
    }

    /**
     * Starting an experiment needs an enabled verified default, without changing account records.
     */
    public function test_unverified_owner_cannot_start(): void {
        global $DB;
        $DB->set_field('tupmeet_accounts', 'connectionstatus', 'disconnected', ['id' => $this->owner]);
        try {
            $this->start();
            $this->fail('Expected verified owner');
        } catch (failure $e) {
            $this->assertNull($this->poc->state());
        }
        $this->assertSame([], $this->calls);
    }

    /**
     * A Moodle student ID cannot be substituted for the eligible teacher.
     */
    public function test_ineligible_teacher_cannot_start(): void {
        $this->teacher = $this->getDataGenerator()->create_user(['email' => 'student@example.invalid']);
        $this->getDataGenerator()->enrol_user($this->teacher->id, $this->course->id, 'student');
        try {
            $this->start();
            $this->fail('Expected teacher eligibility validation');
        } catch (\moodle_exception $e) {
            $this->assertNull($this->poc->state());
        }
        $this->assertSame([], $this->calls);
    }

    /**
     * Ordering cannot be bypassed, and successful creation cannot be replayed.
     */
    public function test_step_order_and_successful_replay(): void {
        $this->start();
        foreach (['member', 'verify', 'artifacts', 'native', 'fallback'] as $action) {
            try {
                $this->step($action, ['confirmed' => 1]);
                $this->fail('Expected ordered steps');
            } catch (failure $e) {
                $this->assertSame('NOT_RUN', $this->poc->state()['stages'][$action]['status']);
            }
        }
        $this->assertSame([], $this->calls);
        $this->step('create');
        $this->expectExceptionMessage(get_string('pocnorepeat', 'tupmeet'));
        $this->step('create');
    }

    /**
     * Expiry disables remote operations and a different site administrator cannot reuse this run.
     */
    public function test_expiry_and_administrator_isolation(): void {
        global $USER;
        $this->start();
        $state = $this->poc->state();
        $adminid = $USER->id;
        $other = $this->getDataGenerator()->create_user();
        set_config('siteadmins', $adminid . ',' . $other->id);
        $this->setUser($other);
        $this->assertNull($this->poc->state());
        try {
            $this->poc->dispatch('create', ['runid' => $state['runid']], 'POST', sesskey());
            $this->fail('Expected separate administrator cache');
        } catch (failure $e) {
            $this->assertSame([], $this->calls);
        }
        $this->setUser($adminid);
        $state['expires'] = time() - 1;
        \core_cache\cache::make('mod_tupmeet', 'meet_first_poc')->set((string) $adminid, $state);
        $this->assertFalse($this->poc->available($state, 'create'));
        $this->expectException(failure::class);
        $this->step('create');
    }
}
