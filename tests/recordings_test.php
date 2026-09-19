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

use mod_tupmeet\local\account\oauth_client_factory;
use mod_tupmeet\local\google\drive_metadata_service;
use mod_tupmeet\local\google\recording_exception;
use mod_tupmeet\local\google\recording_service;
use mod_tupmeet\local\recording\filename;
use mod_tupmeet\local\recording\polling;
use mod_tupmeet\local\recording\recording_manager;
use mod_tupmeet\local\recording\rename_manager;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../lib.php');

/**
 * Phase 4 integration with real XMLDB persistence/queues and fake native OAuth HTTP only.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(recording_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(recording_service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(drive_metadata_service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(rename_manager::class)]
final class recordings_test extends \advanced_testcase {
    /**
     * Discovery sets visibility once, then preserves manual decisions through processing and preference edits.
     * @param string $mode Initial publication preference
     * @param string $state First state
     * @dataProvider publication_cases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('publication_cases')]
    public function test_publication_survives_discovery(string $mode, string $state): void {
        global $DB;
        $DB->set_field('tupmeet', 'publicationmode', $mode, ['id' => $this->meeting->id]);
        $this->responses = [
            ['body' => ['conferenceRecords' => [$this->conference()]]],
            ['body' => ['recordings' => [$this->recording('Rec_1', $state)]]],
        ];
        recording_manager::queue((int) $this->meeting->id);
        $meeting = $DB->get_record('tupmeet', ['id' => $this->meeting->id]);
        $manager = new recording_manager($this->service);
        $this->assertTrue($manager->synchronize((int) $meeting->id, $meeting->recordingsyncversion));
        $record = $DB->get_record('tupmeet_recordings', ['tupmeetid' => $meeting->id], '*', MUST_EXIST);
        $this->assertEquals($mode === 'automatic' ? 1 : 0, $record->studentvisible);
        $this->assertEquals(0, $record->visibilitymodified);
        $this->assertEquals(0, $record->visibilityuserid);
        $manual = $mode === 'automatic' ? 0 : 1;
        $DB->update_record('tupmeet_recordings', (object) [
            'id' => $record->id, 'studentvisible' => $manual, 'visibilityuserid' => 123, 'visibilitymodified' => 123456,
        ]);
        // Change the activity preference to disagree with the individual decision.
        $DB->set_field('tupmeet', 'publicationmode', $manual ? 'manual' : 'automatic', ['id' => $meeting->id]);
        $DB->update_record('tupmeet', (object) [
            'id' => $meeting->id, 'recordingsyncstatus' => 'idle', 'recordingsnextsync' => 0,
        ]);
        $this->responses = [
            ['body' => ['conferenceRecords' => [$this->conference()]]],
            ['body' => ['recordings' => [$this->recording()]]],
        ];
        recording_manager::queue((int) $meeting->id);
        $meeting = $DB->get_record('tupmeet', ['id' => $meeting->id]);
        $this->assertTrue($manager->synchronize((int) $meeting->id, $meeting->recordingsyncversion));
        $after = $DB->get_record('tupmeet_recordings', ['id' => $record->id]);
        $this->assertEquals($manual, $after->studentvisible);
        $this->assertEquals(123, $after->visibilityuserid);
        $this->assertEquals(123456, $after->visibilitymodified);
        $this->assertSame('FILE_GENERATED', $after->state);
        $this->assertSame('File_Rec_1', $after->drivefileid);
        $this->assertEquals(1, $DB->count_records('tupmeet_recordings'));
    }

    /**
     * Every discovery entry state and preference.
     * @return array
     */
    public static function publication_cases(): array {
        $cases = [];
        foreach (['automatic', 'manual'] as $mode) {
            foreach (['STARTED', 'ENDED', 'FILE_GENERATED'] as $state) {
                $cases[] = [$mode, $state];
            }
        }
        return $cases;
    }

    /** @var \stdClass Historical owner. */
    private \stdClass $owner;
    /** @var \stdClass Activity. */
    private \stdClass $meeting;
    /** @var oauth_client_factory Fake native client factory. */
    private oauth_client_factory $factory;
    /** @var recording_service Real Meet service. */
    private recording_service $service;
    /** @var drive_metadata_service Real Drive service. */
    private drive_metadata_service $drive;
    /** @var array Scripted HTTP responses. */
    private array $responses = [];
    /** @var array Actual request observations. */
    private array $calls = [];
    /** @var int Last HTTP status. */
    private int $status = 200;
    /** @var int Last errno. */
    private int $errno = 0;
    /** @var \Closure|null Mutation during a request for stale-worker coverage. */
    private ?\Closure $hook = null;

    /**
     * Real DB/queues with strictly scripted, offline transport.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $id = $DB->insert_record('tupmeet_accounts', (object) ['displayname' => 'Historical fixture',
            'googleemail' => 'owner@example.invalid', 'googlesub' => 'fixture-subject', 'issuerid' => 999,
            'enabled' => 0, 'isdefault' => 0, 'connectionstatus' => 'verified']);
        $this->owner = $DB->get_record('tupmeet_accounts', ['id' => $id]);
        $id = $DB->insert_record('tupmeet', (object) [
            'name' => 'Farmacología: UCI / Grupo A', 'accountid' => $id, 'timezone' => 'America/Cancun',
            'startdatetime' => strtotime('2026-09-19T22:00:00Z'), 'enddatetime' => strtotime('2026-09-19T23:00:00Z'),
            'provisionmode' => 'meet', 'spacestatus' => 'ready', 'meetspacename' => 'spaces/Canonical_1',
            'meeturi' => 'https://meet.google.com/abc-defg-hij', 'meetingcode' => 'abc-defg-hij',
            'syncstatus' => 'error', 'syncversion' => 'calendar-revision',
        ]);
        $this->meeting = $DB->get_record('tupmeet', ['id' => $id]);
        $client = $this->getMockBuilder(\core\oauth2\client::class)->disableOriginalConstructor()
            ->onlyMethods(['get', 'post', 'get_info', 'get_errno', 'setHeader'])->getMock();
        $client->method('get')->willReturnCallback(fn($url, $params, $options) => $this->http('GET', $url, null, $options));
        $client->method('post')->willReturnCallback(fn($url, $body, $options) => $this->http('PATCH', $url, $body, $options));
        $client->method('get_info')->willReturnCallback(fn() => ['http_code' => $this->status]);
        $client->method('get_errno')->willReturnCallback(fn() => $this->errno);
        $this->factory = $this->createMock(oauth_client_factory::class);
        $this->factory->method('for_account')->willReturnCallback(function ($account) use ($client) {
            global $DB;
            $this->assertFalse($DB->is_transaction_started());
            $this->assertEquals($this->owner->id, $account->id);
            return $client;
        });
        $this->service = new recording_service($this->factory);
        $this->drive = new drive_metadata_service($this->factory);
    }

    /**
     * Fail closed on any unexpected request; do not print remote bodies on assertion failure.
     * @param string $method Verb
     * @param string $url URL
     * @param string|null $body Request body
     * @param array $options Curl options
     * @return string
     */
    private function http(string $method, string $url, ?string $body, array $options): string {
        global $DB;
        $this->assertFalse($DB->is_transaction_started());
        $this->assertFalse($options['CURLOPT_FOLLOWLOCATION']);
        $this->assertSame($method, $options['CURLOPT_CUSTOMREQUEST']);
        $path = parse_url($url, PHP_URL_PATH);
        if (parse_url($url, PHP_URL_HOST) === 'meet.googleapis.com') {
            $this->assertSame('GET', $method);
            $this->assertMatchesRegularExpression('~^/v2/conferenceRecords(?:/[A-Za-z0-9_-]+/recordings)?$~D', $path);
        } else {
            $this->assertSame('www.googleapis.com', parse_url($url, PHP_URL_HOST));
            $this->assertMatchesRegularExpression('~^/drive/v3/files/[A-Za-z0-9_-]+$~D', $path);
            $this->assertStringNotContainsString('alt=media', $url);
            $this->assertStringNotContainsString('permissions', $url);
        }
        $this->calls[] = [$method, $url, $body];
        $response = array_shift($this->responses);
        $this->assertNotNull($response, 'Unexpected HTTP: offline fixtures exhausted');
        if ($this->hook) {
            ($this->hook)();
        }
        $this->status = $response['status'] ?? 200;
        $this->errno = $response['errno'] ?? 0;
        if (!empty($response['throw'])) {
            throw new \RuntimeException('Synthetic transport failure');
        }
        return isset($response['raw']) ? $response['raw'] : json_encode((object) ($response['body'] ?? []));
    }

    /**
     * A real conference whose start differs from the scheduled start.
     * @param string $id Suffix
     * @return array
     */
    private function conference(string $id = 'Conf_1'): array {
        return ['name' => 'conferenceRecords/' . $id, 'space' => 'spaces/Canonical_1',
            'startTime' => '2026-09-19T22:03:00Z', 'endTime' => '2026-09-19T23:04:00Z'];
    }

    /**
     * A recording fixture with a destination exclusively from Meet.
     * @param string $id Suffix
     * @param string $state State
     * @param string $start Actual recording start
     * @return array
     */
    private function recording(
        string $id = 'Rec_1',
        string $state = 'FILE_GENERATED',
        string $start = '2026-09-19T22:04:00Z'
    ): array {
        $row = ['name' => 'conferenceRecords/Conf_1/recordings/' . $id, 'state' => $state, 'startTime' => $start];
        if ($state !== 'STARTED') {
            $row['endTime'] = '2026-09-19T23:00:00Z';
        }
        // Earlier states must ignore even an untrusted, premature destination.
        $row['driveDestination'] = ['file' => 'File_' . $id,
            'exportUri' => 'https://drive.google.com/file/d/File_' . $id . '/view'];
        return $row;
    }

    /**
     * Queue and execute a full discovery revision.
     * @param array|null $recordings Recording fixtures
     * @return \stdClass Latest activity
     */
    private function discover(?array $recordings = null): \stdClass {
        global $DB;
        $DB->set_field('tupmeet', 'recordingsyncqueued', 0, ['id' => $this->meeting->id]);
        $this->assertTrue(recording_manager::queue((int) $this->meeting->id, true));
        $meeting = $DB->get_record('tupmeet', ['id' => $this->meeting->id]);
        $this->responses = [['body' => ['conferenceRecords' => [$this->conference()]]],
            ['body' => ['recordings' => $recordings ?? [$this->recording()]]]];
        $this->assertTrue((new recording_manager($this->service))->synchronize((int) $meeting->id, $meeting->recordingsyncversion));
        $this->assertSame([], $this->responses);
        return $DB->get_record('tupmeet', ['id' => $meeting->id]);
    }

    /**
     * Current local recording.
     * @param string $suffix Recording suffix
     * @return \stdClass
     */
    private function local(string $suffix = 'Rec_1'): \stdClass {
        global $DB;
        return $DB->get_record(
            'tupmeet_recordings',
            ['recordingname' => 'conferenceRecords/Conf_1/recordings/' . $suffix],
            '*',
            MUST_EXIST
        );
    }

    /**
     * Drive metadata fixture.
     * @param string $name Current filename
     * @return array
     */
    private function metadata(string $name = 'Original.mp4'): array {
        return ['id' => 'File_Rec_1', 'name' => $name, 'mimeType' => 'video/mp4',
            'parents' => ['Folder_fixture'], 'trashed' => false, 'capabilities' => ['canRename' => true]];
    }

    /**
     * Canonical discovery and native rename preserve every other subsystem and use the disabled historical owner.
     */
    public function test_full_metadata_flow_is_independent_and_idempotent(): void {
        global $DB;
        $before = $this->meeting;
        $meeting = $this->discover();
        $record = $this->local();
        $this->assertSame('ready', $meeting->recordingsyncstatus);
        foreach (
            ['accountid', 'syncstatus', 'meeturi', 'meetingcode', 'meetspacename',
                'cohoststatus', 'meetconfigstatus', 'spacestatus', 'publicationmode', 'autotranscript'] as $field
        ) {
            $this->assertEquals($before->{$field}, $meeting->{$field});
        }
        $this->assertSame('FILE_GENERATED', $record->state);
        $this->assertSame('Farmacología - UCI - Grupo A - 2026-09-19 - 17-03.mp4', $record->desiredfilename);
        parse_str(parse_url($this->calls[0][1], PHP_URL_QUERY), $query);
        $this->assertSame('space.name = "spaces/Canonical_1"', $query['filter']);
        $this->assertSame('100', $query['pageSize']);
        $this->assertStringNotContainsString('abc-defg-hij', $this->calls[0][1]);
        $this->assertEquals(1, $DB->count_records('task_adhoc', ['classname' => '\mod_tupmeet\task\rename_recording']));
        // A new semester's default must never authenticate the historical file's rename.
        $DB->insert_record('tupmeet_accounts', (object) ['displayname' => 'Next semester',
            'googleemail' => 'replacement@example.invalid', 'issuerid' => 1000, 'enabled' => 1, 'isdefault' => 1]);
        $this->responses = [['body' => $this->metadata()], ['body' => $this->metadata($record->desiredfilename)]];
        $rename = new rename_manager($this->drive);
        $this->assertTrue($rename->synchronize((int) $record->id, $record->renameversion));
        $after = $this->local();
        $this->assertSame('ready', $after->renamestatus);
        $this->assertSame('Original.mp4', $after->originalfilename);
        $this->assertSame($record->desiredfilename, $after->drivefilename);
        $this->assertGreaterThan(0, (int) $after->renamedat);
        $this->assertSame(['name' => $record->desiredfilename], json_decode($this->calls[3][2], true));
        $this->assertStringStartsWith('https://www.googleapis.com/drive/v3/files/File_Rec_1?', $this->calls[2][1]);
        $this->assertStringNotContainsString('addParents', $this->calls[3][1]);
        $this->assertStringNotContainsString('removeParents', $this->calls[3][1]);
        $count = count($this->calls);
        $this->assertTrue($rename->synchronize((int) $record->id, $record->renameversion));
        $this->assertCount($count, $this->calls);
        $DB->set_field('tupmeet', 'name', 'Renamed activity', ['id' => $meeting->id]);
        $this->discover();
        $this->assertSame($after->desiredfilename, $this->local()->desiredfilename);
        $this->assertSame('ready', $this->local()->renamestatus);
        $this->assertEquals(1, $DB->count_records('tupmeet_conferences'));
        $this->assertEquals(1, $DB->count_records('tupmeet_recordings'));
        $this->assertEquals($record->firstseen, $this->local()->firstseen);
    }

    /**
     * Both collection endpoints exhaust their pages and encode page tokens as query data.
     */
    public function test_pagination_of_both_lists(): void {
        $second = $this->recording('Rec_2', 'FILE_GENERATED', '2026-09-19T22:20:00Z');
        $this->responses = [
            ['body' => ['conferenceRecords' => [$this->conference()], 'nextPageToken' => 'conference&token']],
            ['body' => ['conferenceRecords' => [$this->conference('Conf_2')]]],
            ['body' => ['recordings' => [$this->recording()], 'nextPageToken' => 'recording/token']],
            ['body' => ['recordings' => [$second]]],
            ['body' => []],
        ];
        $data = iterator_to_array($this->service->discover($this->owner, $this->meeting->meetspacename));
        $this->assertCount(2, $data);
        $this->assertCount(2, $data[0]['recordings']);
        $this->assertCount(5, $this->calls);
        $this->assertStringContainsString('pageToken=conference%26token', $this->calls[1][1]);
        $this->assertStringContainsString('pageToken=recording%2Ftoken', $this->calls[3][1]);
    }

    /**
     * Cyclic cursors fail safely rather than looping or pretending a partial list was complete.
     */
    public function test_pagination_cycle_is_bounded(): void {
        $this->responses = array_fill(0, 2, ['body' => ['nextPageToken' => 'same']]);
        $this->expectException(recording_exception::class);
        iterator_to_array($this->service->discover($this->owner, 'spaces/Canonical_1'));
    }

    /**
     * Many unique cursors still respect the defensive page ceiling.
     */
    public function test_pagination_ceiling(): void {
        for ($i = 0; $i < recording_service::MAX_PAGES; $i++) {
            $this->responses[] = ['body' => ['nextPageToken' => 'page' . $i]];
        }
        try {
            iterator_to_array($this->service->discover($this->owner, 'spaces/Canonical_1'));
            $this->fail('Unbounded listing accepted');
        } catch (recording_exception $e) {
            $this->assertCount(recording_service::MAX_PAGES, $this->calls);
        }
    }

    /**
     * Invalid resources must never reach local persistence or Drive.
     * @param string $fault Mutation
     * @dataProvider invalid_resources
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_resources')]
    public function test_invalid_google_resource_is_rejected(string $fault): void {
        $conference = $this->conference();
        $recording = $this->recording();
        switch ($fault) {
            case 'conference_name':
                $conference['name'] = 'conferenceRecords/../escape';
                break;
            case 'space':
                $conference['space'] = 'spaces/Someone_else';
                break;
            case 'conference_time':
                $conference['startTime'] = '2026-02-30T10:00:00Z';
                break;
            case 'parent':
                $recording['name'] = 'conferenceRecords/Other/recordings/Rec_1';
                break;
            case 'unknown_state':
                $recording['state'] = 'UNKNOWN_FUTURE_STATE';
                break;
            case 'file':
                $recording['driveDestination']['file'] = '../other';
                break;
            case 'missing_file':
                unset($recording['driveDestination']['file']);
                break;
            case 'url':
                $recording['driveDestination']['exportUri'] = 'https://drive.google.com.evil.invalid/file/d/File_Rec_1/view';
                break;
            case 'wrong_file_url':
                $recording['driveDestination']['exportUri'] = 'https://drive.google.com/file/d/Other/view';
                break;
            case 'http':
                $recording['driveDestination']['exportUri'] = 'http://drive.google.com/file/d/File_Rec_1/view';
                break;
            case 'missing_end':
                unset($recording['endTime']);
                break;
            case 'before_conference':
                $recording['startTime'] = '2026-09-19T20:00:00Z';
                break;
        }
        $this->responses = [['body' => ['conferenceRecords' => [$conference]]], ['body' => ['recordings' => [$recording]]]];
        $this->expectException(recording_exception::class);
        iterator_to_array($this->service->discover($this->owner, 'spaces/Canonical_1'));
    }

    /**
     * Unsafe remote variants.
     * @return array
     */
    public static function invalid_resources(): array {
        return array_map(static fn($s) => [$s], ['conference_name', 'space', 'conference_time', 'parent',
            'unknown_state', 'file', 'missing_file', 'url', 'wrong_file_url', 'http', 'missing_end', 'before_conference']);
    }

    /**
     * STARTED/ENDED never expose premature destinations; later regressions preserve generated metadata.
     */
    public function test_processing_transitions_preserve_generated_metadata(): void {
        $this->discover([$this->recording('Rec_1', 'STARTED')]);
        $this->assertNull($this->local()->drivefileid);
        $this->assertNull($this->local()->exporturi);
        $this->assertSame('unavailable', $this->local()->renamestatus);
        $this->discover([$this->recording('Rec_1', 'ENDED')]);
        $this->assertSame('ENDED', $this->local()->state);
        $this->assertNull($this->local()->desiredfilename);
        $this->discover();
        $generated = $this->local();
        $this->discover([$this->recording('Rec_1', 'STARTED')]);
        $this->assertSame('FILE_GENERATED', $this->local()->state);
        $this->assertSame($generated->drivefileid, $this->local()->drivefileid);
        $this->assertSame($generated->exporturi, $this->local()->exporturi);
    }

    /**
     * Remote absence retains both tables and does not fake a new lastseen timestamp.
     */
    public function test_absence_never_deletes_local_history(): void {
        global $DB;
        $this->discover();
        $DB->set_field('tupmeet_recordings', 'lastseen', 1234, ['id' => $this->local()->id]);
        $DB->set_field('tupmeet', 'recordingsyncqueued', 0, ['id' => $this->meeting->id]);
        recording_manager::queue((int) $this->meeting->id, true);
        $meeting = $DB->get_record('tupmeet', ['id' => $this->meeting->id]);
        $this->responses = [['body' => []]];
        $this->assertTrue((new recording_manager($this->service))->synchronize((int) $meeting->id, $meeting->recordingsyncversion));
        $this->assertEquals(1234, $this->local()->lastseen);
        $this->assertSame('ready', $DB->get_field('tupmeet', 'recordingsyncstatus', ['id' => $meeting->id]));
        $this->assertEquals(1, $DB->count_records('tupmeet_conferences'));
        $this->assertSame('FILE_GENERATED', $this->local()->state);
    }

    /**
     * Multiple recordings are ordered across the full list and use the conference start, not each segment start.
     */
    public function test_multiple_segments_freeze_ordered_names(): void {
        $this->discover([$this->recording('Third', 'FILE_GENERATED', '2026-09-19T22:40:00Z'),
            $this->recording(), $this->recording('Second', 'FILE_GENERATED', '2026-09-19T22:20:00Z')]);
        $this->assertStringEndsWith('17-03.mp4', $this->local()->desiredfilename);
        $this->assertStringEndsWith('17-03 - Parte 2.mp4', $this->local('Second')->desiredfilename);
        $this->assertStringEndsWith('17-03 - Parte 3.mp4', $this->local('Third')->desiredfilename);
    }

    /**
     * A newly revealed earlier segment cannot overwrite or collide with frozen historical names.
     */
    public function test_late_earlier_segment_requires_manual_review(): void {
        $this->discover();
        $original = $this->local()->desiredfilename;
        $this->discover([$this->recording('Earlier', 'FILE_GENERATED', '2026-09-19T22:03:30Z'), $this->recording()]);
        $this->assertSame($original, $this->local()->desiredfilename);
        $this->assertSame('skipped', $this->local('Earlier')->renamestatus);
        $this->assertNull($this->local('Earlier')->desiredfilename);
        $this->assertSame('FILE_GENERATED', $this->local('Earlier')->state);
    }

    /**
     * Changed remote identity cannot redirect an existing frozen rename.
     */
    public function test_destination_change_preserves_previous_metadata(): void {
        $this->discover();
        $record = $this->recording();
        $record['driveDestination'] = ['file' => 'New_id', 'exportUri' => 'https://drive.google.com/file/d/New_id/view'];
        $meeting = $this->discover([$record]);
        $this->assertSame('error', $meeting->recordingsyncstatus);
        $this->assertSame('File_Rec_1', $this->local()->drivefileid);
    }

    /**
     * Native read errors retry only transient conditions and stop at five attempts.
     * @param int $status Status
     * @param int $errno Transport code
     * @param bool $retry Retryable
     * @dataProvider failures
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function test_meet_retry_budget(int $status, int $errno, bool $retry): void {
        global $DB;
        recording_manager::queue((int) $this->meeting->id);
        $meeting = $DB->get_record('tupmeet', ['id' => $this->meeting->id]);
        $manager = new recording_manager($this->service);
        $limit = $retry ? 5 : 1;
        for ($i = 1; $i <= $limit; $i++) {
            $DB->set_field('tupmeet', 'recordingsnextsync', 0, ['id' => $meeting->id]);
            $this->responses = [['status' => $status, 'errno' => $errno, 'body' => ['error' => 'DO_NOT_PERSIST_BODY']]];
            $this->assertSame($i === $limit, $manager->synchronize((int) $meeting->id, $meeting->recordingsyncversion));
            $after = $DB->get_record('tupmeet', ['id' => $meeting->id]);
            $this->assertEquals($i, $after->recordingsyncattempts);
            $this->assertSame($i === $limit ? 'error' : 'pending', $after->recordingsyncstatus);
            $this->assertStringNotContainsString('DO_NOT_PERSIST_BODY', json_encode($after));
            $this->assertSame($this->meeting->meeturi, $after->meeturi);
        }
        $count = count($this->calls);
        $this->assertTrue($manager->synchronize((int) $meeting->id, $meeting->recordingsyncversion));
        $this->assertCount($count, $this->calls);
        $this->assertFalse(recording_manager::queue((int) $meeting->id));
    }

    /**
     * Read/rename error policy.
     * @return array
     */
    public static function failures(): array {
        return ['quota' => [429, 0, true], 'server' => [503, 0, true], 'timeout' => [0, 28, true],
            'forbidden' => [403, 0, false], 'unauthorized' => [401, 0, false], 'missing' => [404, 0, false]];
    }

    /**
     * Rename failures leave generated destinations intact and have an independent retry budget.
     * @param int $status Status
     * @param int $errno Transport code
     * @param bool $retry Retryable
     * @dataProvider failures
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function test_drive_retry_budget(int $status, int $errno, bool $retry): void {
        global $DB;
        $meeting = $this->discover();
        $record = $this->local();
        $manager = new rename_manager($this->drive);
        $limit = $retry ? 5 : 1;
        for ($i = 1; $i <= $limit; $i++) {
            $DB->set_field('tupmeet_recordings', 'renamenextattempt', 0, ['id' => $record->id]);
            $this->responses = [['status' => $status, 'errno' => $errno, 'body' => ['error' => 'DO_NOT_PERSIST_BODY']]];
            $this->assertSame($i === $limit, $manager->synchronize((int) $record->id, $record->renameversion));
            $this->assertEquals($i, $this->local()->renameattempts);
            $this->assertSame($i === $limit ? 'error' : 'pending', $this->local()->renamestatus);
            $this->assertSame('FILE_GENERATED', $this->local()->state);
            $this->assertSame($record->drivefileid, $this->local()->drivefileid);
            $this->assertSame($record->exporturi, $this->local()->exporturi);
            $this->assertStringNotContainsString('DO_NOT_PERSIST_BODY', json_encode($this->local()));
        }
        $this->assertEquals($meeting, $DB->get_record('tupmeet', ['id' => $meeting->id]));
    }

    /**
     * PATCH timeout converges via the next metadata GET, without a second PATCH.
     */
    public function test_lost_patch_response_converges_without_second_patch(): void {
        global $DB;
        $this->discover();
        $record = $this->local();
        $manager = new rename_manager($this->drive);
        $this->responses = [['body' => $this->metadata()], ['errno' => 28]];
        $this->assertFalse($manager->synchronize((int) $record->id, $record->renameversion));
        $DB->set_field('tupmeet_recordings', 'renamenextattempt', 0, ['id' => $record->id]);
        $this->responses = [['body' => $this->metadata($record->desiredfilename)]];
        $this->assertTrue($manager->synchronize((int) $record->id, $record->renameversion));
        $this->assertSame('ready', $this->local()->renamestatus);
        $this->assertCount(1, array_filter($this->calls, static fn($call) => $call[0] === 'PATCH'));
        $this->assertSame('Original.mp4', $this->local()->originalfilename);
    }

    /**
     * Wrong Drive metadata never causes a PATCH.
     * @param string $fault Mutation
     * @dataProvider drive_faults
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('drive_faults')]
    public function test_drive_validation_before_patch(string $fault): void {
        $this->discover();
        $record = $this->local();
        $file = $this->metadata();
        switch ($fault) {
            case 'mime':
                $file['mimeType'] = 'application/vnd.google-apps.folder';
                break;
            case 'trashed':
                $file['trashed'] = true;
                break;
            case 'id':
                $file['id'] = 'Other_file';
                break;
            case 'capability':
                $file['capabilities']['canRename'] = false;
                break;
        }
        $this->responses = [['body' => $file]];
        $this->assertTrue((new rename_manager($this->drive))->synchronize((int) $record->id, $record->renameversion));
        $this->assertSame($fault === 'capability' ? 'skipped' : 'error', $this->local()->renamestatus);
        $this->assertCount(0, array_filter($this->calls, static fn($call) => $call[0] === 'PATCH'));
    }

    /**
     * Drive metadata failures.
     * @return array
     */
    public static function drive_faults(): array {
        return [['mime'], ['trashed'], ['id'], ['capability']];
    }

    /**
     * A missing optional capability is accepted; returned parent/name drift cannot be marked successful.
     */
    public function test_patch_confirmation_rejects_parent_drift(): void {
        $this->discover();
        $record = $this->local();
        $before = $this->metadata();
        unset($before['capabilities']);
        $after = $this->metadata($record->desiredfilename);
        $after['parents'] = ['Unexpected_folder'];
        $this->responses = [['body' => $before], ['body' => $after]];
        $this->assertTrue((new rename_manager($this->drive))->synchronize((int) $record->id, $record->renameversion));
        $this->assertSame('error', $this->local()->renamestatus);
        $this->assertSame(['name' => $record->desiredfilename], json_decode(end($this->calls)[2], true));
    }

    /**
     * Stale queued discovery does not contact Google.
     */
    public function test_stale_revision_before_http(): void {
        $this->assertTrue((new recording_manager($this->service))->synchronize((int) $this->meeting->id, 'old'));
        $this->assertSame([], $this->calls);
    }

    /**
     * A concurrent revision change cannot be overwritten by the old response.
     */
    public function test_stale_revision_during_http(): void {
        global $DB;
        recording_manager::queue((int) $this->meeting->id);
        $meeting = $DB->get_record('tupmeet', ['id' => $this->meeting->id]);
        $this->responses = [['body' => ['conferenceRecords' => [$this->conference()]]], ['body' => []]];
        $this->hook = function () use ($DB) {
            $DB->set_field('tupmeet', 'recordingsyncversion', 'replacement', ['id' => $this->meeting->id]);
        };
        $this->assertTrue((new recording_manager($this->service))->synchronize((int) $meeting->id, $meeting->recordingsyncversion));
        $this->assertEquals(0, $DB->count_records('tupmeet_conferences'));
        $this->assertSame('replacement', $DB->get_field('tupmeet', 'recordingsyncversion', ['id' => $meeting->id]));
        // Also model invalidation after the caller's pre-check but before the short upsert transaction.
        $persist = new \ReflectionMethod(recording_manager::class, 'persist');
        $persist->invoke(new recording_manager($this->service), $meeting, [
            'conferencename' => 'conferenceRecords/Conf_1', 'starttime' => 1790000000,
            'endtime' => 1790003600, 'recordings' => [],
        ]);
        $this->assertEquals(0, $DB->count_records('tupmeet_conferences'));
    }

    /**
     * A deleted activity/recording cannot be patched by an in-flight metadata GET.
     */
    public function test_delete_during_drive_get_prevents_patch(): void {
        global $DB;
        $this->discover();
        $record = $this->local();
        $this->responses = [['body' => $this->metadata()]];
        $this->hook = function () use ($DB, $record) {
            $DB->delete_records('tupmeet_recordings', ['id' => $record->id]);
        };
        $this->assertTrue((new rename_manager($this->drive))->synchronize((int) $record->id, $record->renameversion));
        $this->assertCount(0, array_filter($this->calls, static fn($call) => $call[0] === 'PATCH'));
    }

    /**
     * Historical canonical Calendar activities are read-only; legacy and meeting-code aliases are ineligible.
     */
    public function test_historical_eligibility(): void {
        global $DB;
        $DB->set_field('tupmeet', 'provisionmode', 'calendar', ['id' => $this->meeting->id]);
        $this->discover();
        $this->assertSame('skipped', $this->local()->renamestatus);
        $this->assertNull($this->local()->desiredfilename);
        $this->assertFalse(rename_manager::queue((int) $this->local()->id, true));
        foreach (['legacy', 'meet'] as $mode) {
            $activity = clone $this->meeting;
            $activity->provisionmode = $mode;
            $activity->meetspacename = 'spaces/abc-defg-hij';
            $this->assertFalse(recording_manager::eligible($activity));
        }
        $activity->meetspacename = 'spaces/Canonical_1';
        $activity->spacestatus = 'uncertain';
        $this->assertFalse(recording_manager::eligible($activity));
    }

    /**
     * Scheduler selects only a bounded due batch and deduplicates pending work, without HTTP.
     */
    public function test_scheduler_batch_dedupe_and_manual_throttle(): void {
        global $DB;
        for ($i = 0; $i < 30; $i++) {
            $row = clone $this->meeting;
            unset($row->id);
            $DB->insert_record('tupmeet', $row);
        }
        $this->assertEquals(25, recording_manager::dispatch());
        $this->assertEquals(6, recording_manager::dispatch());
        $this->assertEquals(0, recording_manager::dispatch());
        $this->assertEquals(31, $DB->count_records('task_adhoc', ['classname' => '\mod_tupmeet\task\sync_recordings']));
        $this->assertFalse(recording_manager::queue((int) $this->meeting->id, true));
        $this->assertSame([], $this->calls);
    }

    /**
     * Lock contention cannot renew revisions or perform network calls.
     */
    public function test_lock_contention(): void {
        global $CFG;
        // MariaDB GET_LOCK is reentrant on one connection; exercise contention through Moodle's row backend.
        $CFG->lock_factory = '\core\lock\db_record_lock_factory';
        $lock = \core\lock\lock_config::get_lock_factory('mod_tupmeet')->get_lock('meeting:' . $this->meeting->id, 0);
        try {
            $this->assertFalse(recording_manager::queue((int) $this->meeting->id, true));
            $this->assertFalse((new recording_manager($this->service))->synchronize((int) $this->meeting->id, 'idle'));
            $this->assertSame([], $this->calls);
        } finally {
            $lock->release();
        }
    }

    /**
     * The worker rejects ambient transactions before native OAuth or HTTP.
     */
    public function test_http_never_runs_inside_transaction(): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            (new recording_manager($this->service))->synchronize((int) $this->meeting->id, 'idle');
            $this->fail('Transaction accepted');
        } catch (\coding_exception $e) {
            $this->assertSame([], $this->calls);
        } finally {
            $transaction->allow_commit();
        }
    }

    /**
     * Local deletion removes normalized children and does not contact Google or change the owner.
     */
    public function test_local_delete_removes_children_only(): void {
        global $DB;
        $this->discover();
        $count = count($this->calls);
        $this->assertTrue(tupmeet_delete_instance($this->meeting->id));
        $this->assertEquals(0, $DB->count_records('tupmeet_recordings'));
        $this->assertEquals(0, $DB->count_records('tupmeet_conferences'));
        $this->assertTrue($DB->record_exists('tupmeet_accounts', ['id' => $this->owner->id]));
        $this->assertTrue((new recording_manager($this->service))->synchronize((int) $this->meeting->id, 'obsolete'));
        $this->assertCount($count, $this->calls);
    }

    /**
     * Nanoseconds and explicit offsets preserve chronological part order even inside one second.
     */
    public function test_fractional_start_order(): void {
        $this->discover([
            $this->recording('Alpha_later', 'FILE_GENERATED', '2026-09-19T22:04:00.900000001Z'),
            $this->recording('Zulu_first', 'FILE_GENERATED', '2026-09-19T17:04:00.100000002-05:00'),
        ]);
        $this->assertEquals(100000002, $this->local('Zulu_first')->startnanos);
        $this->assertStringEndsWith('17-03.mp4', $this->local('Zulu_first')->desiredfilename);
        $this->assertStringEndsWith('17-03 - Parte 2.mp4', $this->local('Alpha_later')->desiredfilename);
    }

    /**
     * Scheduled recovery reuses the same revision and does not reset an interrupted worker's budget.
     */
    public function test_interrupted_discovery_is_recovered_without_budget_reset(): void {
        global $DB;
        recording_manager::queue((int) $this->meeting->id);
        $record = $DB->get_record('tupmeet', ['id' => $this->meeting->id]);
        $DB->update_record('tupmeet', (object) ['id' => $record->id, 'recordingsyncstatus' => 'syncing',
            'recordingsyncattempts' => 3, 'recordingsnextsync' => time() - 1, 'recordingsyncqueued' => time() - 1900]);
        $DB->delete_records('task_adhoc', ['classname' => '\mod_tupmeet\task\sync_recordings']);
        $this->assertEquals(1, recording_manager::dispatch());
        $after = $DB->get_record('tupmeet', ['id' => $record->id]);
        $this->assertSame($record->recordingsyncversion, $after->recordingsyncversion);
        $this->assertEquals(3, $after->recordingsyncattempts);
        $this->responses = [['body' => []]];
        $manager = new recording_manager($this->service);
        $this->assertTrue($manager->synchronize((int) $record->id, $record->recordingsyncversion));
        $this->assertEquals(4, $DB->get_field('tupmeet', 'recordingsyncattempts', ['id' => $record->id]));
        $this->assertSame('ready', $DB->get_field('tupmeet', 'recordingsyncstatus', ['id' => $record->id]));
    }

    /**
     * Neither worker spends another HTTP attempt before its recorded backoff expires.
     */
    public function test_not_before_prevents_early_http(): void {
        global $DB;
        $this->discover();
        $record = $this->local();
        $DB->set_field('tupmeet_recordings', 'renamenextattempt', time() + 3600, ['id' => $record->id]);
        $count = count($this->calls);
        $this->assertFalse((new rename_manager($this->drive))->synchronize((int) $record->id, $record->renameversion));
        $DB->update_record('tupmeet', (object) ['id' => $this->meeting->id, 'recordingsyncstatus' => 'pending',
            'recordingsyncattempts' => 1, 'recordingsnextsync' => time() + 3600]);
        $meeting = $DB->get_record('tupmeet', ['id' => $this->meeting->id]);
        $this->assertFalse(
            (new recording_manager($this->service))->synchronize((int) $meeting->id, $meeting->recordingsyncversion)
        );
        $this->assertCount($count, $this->calls);
    }

    /**
     * Rename recovery remains independent when discovery is dormant, and a stale rename cannot spend budget.
     */
    public function test_rename_recovery_and_stale_revision(): void {
        global $DB;
        $this->discover();
        $record = $this->local();
        $DB->delete_records('task_adhoc', ['classname' => '\mod_tupmeet\task\rename_recording']);
        $DB->update_record('tupmeet_recordings', (object) ['id' => $record->id, 'renamequeued' => time() - 1900,
            'renameattempts' => 2]);
        $DB->set_field('tupmeet', 'recordingsnextsync', 0, ['id' => $this->meeting->id]);
        $this->assertEquals(0, recording_manager::dispatch());
        $this->assertEquals(1, $DB->count_records('task_adhoc', ['classname' => '\mod_tupmeet\task\rename_recording']));
        $this->assertSame($record->renameversion, $this->local()->renameversion);
        $this->assertEquals(2, $this->local()->renameattempts);
        $count = count($this->calls);
        $this->assertTrue((new rename_manager($this->drive))->synchronize((int) $record->id, 'obsolete'));
        $this->assertCount($count, $this->calls);
    }

    /**
     * Unknown states discovered later cannot corrupt an already valid local recording.
     */
    public function test_unknown_state_keeps_existing_generated_recording(): void {
        $this->discover();
        $before = $this->local();
        $meeting = $this->discover([$this->recording('Rec_1', 'UNKNOWN_FUTURE_STATE')]);
        $this->assertSame('error', $meeting->recordingsyncstatus);
        $this->assertEquals($before, $this->local());
    }


    /**
     * Malformed success bodies are errors, not successful empty collections.
     * @param string $body Invalid body
     * @dataProvider malformed_bodies
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformed_bodies')]
    public function test_malformed_success_body(string $body): void {
        $this->responses = [['raw' => $body]];
        $this->expectException(recording_exception::class);
        iterator_to_array($this->service->discover($this->owner, 'spaces/Canonical_1'));
    }

    /**
     * Non-object and truncated responses.
     * @return array
     */
    public static function malformed_bodies(): array {
        return [['[]'], ['null'], ['{"conferenceRecords":'], ['"unexpected"']];
    }


    /**
     * A recurring Space retains separate conferences and filenames across actual session dates.
     */
    public function test_recurrence_persists_multiple_conferences(): void {
        global $DB;
        $DB->update_record('tupmeet', (object) ['id' => $this->meeting->id, 'isrecurring' => 1,
            'recurrencedays' => '["sat"]', 'recurrenceinterval' => 1, 'recurrenceuntil' => strtotime('2026-10-03T05:00:00Z')]);
        $second = $this->conference('Conf_2');
        $second['startTime'] = '2026-09-26T22:03:00Z';
        $second['endTime'] = '2026-09-26T23:04:00Z';
        $recording = $this->recording('Second_session');
        $recording['name'] = 'conferenceRecords/Conf_2/recordings/Second_session';
        $recording['startTime'] = '2026-09-26T22:04:00Z';
        $recording['endTime'] = '2026-09-26T23:00:00Z';
        $this->assertTrue(recording_manager::queue((int) $this->meeting->id));
        $meeting = $DB->get_record('tupmeet', ['id' => $this->meeting->id]);
        $this->responses = [
            ['body' => ['conferenceRecords' => [$this->conference(), $second]]],
            ['body' => ['recordings' => [$this->recording()]]],
            ['body' => ['recordings' => [$recording]]],
        ];
        $manager = new recording_manager($this->service);
        $this->assertTrue($manager->synchronize((int) $meeting->id, $meeting->recordingsyncversion));
        $this->assertEquals(2, $DB->count_records('tupmeet_conferences'));
        $records = $DB->get_records('tupmeet_recordings', ['tupmeetid' => $meeting->id], 'starttime');
        $this->assertCount(2, $records);
        $this->assertStringContainsString('2026-09-19 - 17-03', reset($records)->desiredfilename);
        $this->assertStringContainsString('2026-09-26 - 17-03', end($records)->desiredfilename);
        $this->assertSame($meeting->meetspacename, $DB->get_field('tupmeet', 'meetspacename', ['id' => $meeting->id]));
    }
}
