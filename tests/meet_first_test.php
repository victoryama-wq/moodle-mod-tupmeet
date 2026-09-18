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
use mod_tupmeet\local\google\space_service;
use mod_tupmeet\local\google\meet_service;
use mod_tupmeet\local\google\member_service;
use mod_tupmeet\local\meeting\meeting_manager;
use mod_tupmeet\local\meeting\space_manager;
use mod_tupmeet\local\meeting\cohost_manager;
use mod_tupmeet\local\meeting\meet_config_manager;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/fixtures/issuer.php');
require_once(__DIR__ . '/../lib.php');

/**
 * Production Meet-first reconciliation with native OAuth HTTP as the only fake boundary.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(space_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(space_service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(calendar_service::class)]
final class meet_first_test extends \advanced_testcase {
    /**
     * @var account_manager Account manager.
     */
    private account_manager $accounts;
    /**
     * @var space_manager Space coordinator.
     */
    private space_manager $spaces;
    /**
     * @var meeting_manager Calendar coordinator.
     */
    private meeting_manager $calendar;
    /**
     * @var cohost_manager Membership coordinator.
     */
    private cohost_manager $cohosts;
    /**
     * @var meet_config_manager Artifact coordinator.
     */
    private meet_config_manager $artifacts;
    /**
     * @var \stdClass Course.
     */
    private \stdClass $course;
    /**
     * @var \stdClass Teacher.
     */
    private \stdClass $teacher;
    /**
     * @var int Historical owner.
     */
    private int $owner;
    /**
     * @var array HTTP observations.
     */
    private array $calls = [];
    /**
     * @var array Synthetic Space.
     */
    private array $space = ['name' => 'spaces/Permanent_1', 'meetingUri' => 'https://meet.google.com/abc-defg-hij',
        'meetingCode' => 'abc-defg-hij'];
    /**
     * @var array Synthetic Calendar events.
     */
    private array $events = [];
    /**
     * @var array Synthetic members.
     */
    private array $members = [];
    /**
     * @var int HTTP status.
     */
    private int $status = 200;
    /**
     * @var int Transport errno.
     */
    private int $errno = 0;
    /**
     * @var string Fault target.
     */
    private string $fault = '';
    /**
     * @var int Fault status.
     */
    private int $failure = 0;
    /**
     * @var \Closure|null Hook before native client is returned.
     */
    private ?\Closure $beforeclient = null;
    /**
     * @var \Closure|null Hook during HTTP.
     */
    private ?\Closure $duringhttp = null;
    /**
     * @var \Closure|null Tamper only with the confirming Calendar GET.
     */
    private ?\Closure $tamper = null;

    /**
     * Initialize real persistence and simulated native transport.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $this->redirectMessages();
        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_user(['email' => 'teacher@example.invalid']);
        $this->getDataGenerator()->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $oauth = $this->getMockBuilder(oauth_client_factory::class)->onlyMethods(['get_system_client'])->getMock();
        $oauth->method('get_system_client')->willReturnCallback(function (\core\oauth2\issuer $issuer) {
            if ($this->beforeclient) {
                ($this->beforeclient)();
            }
            $client = $this->getMockBuilder(client::class)->disableOriginalConstructor()
                ->onlyMethods(['get', 'post', 'setHeader', 'get_info', 'get_errno', 'get_raw_userinfo'])->getMock();
            $client->method('get_raw_userinfo')->willReturn((object) ['sub' => 'owner-' . $issuer->get('id'),
                'email' => 'owner' . $issuer->get('id') . '@example.invalid', 'email_verified' => true, 'hd' => 'example.invalid']);
            $client->method('get_info')->willReturnCallback(fn() => ['http_code' => $this->status]);
            $client->method('get_errno')->willReturnCallback(fn() => $this->errno);
            $client->method('get')->willReturnCallback(fn($url, $params, $options) =>
                $this->http((int) $issuer->get('id'), 'GET', $url, null, $options));
            $client->method('post')->willReturnCallback(fn($url, $body, $options) =>
                $this->http(
                    (int) $issuer->get('id'),
                    $options['CURLOPT_CUSTOMREQUEST'],
                    $url,
                    json_decode($body, true),
                    $options
                ));
            return $client;
        });
        $this->accounts = new account_manager($oauth);
        $this->owner = $this->account();
        $this->accounts->set_default($this->owner);
        $this->spaces = new space_manager(new space_service($oauth));
        $this->calendar = new meeting_manager(new calendar_service($oauth));
        $this->cohosts = new cohost_manager(new member_service($oauth));
        $this->artifacts = new meet_config_manager(new meet_service($oauth));
    }

    /**
     * Register a verified synthetic owner.
     * @return int Account ID
     */
    private function account(): int {
        $issuer = testing\issuer::create();
        $id = $this->accounts->register('Synthetic owner', (int) $issuer->get('id'));
        $this->accounts->verify($id);
        return $id;
    }

    /**
     * Create real committed desired state; no remote calls.
     * @param bool $recurring Weekly Saturday series
     * @return int Activity ID
     */
    private function create(bool $recurring = false): int {
        $start = strtotime('2026-10-10T09:00:00-05:00');
        return $this->calendar->create((object) ['course' => $this->course->id, 'name' => 'Synthetic Meet-first',
            'cohostuserid' => $this->teacher->id, 'cohostemail' => 'forged@example.invalid',
            'startdatetime' => $start, 'enddatetime' => $start + HOURSECS, 'autorecord' => 1, 'autotranscript' => 0,
            'isrecurring' => (int) $recurring, 'recurrencedays' => '["sat"]', 'recurrenceinterval' => 1,
            'recurrenceuntil' => $start + 4 * WEEKSECS]);
    }

    /**
     * Reload state.
     * @param int $id Activity ID
     * @return \stdClass Record
     */
    private function record(int $id): \stdClass {
        global $DB;
        return $DB->get_record('tupmeet', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Emulate provider state, observing every real production URL and request.
     * @param int $issuer Owner issuer
     * @param string $method Verb
     * @param string $url URL
     * @param array|null $payload Body
     * @param array $options Native options
     * @return string JSON
     */
    private function http(int $issuer, string $method, string $url, ?array $payload, array $options): string {
        global $DB;
        $this->assertFalse($DB->is_transaction_started(), 'HTTP must be outside every transaction');
        $this->assertNotSame('DELETE', $method, 'No external deletes');
        $this->calls[] = [$issuer, $method, $url, $payload, $options];
        $this->status = 200;
        $this->errno = 0;
        $calendar = str_contains($url, '/calendar/v3/');
        $member = str_contains($url, '/members');
        $create = $url === 'https://meet.googleapis.com/v2/spaces' && $method === 'POST';
        if ($create) {
            $record = $DB->get_record('tupmeet', ['spacestatus' => 'creating'], '*', MUST_EXIST);
            $this->assertGreaterThan(0, (int) $record->spaceattempts);
            $this->assertGreaterThan(0, (int) $record->spacemodified);
            $this->assertFalse($options['CURLOPT_FOLLOWLOCATION']);
            $this->assertSame([], $payload);
        }
        if (
            ($create && $this->fault === 'space') || ($calendar && $this->fault === 'calendar') ||
                ($member && $this->fault === 'member') || (!$calendar && !$member && !$create && $this->fault === 'artifact')
        ) {
            $this->status = $this->failure;
            return '{}';
        }
        if ($create && $this->fault === 'timeout') {
            throw new \RuntimeException('Synthetic transport timeout, never surfaced');
        }
        if ($create && $this->fault === 'partial429') {
            $this->status = 429;
            $this->errno = 28;
            return '{}';
        }
        if ($this->duringhttp) {
            $hook = $this->duringhttp;
            $this->duringhttp = null;
            $hook();
        }
        if ($calendar) {
            $id = $method === 'POST' ? $payload['id'] : basename(parse_url($url, PHP_URL_PATH));
            if ($method === 'GET') {
                if (!isset($this->events[$id])) {
                    $this->status = 404;
                    return '{}';
                }
                $event = $this->events[$id];
                if ($this->tamper) {
                    $event = ($this->tamper)($event);
                }
                return json_encode($event);
            }
            $this->assertStringContainsString('sendUpdates=all', $url);
            $this->assertStringContainsString('conferenceDataVersion=1', $url);
            $this->assertArrayNotHasKey('createRequest', $payload['conferenceData'] ?? []);
            $this->assertArrayNotHasKey('signature', $payload['conferenceData'] ?? []);
            $this->events[$id] = array_replace($this->events[$id] ?? [], $payload, ['id' => $id]);
            if ($this->fault === 'calendartimeout') {
                throw new \RuntimeException('Lost response after storing the native event');
            }
            return json_encode($this->events[$id]);
        }
        if ($member) {
            if ($method === 'GET') {
                return json_encode(['members' => $this->members]);
            }
            $item = $payload + ['name' => 'spaces/Permanent_1/members/Teacher_1'];
            $this->members[] = $item;
            return json_encode($item);
        }
        if ($method === 'PATCH') {
            $this->space['config'] = $payload['config'];
        }
        return json_encode($this->space);
    }

    /**
     * New activities queue only Space creation with no artificial delay.
     */
    public function test_new_activity_and_atomic_task(): void {
        global $DB;
        $id = $this->create();
        $record = $this->record($id);
        $this->assertSame('meet', $record->provisionmode);
        $this->assertSame('pending', $record->spacestatus);
        $this->assertSame('pending', $record->syncstatus);
        $this->assertSame($this->teacher->email, $record->cohostemail);
        $this->assertEquals(0, $record->spacenextattempt);
        $tasks = $DB->get_records('task_adhoc', ['component' => 'mod_tupmeet']);
        $this->assertCount(1, $tasks);
        $task = reset($tasks);
        $this->assertSame('\\mod_tupmeet\\task\\sync_space', $task->classname);
        $this->assertLessThanOrEqual(time(), (int) $task->nextruntime);
        $this->assertSame([], $this->calls);
    }

    /**
     * One Space is durably stored and queues three independent workers.
     */
    public function test_create_once_and_queue_dependents(): void {
        global $DB;
        $id = $this->create(true);
        $this->assertTrue($this->spaces->synchronize($id));
        $this->assertTrue($this->spaces->synchronize($id));
        $record = $this->record($id);
        $this->assertSame('ready', $record->spacestatus);
        $this->assertSame($this->space['name'], $record->meetspacename);
        $this->assertSame($this->space['meetingUri'], $record->meeturi);
        $this->assertSame($this->space['meetingCode'], $record->meetingcode);
        $this->assertNotSame('spaces/' . $record->meetingcode, $record->meetspacename);
        $this->assertCount(1, $this->calls);
        foreach (['sync_cohost', 'sync_meet_config', 'sync_meeting'] as $name) {
            $this->assertEquals(1, $DB->count_records('task_adhoc', ['classname' => '\\mod_tupmeet\\task\\' . $name]));
        }
    }

    /**
     * Uncertain outcomes never automatically or explicitly recreate a Space.
     * @param string $fault Simulated fault
     * @param int $status Response code
     * @dataProvider uncertain_cases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('uncertain_cases')]
    public function test_uncertain_never_retries(string $fault, int $status): void {
        global $DB;
        $id = $this->create();
        $this->fault = $fault;
        $this->failure = $status;
        $this->assertTrue($this->spaces->synchronize($id));
        $this->assertSame('uncertain', $this->record($id)->spacestatus);
        $count = $DB->count_records('task_adhoc');
        space_manager::retry($id);
        space_manager::queue($this->record($id));
        $this->assertTrue($this->spaces->synchronize($id));
        $task = new task\sync_space();
        $task->set_custom_data(['id' => $id, 'version' => $this->record($id)->spaceversion]);
        $task->execute();
        $this->assertSame('uncertain', $this->record($id)->spacestatus);
        $this->assertCount(1, $this->calls);
        $this->assertEquals($count, $DB->count_records('task_adhoc'));
    }

    /**
     * Ambiguous responses.
     * @return array Cases
     */
    public static function uncertain_cases(): array {
        return [['timeout', 0], ['space', 0], ['space', 200], ['space', 408], ['space', 409],
            ['space', 500], ['space', 503], ['partial429', 429]];
    }

    /**
     * A confirmed quota rejection defers retries without a permanent failure or fixed normal throttle.
     */
    public function test_quota_backoff_then_success(): void {
        global $DB;
        $id = $this->create();
        $this->fault = 'space';
        $this->failure = 429;
        $this->assertFalse($this->spaces->synchronize($id));
        $saved = $this->record($id);
        $this->assertSame('pending', $saved->spacestatus);
        $this->assertGreaterThan(time(), (int) $saved->spacenextattempt);
        $this->assertFalse($this->spaces->synchronize($id));
        $this->assertCount(1, $this->calls);
        $DB->set_field('tupmeet', 'spacenextattempt', time() - 1, ['id' => $id]);
        $this->fault = '';
        $this->assertTrue($this->spaces->synchronize($id));
        $this->assertSame('ready', $this->record($id)->spacestatus);
        $this->assertEquals(2, $this->record($id)->spaceattempts);
    }

    /**
     * Definite authorization rejection can be retried only through the explicit action.
     */
    public function test_definitive_rejection_manual_retry(): void {
        $id = $this->create();
        $version = $this->record($id)->spaceversion;
        $this->fault = 'space';
        $this->failure = 403;
        $this->assertTrue($this->spaces->synchronize($id));
        $this->assertSame('error', $this->record($id)->spacestatus);
        $this->assertTrue($this->spaces->synchronize($id));
        $this->assertCount(1, $this->calls);
        space_manager::retry($id);
        $this->assertNotSame($version, $this->record($id)->spaceversion);
        $this->fault = '';
        $this->assertTrue($this->spaces->synchronize($id));
        $this->assertSame('ready', $this->record($id)->spacestatus);
    }

    /**
     * A worker interrupted after its checkpoint cannot send another POST on restart.
     */
    public function test_interrupted_creating_requires_review(): void {
        global $DB;
        $id = $this->create();
        $DB->set_field('tupmeet', 'spacestatus', 'creating', ['id' => $id]);
        $this->assertTrue($this->spaces->synchronize($id));
        $this->assertSame('uncertain', $this->record($id)->spacestatus);
        $this->assertSame([], $this->calls);
    }

    /**
     * Revision changes before POST cancel work; stale replies cannot overwrite state.
     */
    public function test_space_revision_guards(): void {
        global $DB;
        $id = $this->create();
        $this->assertTrue($this->spaces->synchronize($id, 'obsolete'));
        $this->beforeclient = fn() => $DB->set_field('tupmeet', 'spaceversion', 'changed', ['id' => $id]);
        $this->assertTrue($this->spaces->synchronize($id));
        $this->assertSame([], $this->calls);
        $this->beforeclient = null;
        $this->duringhttp = fn() => $DB->set_field('tupmeet', 'spaceversion', 'changed-again', ['id' => $id]);
        $this->assertTrue($this->spaces->synchronize($id));
        $this->assertNull($this->record($id)->meetspacename);
        $this->assertSame('creating', $this->record($id)->spacestatus);
        $this->assertTrue($this->spaces->synchronize($id));
        $this->assertSame('uncertain', $this->record($id)->spacestatus);
    }

    /**
     * A concurrent schedule edit does not invalidate or duplicate the independent Space creation.
     */
    public function test_schedule_edit_during_space_post(): void {
        $id = $this->create();
        $version = $this->record($id)->spaceversion;
        $this->duringhttp = fn() => $this->calendar->update((object) ['id' => $id, 'name' => 'Edited while creating']);
        $this->assertTrue($this->spaces->synchronize($id));
        $this->assertSame('ready', $this->record($id)->spacestatus);
        $this->assertSame($version, $this->record($id)->spaceversion);
        $this->assertSame('Edited while creating', $this->record($id)->name);
        $this->assertCount(1, $this->calls);
    }

    /**
     * Calendar creates and verifies a single series using exactly the stored Space and attendee.
     */
    public function test_native_calendar_series_and_invitation(): void {
        $id = $this->create(true);
        $this->assertTrue($this->spaces->synchronize($id));
        $this->assertTrue($this->calendar->synchronize($id));
        $record = $this->record($id);
        $event = $this->events[$record->calendareventid];
        $this->assertSame('ready', $record->syncstatus);
        $this->assertSame($record->meetingcode, $event['conferenceData']['conferenceId']);
        $this->assertSame('hangoutsMeet', $event['conferenceData']['conferenceSolution']['key']['type']);
        $this->assertSame($record->meeturi, $event['conferenceData']['entryPoints'][0]['uri']);
        $this->assertSame([['email' => $this->teacher->email]], $event['attendees']);
        $this->assertStringContainsString('BYDAY=SA', $event['recurrence'][0]);
        $this->assertSame(['POST', 'GET', 'POST', 'GET'], array_column($this->calls, 1));
        $this->assertTrue($this->calendar->synchronize($id));
        $this->assertCount(4, $this->calls);
        $this->assertCount(1, $this->events);
    }

    /**
     * Success requires a subsequent matching GET, not a successful insert response.
     * @param string $field Tampered confirmation field
     * @dataProvider confirmation_fields
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('confirmation_fields')]
    public function test_calendar_get_must_confirm_same_meet(string $field): void {
        $id = $this->create();
        $this->spaces->synchronize($id);
        $this->tamper = static function ($event) use ($field) {
            switch ($field) {
                case 'id':
                    $event['id'] = 'foreign';
                    break;
                case 'code':
                    $event['conferenceData']['conferenceId'] = 'xxx-yyyy-zzz';
                    break;
                case 'type':
                    $event['conferenceData']['conferenceSolution']['key']['type'] = 'other';
                    break;
                case 'uri':
                    $event['conferenceData']['entryPoints'][0]['uri'] = 'https://meet.google.com/xxx-yyyy-zzz';
                    break;
                case 'attendee':
                    $event['attendees'] = [];
                    break;
                case 'secondvideo':
                    $event['conferenceData']['entryPoints'][] = ['entryPointType' => 'video',
                        'uri' => 'https://meet.google.com/xxx-yyyy-zzz'];
                    break;
            }
            return $event;
        };
        $this->assertFalse($this->calendar->synchronize($id));
        $this->assertSame('error', $this->record($id)->syncstatus);
        $this->assertSame('ready', $this->record($id)->spacestatus);
        $this->assertSame($this->space['meetingUri'], $this->record($id)->meeturi);
    }

    /**
     * Native conference verification failures.
     * @return array Cases
     */
    public static function confirmation_fields(): array {
        return [['id'], ['code'], ['type'], ['uri'], ['attendee'], ['secondvideo']];
    }

    /**
     * Recover Calendar's idempotent write without creating another Space or resending the invitation.
     */
    public function test_calendar_lost_response_recovers_by_get(): void {
        $id = $this->create();
        $this->spaces->synchronize($id);
        $this->fault = 'calendartimeout';
        $this->assertFalse($this->calendar->synchronize($id));
        $this->fault = '';
        $this->assertTrue($this->calendar->synchronize($id));
        $this->assertSame(['POST', 'GET', 'POST', 'GET'], array_column($this->calls, 1));
        $this->assertCount(1, $this->events);
    }

    /**
     * Failure of Calendar cannot block COHOST or either artifact preference on the same canonical Space.
     */
    public function test_independent_children_and_preference_edits(): void {
        $id = $this->create();
        $this->spaces->synchronize($id);
        $this->fault = 'calendar';
        $this->failure = 403;
        $this->assertFalse($this->calendar->synchronize($id));
        $this->assertTrue($this->cohosts->synchronize($id));
        $this->assertTrue($this->artifacts->synchronize($id));
        $before = $this->record($id);
        $this->assertSame('ready', $before->cohoststatus);
        $this->assertSame('ready', $before->meetconfigstatus);
        foreach (['autorecord' => 0, 'autotranscript' => 1] as $field => $value) {
            $this->calendar->update((object) ['id' => $id, $field => $value]);
            $this->assertTrue($this->artifacts->synchronize($id));
            $after = $this->record($id);
            foreach (['meetspacename', 'meeturi', 'meetingcode', 'cohostmembername', 'syncversion', 'spaceversion'] as $key) {
                $this->assertSame($before->{$key}, $after->{$key}, $key);
            }
        }
        $this->assertSame('OFF', $this->space['config']['artifactConfig']['recordingConfig']['autoRecordingGeneration']);
        $this->assertSame('ON', $this->space['config']['artifactConfig']['transcriptionConfig']['autoTranscriptionGeneration']);
        $this->assertCount(1, $this->members);
    }

    /**
     * Independent child errors never clear the Meet or block Calendar.
     * @param string $fault Child fault
     * @dataProvider child_faults
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('child_faults')]
    public function test_child_error_preserves_space(string $fault): void {
        $id = $this->create();
        $this->spaces->synchronize($id);
        $this->fault = $fault;
        $this->failure = 403;
        $manager = $fault === 'member' ? $this->cohosts : $this->artifacts;
        $this->assertFalse($manager->synchronize($id));
        $this->assertTrue($this->calendar->synchronize($id));
        $this->assertSame('ready', $this->record($id)->spacestatus);
        $this->assertSame($this->space['meetingUri'], $this->record($id)->meeturi);
    }

    /**
     * Isolated child failures.
     * @return array Cases
     */
    public static function child_faults(): array {
        return [['member'], ['artifact']];
    }

    /**
     * Name/schedule/recurrence edits update only the existing Calendar series.
     */
    public function test_calendar_edit_preserves_all_meet_identity(): void {
        $id = $this->create(true);
        $this->spaces->synchronize($id);
        $this->cohosts->synchronize($id);
        $this->artifacts->synchronize($id);
        $this->calendar->synchronize($id);
        $before = $this->record($id);
        $this->calls = [];
        foreach (
            [['name' => 'Renamed'], ['startdatetime' => $before->startdatetime + 1800,
            'enddatetime' => $before->enddatetime + 1800], ['recurrenceinterval' => 2]] as $change
        ) {
            $this->calendar->update((object) ($change + ['id' => $id]));
            $this->spaces->synchronize($id);
            $this->cohosts->synchronize($id);
            $this->artifacts->synchronize($id);
            $this->assertTrue($this->calendar->synchronize($id));
            $after = $this->record($id);
            foreach (
                ['accountid', 'calendareventid', 'meetspacename', 'meeturi', 'meetingcode', 'cohostmembername',
                'cohostversion', 'meetconfigversion', 'spaceversion'] as $field
            ) {
                $this->assertSame($before->{$field}, $after->{$field}, $field);
            }
        }
        foreach ($this->calls as $call) {
            $this->assertStringContainsString('/calendar/v3/', $call[2]);
            if ($call[1] === 'PATCH') {
                $this->assertArrayNotHasKey('conferenceData', $call[3]);
            }
        }
        $this->assertCount(1, $this->events);
    }

    /**
     * Calendar rechecks the stored teacher identity, rejecting forged or subsequently changed email.
     */
    public function test_attendee_identity_changed_stops_calendar(): void {
        global $DB;
        $id = $this->create();
        $this->spaces->synchronize($id);
        $DB->set_field('user', 'email', 'changed@example.invalid', ['id' => $this->teacher->id]);
        $this->assertFalse($this->calendar->synchronize($id));
        $this->assertCount(1, $this->calls);
        $this->assertSame($this->space['meetingUri'], $this->record($id)->meeturi);
    }

    /**
     * Historical owner, not a newly configured default, authenticates every child.
     */
    public function test_historical_owner_is_preserved(): void {
        $id = $this->create();
        $owner = $this->accounts->get_account($this->owner);
        $this->accounts->set_default($this->account());
        $this->accounts->set_enabled($this->owner, false);
        $this->spaces->synchronize($id);
        $this->cohosts->synchronize($id);
        $this->artifacts->synchronize($id);
        $this->calendar->synchronize($id);
        foreach ($this->calls as $call) {
            $this->assertEquals($owner->issuerid, $call[0]);
        }
        $this->assertEquals($this->owner, $this->record($id)->accountid);
    }

    /**
     * Historical activities never create a Space or write membership, including old queued/manual retries.
     */
    public function test_historical_mode_disables_new_writes(): void {
        global $DB;
        $id = $this->create();
        $DB->update_record('tupmeet', (object) ['id' => $id, 'provisionmode' => 'calendar',
            'syncstatus' => 'ready', 'meeturi' => $this->space['meetingUri'],
            'meetspacename' => $this->space['name'], 'cohostmembername' => 'spaces/Permanent_1/members/Manual_1']);
        $before = $this->record($id);
        $this->spaces->synchronize($id);
        $this->cohosts->synchronize($id);
        cohost_manager::retry($id);
        $this->cohosts->synchronize($id);
        $this->assertEquals($before, $this->record($id));
        $this->assertSame([], $this->calls);
    }

    /**
     * A shared lock prevents a second creator, without an artificial global rate limiter.
     */
    public function test_shared_lock_contention(): void {
        global $CFG;
        // MariaDB GET_LOCK is reentrant on one connection; use Moodle's row-lock backend.
        $CFG->lock_factory = '\core\lock\db_record_lock_factory';
        $id = $this->create();
        $lock = \core\lock\lock_config::get_lock_factory('mod_tupmeet')->get_lock('meeting:' . $id, 0);
        try {
            $this->assertFalse($this->spaces->synchronize($id));
            $this->assertSame([], $this->calls);
        } finally {
            $lock->release();
        }
        $this->assertTrue($this->spaces->synchronize($id));
    }

    /**
     * Local deletion invokes no Google operation, even with a provisioned series.
     */
    public function test_delete_never_deletes_external_resources(): void {
        $id = $this->create();
        $this->spaces->synchronize($id);
        $this->cohosts->synchronize($id);
        $this->calendar->synchronize($id);
        $count = count($this->calls);
        $this->assertTrue(tupmeet_delete_instance($id));
        $this->spaces->synchronize($id);
        $this->cohosts->synchronize($id);
        $this->calendar->synchronize($id);
        $this->assertCount($count, $this->calls);
        $this->assertCount(1, $this->events);
        $this->assertCount(1, $this->members);
    }

    /**
     * Both creation entry points reject an open transaction before native HTTP.
     */
    public function test_no_space_http_in_transaction(): void {
        global $DB;
        $id = $this->create();
        $transaction = $DB->start_delegated_transaction();
        try {
            $this->spaces->synchronize($id);
            $this->fail('Expected transaction guard');
        } catch (\coding_exception $e) {
            $this->assertSame([], $this->calls);
        } finally {
            $transaction->allow_commit();
        }
    }

    /**
     * A code-shaped name is not accepted as a permanent resource ID.
     */
    public function test_code_is_not_permanent_identity(): void {
        $id = $this->create();
        $this->space['name'] = 'spaces/abc-defg-hij';
        $this->assertTrue($this->spaces->synchronize($id));
        $this->assertSame('uncertain', $this->record($id)->spacestatus);
        $this->assertNull($this->record($id)->meetspacename);
    }

    /**
     * Losing the local confirmation after a successful POST is uncertain, never a second Space.
     */
    public function test_space_confirmation_database_failure(): void {
        global $DB;
        $id = $this->create();
        $table = new \xmldb_table('tupmeet');
        $field = new \xmldb_field('meeturi', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'meetingcode');
        $this->duringhttp = fn() => $DB->get_manager()->drop_field($table, $field);
        try {
            $this->assertTrue($this->spaces->synchronize($id));
        } finally {
            $DB->get_manager()->add_field($table, $field);
        }
        $this->assertSame('uncertain', $this->record($id)->spacestatus);
        $this->assertNull($this->record($id)->meetspacename);
        $this->assertTrue($this->spaces->synchronize($id));
        $this->assertCount(1, $this->calls);
    }

    /**
     * Calendar's stale response never confirms a newer local revision or changes the Space.
     */
    public function test_calendar_stale_reply_and_task(): void {
        $id = $this->create();
        $this->spaces->synchronize($id);
        $old = $this->record($id);
        $this->assertTrue($this->calendar->synchronize($id, 'obsolete'));
        $this->assertCount(1, $this->calls);
        $this->duringhttp = fn() => $this->calendar->update((object) ['id' => $id, 'name' => 'Concurrent edit']);
        $this->assertFalse($this->calendar->synchronize($id));
        $this->assertSame('pending', $this->record($id)->syncstatus);
        $this->assertNotSame($old->syncversion, $this->record($id)->syncversion);
        $this->assertSame($old->spaceversion, $this->record($id)->spaceversion);
        $this->assertTrue($this->calendar->synchronize($id));
        $this->assertSame('Concurrent edit', $this->events[$old->calendareventid]['summary']);
        $this->assertCount(1, $this->events);
    }

    /**
     * An outer Moodle rollback removes both desired state and its Space task, without any HTTP.
     */
    public function test_space_task_rollback(): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $this->create();
        try {
            $transaction->rollback(new \RuntimeException('Synthetic local rollback'));
        } catch (\RuntimeException $e) {
            $this->assertSame([], $this->calls);
        }
        $this->assertEquals(0, $DB->count_records('tupmeet'));
        $this->assertEquals(0, $DB->count_records('task_adhoc', ['component' => 'mod_tupmeet']));
    }
}
