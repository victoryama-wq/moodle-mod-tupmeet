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

use mod_tupmeet\local\recording\actions;
use mod_tupmeet\output\activity_view;
use mod_tupmeet\output\recording_list;
use mod_tupmeet\output\session_summary;
use mod_tupmeet\privacy\provider;

defined('MOODLE_INTERNAL') || die();

/**
 * Academic publication, authorization, template disclosure and privacy contracts.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(actions::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(recording_list::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(activity_view::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(session_summary::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class publication_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass Activity. */
    private \stdClass $meeting;
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Module. */
    private \stdClass $cm;
    /** @var \context_module Context. */
    private \context_module $context;
    /** @var \stdClass Manager. */
    private \stdClass $teacher;
    /** @var \stdClass Student. */
    private \stdClass $student;
    /** @var int Local recording. */
    private int $recordingid;

    /**
     * Offline local fixture; no valid Google credentials or remote operations.
     */
    protected function setUp(): void {
        global $DB, $PAGE;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_user();
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
        $id = $DB->insert_record('tupmeet', (object) [
            'course' => $this->course->id, 'name' => 'Academic fixture', 'timezone' => 'America/Cancun',
            'provisionmode' => 'meet', 'spacestatus' => 'ready', 'meetspacename' => 'spaces/Canonical',
            'meeturi' => 'https://meet.google.com/abc-defg-hij', 'syncstatus' => 'error',
            'cohoststatus' => 'error', 'cohosthttpstatus' => 403, 'recordingsyncstatus' => 'error',
            'startdatetime' => strtotime('2026-09-19T22:00:00Z'), 'enddatetime' => strtotime('2026-09-19T23:30:00Z'),
        ]);
        $this->meeting = $DB->get_record('tupmeet', ['id' => $id]);
        $cmid = $DB->insert_record('course_modules', (object) [
            'course' => $this->course->id, 'module' => $DB->get_field('modules', 'id', ['name' => 'tupmeet']),
            'instance' => $id, 'section' => $DB->get_field(
                'course_sections',
                'id',
                ['course' => $this->course->id, 'section' => 0]
            ),
        ]);
        $this->cm = get_coursemodule_from_id('tupmeet', $cmid, 0, false, MUST_EXIST);
        $this->context = \context_module::instance($cmid);
        $PAGE->set_context($this->context);
        $PAGE->set_url('/mod/tupmeet/view.php', ['id' => $cmid]);
        $conferenceid = $DB->insert_record('tupmeet_conferences', (object) [
            'tupmeetid' => $id, 'conferencename' => 'conferenceRecords/Fixture', 'starttime' => 1790000000,
        ]);
        $this->recordingid = $DB->insert_record('tupmeet_recordings', (object) [
            'tupmeetid' => $id, 'conferenceid' => $conferenceid,
            'recordingname' => 'conferenceRecords/Fixture/recordings/Fixture',
            'state' => 'FILE_GENERATED', 'drivefileid' => 'Private_file',
            'exporturi' => 'https://drive.google.com/file/d/Private_file/view',
            'desiredfilename' => 'Frozen private.mp4', 'drivefilename' => 'Academic.mp4',
            'originalfilename' => 'Original private.mp4', 'renamestatus' => 'error', 'renamehttpstatus' => 403,
        ]);
        $this->setUser($this->teacher);
    }

    /**
     * The public state never depends on rename, and hidden rows never reach HTML or template context.
     * @param string $state Processing state
     * @param int $visible Initial local visibility
     * @dataProvider student_cases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('student_cases')]
    public function test_student_projection(string $state, int $visible): void {
        global $DB;
        $DB->update_record('tupmeet_recordings', (object) [
            'id' => $this->recordingid, 'studentvisible' => $visible, 'state' => $state,
        ]);
        $this->setUser($this->student);
        $data = recording_list::data($this->meeting, $this->context, (int) $this->cm->id);
        $this->assertCount($visible, $data['rows']);
        $this->assertFalse($data['manage']);
        $this->assertArrayNotHasKey('sesskey', $data);
        if ($visible) {
            $row = $data['rows'][0];
            $this->assertSame(get_string('recordingsstate' . $state, 'tupmeet'), $row['state']);
            $this->assertSame($state === 'FILE_GENERATED', isset($row['url']));
            $this->assertEqualsCanonicalizing($state === 'FILE_GENERATED' ?
                ['date', 'hours', 'state', 'part', 'url'] : ['date', 'hours', 'state', 'part'], array_keys($row));
        }
        $html = activity_view::render($this->meeting, $this->context, (int) $this->cm->id);
        foreach (
            ['technical_panel', '<details', 'recordings.php', 'retry.php', 'HTTP', '403',
            'Original private', 'Frozen private', 'Academic.mp4', 'conferenceRecords/', 'spaces/Canonical'] as $private
        ) {
            $this->assertStringNotContainsString($private, $html);
        }
        if (!$visible || $state !== 'FILE_GENERATED') {
            $this->assertStringNotContainsString('Private_file', $html);
        } else {
            $this->assertStringContainsString('https://drive.google.com/file/d/Private_file/view', $html);
            $this->assertStringContainsString('target="_blank"', $html);
            $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        }
        $this->assertStringContainsString($this->meeting->meeturi, $html);
        $this->assertSame(0, $DB->count_records('task_adhoc'));
    }

    /**
     * Every processing state with each visibility decision.
     * @return array
     */
    public static function student_cases(): array {
        $cases = [];
        foreach (['STARTED', 'ENDED', 'FILE_GENERATED'] as $state) {
            foreach ([0, 1] as $visible) {
                $cases[] = [$state, $visible];
            }
        }
        return $cases;
    }

    /**
     * Native icons represent actions, labels are accessible, and diagnostics stay in the technical panel.
     * @param int $visible Local visibility
     * @dataProvider visibility_values
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('visibility_values')]
    public function test_teacher_icons_and_technical_panel(int $visible): void {
        global $DB, $OUTPUT;
        $DB->set_field('tupmeet_recordings', 'studentvisible', $visible, ['id' => $this->recordingid]);
        $data = recording_list::data($this->meeting, $this->context, (int) $this->cm->id);
        $row = $data['rows'][0];
        $this->assertSame($visible ? 'hide' : 'show', $row['action']);
        $this->assertSame($OUTPUT->pix_icon($visible ? 't/hide' : 't/show', '', 'moodle'), $row['visibilityicon']);
        $this->assertSame(get_string($visible ? 'recordinghide' : 'recordingshow', 'tupmeet'), $row['visibilitylabel']);
        $academic = recording_list::render($this->meeting, $this->context, (int) $this->cm->id);
        $this->assertStringContainsString('title="' . $row['visibilitylabel'] . '"', $academic);
        $this->assertStringContainsString('aria-label="' . $row['visibilitylabel'], $academic);
        $this->assertStringContainsString('method="post"', $academic);
        $this->assertStringContainsString('name="sesskey"', $academic);
        $this->assertStringNotContainsString('name="studentvisible"', $academic);
        $this->assertStringNotContainsString('Original private', $academic);
        $this->assertStringNotContainsString('HTTP', $academic);
        $this->assertStringContainsString('Academic.mp4', $academic);
        $this->assertStringContainsString('scope="col"', $academic);
        $this->assertStringContainsString('scope="row"', $academic);
        $full = activity_view::render($this->meeting, $this->context, (int) $this->cm->id);
        $this->assertStringContainsString('<details', $full);
        $this->assertStringContainsString('Original private', $full);
        $this->assertStringContainsString('HTTP 403', $full);
        $this->assertLessThan(strpos($full, 'HTTP 403'), strpos($full, '<details'));
        $this->assertSame(1, substr_count($full, $this->meeting->meeturi));
    }

    /**
     * Local states.
     * @return array
     */
    public static function visibility_values(): array {
        return [[0], [1]];
    }

    /**
     * Explicit actions affect only three local fields and produce a safe, idempotent event.
     * @param string $state Recording state
     * @param int $visible Target visibility
     * @dataProvider student_cases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('student_cases')]
    public function test_visibility_action_and_audit(string $state, int $visible): void {
        global $DB;
        $DB->update_record('tupmeet_recordings', (object) [
            'id' => $this->recordingid, 'state' => $state, 'studentvisible' => 1 - $visible,
        ]);
        $before = $DB->get_record('tupmeet_recordings', ['id' => $this->recordingid]);
        $server = $_SERVER;
        $post = $_POST;
        $get = $_GET;
        $sink = $this->redirectEvents();
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_GET = [];
            // Extraneous untrusted values cannot choose a destination, filename or visibility.
            $_POST = ['sesskey' => sesskey(), 'studentvisible' => 1 - $visible,
                'fileId' => 'Untrusted', 'exportUri' => 'https://evil.invalid', 'filename' => 'Untrusted'];
            $this->assertTrue(actions::execute($this->cm, $this->context, $visible ? 'show' : 'hide', $this->recordingid));
            $after = $DB->get_record('tupmeet_recordings', ['id' => $this->recordingid]);
            $this->assertEquals($visible, $after->studentvisible);
            $this->assertEquals($this->teacher->id, $after->visibilityuserid);
            $this->assertGreaterThanOrEqual(time() - 5, $after->visibilitymodified);
            foreach ($before as $key => $value) {
                if (!in_array($key, ['studentvisible', 'visibilityuserid', 'visibilitymodified'])) {
                    $this->assertEquals($value, $after->{$key}, $key);
                }
            }
            $events = $sink->get_events();
            $this->assertCount(1, $events);
            $event = $events[0];
            $this->assertInstanceOf(\mod_tupmeet\event\recording_visibility_changed::class, $event);
            $this->assertEquals($this->context->id, $event->contextid);
            $this->assertEquals($this->recordingid, $event->objectid);
            $this->assertEquals(['visible' => $visible, 'activityid' => $this->meeting->id], $event->other);
            $this->assertStringNotContainsString('Private_file', json_encode($event->get_data()));
            $this->assertNotEmpty($event->get_name());
            $this->assertStringContainsString((string) $this->recordingid, $event->get_description());
            $this->assertStringContainsString('/view.php', $event->get_url()->out(false));
            $this->assertTrue(actions::execute($this->cm, $this->context, $visible ? 'show' : 'hide', $this->recordingid));
            $this->assertCount(1, $sink->get_events());
            $this->assertEquals($after, $DB->get_record('tupmeet_recordings', ['id' => $this->recordingid]));
            $this->assertSame(0, $DB->count_records('task_adhoc'));
        } finally {
            $sink->close();
            $_SERVER = $server;
            $_POST = $post;
            $_GET = $get;
        }
    }

    /**
     * Reject unauthorized or cross-module actions before changing any row.
     * @param string $action Explicit action
     * @param string $fault Broken boundary
     * @dataProvider rejected_actions
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejected_actions')]
    public function test_reject_visibility_action(string $action, string $fault): void {
        global $DB;
        $server = $_SERVER;
        $post = $_POST;
        $get = $_GET;
        try {
            $_SERVER['REQUEST_METHOD'] = $fault === 'get' ? 'GET' : 'POST';
            $_GET = [];
            $_POST = ['sesskey' => $fault === 'sesskey' ? 'invalid' : sesskey()];
            if ($fault === 'student') {
                $this->setUser($this->student);
                $_POST['sesskey'] = sesskey();
            }
            $cm = clone $this->cm;
            if ($fault === 'context') {
                $cm->id++;
            }
            if ($fault === 'activity') {
                $DB->set_field('tupmeet_recordings', 'tupmeetid', $this->meeting->id + 100, ['id' => $this->recordingid]);
            }
            $before = $DB->get_record('tupmeet_recordings', ['id' => $this->recordingid]);
            try {
                actions::execute($cm, $this->context, $action, $this->recordingid);
                $this->fail('Unauthorized mutation accepted');
            } catch (\moodle_exception $e) {
                $this->assertEquals($before, $DB->get_record('tupmeet_recordings', ['id' => $this->recordingid]));
            }
        } finally {
            $_SERVER = $server;
            $_POST = $post;
            $_GET = $get;
        }
    }

    /**
     * Authorization boundary combinations.
     * @return array
     */
    public static function rejected_actions(): array {
        $rows = [];
        foreach (['hide', 'show'] as $action) {
            foreach (['get', 'sesskey', 'student', 'context', 'activity'] as $fault) {
                $rows[] = [$action, $fault];
            }
        }
        return $rows;
    }

    /**
     * Local end date and wall duration survive recurrence and DST; no technical data is exported.
     * @param string $zone Zone
     * @param string $start First local session
     * @param string $until Last allowed local date
     * @param string $now Search instant
     * @param string|null $expected Next local date
     * @param int $interval Weekly interval or zero for a simple session
     * @dataProvider session_cases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('session_cases')]
    public function test_session_summary(
        string $zone,
        string $start,
        string $until,
        string $now,
        ?string $expected,
        int $interval
    ): void {
        $zoneobj = new \DateTimeZone($zone);
        $meeting = clone $this->meeting;
        $meeting->timezone = $zone;
        $first = new \DateTimeImmutable($start, $zoneobj);
        $meeting->startdatetime = $first->getTimestamp();
        $meeting->enddatetime = $first->modify('+90 minutes')->getTimestamp();
        $meeting->isrecurring = (int) ($interval > 0);
        $meeting->recurrenceinterval = max(1, $interval);
        $meeting->recurrencedays = json_encode([strtolower($first->format('D'))]);
        $meeting->recurrenceuntil = (new \DateTimeImmutable($until, $zoneobj))->getTimestamp();
        $data = session_summary::data($meeting, (new \DateTimeImmutable($now, $zoneobj))->getTimestamp());
        $this->assertSame($expected !== null, $data['hassession']);
        $this->assertSame($meeting->meeturi, $data['joinurl']);
        if ($expected !== null) {
            $stamp = (new \DateTimeImmutable($expected, $zoneobj))->getTimestamp();
            $this->assertSame(userdate($stamp, get_string('sessiondateformat', 'tupmeet'), $zone), $data['date']);
            $this->assertSame($first->format('H:i') . ' – ' . $first->modify('+90 minutes')->format('H:i'), $data['hours']);
        }
        if ($interval) {
            $this->assertSame(userdate(
                $meeting->recurrenceuntil,
                get_string('sessiondateformat', 'tupmeet'),
                $zone
            ), $data['until']);
            $this->assertNotEmpty($data['pattern']);
        } else {
            $this->assertArrayNotHasKey('until', $data);
        }
        foreach (['timezone', 'syncstatus', 'cohoststatus', 'accountid', 'meetspacename'] as $key) {
            $this->assertArrayNotHasKey($key, $data);
        }
    }

    /**
     * Academic schedule cases, including final inclusive date and both DST offsets.
     * @return array
     */
    public static function session_cases(): array {
        return [
            ['America/Cancun', '2026-09-19 17:00', '2026-10-03', '2026-09-19 12:00', '2026-09-19 17:00', 0],
            ['America/Cancun', '2026-09-19 17:00', '2026-10-03', '2026-09-20 12:00', null, 0],
            ['America/Cancun', '2026-09-19 17:00', '2026-10-03', '2026-09-20 12:00', '2026-09-26 17:00', 1],
            ['America/Cancun', '2026-09-19 17:00', '2026-10-03', '2026-10-03 12:00', '2026-10-03 17:00', 1],
            ['America/Cancun', '2026-09-19 17:00', '2026-10-03', '2026-10-04 12:00', null, 1],
            ['America/Cancun', '2026-09-19 23:30', '2026-10-03', '2026-09-20 12:00', '2026-10-03 23:30', 2],
            ['America/New_York', '2026-10-25 17:00', '2026-11-01', '2026-10-26 12:00', '2026-11-01 17:00', 1],
            ['America/New_York', '2026-03-01 17:00', '2026-03-08', '2026-03-02 12:00', '2026-03-08 17:00', 1],
        ];
    }

    /**
     * Last actors are discoverable/exportable; erasure removes attribution, never visibility or shared history.
     * @param string $method Privacy entrypoint
     * @dataProvider privacy_cases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('privacy_cases')]
    public function test_visibility_privacy(string $method): void {
        global $DB;
        $DB->update_record('tupmeet_recordings', (object) [
            'id' => $this->recordingid, 'studentvisible' => 1,
            'visibilityuserid' => $this->teacher->id, 'visibilitymodified' => time(),
        ]);
        $contexts = provider::get_contexts_for_userid((int) $this->teacher->id)->get_contextids();
        $this->assertContains((int) $this->context->id, array_map('intval', $contexts));
        $users = new \core_privacy\local\request\userlist($this->context, 'mod_tupmeet');
        provider::get_users_in_context($users);
        $this->assertEquals([$this->teacher->id], $users->get_userids());
        $approved = new \core_privacy\local\request\approved_contextlist(
            $this->teacher,
            'mod_tupmeet',
            [$this->context->id]
        );
        provider::export_user_data($approved);
        $data = \core_privacy\local\request\writer::with_context($this->context)->get_data([
            get_string('visibilityhistory', 'tupmeet'),
        ]);
        $this->assertCount(1, $data->recordings);
        $this->assertEquals($this->teacher->id, $data->recordings[0]->visibilityuserid);
        $this->assertStringNotContainsString('Private_file', json_encode($data));
        $before = $DB->get_record('tupmeet_recordings', ['id' => $this->recordingid]);
        provider::delete_data_for_users(new \core_privacy\local\request\approved_userlist(
            $this->context,
            'mod_tupmeet',
            [$this->student->id]
        ));
        provider::delete_data_for_all_users_in_context(\context_course::instance($this->course->id));
        $this->assertEquals($before, $DB->get_record('tupmeet_recordings', ['id' => $this->recordingid]));
        if ($method === 'user') {
            provider::delete_data_for_user($approved);
        } else if ($method === 'users') {
            provider::delete_data_for_users(new \core_privacy\local\request\approved_userlist(
                $this->context,
                'mod_tupmeet',
                [$this->teacher->id]
            ));
        } else {
            provider::delete_data_for_all_users_in_context($this->context);
        }
        $after = $DB->get_record('tupmeet_recordings', ['id' => $this->recordingid]);
        foreach ($before as $key => $value) {
            $this->assertEquals(in_array($key, ['visibilityuserid', 'visibilitymodified']) ? 0 : $value, $after->{$key}, $key);
        }
    }

    /**
     * All privacy erasure paths.
     * @return array
     */
    public static function privacy_cases(): array {
        return [['user'], ['users'], ['context']];
    }

    /**
     * Hidden rows do not affect student pagination; real conference time and segment order drive the list.
     */
    public function test_filtered_pagination_and_segments(): void {
        global $DB;
        $base = $DB->get_record('tupmeet_recordings', ['id' => $this->recordingid]);
        unset($base->id);
        for ($i = 0; $i < 52; $i++) {
            $row = clone $base;
            $row->recordingname = 'conferenceRecords/Fixture/recordings/' . $i;
            $row->drivefileid = 'Hidden_' . $i;
            $row->exporturi = 'https://drive.google.com/file/d/Hidden_' . $i . '/view';
            $DB->insert_record('tupmeet_recordings', $row);
        }
        $DB->update_record('tupmeet_recordings', (object) ['id' => $this->recordingid, 'studentvisible' => 1]);
        $later = $DB->insert_record('tupmeet_conferences', (object) [
            'tupmeetid' => $this->meeting->id, 'conferencename' => 'conferenceRecords/Later', 'starttime' => 1790086400,
        ]);
        foreach ([1, 2, 3] as $part) {
            $row = clone $base;
            $row->recordingname = 'conferenceRecords/Later/recordings/' . $part;
            $row->conferenceid = $later;
            $row->studentvisible = 1;
            $row->starttime = 1790086400 + $part;
            $row->partnumber = $part;
            $DB->insert_record('tupmeet_recordings', $row);
        }
        $this->setUser($this->student);
        $data = recording_list::data($this->meeting, $this->context, (int) $this->cm->id, 999);
        $this->assertCount(4, $data['rows']);
        $this->assertSame('', $data['paging']);
        $this->assertSame('', $data['rows'][0]['part']);
        $this->assertSame(get_string('recordingpart', 'tupmeet', 2), $data['rows'][1]['part']);
        $this->assertSame(get_string('recordingpart', 'tupmeet', 3), $data['rows'][2]['part']);
        $this->assertNotEquals($data['rows'][0]['date'], $data['rows'][3]['date']);
        $this->assertStringNotContainsString('Hidden_', json_encode($data));
        $this->setUser($this->teacher);
        $this->assertCount(50, recording_list::data($this->meeting, $this->context, (int) $this->cm->id)['rows']);
        $this->assertCount(6, recording_list::data($this->meeting, $this->context, (int) $this->cm->id, 1)['rows']);
    }

    /**
     * Missing module-view capability blocks the data source even when invoked directly.
     */
    public function test_guest_cannot_read_catalog(): void {
        $this->setGuestUser();
        $this->expectException(\required_capability_exception::class);
        recording_list::data($this->meeting, $this->context, (int) $this->cm->id);
    }

    /**
     * Stored names are escaped in academic markup, including manager labels.
     */
    public function test_filename_escaping(): void {
        global $DB;
        $DB->set_field('tupmeet_recordings', 'drivefilename', '<script>alert(1)</script>.mp4', ['id' => $this->recordingid]);
        $html = recording_list::render($this->meeting, $this->context, (int) $this->cm->id);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }
}
