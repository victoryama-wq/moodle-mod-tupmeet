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
use mod_tupmeet\local\google\meet_service;
use mod_tupmeet\local\meeting\meeting_manager;
use mod_tupmeet\local\meeting\meet_config_manager;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/fixtures/issuer.php');
require_once(__DIR__ . '/../lib.php');

/**
 * Real persistence, services, OAuth identity checks and native HTTP options with synthetic remote responses.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(meet_service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(meet_config_manager::class)]
final class meet_config_test extends \advanced_testcase {
    /** @var account_manager Real account manager. */
    private account_manager $accounts;
    /** @var meeting_manager Real Calendar coordinator. */
    private meeting_manager $meetings;
    /** @var meet_config_manager Real Meet coordinator. */
    private meet_config_manager $config;
    /** @var int Institutional owner. */
    private int $owner;
    /** @var array Synthetic Calendar events. */
    private array $events = [];
    /** @var array Synthetic Space. */
    private array $space;
    /** @var array Observed Meet HTTP calls. */
    private array $calls = [];
    /** @var int Calendar insert count. */
    private int $creates = 0;
    /** @var int Calendar HTTP count. */
    private int $calendarcalls = 0;
    /** @var int Last native HTTP status. */
    private int $httpstatus = 200;
    /** @var int Meet error status, if any. */
    private int $failure = 0;
    /** @var bool Simulate a lost PATCH response after a successful remote write. */
    private bool $timeout = false;
    /** @var bool Return a malformed success body. */
    private bool $malformed = false;
    /** @var bool Ignore the requested remote write. */
    private bool $ignorepatch = false;
    /** @var \Closure|null Callback after GET or PATCH to simulate concurrency/crashes. */
    private ?\Closure $duringhttp = null;

    /**
     * Stub native client acquisition and HTTP only. All plugin service/state methods remain real.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $this->space = [
            'name' => 'spaces/Stable_space-1', 'meetingCode' => 'abc-defg-hij',
            'meetingUri' => 'https://meet.google.com/abc-defg-hij',
            'config' => [
                'accessType' => 'TRUSTED', 'moderation' => 'ON', 'entryPointAccess' => 'ALL',
                'artifactConfig' => [
                    'recordingConfig' => ['autoRecordingGeneration' => 'OFF'],
                    'transcriptionConfig' => ['autoTranscriptionGeneration' => 'OFF'],
                    'smartNotesConfig' => ['autoSmartNotesGeneration' => 'ON'],
                ],
            ],
        ];
        $oauth = $this->getMockBuilder(oauth_client_factory::class)->onlyMethods(['get_system_client'])->getMock();
        $oauth->method('get_system_client')->willReturnCallback(function (\core\oauth2\issuer $issuer) {
            $client = $this->getMockBuilder(client::class)->disableOriginalConstructor()
                ->onlyMethods(['get', 'post', 'setHeader', 'get_info', 'get_raw_userinfo'])->getMock();
            $client->method('get_raw_userinfo')->willReturn((object) [
                'sub' => 'owner-' . $issuer->get('id'), 'email' => 'owner' . $issuer->get('id') . '@example.invalid',
                'email_verified' => true, 'hd' => 'example.invalid',
            ]);
            $client->method('get')->willReturnCallback(function ($url, $params, $options) use ($issuer) {
                return $this->http((int) $issuer->get('id'), 'GET', $url, null, $options);
            });
            $client->method('post')->willReturnCallback(function ($url, $json, $options) use ($issuer) {
                return $this->http(
                    (int) $issuer->get('id'),
                    $options['CURLOPT_CUSTOMREQUEST'],
                    $url,
                    json_decode($json, true, 512, JSON_THROW_ON_ERROR),
                    $options
                );
            });
            $client->method('get_info')->willReturnCallback(fn() => ['http_code' => $this->httpstatus]);
            return $client;
        });
        $this->accounts = new account_manager($oauth);
        $this->meetings = new meeting_manager(new calendar_service($oauth));
        $this->config = new meet_config_manager(new meet_service($oauth));
        $this->owner = $this->account();
        $this->accounts->set_default($this->owner);
    }

    /**
     * Register an isolated institutional fixture with real identity comparison.
     *
     * @return int Account ID
     */
    private function account(): int {
        $issuer = \mod_tupmeet\testing\issuer::create();
        $id = $this->accounts->register('Synthetic owner', (int) $issuer->get('id'));
        $this->accounts->verify($id);
        return $id;
    }

    /**
     * Synthetic provider, observing the actual native client HTTP interface.
     *
     * @param int $issuerid Owner issuer
     * @param string $method HTTP verb
     * @param string $url URL
     * @param array|null $payload JSON body
     * @param array $options Native curl options
     * @return string Response body
     */
    private function http(int $issuerid, string $method, string $url, ?array $payload, array $options): string {
        global $DB;
        $this->assertFalse($DB->is_transaction_started(), 'No remote request inside a DB transaction');
        $this->httpstatus = 200;
        if (str_starts_with($url, 'https://www.googleapis.com/calendar/v3/')) {
            $this->calendarcalls++;
            $id = $method === 'POST' ? $payload['id'] : basename(parse_url($url, PHP_URL_PATH));
            if ($method === 'GET') {
                $this->httpstatus = isset($this->events[$id]) ? 200 : 404;
                return json_encode($this->events[$id] ?? []);
            }
            $this->creates += (int) ($method === 'POST');
            $this->events[$id] = array_replace($this->events[$id] ?? [], $payload, [
                'id' => $id, 'conferenceData' => ['entryPoints' => [
                    ['entryPointType' => 'video', 'uri' => $this->space['meetingUri']],
                ]],
            ]);
            return json_encode($this->events[$id]);
        }
        $this->assertStringStartsWith('https://meet.googleapis.com/v2/spaces/', $url);
        $this->assertContains($method, ['GET', 'PATCH'], 'No spaces.create or other Meet operations');
        $this->calls[] = [$issuerid, $method, $url, $payload, $options];
        if ($this->failure) {
            $this->httpstatus = $this->failure;
            return '{"error":{"message":"synthetic confidential provider detail"}}';
        }
        if ($this->malformed) {
            return 'not-json synthetic confidential detail';
        }
        if ($method === 'PATCH' && !$this->ignorepatch) {
            $this->space['config']['artifactConfig'] = array_replace(
                $this->space['config']['artifactConfig'],
                $payload['config']['artifactConfig']
            );
        }
        $response = json_encode($this->space);
        if ($this->duringhttp) {
            ($this->duringhttp)($method);
        }
        if ($method === 'PATCH' && $this->timeout) {
            throw new \RuntimeException('synthetic confidential timeout');
        }
        return $response;
    }

    /**
     * Create a real activity/Calendar event with only provider HTTP substituted.
     *
     * @param int $record Recording preference
     * @param int $transcript Transcription preference
     * @param bool $recurring Weekly series
     * @return int Activity ID
     */
    private function meeting(int $record = 1, int $transcript = 0, bool $recurring = false): int {
        $start = (new \DateTimeImmutable('2026-10-10T09:00:00-05:00'))->getTimestamp();
        $id = $this->meetings->create((object) [
            'name' => 'Synthetic meeting', 'startdatetime' => $start, 'enddatetime' => $start + HOURSECS,
            'autorecord' => $record, 'autotranscript' => $transcript,
            'isrecurring' => (int) $recurring, 'recurrenceinterval' => 1,
            'recurrencedays' => '["sat"]', 'recurrenceuntil' => $start + 4 * WEEKSECS,
        ]);
        $this->assertTrue($this->meetings->synchronize($id));
        return $id;
    }

    /**
     * Read persisted state.
     *
     * @param int $id Activity ID
     * @return \stdClass
     */
    private function record(int $id): \stdClass {
        global $DB;
        return $DB->get_record('tupmeet', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Every ON/OFF combination is explicitly applied with only the two managed leaf fields.
     *
     * @dataProvider preferences_provider
     * @param int $record Desired recording
     * @param int $transcript Desired transcription
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('preferences_provider')]
    public function test_preference_combinations(int $record, int $transcript): void {
        $id = $this->meeting($record, $transcript);
        // Opposite remote values force both ON and OFF writes to be exercised.
        $this->space['config']['artifactConfig']['recordingConfig']['autoRecordingGeneration'] = $record ? 'OFF' : 'ON';
        $this->space['config']['artifactConfig']['transcriptionConfig']['autoTranscriptionGeneration'] = $transcript ? 'OFF' : 'ON';
        $before = $this->space['config'];
        $this->assertTrue($this->config->synchronize($id));
        $saved = $this->record($id);
        $this->assertSame('spaces/Stable_space-1', $saved->meetspacename);
        $this->assertSame('ready', $saved->meetconfigstatus);
        $this->assertGreaterThan(0, (int) $saved->meetconfigmodified);
        $this->assertCount(2, $this->calls);
        $this->assertSame('https://meet.googleapis.com/v2/spaces/abc-defg-hij', $this->calls[0][2]);
        $patch = $this->calls[1];
        $mask = 'config.artifactConfig.recordingConfig.autoRecordingGeneration,' .
            'config.artifactConfig.transcriptionConfig.autoTranscriptionGeneration';
        $this->assertSame('https://meet.googleapis.com/v2/spaces/Stable_space-1?updateMask=' . rawurlencode($mask), $patch[2]);
        $this->assertSame([
            'name' => 'spaces/Stable_space-1', 'config' => ['artifactConfig' => [
                'recordingConfig' => ['autoRecordingGeneration' => $record ? 'ON' : 'OFF'],
                'transcriptionConfig' => ['autoTranscriptionGeneration' => $transcript ? 'ON' : 'OFF'],
            ]],
        ], $patch[3]);
        $this->assertSame(15, $patch[4]['CURLOPT_TIMEOUT']);
        $this->assertSame(5, $patch[4]['CURLOPT_CONNECTTIMEOUT']);
        $this->assertFalse($patch[4]['CURLOPT_FOLLOWLOCATION']);
        foreach (['accessType', 'moderation', 'entryPointAccess'] as $field) {
            $this->assertSame($before[$field], $this->space['config'][$field]);
        }
        $this->assertSame(
            $before['artifactConfig']['smartNotesConfig'],
            $this->space['config']['artifactConfig']['smartNotesConfig']
        );
    }

    /**
     * All recording/transcription combinations.
     *
     * @return array Cases
     */
    public static function preferences_provider(): array {
        return [[1, 0], [0, 1], [1, 1], [0, 0]];
    }

    /**
     * Confirming both OFF still resolves the Space, but needs no PATCH when already matched.
     */
    public function test_matching_configuration_skips_patch_and_duplicate_work(): void {
        global $DB;
        $id = $this->meeting(0, 0);
        meet_config_manager::queue($this->record($id));
        meet_config_manager::queue($this->record($id));
        $this->assertEquals(1, $DB->count_records('task_adhoc', ['classname' => '\\mod_tupmeet\\task\\sync_meet_config']));
        $this->assertTrue($this->config->synchronize($id));
        $this->assertTrue($this->config->synchronize($id));
        $this->assertCount(1, $this->calls);
        $this->assertSame('GET', $this->calls[0][1]);
        $this->assertSame('ready', $this->record($id)->meetconfigstatus);
    }

    /**
     * A retry after remote success and transport failure reads the persisted name and avoids another PATCH.
     */
    public function test_timeout_retry_uses_persisted_space(): void {
        $id = $this->meeting(1, 1);
        $this->timeout = true;
        $this->assertFalse($this->config->synchronize($id));
        $saved = $this->record($id);
        $this->assertSame('spaces/Stable_space-1', $saved->meetspacename);
        $this->assertSame('error', $saved->meetconfigstatus);
        $this->assertSame('ready', $saved->syncstatus);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $saved->meeturi);
        $this->timeout = false;
        $this->assertTrue($this->config->synchronize($id));
        $this->assertCount(3, $this->calls);
        $this->assertSame('https://meet.googleapis.com/v2/spaces/Stable_space-1', $this->calls[2][2]);
    }

    /**
     * Every provider status preserves Calendar availability, including missing entitlement and rate limiting.
     *
     * @dataProvider errors_provider
     * @param int $status HTTP error
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('errors_provider')]
    public function test_provider_failures_preserve_calendar(int $status): void {
        $id = $this->meeting();
        $before = $this->record($id);
        $this->failure = $status;
        $this->assertFalse($this->config->synchronize($id));
        $after = $this->record($id);
        foreach (['accountid', 'calendareventid', 'meeturi', 'meetingcode', 'syncstatus', 'syncversion'] as $field) {
            $this->assertSame($before->{$field}, $after->{$field}, $field);
        }
        $this->assertSame('error', $after->meetconfigstatus);
    }

    /**
     * Authorization, feature/policy rejection, absent Space and transient failures.
     *
     * @return array HTTP statuses
     */
    public static function errors_provider(): array {
        return [[401], [403], [400], [404], [429], [503]];
    }

    /**
     * Broken JSON and unconfirmed settings are failures, never false success.
     */
    public function test_malformed_and_unconfirmed_response(): void {
        $id = $this->meeting();
        $this->malformed = true;
        $this->assertFalse($this->config->synchronize($id));
        $this->malformed = false;
        $this->ignorepatch = true;
        $this->assertFalse($this->config->synchronize($id));
        $this->assertSame('error', $this->record($id)->meetconfigstatus);
    }

    /**
     * A canonical resource cannot switch to another space and cannot inject a URL/path.
     *
     * @dataProvider invalid_names_provider
     * @param string $name Unsafe or malformed resource
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_names_provider')]
    public function test_invalid_space_name_is_rejected(string $name): void {
        $id = $this->meeting();
        $this->space['name'] = $name;
        $this->assertFalse($this->config->synchronize($id));
        $this->assertCount(1, $this->calls);
        $this->assertNull($this->record($id)->meetspacename);
    }

    /**
     * Resource validation attacks.
     *
     * @return array Invalid names
     */
    public static function invalid_names_provider(): array {
        return [['https://evil.invalid/spaces/x'], ['spaces/../x'], ['spaces/x?key=value'], ["spaces/x\n"], ['spaces/']];
    }

    /**
     * Rebinding a known resource or resolving a code to a different join identity is rejected.
     */
    public function test_space_identity_mismatch(): void {
        $id = $this->meeting(0, 0);
        $this->space['meetingCode'] = 'zzz-yyyy-xxx';
        $this->assertFalse($this->config->synchronize($id));
        $this->space['meetingCode'] = 'abc-defg-hij';
        $this->assertTrue($this->config->synchronize($id));
        $this->meetings->update((object) ['id' => $id, 'autorecord' => 1]);
        $this->space['name'] = 'spaces/Another';
        $this->assertFalse($this->config->synchronize($id));
        $this->assertSame('spaces/Stable_space-1', $this->record($id)->meetspacename);
    }

    /**
     * Edits of preferences preserve all identities and do not send Calendar HTTP.
     */
    public function test_preference_edit_and_historical_disabled_owner(): void {
        $id = $this->meeting(1, 0);
        $this->assertTrue($this->config->synchronize($id));
        $before = $this->record($id);
        $calendarcalls = $this->calendarcalls;
        $other = $this->account();
        $this->accounts->set_default($other);
        $this->accounts->set_enabled($this->owner, false);
        $this->meetings->update((object) [
            'id' => $id, 'autorecord' => 0, 'autotranscript' => 1, 'accountid' => $other, 'meetspacename' => 'spaces/Foreign',
        ]);
        $this->assertTrue($this->meetings->synchronize($id));
        $this->assertTrue($this->config->synchronize($id));
        $this->assertSame($calendarcalls, $this->calendarcalls);
        $this->assertSame(1, $this->creates);
        $after = $this->record($id);
        $identifiers = ['accountid', 'calendareventid', 'meeturi', 'meetingcode', 'meetspacename', 'syncversion', 'syncstatus'];
        foreach ($identifiers as $field) {
            $this->assertSame($before->{$field}, $after->{$field}, $field);
        }
        $this->assertSame((int) $this->accounts->get_account($this->owner)->issuerid, end($this->calls)[0]);
        $this->assertSame('OFF', end($this->calls)[3]['config']['artifactConfig']['recordingConfig']['autoRecordingGeneration']);
        $artifacts = end($this->calls)[3]['config']['artifactConfig'];
        $this->assertSame('ON', $artifacts['transcriptionConfig']['autoTranscriptionGeneration']);
    }

    /**
     * A recurring series and its later schedule edits reuse the same configured space.
     */
    public function test_recurring_series_retains_space_configuration(): void {
        $id = $this->meeting(1, 1, true);
        $this->assertTrue($this->config->synchronize($id));
        $before = $this->record($id);
        $this->meetings->update((object) ['id' => $id, 'recurrenceinterval' => 2]);
        $this->assertTrue($this->meetings->synchronize($id));
        $this->assertTrue($this->config->synchronize($id));
        $this->assertSame($before->meetspacename, $this->record($id)->meetspacename);
        $this->assertSame($before->calendareventid, $this->record($id)->calendareventid);
        $this->assertSame($before->meeturi, $this->record($id)->meeturi);
        $this->assertSame(1, $this->creates);
        $this->assertCount(3, $this->calls, 'One initial GET/PATCH, then GET of the unchanged Space');
        $this->assertStringContainsString('INTERVAL=2', $this->events[$before->calendareventid]['recurrence'][0]);
    }

    /**
     * Five failed attempts exhaust the revision; a manual retry creates new desired state, not new spaces.
     */
    public function test_bounded_retries_and_stale_tasks(): void {
        global $DB;
        $id = $this->meeting();
        $oldversion = $this->record($id)->meetconfigversion;
        $tasks = $DB->count_records('task_adhoc');
        $this->failure = 403;
        for ($attempt = 1; $attempt <= meet_config_manager::MAX_ATTEMPTS; $attempt++) {
            $this->assertSame($attempt === meet_config_manager::MAX_ATTEMPTS, $this->config->synchronize($id, $oldversion));
        }
        $this->assertTrue($this->config->synchronize($id, $oldversion));
        $this->assertCount(5, $this->calls);
        $this->assertEquals($tasks, $DB->count_records('task_adhoc'), 'Retries do not create additional tasks');
        $this->meetings->update((object) ['id' => $id]);
        $this->assertTrue($this->config->synchronize($id, $oldversion));
        $this->assertCount(5, $this->calls, 'Stale task must not send HTTP');
        $this->failure = 0;
        $this->assertTrue($this->config->synchronize($id));
        $this->assertEquals(1, $this->record($id)->meetconfigattempts);
        $this->assertSame('ready', $this->record($id)->meetconfigstatus);
    }

    /**
     * An edit in flight cannot be marked ready by the old PATCH response; new work converges to OFF.
     */
    public function test_edit_during_patch_retains_pending_revision(): void {
        $id = $this->meeting(1, 0);
        $this->duringhttp = function ($method) use ($id) {
            if ($method === 'PATCH') {
                $this->duringhttp = null;
                $this->meetings->update((object) ['id' => $id, 'autorecord' => 0]);
            }
        };
        $this->assertTrue($this->config->synchronize($id));
        $this->assertSame('pending', $this->record($id)->meetconfigstatus);
        $this->assertSame('spaces/Stable_space-1', $this->record($id)->meetspacename);
        $this->assertTrue($this->config->synchronize($id));
        $this->assertSame('OFF', $this->space['config']['artifactConfig']['recordingConfig']['autoRecordingGeneration']);
    }

    /**
     * Resolve-before-PATCH detects edits during GET and does not apply stale settings.
     */
    public function test_edit_during_resolution_skips_stale_patch(): void {
        $id = $this->meeting();
        $this->duringhttp = function ($method) use ($id) {
            $this->duringhttp = null;
            $this->meetings->update((object) ['id' => $id, 'autotranscript' => 1]);
        };
        $this->assertTrue($this->config->synchronize($id));
        $this->assertCount(1, $this->calls);
        $this->assertSame('pending', $this->record($id)->meetconfigstatus);
        $this->assertTrue($this->config->synchronize($id));
    }

    /**
     * Pending Calendar, untouched historical rows and deleted activities never contact Meet.
     */
    public function test_unavailable_unconfigured_and_deleted_are_noops(): void {
        global $DB;
        $id = $this->meeting();
        $DB->set_field('tupmeet', 'syncstatus', 'pending', ['id' => $id]);
        $this->assertTrue($this->config->synchronize($id));
        $DB->set_field('tupmeet', 'syncstatus', 'ready', ['id' => $id]);
        $DB->set_field('tupmeet', 'meetconfigstatus', 'unconfigured', ['id' => $id]);
        $this->assertTrue($this->config->synchronize($id));
        tupmeet_delete_instance($id);
        $this->assertTrue($this->config->synchronize($id));
        $this->assertSame([], $this->calls);
    }

    /**
     * Explicitly reject calls within a surrounding Moodle transaction.
     */
    public function test_open_transaction_is_rejected(): void {
        global $DB;
        $id = $this->meeting();
        $transaction = $DB->start_delegated_transaction();
        try {
            $this->config->synchronize($id);
            $this->fail('Expected transaction guard');
        } catch (\coding_exception $e) {
            $this->assertSame([], $this->calls);
        } finally {
            $transaction->allow_commit();
        }
    }

    /**
     * Permanent identity remains usable without consulting a stale meeting code.
     */
    public function test_permanent_name_does_not_depend_on_code(): void {
        global $DB;
        $id = $this->meeting();
        $this->assertTrue($this->config->synchronize($id));
        $DB->set_field('tupmeet', 'meetingcode', 'expired-alias', ['id' => $id]);
        $this->meetings->update((object) ['id' => $id, 'autorecord' => 0]);
        $this->assertTrue($this->config->synchronize($id));
        $this->assertSame('https://meet.googleapis.com/v2/spaces/Stable_space-1', $this->calls[2][2]);
        $DB->set_field('tupmeet', 'meetspacename', 'spaces/../unsafe', ['id' => $id]);
        $this->meetings->update((object) ['id' => $id]);
        $calls = count($this->calls);
        $this->assertFalse($this->config->synchronize($id));
        $this->assertCount($calls, $this->calls);
    }

    /**
     * Missing remote values do not prove OFF; the explicit OFF patch must be acknowledged.
     */
    public function test_absent_settings_are_explicitly_patched_off(): void {
        $id = $this->meeting(0, 0);
        $this->space['config']['artifactConfig'] = [];
        $this->assertTrue($this->config->synchronize($id));
        $this->assertCount(2, $this->calls);
        $this->assertSame('OFF', $this->space['config']['artifactConfig']['recordingConfig']['autoRecordingGeneration']);
    }

    /**
     * A failed local confirmation after remote PATCH is recovered without repeating the remote write.
     */
    public function test_local_write_failure_after_remote_patch(): void {
        global $DB;
        $id = $this->meeting();
        $table = new \xmldb_table('tupmeet');
        $field = new \xmldb_field('meetconfigmodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $this->duringhttp = function ($method) use ($DB, $table, $field) {
            if ($method === 'PATCH') {
                $this->duringhttp = null;
                $DB->get_manager()->drop_field($table, $field);
            }
        };
        try {
            $this->assertFalse($this->config->synchronize($id));
        } finally {
            $DB->get_manager()->add_field($table, $field);
        }
        $this->assertSame('spaces/Stable_space-1', $this->record($id)->meetspacename);
        $this->assertTrue($this->config->synchronize($id));
        $this->assertCount(3, $this->calls);
        $this->assertSame('GET', end($this->calls)[1]);
    }

    /**
     * Native task execution safely retires obsolete work without acquiring any OAuth client.
     */
    public function test_stale_native_task_and_rollback(): void {
        global $DB;
        $id = $this->meeting();
        $before = $this->record($id);
        $tasks = $DB->count_records('task_adhoc');
        $transaction = $DB->start_delegated_transaction();
        $this->meetings->update((object) ['id' => $id, 'autorecord' => 0]);
        try {
            $transaction->rollback(new \moodle_exception('invalidrequest', 'mod_tupmeet'));
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidrequest', $e->errorcode);
        }
        $this->assertSame($before->meetconfigversion, $this->record($id)->meetconfigversion);
        $this->assertEquals($tasks, $DB->count_records('task_adhoc'));
        $task = new \mod_tupmeet\task\sync_meet_config();
        $task->set_custom_data(['id' => $id, 'version' => 'obsolete']);
        $task->execute();
        $this->assertSame([], $this->calls);
    }

    /**
     * The service itself sanitizes HTTP errors before any caller can log exception details.
     */
    public function test_native_http_error_is_sanitized(): void {
        $client = $this->getMockBuilder(client::class)->disableOriginalConstructor()->onlyMethods(['get', 'get_info'])->getMock();
        $client->method('get')->willReturn('synthetic confidential response');
        $client->method('get_info')->willReturn(['http_code' => 403]);
        try {
            (new meet_service())->get_space($client, (object) ['meetspacename' => 'spaces/Known']);
            $this->fail('Expected safe failure');
        } catch (\moodle_exception $e) {
            $this->assertSame('meetconfigfailed', $e->errorcode);
            $this->assertStringNotContainsString('synthetic confidential', $e->getMessage());
            $this->assertEmpty($e->debuginfo);
            $this->assertNull($e->getPrevious());
        }
    }
}
