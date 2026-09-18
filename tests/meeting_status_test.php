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

use mod_tupmeet\output\meeting_status;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/fixtures/issuer.php');

/**
 * Real capabilities keep artifact diagnostics private while the meeting remains joinable.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(meeting_status::class)]
final class meeting_status_test extends \advanced_testcase {
    /**
     * Error diagnostics and retry are shown to editing teachers, never to students.
     */
    public function test_teacher_and_student_visibility(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $issuer = \mod_tupmeet\testing\issuer::create();
        $DB->insert_record('tupmeet_accounts', (object) [
            'displayname' => 'Fixture', 'googleemail' => 'fixture@example.invalid',
            'googlesub' => 'synthetic', 'enabled' => 1, 'isdefault' => 1, 'connectionstatus' => 'verified',
            'issuerid' => $issuer->get('id'),
        ]);
        $course = $this->getDataGenerator()->create_course();
        $meeting = $this->getDataGenerator()->create_module('tupmeet', ['course' => $course->id]);
        $context = \context_module::instance($meeting->cmid);
        $PAGE->set_context($context);
        $PAGE->set_url('/mod/tupmeet/view.php', ['id' => $meeting->cmid]);
        $meeting->syncstatus = 'ready';
        $meeting->provisionmode = 'calendar';
        $meeting->meeturi = 'https://meet.google.com/abc-defg-hij';
        $meeting->meetconfigstatus = 'error';
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($teacher);
        $html = meeting_status::render($meeting, $context, (int) $meeting->cmid);
        $this->assertStringContainsString($meeting->meeturi, $html);
        $this->assertStringContainsString(get_string('meetconfigerrornotice', 'tupmeet'), $html);
        $this->assertStringContainsString('retry.php', $html);
        $this->assertStringContainsString('method="post"', $html);
        $this->setUser($student);
        $html = meeting_status::render($meeting, $context, (int) $meeting->cmid);
        $this->assertStringContainsString($meeting->meeturi, $html);
        $this->assertStringNotContainsString('retry.php', $html);
        $this->assertStringNotContainsString(get_string('meetconfigerror', 'tupmeet'), $html);
        $this->assertStringNotContainsString(get_string('autorecord', 'tupmeet'), $html);
        $this->setUser($teacher);
        $meeting->meetconfigstatus = 'ready';
        $meeting->autorecord = 1;
        $meeting->autotranscript = 0;
        $html = meeting_status::render($meeting, $context, (int) $meeting->cmid);
        $this->assertStringContainsString(get_string('artifacton', 'tupmeet'), $html);
        $this->assertStringContainsString(get_string('artifactoff', 'tupmeet'), $html);
        $this->assertStringNotContainsString('retry.php', $html);
    }
    /**
     * Member diagnostics use allowlists and require management capability, including for historical errors.
     */
    public function test_cohost_diagnostics_are_private_and_sanitized(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $issuer = testing\issuer::create();
        $DB->insert_record('tupmeet_accounts', (object) [
            'displayname' => 'Fixture', 'googleemail' => 'fixture@example.invalid', 'googlesub' => 'synthetic',
            'enabled' => 1, 'isdefault' => 1, 'connectionstatus' => 'verified', 'issuerid' => $issuer->get('id'),
        ]);
        $course = $this->getDataGenerator()->create_course();
        $meeting = $this->getDataGenerator()->create_module('tupmeet', ['course' => $course->id]);
        $context = \context_module::instance($meeting->cmid);
        $PAGE->set_context($context);
        $PAGE->set_url('/mod/tupmeet/view.php', ['id' => $meeting->cmid]);
        $meeting->syncstatus = 'ready';
        $meeting->provisionmode = 'calendar';
        $meeting->meetconfigstatus = 'ready';
        $meeting->meeturi = 'https://meet.google.com/abc-defg-hij';
        $meeting->cohoststatus = 'error';
        $meeting->cohosterrorstage = 'create';
        $meeting->cohosthttpstatus = 403;
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($teacher);
        $html = meeting_status::render($meeting, $context, (int) $meeting->cmid);
        $this->assertStringContainsString(get_string('cohoststagecreate', 'tupmeet'), $html);
        $this->assertStringContainsString('403', $html);
        $this->setUser($student);
        $html = meeting_status::render($meeting, $context, (int) $meeting->cmid);
        $this->assertStringContainsString($meeting->meeturi, $html);
        foreach (['cohoststagecreate', 'cohosterrorstage', 'cohosthttpstatus', 'cohostfailed'] as $key) {
            $this->assertStringNotContainsString(get_string($key, 'tupmeet'), $html);
        }
        $this->assertStringNotContainsString('403', $html);
        $this->setUser($teacher);
        $meeting->cohosterrorstage = '<script>synthetic private</script>';
        $meeting->cohosthttpstatus = '403 synthetic private';
        $html = meeting_status::render($meeting, $context, (int) $meeting->cmid);
        $this->assertStringContainsString(get_string('cohoststageunknown', 'tupmeet'), $html);
        $this->assertStringNotContainsString('synthetic private', $html);
        $this->assertStringNotContainsString(get_string('cohosthttpstatus', 'tupmeet'), $html);
        $meeting->cohoststatus = 'ready';
        $html = meeting_status::render($meeting, $context, (int) $meeting->cmid);
        $this->assertStringNotContainsString(get_string('cohosterrorstage', 'tupmeet'), $html);
    }

    /**
     * Calendar failure keeps Meet-first joining available and diagnostics private.
     */
    public function test_meet_first_join_and_uncertain_visibility(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->redirectMessages();
        $issuer = testing\issuer::create();
        $DB->insert_record('tupmeet_accounts', (object) ['displayname' => 'Fixture',
            'googleemail' => 'fixture@example.invalid', 'googlesub' => 'synthetic', 'enabled' => 1,
            'isdefault' => 1, 'connectionstatus' => 'verified', 'issuerid' => $issuer->get('id')]);
        $course = $this->getDataGenerator()->create_course();
        $meeting = $this->getDataGenerator()->create_module('tupmeet', ['course' => $course->id]);
        $context = \context_module::instance($meeting->cmid);
        $PAGE->set_context($context);
        $PAGE->set_url('/mod/tupmeet/view.php', ['id' => $meeting->cmid]);
        $meeting->spacestatus = 'ready';
        $meeting->syncstatus = 'error';
        $meeting->meetspacename = 'spaces/Permanent_1';
        $meeting->meetingcode = 'abc-defg-hij';
        $meeting->meeturi = 'https://meet.google.com/abc-defg-hij';
        $html = meeting_status::render($meeting, $context, (int) $meeting->cmid);
        $this->assertStringContainsString($meeting->meeturi, $html);
        $this->assertStringContainsString(get_string('calendarindependenterror', 'tupmeet'), $html);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        $html = meeting_status::render($meeting, $context, (int) $meeting->cmid);
        $this->assertStringContainsString($meeting->meeturi, $html);
        $this->assertStringNotContainsString('retry.php', $html);
        $this->assertStringNotContainsString(get_string('calendarindependenterror', 'tupmeet'), $html);
        $meeting->spacestatus = 'uncertain';
        $html = meeting_status::render($meeting, $context, (int) $meeting->cmid);
        $this->assertStringNotContainsString($meeting->meeturi, $html);
        $this->assertStringNotContainsString(get_string('spaceuncertainnotice', 'tupmeet'), $html);
        $this->setAdminUser();
        $html = meeting_status::render($meeting, $context, (int) $meeting->cmid);
        $this->assertStringContainsString(get_string('spaceuncertainnotice', 'tupmeet'), $html);
        $this->assertStringNotContainsString('retry.php', $html);
    }
}
