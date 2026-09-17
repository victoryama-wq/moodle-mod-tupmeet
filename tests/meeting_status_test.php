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
}
