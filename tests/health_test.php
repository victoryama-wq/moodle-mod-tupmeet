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

use mod_tupmeet\local\diagnostic\health_service;
use mod_tupmeet\local\recording\recording_manager;
use mod_tupmeet\output\health_dashboard;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/fixtures/issuer.php');

/**
 * Local operational evidence, authorization and bounded volume contracts.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(health_service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(health_dashboard::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(recording_manager::class)]
final class health_test extends \advanced_testcase {
    /**
     * @var \stdClass Course fixture.
     */
    private \stdClass $course;

    /**
     * Set up local-only fixtures, never OAuth credentials.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * Insert metadata without provisioning remotely or generating unneeded user accounts.
     * @param array $fields Overrides
     * @return int Activity ID
     */
    private function activity(array $fields = []): int {
        global $DB;
        $id = $DB->insert_record('tupmeet', (object) ($fields + [
            'course' => $this->course->id, 'name' => 'Local fixture', 'accountid' => 999,
            'provisionmode' => 'meet', 'spacestatus' => 'ready', 'meetspacename' => 'spaces/Private_space',
            'meeturi' => 'https://meet.google.com/abc-defg-hij', 'syncstatus' => 'ready',
        ]));
        $DB->insert_record('course_modules', (object) [
            'course' => $this->course->id, 'instance' => $id,
            'module' => $DB->get_field('modules', 'id', ['name' => 'tupmeet']),
        ]);
        return (int) $id;
    }

    /**
     * Site capability is enforced in the service, not just hidden navigation.
     * @param string $role Role
     * @dataProvider denied_roles
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('denied_roles')]
    public function test_access_denied(string $role): void {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, $role);
        $this->setUser($user);
        $this->expectException(\required_capability_exception::class);
        health_service::snapshot();
    }

    /**
     * Course capabilities do not confer site diagnostics.
     * @return array
     */
    public static function denied_roles(): array {
        return [['student'], ['teacher'], ['editingteacher'], ['manager']];
    }

    /**
     * Explicit delegated site configuration works without requiring is_siteadmin.
     */
    public function test_delegated_site_configuration(): void {
        $user = $this->getDataGenerator()->create_user();
        $role = create_role('Diagnostics', 'diagnosticfixture', '');
        assign_capability('moodle/site:config', CAP_ALLOW, $role, \context_system::instance()->id);
        role_assign($role, $user->id, \context_system::instance()->id);
        $this->setUser($user);
        $this->assertFalse(is_siteadmin());
        $this->assertSame(0, health_service::snapshot()['activities']);
    }

    /**
     * Output cannot be rendered by a student even with an acquired snapshot.
     */
    public function test_output_access_denied(): void {
        $data = health_service::snapshot();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        health_dashboard::render($data);
    }

    /**
     * Missing, duplicate, disabled or unverified defaults are visible without exposing identity.
     * @param string $fault Local configuration fault
     * @dataProvider account_cases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('account_cases')]
    public function test_account_summary(string $fault): void {
        global $DB, $PAGE;
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_url('/mod/tupmeet/health.php');
        $issuer = \mod_tupmeet\testing\issuer::create();
        $account = (object) ['displayname' => 'PRIVATE ACCOUNT', 'googleemail' => 'private@example.invalid',
            'googlesub' => 'SECRET_SUBJECT', 'issuerid' => $issuer->get('id'), 'enabled' => 1,
            'isdefault' => $fault === 'zero' ? 0 : 1, 'connectionstatus' => 'verified', 'timeverified' => 1790000000];
        if ($fault === 'disabled') {
            $account->enabled = 0;
        } else if ($fault === 'unverified') {
            $account->connectionstatus = 'pending';
        } else if ($fault === 'issuer') {
            $account->issuerid = 999999;
        }
        $DB->insert_record('tupmeet_accounts', $account);
        if ($fault === 'multiple') {
            $DB->insert_record('tupmeet_accounts', $account);
        }
        $data = health_service::snapshot();
        $this->assertSame($fault === 'valid', $data['accounts']['configured']);
        $this->assertSame($fault === 'multiple' ? 2 : 1, $data['accounts']['total']);
        $html = health_dashboard::render($data);
        foreach (
            ['SECRET_SUBJECT', 'private@example.invalid', 'PRIVATE ACCOUNT', 'access_token', 'refresh_token',
                'clientsecret', 'clientid'] as $secret
        ) {
            $this->assertStringNotContainsString($secret, $html . json_encode($data));
        }
        if ($fault === 'valid') {
            $this->assertStringContainsString(userdate(1790000000), $html);
        }
    }

    /**
     * Local account states.
     * @return array
     */
    public static function account_cases(): array {
        return [['valid'], ['zero'], ['multiple'], ['disabled'], ['unverified'], ['issuer']];
    }

    /**
     * Aggregates project every subsystem, preserve evidence semantics and expose no remote identifiers.
     */
    public function test_metrics_and_safe_attention(): void {
        global $DB, $PAGE;
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_url('/mod/tupmeet/health.php');
        $first = $this->activity(['name' => '<script>alert(1)</script>Course & meeting', 'spacestatus' => 'uncertain',
            'spacemodified' => 100, 'spacehttpstatus' => 403, 'syncstatus' => 'error', 'meetconfigstatus' => 'error',
            'cohoststatus' => 'error', 'recordingsyncstatus' => 'error']);
        $this->activity(['spacestatus' => 'pending', 'syncstatus' => 'pending', 'meetconfigstatus' => 'pending',
            'cohoststatus' => 'pending', 'recordingsyncstatus' => 'syncing']);
        $this->activity(['spacemodified' => 110, 'lastsync' => 120, 'meetconfigstatus' => 'ready',
            'meetconfigmodified' => 130, 'cohoststatus' => 'ready', 'cohostmodified' => 140,
            'recordingsyncstatus' => 'ready', 'recordingslastsync' => 150]);
        $this->activity(['provisionmode' => 'calendar', 'spacestatus' => 'pending']);
        foreach (['STARTED', 'ENDED', 'FILE_GENERATED'] as $i => $state) {
            $DB->insert_record('tupmeet_recordings', (object) ['tupmeetid' => $first,
                'recordingname' => 'conferenceRecords/Private/recordings/Private' . $i, 'state' => $state,
                'drivefileid' => 'PRIVATE_FILE', 'exporturi' => 'https://drive.google.com/file/d/PRIVATE_FILE/view',
                'renamestatus' => $i === 0 ? 'error' : 'ready', 'renamedat' => $i === 0 ? 999 : 160,
                'studentvisible' => $i === 0 ? 0 : 1, 'renamehttpstatus' => 403]);
        }
        $writes = $DB->perf_get_writes();
        $data = health_service::snapshot();
        $this->assertSame($writes, $DB->perf_get_writes());
        $this->assertSame(4, $data['activities']);
        $this->assertSame(['pending' => 1, 'ready' => 1, 'uncertain' => 1], $data['metrics']['space']['counts']);
        $this->assertSame(['error' => 1, 'pending' => 1, 'ready' => 2], $data['metrics']['calendar']['counts']);
        foreach (
            ['space' => 110, 'calendar' => 120, 'artifacts' => 130, 'cohost' => 140,
                'discovery' => 150, 'rename' => 160] as $key => $latest
        ) {
            $this->assertSame($latest, $data['metrics'][$key]['latest']);
        }
        foreach (['artifacts', 'cohost', 'discovery', 'rename'] as $key) {
            $this->assertSame(1, $data['metrics'][$key]['counts']['error']);
        }
        $this->assertSame(['ENDED' => 1, 'FILE_GENERATED' => 1, 'STARTED' => 1], $data['metrics']['recordings']['counts']);
        $this->assertSame(['visible' => 2, 'hidden' => 1], $data['visibility']);
        $this->assertSame(6, $data['attention']['total']);
        $this->assertSame(6, count($data['attention']['rows']));
        foreach ($data['attention']['rows'] as $row) {
            $this->assertContains($row['state'], ['error', 'uncertain']);
        }
        $html = health_dashboard::render($data);
        foreach (['PRIVATE_FILE', 'Private_space', 'conferenceRecords/', 'exporturi', '<script>', 'abc-defg-hij'] as $secret) {
            $this->assertStringNotContainsString($secret, $html . json_encode($data));
        }
        $this->assertStringContainsString('/mod/tupmeet/view.php?id=', $html);
        $this->assertStringContainsString('table-responsive', $html);
        $this->assertStringNotContainsString('<form', $html);
    }

    /**
     * Public task APIs distinguish no evidence, lag, disabled and normal backoff.
     * @param string $state Fixture state
     * @dataProvider cron_cases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('cron_cases')]
    public function test_scheduled_diagnostics(string $state): void {
        global $DB;
        $classname = '\\mod_tupmeet\\task\\discover_recordings';
        $task = \core\task\manager::get_scheduled_task($classname);
        $this->assertNotFalse($task);
        if ($state === 'missing') {
            $DB->delete_records('task_scheduled', ['classname' => $classname]);
        } else {
            $task->set_disabled($state === 'disabled');
            $task->set_last_run_time($state === 'never' ? 0 : time() - ($state === 'late' ? 901 : 10));
            $task->set_next_run_time(time() + 100);
            $task->set_fail_delay($state === 'backoff' ? 60 : 0);
            \core\task\manager::configure_scheduled_task($task);
            // The public configuration API deliberately preserves the scheduler's last-run timestamp.
            $DB->set_field('task_scheduled', 'lastruntime', $task->get_last_run_time(), ['classname' => $classname]);
        }
        $before = $DB->get_records('task_scheduled');
        $data = health_service::snapshot();
        $this->assertSame($state, $data['tasks']['cron']['status']);
        $this->assertEquals($before, $DB->get_records('task_scheduled'));
    }

    /**
     * Scheduled states.
     * @return array
     */
    public static function cron_cases(): array {
        return [['missing'], ['disabled'], ['never'], ['late'], ['recent'], ['backoff']];
    }

    /**
     * Read-only adhoc aggregation excludes normal backoff and running work from late counts.
     */
    public function test_adhoc_diagnostics(): void {
        global $DB;
        $now = time();
        foreach (
            [[$now + 600, 0, 0], [$now, 0, 0], [$now - 1900, 0, 0],
                [$now - 3600, 60, 0], [$now - 3600, 0, $now - 1]] as $i => [$next, $delay, $running]
        ) {
            $task = new \mod_tupmeet\task\sync_recordings();
            $task->set_component('mod_tupmeet');
            $task->set_custom_data(['id' => $i, 'private' => 'PRIVATE_TASK_PAYLOAD']);
            $task->set_next_run_time($next);
            $id = \core\task\manager::queue_adhoc_task($task);
            // Simulate Moodle's scheduler state in this disposable test DB only.
            $DB->update_record('task_adhoc', (object) ['id' => $id, 'faildelay' => $delay, 'timestarted' => $running]);
        }
        $before = $DB->get_records('task_adhoc');
        $data = health_service::snapshot();
        $this->assertSame(['total' => 5, 'running' => 1, 'backoff' => 1, 'due' => 2, 'late' => 1,
            'pending' => 4, 'waiting' => 1, 'excessive' => false], $data['tasks']['queue']);
        $this->assertEquals($before, $DB->get_records('task_adhoc'));
        $this->assertStringNotContainsString('PRIVATE_TASK_PAYLOAD', json_encode($data));
    }

    /**
     * Logical volume exercises aggregates, stable pagination and two bounded scheduler passes without HTTP.
     * @param int $volume Number of activities
     * @dataProvider volumes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('volumes')]
    public function test_bounded_volume(int $volume): void {
        global $DB;
        $this->preventResetByRollback();
        // A single transaction keeps bulk fixture creation inexpensive; dispatcher is tested after commit.
        $transaction = $DB->start_delegated_transaction();
        for ($i = 0; $i < $volume; $i++) {
            $this->activity(['syncstatus' => 'error', 'timemodified' => 1000 + $i]);
        }
        $transaction->allow_commit();
        $first = health_service::snapshot();
        $this->assertSame($volume, $first['activities']);
        $this->assertSame($volume, $first['metrics']['space']['counts']['ready']);
        $this->assertSame($volume, $first['attention']['total']);
        $this->assertCount(50, $first['attention']['rows']);
        $reads = $DB->perf_get_reads();
        $second = health_service::snapshot(1);
        $this->assertLessThan(25, $DB->perf_get_reads() - $reads);
        $this->assertCount(50, $second['attention']['rows']);
        $this->assertSame([], array_intersect(
            array_column($first['attention']['rows'], 'cmid'),
            array_column($second['attention']['rows'], 'cmid')
        ));
        $this->assertGreaterThan($second['attention']['rows'][0]['modified'], $first['attention']['rows'][0]['modified']);
        $this->assertSame(25, recording_manager::BATCH);
        $this->assertSame(25, recording_manager::dispatch());
        $this->assertSame(25, $DB->count_records('task_adhoc'));
        $this->assertSame(25, recording_manager::dispatch());
        $this->assertSame(50, $DB->count_records('task_adhoc'));
        $ids = $DB->get_fieldset_select('tupmeet', 'id', 'recordingsyncstatus = ?', ['pending']);
        foreach ($ids as $id) {
            $this->assertFalse(recording_manager::queue((int) $id));
        }
        $this->assertSame(50, $DB->count_records('task_adhoc'));
    }

    /**
     * Meaningful bounded load levels.
     * @return array
     */
    public static function volumes(): array {
        return [[100], [500], [1000]];
    }

    /**
     * Invalid canonical identities cannot occupy the entire first batch on every scheduler pass.
     * @param string $state Discovery state
     * @dataProvider invalid_states
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_states')]
    public function test_invalid_rows_do_not_starve(string $state): void {
        global $DB;
        $this->preventResetByRollback();
        for ($i = 0; $i < 25; $i++) {
            $this->activity(['meetspacename' => 'spaces/../Invalid', 'recordingsyncstatus' => $state,
                'recordingsnextsync' => time() - 100, 'recordingsyncattempts' => 3]);
        }
        $valid = $this->activity(['recordingsnextsync' => time() - 1]);
        $this->assertSame(0, recording_manager::dispatch());
        $this->assertSame(1, recording_manager::dispatch());
        $this->assertSame(25, $DB->count_records('tupmeet', ['recordingsyncstatus' => 'error', 'recordingsnextsync' => 0]));
        $this->assertSame('pending', $DB->get_field('tupmeet', 'recordingsyncstatus', ['id' => $valid]));
        $this->assertSame(1, $DB->count_records('task_adhoc'));
    }

    /**
     * Corruption can exist on old/interrupted states, not just initial backfill.
     * @return array
     */
    public static function invalid_states(): array {
        return [['idle'], ['pending'], ['syncing'], ['ready']];
    }

    /**
     * Quarantine respects contention, preserves the owner/revision/budget and retires stale work without HTTP.
     */
    public function test_quarantine_uses_lock_and_preserves_history(): void {
        global $CFG, $DB;
        $this->preventResetByRollback();
        $id = $this->activity(['meetspacename' => 'spaces/../Invalid', 'recordingsyncstatus' => 'syncing',
            'recordingsyncversion' => 'same-revision', 'recordingsyncattempts' => 4, 'recordingsnextsync' => time() - 100]);
        $before = $DB->get_record('tupmeet', ['id' => $id]);
        $CFG->lock_factory = '\\core\\lock\\db_record_lock_factory';
        $lock = \core\lock\lock_config::get_lock_factory('mod_tupmeet')->get_lock('meeting:' . $id, 0);
        try {
            $this->assertFalse(recording_manager::queue($id));
            $this->assertEquals($before, $DB->get_record('tupmeet', ['id' => $id]));
        } finally {
            $lock->release();
        }
        $this->assertFalse(recording_manager::queue($id));
        $after = $DB->get_record('tupmeet', ['id' => $id]);
        foreach ($before as $field => $value) {
            if (!in_array($field, ['recordingsyncstatus', 'recordingsnextsync', 'recordingshttpstatus'], true)) {
                $this->assertEquals($value, $after->{$field}, $field);
            }
        }
        $this->assertSame('error', $after->recordingsyncstatus);
        $this->assertEquals(0, $after->recordingsnextsync);
        $this->assertTrue((new recording_manager())->synchronize($id, 'obsolete'));
        $this->assertEquals($after, $DB->get_record('tupmeet', ['id' => $id]));
        $this->assertSame(0, recording_manager::dispatch());
        $this->assertSame(0, $DB->count_records('task_adhoc'));
    }

    /**
     * Queue size is diagnostic only, with normal future work separated from lateness.
     * @param int $volume Queued tasks
     * @param bool $warning Expected warning
     * @dataProvider queue_sizes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('queue_sizes')]
    public function test_queue_warning_threshold(int $volume, bool $warning): void {
        global $DB;
        $rows = [];
        for ($i = 0; $i < $volume; $i++) {
            $rows[] = (object) ['classname' => '\\mod_tupmeet\\task\\sync_recordings',
                'component' => 'mod_tupmeet', 'nextruntime' => time() + 3600, 'timecreated' => time()];
        }
        // Synthetic core queue state only; product diagnostics never write these tables.
        $DB->insert_records('task_adhoc', $rows);
        $writes = $DB->perf_get_writes();
        $queue = health_service::snapshot()['tasks']['queue'];
        $this->assertSame($writes, $DB->perf_get_writes());
        $this->assertSame($volume, $queue['pending']);
        $this->assertSame($volume, $queue['waiting']);
        $this->assertSame(0, $queue['late']);
        $this->assertSame($warning, $queue['excessive']);
    }

    /**
     * Boundary around the operational warning.
     * @return array
     */
    public static function queue_sizes(): array {
        return [[1000, false], [1001, true]];
    }

    /**
     * PoC route, registration, cache and all production namespace references are gone.
     */
    public function test_poc_removed(): void {
        $root = dirname(__DIR__);
        foreach (['poc_meet_first.php', 'classes/form/poc_setup_form.php'] as $file) {
            $this->assertFileDoesNotExist($root . '/' . $file);
        }
        $this->assertStringNotContainsString('tupmeetpoc', file_get_contents($root . '/db/caches.php'));
        $this->assertStringNotContainsString('tupmeetpoc', file_get_contents($root . '/settings.php'));
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/classes'));
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $this->assertStringNotContainsString('local\\poc', file_get_contents($file->getPathname()));
            }
        }
        foreach (['space_service', 'calendar_service', 'member_service', 'meet_service'] as $name) {
            $this->assertFileExists($root . '/classes/local/google/' . $name . '.php');
        }
    }
}
