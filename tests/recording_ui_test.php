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
use mod_tupmeet\output\recording_diagnostics as recording_list;

defined('MOODLE_INTERNAL') || die();

/**
 * Authorization and rendering contracts, without external calls.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(actions::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(recording_list::class)]
final class recording_ui_test extends \advanced_testcase {
    /** @var \stdClass Local activity. */
    private \stdClass $meeting;
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Module. */
    private \stdClass $cm;
    /** @var \context_module Context. */
    private \context_module $context;
    /** @var int Local recording ID. */
    private int $recordingid;

    /**
     * Local course/module rows suffice to exercise real Moodle capabilities and output.
     */
    protected function setUp(): void {
        global $DB, $PAGE;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $id = $DB->insert_record('tupmeet', (object) ['course' => $this->course->id, 'name' => 'Fixture',
            'provisionmode' => 'meet', 'spacestatus' => 'ready', 'meetspacename' => 'spaces/Canonical',
            'accountid' => 1, 'timezone' => 'America/Cancun', 'recordingsyncstatus' => 'error']);
        $this->meeting = $DB->get_record('tupmeet', ['id' => $id]);
        $cmid = $DB->insert_record('course_modules', (object) ['course' => $this->course->id,
            'module' => $DB->get_field('modules', 'id', ['name' => 'tupmeet']), 'instance' => $id,
            'section' => $DB->get_field('course_sections', 'id', ['course' => $this->course->id, 'section' => 0])]);
        $this->cm = get_coursemodule_from_id('tupmeet', $cmid, 0, false, MUST_EXIST);
        $this->context = \context_module::instance($cmid);
        $PAGE->set_context($this->context);
        $PAGE->set_url('/mod/tupmeet/view.php', ['id' => $cmid]);
        $conferenceid = $DB->insert_record('tupmeet_conferences', (object) ['tupmeetid' => $id,
            'conferencename' => 'conferenceRecords/Test', 'starttime' => 1790000000]);
        $this->recordingid = $DB->insert_record('tupmeet_recordings', (object) ['tupmeetid' => $id,
            'conferenceid' => $conferenceid, 'recordingname' => 'conferenceRecords/Test/recordings/Test',
            'state' => 'FILE_GENERATED', 'drivefileid' => 'Fixture_file',
            'exporturi' => 'https://drive.google.com/file/d/Fixture_file/view',
            'desiredfilename' => 'Fixture.mp4', 'drivefilename' => '<script>alert(1)</script>.mp4',
            'renamestatus' => 'error', 'renamehttpstatus' => 403, 'renameversion' => 'revision']);
    }

    /**
     * Admin/teacher receive metadata, safe links and POST retry forms; students receive no section at all.
     */
    public function test_catalog_capability_and_safe_links(): void {
        foreach (['editingteacher', 'student'] as $role) {
            $user = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($user->id, $this->course->id, $role);
            $this->setUser($user);
            $html = recording_list::render($this->meeting, $this->context, (int) $this->cm->id);
            if ($role === 'student') {
                $this->assertSame('', $html);
            } else {
                $this->assertStringContainsString('https://drive.google.com/file/d/Fixture_file/view', $html);
                $this->assertStringContainsString('noopener noreferrer', $html);
                $this->assertStringContainsString('target="_blank"', $html);
                $this->assertStringContainsString('method="post"', $html);
                $this->assertStringContainsString('sesskey', $html);
                $this->assertStringContainsString('403', $html);
                $this->assertStringNotContainsString('<script>', $html);
                $this->assertStringContainsString('&lt;script&gt;', $html);
            }
        }
    }

    /**
     * Processing rows have no link; malformed legacy local URLs are also suppressed.
     */
    public function test_processing_and_invalid_links_are_not_rendered(): void {
        global $DB;
        foreach (['STARTED', 'ENDED', 'FILE_GENERATED'] as $state) {
            $DB->update_record('tupmeet_recordings', (object) ['id' => $this->recordingid,
                'state' => $state, 'exporturi' => 'https://evil.invalid/']);
            $html = recording_list::render($this->meeting, $this->context, (int) $this->cm->id);
            $this->assertStringNotContainsString('https://evil.invalid', $html);
            $this->assertStringContainsString(get_string('recordingsstate' . $state, 'tupmeet'), $html);
        }
    }

    /**
     * Web actions reject GET, missing session keys and students even if a local recording ID is known.
     * @param string $fault Missing protection
     * @dataProvider authorization_faults
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('authorization_faults')]
    public function test_action_rejects_invalid_authorization(string $fault): void {
        $server = $_SERVER;
        $post = $_POST;
        $get = $_GET;
        try {
            $_SERVER['REQUEST_METHOD'] = $fault === 'get' ? 'GET' : 'POST';
            $_POST = ['sesskey' => $fault === 'sesskey' ? 'wrong' : sesskey()];
            $_GET = [];
            if ($fault === 'student') {
                $user = $this->getDataGenerator()->create_user();
                $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'student');
                $this->setUser($user);
                $_POST['sesskey'] = sesskey();
            }
            $this->expectException(\moodle_exception::class);
            actions::execute($this->cm, $this->context, 'rename', $this->recordingid);
        } finally {
            $_SERVER = $server;
            $_POST = $post;
            $_GET = $get;
        }
    }

    /**
     * Independent missing guards.
     * @return array
     */
    public static function authorization_faults(): array {
        return [['get'], ['sesskey'], ['student']];
    }

    /**
     * A valid rename POST queues only rename, not discovery or meeting provisioning.
     */
    public function test_manual_rename_is_scoped_and_throttled(): void {
        global $DB;
        $server = $_SERVER;
        $post = $_POST;
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST['sesskey'] = sesskey();
            $this->assertTrue(actions::execute($this->cm, $this->context, 'rename', $this->recordingid));
            $this->assertFalse(actions::execute($this->cm, $this->context, 'rename', $this->recordingid));
            $this->assertEquals(1, $DB->count_records('task_adhoc', ['classname' => '\mod_tupmeet\task\rename_recording']));
            $this->assertEquals(1, $DB->count_records('task_adhoc'));
            $this->assertSame('error', $DB->get_field('tupmeet', 'recordingsyncstatus', ['id' => $this->meeting->id]));
        } finally {
            $_SERVER = $server;
            $_POST = $post;
        }
    }

    /**
     * A valid discovery POST queues only discovery and a local recording from another activity is rejected.
     */
    public function test_manual_sync_and_recording_ownership(): void {
        global $DB;
        $server = $_SERVER;
        $post = $_POST;
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST['sesskey'] = sesskey();
            $this->assertTrue(actions::execute($this->cm, $this->context, 'sync'));
            $this->assertEquals(1, $DB->count_records('task_adhoc', ['classname' => '\mod_tupmeet\task\sync_recordings']));
            $DB->set_field('tupmeet_recordings', 'tupmeetid', $this->meeting->id + 100, ['id' => $this->recordingid]);
            $this->expectException(\dml_missing_record_exception::class);
            actions::execute($this->cm, $this->context, 'rename', $this->recordingid);
        } finally {
            $_SERVER = $server;
            $_POST = $post;
        }
    }
}
