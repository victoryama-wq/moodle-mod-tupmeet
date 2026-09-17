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
use mod_tupmeet\local\google\member_service;
use mod_tupmeet\local\meeting\cohost_identity;
use mod_tupmeet\local\meeting\cohost_manager;
use mod_tupmeet\local\meeting\meeting_manager;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/fixtures/issuer.php');
require_once(__DIR__ . '/../lib.php');

/**
 * Real cohost persistence and services with only native OAuth acquisition/HTTP simulated.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(cohost_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(member_service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(cohost_identity::class)]
final class cohost_test extends \advanced_testcase {
    /** @var \stdClass Active course teacher. */
    private \stdClass $teacher;
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var account_manager Real institutional identities. */
    private account_manager $accounts;
    /** @var int Original owner. */
    private int $owner;
    /** @var cohost_manager Real worker. */
    private cohost_manager $manager;
    /** @var member_service Real Google service. */
    private member_service $service;
    /** @var array Fake remote members. */
    private array $members = [];
    /** @var array HTTP observations. */
    private array $calls = [];
    /** @var int Native HTTP response status. */
    private int $status = 200;
    /** @var int Injected remote failure. */
    private int $failure = 0;
    /** @var bool Lost response after remote creation. */
    private bool $timeout = false;
    /** @var bool Conflict after concurrent remote creation. */
    private bool $conflict = false;
    /** @var bool Paginated responses. */
    private bool $paginate = false;
    /** @var bool Repeated page token. */
    private bool $cycle = false;
    /** @var bool Broken success response. */
    private bool $malformed = false;
    /** @var array|null Invalid successful POST response. */
    private ?array $postresponse = null;
    /** @var \Closure|null Concurrent edit/fault after an HTTP request. */
    private ?\Closure $duringhttp = null;

    /**
     * Prepare real local identities, enrolments and ownership.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_user(['email' => 'teacher@example.invalid']);
        $this->getDataGenerator()->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $oauth = $this->getMockBuilder(oauth_client_factory::class)->onlyMethods(['get_system_client'])->getMock();
        $oauth->method('get_system_client')->willReturnCallback(function (\core\oauth2\issuer $issuer) {
            $client = $this->getMockBuilder(client::class)->disableOriginalConstructor()
                ->onlyMethods(['get', 'post', 'setHeader', 'get_info', 'get_raw_userinfo'])->getMock();
            $client->method('get_raw_userinfo')->willReturn((object) [
                'sub' => 'subject-' . $issuer->get('id'), 'email' => 'owner' . $issuer->get('id') . '@example.invalid',
                'email_verified' => true, 'hd' => 'example.invalid',
            ]);
            $client->method('get_info')->willReturnCallback(fn() => ['http_code' => $this->status]);
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
        $this->service = new member_service($oauth);
        $this->manager = new cohost_manager($this->service);
    }

    /**
     * Register a verified synthetic owner.
     *
     * @return int Account ID
     */
    private function account(): int {
        $issuer = testing\issuer::create();
        $id = $this->accounts->register('Synthetic', (int) $issuer->get('id'));
        $this->accounts->verify($id);
        return $id;
    }

    /**
     * Create through the real manager, then supply the already-tested Calendar/Phase 3 result.
     *
     * @param bool $recurring Create a valid weekly series
     * @return \stdClass Saved activity
     */
    private function meeting(bool $recurring = false): \stdClass {
        global $DB;
        $id = (new meeting_manager())->create((object) [
            'course' => $this->course->id, 'name' => 'Synthetic cohost meeting',
            'cohostuserid' => $this->teacher->id, 'cohostemail' => 'injected@example.invalid',
            'startdatetime' => 1791637200, 'enddatetime' => 1791640800,
            'autorecord' => 1, 'autotranscript' => 1, 'isrecurring' => (int) $recurring,
            'recurrencedays' => json_encode([strtolower(local\meeting\schedule::date(
                1791637200,
                local\meeting\schedule::timezone()->getName()
            )->format('D'))]), 'recurrenceinterval' => 1, 'recurrenceuntil' => 1794056400,
        ]);
        $DB->update_record('tupmeet', (object) [
            'id' => $id, 'syncstatus' => 'ready', 'meetconfigstatus' => 'ready',
            'meetspacename' => 'spaces/Stable_1', 'meetingcode' => 'abc-defg-hij',
            'meeturi' => 'https://meet.google.com/abc-defg-hij',
        ]);
        return $DB->get_record('tupmeet', ['id' => $id]);
    }

    /**
     * Return a provider member fixture.
     *
     * @param string $role Google role
     * @return array Member
     */
    private function member(string $role = 'COHOST'): array {
        return ['name' => 'spaces/Stable_1/members/Teacher_1', 'email' => $this->teacher->email, 'role' => $role];
    }

    /**
     * Emulate only the native HTTP boundary and inspect every actual URL/body/option.
     *
     * @param int $issuer Owner
     * @param string $method Verb
     * @param string $url Target
     * @param array|null $body Payload
     * @param array $options Curl options
     * @return string Response
     */
    private function http(int $issuer, string $method, string $url, ?array $body, array $options): string {
        global $DB;
        $this->assertFalse($DB->is_transaction_started());
        $this->assertContains($method, ['GET', 'POST', 'PATCH'], 'Never DELETE');
        $this->assertStringStartsWith('https://meet.googleapis.com/v2/spaces/', $url);
        if ($method !== 'GET') {
            $this->assertStringContainsString('/members', $url, 'Never spaces.create or artifact PATCH');
        }
        $this->assertFalse($options['CURLOPT_FOLLOWLOCATION']);
        $this->assertSame(15, $options['CURLOPT_TIMEOUT']);
        $this->calls[] = [$issuer, $method, $url, $body];
        $this->status = $this->failure ?: 200;
        if ($this->failure) {
            return '{"error":{"message":"synthetic private provider detail"}}';
        }
        if ($this->malformed) {
            return 'synthetic private invalid JSON';
        }
        if (!str_contains($url, '/members')) {
            $response = ['name' => 'spaces/Stable_1', 'meetingCode' => 'abc-defg-hij',
                'meetingUri' => 'https://meet.google.com/abc-defg-hij'];
        } else if ($method === 'GET') {
            $response = ['members' => $this->members];
            if ($this->paginate && !str_contains($url, 'pageToken=')) {
                $response = ['members' => [], 'nextPageToken' => 'next +/'];
            }
            if ($this->cycle) {
                $response['nextPageToken'] = 'repeat';
            }
        } else if ($method === 'POST') {
            $this->assertSame(['email' => $this->teacher->email, 'role' => 'COHOST'], $body);
            $response = $this->member();
            $this->members[] = $response;
            $response = $this->postresponse ?? $response;
            if ($this->conflict) {
                $this->status = 409;
            }
        } else {
            $this->assertStringEndsWith('?updateMask=role', $url);
            $this->assertSame(['name' => $this->member()['name'], 'role' => 'COHOST'], $body);
            $this->members[0]['role'] = 'COHOST';
            $response = $this->members[0];
        }
        if ($this->duringhttp) {
            ($this->duringhttp)($method);
        }
        if ($this->timeout && $method === 'POST') {
            throw new \RuntimeException('synthetic private timeout');
        }
        return json_encode($response);
    }

    /**
     * Server-derived email, permanent parent, durable identity and independent statuses.
     */
    public function test_create_and_snapshot(): void {
        global $DB;
        $record = $this->meeting();
        $this->assertSame($this->teacher->email, $record->cohostemail);
        $this->assertSame([], $this->calls);
        $this->assertTrue($this->manager->synchronize((int) $record->id));
        $after = $DB->get_record('tupmeet', ['id' => $record->id]);
        $this->assertSame('ready', $after->cohoststatus);
        $this->assertSame($this->member()['name'], $after->cohostmembername);
        $this->assertSame(['GET', 'POST'], array_column($this->calls, 1));
        $this->assertSame('https://meet.googleapis.com/v2/spaces/Stable_1/members?pageSize=500', $this->calls[0][2]);
        foreach (
            ['accountid', 'calendareventid', 'meeturi', 'meetingcode', 'meetspacename', 'syncstatus',
                'meetconfigstatus', 'autorecord', 'autotranscript'] as $field
        ) {
            $this->assertSame($record->{$field}, $after->{$field});
        }
    }

    /**
     * An existing cohost across later pages needs no writes.
     */
    public function test_existing_cohost_and_pagination(): void {
        $record = $this->meeting();
        $this->members = [$this->member()];
        $this->paginate = true;
        $this->assertTrue($this->manager->synchronize((int) $record->id));
        $this->assertSame(['GET', 'GET'], array_column($this->calls, 1));
        $this->assertStringContainsString('pageToken=next%20%2B%2F', $this->calls[1][2]);
    }

    /**
     * A different existing role is promoted with a role-only PATCH.
     */
    public function test_promote_existing_member(): void {
        $record = $this->meeting();
        $this->members = [$this->member('ROLE_UNSPECIFIED')];
        $this->assertTrue($this->manager->synchronize((int) $record->id));
        $this->assertSame(['GET', 'PATCH'], array_column($this->calls, 1));
        $this->assertCount(1, $this->members);
    }

    /**
     * A remote winner causes relisting, not another intentional creation.
     */
    public function test_conflict_relists(): void {
        $record = $this->meeting();
        $this->conflict = true;
        $this->assertTrue($this->manager->synchronize((int) $record->id));
        $this->assertSame(['GET', 'POST', 'GET'], array_column($this->calls, 1));
        $this->assertCount(1, $this->members);
    }

    /**
     * A lost POST response converges using list without duplicate membership.
     */
    public function test_timeout_retry_is_idempotent(): void {
        $record = $this->meeting();
        $this->timeout = true;
        $this->assertFalse($this->manager->synchronize((int) $record->id));
        $this->timeout = false;
        $this->assertTrue($this->manager->synchronize((int) $record->id));
        $this->assertSame(['GET', 'POST', 'GET'], array_column($this->calls, 1));
        $this->assertCount(1, $this->members);
    }

    /**
     * Invalid identities and resource paths are never adopted.
     *
     * @param string $name Invalid name
     * @dataProvider invalid_names
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_names')]
    public function test_reject_foreign_or_malformed_member(string $name): void {
        global $DB;
        $record = $this->meeting();
        $member = $this->member();
        $member['name'] = $name;
        $this->members = [$member];
        $this->assertFalse($this->manager->synchronize((int) $record->id));
        $this->assertSame('error', $DB->get_field('tupmeet', 'cohoststatus', ['id' => $record->id]));
        $this->assertSame(['GET'], array_column($this->calls, 1));
    }

    /**
     * Invalid resources.
     *
     * @return array Cases
     */
    public static function invalid_names(): array {
        return [['spaces/Other/members/Teacher'], ['spaces/Stable_1/members/../Other'],
            ['https://example.invalid/member'], ["spaces/Stable_1/members/A\n"], ['spaces/Stable_1/members/']];
    }

    /**
     * API failures isolate status and preserve every Phase 3 preference/state and Join link.
     *
     * @param int $status HTTP error
     * @dataProvider http_errors
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('http_errors')]
    public function test_api_error_isolation(int $status): void {
        global $DB;
        $record = $this->meeting();
        $this->failure = $status;
        $this->assertFalse($this->manager->synchronize((int) $record->id));
        $after = $DB->get_record('tupmeet', ['id' => $record->id]);
        foreach (
            ['accountid', 'calendareventid', 'meeturi', 'meetingcode', 'meetspacename', 'syncstatus',
                'meetconfigstatus', 'meetconfigversion', 'autorecord', 'autotranscript'] as $field
        ) {
            $this->assertSame($record->{$field}, $after->{$field});
        }
        $this->assertSame('error', $after->cohoststatus);
    }

    /**
     * Provider failures.
     *
     * @return array HTTP statuses
     */
    public static function http_errors(): array {
        return [[400], [401], [403], [404], [429], [503]];
    }

    /**
     * Repeated page tokens stop without granting privileges.
     */
    public function test_pagination_cycle_is_bounded(): void {
        $record = $this->meeting();
        $this->cycle = true;
        $this->assertFalse($this->manager->synchronize((int) $record->id));
        $this->assertCount(2, $this->calls);
        $this->assertSame([], $this->members);
    }

    /**
     * Authorization failures never expose provider data, causes or debug fields.
     */
    public function test_sanitized_service_errors(): void {
        $record = $this->meeting();
        $this->malformed = true;
        try {
            $this->service->synchronize($this->accounts->get_account($this->owner), $record, fn() => true, fn() => true);
            $this->fail('Malformed response must fail');
        } catch (\moodle_exception $e) {
            $this->assertSame('cohostfailed', $e->errorcode);
            $this->assertNull($e->getPrevious());
            $this->assertEmpty($e->debuginfo);
            $this->assertStringNotContainsString('synthetic private', $e->getMessage());
        }
    }

    /**
     * Failed artifact settings do not prevent first-time Space resolution and membership.
     */
    public function test_independent_resolution_despite_artifact_error(): void {
        global $DB;
        $record = $this->meeting();
        $DB->update_record('tupmeet', (object) [
            'id' => $record->id, 'meetspacename' => null, 'meetconfigstatus' => 'error',
        ]);
        $this->assertTrue($this->manager->synchronize((int) $record->id));
        $this->assertSame('spaces/Stable_1', $DB->get_field('tupmeet', 'meetspacename', ['id' => $record->id]));
        $this->assertSame('error', $DB->get_field('tupmeet', 'meetconfigstatus', ['id' => $record->id]));
        $this->assertStringEndsWith('/spaces/abc-defg-hij', $this->calls[0][2]);
    }

    /**
     * Disabled historical owners remain the only authorization identity even after default changes.
     */
    public function test_historical_owner_and_recurring_retry(): void {
        global $DB;
        $record = $this->meeting(true);
        $this->accounts->set_default($this->account());
        $this->accounts->set_enabled($this->owner, false);
        $this->assertTrue($this->manager->synchronize((int) $record->id));
        $saved = $DB->get_record('tupmeet', ['id' => $record->id]);
        cohost_manager::prepare($saved);
        $DB->update_record('tupmeet', $saved);
        $this->assertTrue($this->manager->synchronize((int) $record->id));
        $this->assertCount(1, $this->members);
        $owner = $this->accounts->get_account($this->owner);
        foreach ($this->calls as $call) {
            $this->assertEquals($owner->issuerid, $call[0]);
        }
        $this->assertSame(['GET', 'POST', 'GET'], array_column($this->calls, 1));
    }

    /**
     * Initial selection is immutable even before remote confirmation or after a lost response.
     */
    public function test_replacement_is_rejected(): void {
        global $DB;
        $record = $this->meeting();
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $this->course->id, 'editingteacher');
        try {
            (new meeting_manager())->update((object) ['id' => $record->id, 'cohostuserid' => $other->id]);
            $this->fail('Replacement must fail');
        } catch (\moodle_exception $e) {
            $this->assertSame('cohostlocked', $e->errorcode);
        }
        $this->assertEquals($record, $DB->get_record('tupmeet', ['id' => $record->id]));
        $this->assertSame([], $this->calls);
    }

    /**
     * An account email rename is not permission to silently grant another address.
     */
    public function test_changed_email_stops_http(): void {
        global $DB;
        $record = $this->meeting();
        $DB->set_field('user', 'email', 'changed@example.invalid', ['id' => $this->teacher->id]);
        $this->assertFalse($this->manager->synchronize((int) $record->id));
        $this->assertSame([], $this->calls);
        $this->assertSame($this->teacher->email, $DB->get_field('tupmeet', 'cohostemail', ['id' => $record->id]));
    }

    /**
     * Stale work does not perform HTTP and only five attempts are allowed without explicit retry.
     */
    public function test_budget_queue_and_stale_worker(): void {
        global $DB;
        $record = $this->meeting();
        cohost_manager::queue($record);
        cohost_manager::queue($record);
        $count = $DB->count_records('task_adhoc');
        $this->assertEquals(1, $DB->count_records('task_adhoc', ['classname' => '\mod_tupmeet\task\sync_cohost']));
        $this->assertTrue($this->manager->synchronize((int) $record->id, 'obsolete'));
        $this->assertSame([], $this->calls);
        $this->failure = 403;
        for ($i = 1; $i <= 7; $i++) {
            $this->assertSame($i >= 5, $this->manager->synchronize((int) $record->id, $record->cohostversion));
        }
        $this->assertCount(5, $this->calls);
        $this->assertEquals($count, $DB->count_records('task_adhoc'));
        (new meeting_manager())->update((object) ['id' => $record->id]);
        $saved = $DB->get_record('tupmeet', ['id' => $record->id]);
        $this->assertEquals(0, $saved->cohostattempts);
        $this->assertNotSame($record->cohostversion, $saved->cohostversion);
        $this->assertSame($record->calendareventid, $saved->calendareventid);
        $this->assertSame('ready', $saved->syncstatus);
    }

    /**
     * Concurrent saves during GET prevent writes; during POST they prevent stale confirmation.
     *
     * @param string $phase HTTP moment
     * @dataProvider concurrent_phases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('concurrent_phases')]
    public function test_concurrent_edit(string $phase): void {
        global $DB;
        $record = $this->meeting();
        $this->duringhttp = function ($method) use ($phase, $record) {
            if ($method === $phase) {
                $this->duringhttp = null;
                (new meeting_manager())->update((object) ['id' => $record->id]);
            }
        };
        $this->assertTrue($this->manager->synchronize((int) $record->id));
        $this->assertSame('pending', $DB->get_field('tupmeet', 'cohoststatus', ['id' => $record->id]));
        if ($phase === 'GET') {
            $this->assertSame(['GET'], array_column($this->calls, 1));
        }
        $this->assertTrue($this->manager->synchronize((int) $record->id));
        $this->assertCount(1, $this->members);
    }

    /**
     * Concurrent request moments.
     *
     * @return array Cases
     */
    public static function concurrent_phases(): array {
        return [['GET'], ['POST']];
    }

    /**
     * Both entry points forbid HTTP while a transaction is open.
     */
    public function test_no_http_in_transaction(): void {
        global $DB;
        $record = $this->meeting();
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach (['manager', 'service'] as $target) {
                try {
                    if ($target === 'manager') {
                        $this->manager->synchronize((int) $record->id);
                    } else {
                        $account = $this->accounts->get_account($this->owner);
                        $this->service->synchronize($account, $record, fn() => true, fn() => true);
                    }
                    $this->fail('Transaction must be rejected');
                } catch (\coding_exception $e) {
                    $this->assertStringContainsString('commit', $e->getMessage());
                }
            }
            $this->assertSame([], $this->calls);
        } finally {
            $transaction->allow_commit();
        }
    }

    /**
     * Scope is added only to registered Google issuers alongside both previous scopes.
     */
    public function test_scopes_are_limited(): void {
        $owner = $this->accounts->get_account($this->owner);
        $issuer = new \core\oauth2\issuer($owner->issuerid);
        $scopes = tupmeet_oauth2_system_scopes($issuer);
        foreach ([member_service::SCOPE, local\google\meet_service::SCOPE, local\google\calendar_service::SCOPE] as $scope) {
            $this->assertStringContainsString($scope, $scopes);
            $this->assertStringContainsString($scope, \core\oauth2\api::get_system_scopes_for_issuer($issuer));
        }
        $this->assertSame('', tupmeet_oauth2_system_scopes(testing\issuer::create()));
    }
    /**
     * An explicit membership retry leaves the confirmed artifact revision entirely untouched.
     */
    public function test_membership_only_retry(): void {
        global $DB;
        $record = $this->meeting();
        cohost_manager::retry((int) $record->id);
        $saved = $DB->get_record('tupmeet', ['id' => $record->id]);
        foreach (
            ['syncstatus', 'syncversion', 'calendareventid', 'meeturi', 'accountid',
                'meetconfigstatus', 'meetconfigversion', 'meetconfigattempts', 'meetconfigmodified'] as $field
        ) {
            $this->assertSame($record->{$field}, $saved->{$field});
        }
        $this->assertNotSame($record->cohostversion, $saved->cohostversion);
        $this->assertTrue($this->manager->synchronize((int) $record->id));
    }

    /**
     * A held shared activity lock consumes no HTTP attempts and requests native backoff.
     */
    public function test_shared_lock_contention(): void {
        global $DB, $CFG;
        // MariaDB GET_LOCK is reentrant on the same connection; use Moodle's real row-lock backend.
        $CFG->lock_factory = '\core\lock\db_record_lock_factory';
        $record = $this->meeting();
        $lock = \core\lock\lock_config::get_lock_factory('mod_tupmeet')->get_lock('meeting:' . $record->id, 0);
        try {
            $this->assertFalse($this->manager->synchronize((int) $record->id));
            $this->assertSame([], $this->calls);
            $this->assertEquals(0, $DB->get_field('tupmeet', 'cohostattempts', ['id' => $record->id]));
        } finally {
            $lock->release();
        }
    }

    /**
     * No resolution or membership requests are sent while Calendar is pending.
     */
    public function test_calendar_pending_no_http(): void {
        global $DB;
        $record = $this->meeting();
        $DB->set_field('tupmeet', 'syncstatus', 'pending', ['id' => $record->id]);
        $this->assertTrue($this->manager->synchronize((int) $record->id));
        $this->assertSame([], $this->calls);
    }

    /**
     * Duplicate matching emails are ambiguous and never intentionally modified or multiplied.
     */
    public function test_duplicate_remote_email_rejected(): void {
        $record = $this->meeting();
        $other = $this->member();
        $other['name'] = 'spaces/Stable_1/members/Duplicate';
        $this->members = [$this->member(), $other];
        $this->assertFalse($this->manager->synchronize((int) $record->id));
        $this->assertSame(['GET'], array_column($this->calls, 1));
    }
    /**
     * Confirmation requires the expected parent, exact selected email and COHOST role after POST.
     *
     * @param string $field Response field
     * @param string $value Invalid response value
     * @dataProvider unconfirmed_members
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unconfirmed_members')]
    public function test_invalid_post_confirmation(string $field, string $value): void {
        global $DB;
        $record = $this->meeting();
        $this->postresponse = $this->member();
        $this->postresponse[$field] = $value;
        $this->assertFalse($this->manager->synchronize((int) $record->id));
        $this->assertSame('error', $DB->get_field('tupmeet', 'cohoststatus', ['id' => $record->id]));
        $this->assertNull($DB->get_field('tupmeet', 'cohostmembername', ['id' => $record->id]));
    }

    /**
     * Unconfirmed successful responses.
     *
     * @return array Cases
     */
    public static function unconfirmed_members(): array {
        return [['name', 'spaces/Foreign/members/Teacher'], ['email', 'another@example.invalid'],
            ['role', 'ROLE_UNSPECIFIED']];
    }

    /**
     * Once a permanent Space is saved, losing the meeting code does not affect membership operations.
     */
    public function test_permanent_space_without_code(): void {
        global $DB;
        $record = $this->meeting();
        $DB->set_field('tupmeet', 'meetingcode', null, ['id' => $record->id]);
        $this->members = [$this->member()];
        $this->assertTrue($this->manager->synchronize((int) $record->id));
        $this->assertSame(['GET'], array_column($this->calls, 1));
        $this->assertStringContainsString('/spaces/Stable_1/members', $this->calls[0][2]);
    }

    /**
     * The real native task retires an obsolete revision without acquiring an OAuth client.
     */
    public function test_native_stale_task_retires(): void {
        global $DB;
        $record = $this->meeting();
        $task = new task\sync_cohost();
        $task->set_custom_data(['id' => $record->id, 'version' => 'obsolete']);
        $task->execute();
        $this->assertEquals(0, $DB->get_field('tupmeet', 'cohostattempts', ['id' => $record->id]));
        $this->assertSame('pending', $DB->get_field('tupmeet', 'cohoststatus', ['id' => $record->id]));
    }
}
