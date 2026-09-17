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

use mod_tupmeet\local\meeting\cohost_identity;
use mod_tupmeet\local\meeting\meeting_manager;
use mod_tupmeet\privacy\provider;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/fixtures/issuer.php');

/**
 * Real form, enrolment, capability, privacy and visibility checks.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(cohost_identity::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class cohost_identity_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Eligible teacher. */
    private \stdClass $teacher;

    /**
     * Build an enrolled teacher and a synthetic verified owner; no real authorization exists.
     */
    protected function setUp(): void {
        global $DB, $CFG;
        require_once(__DIR__ . '/../mod_form.php');
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_user(['email' => 'teacher@example.invalid']);
        $this->getDataGenerator()->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $issuer = testing\issuer::create();
        $DB->insert_record('tupmeet_accounts', (object) [
            'displayname' => 'Synthetic owner', 'googleemail' => 'owner@example.invalid', 'googlesub' => 'synthetic',
            'issuerid' => $issuer->get('id'), 'enabled' => 1, 'isdefault' => 1, 'connectionstatus' => 'verified',
        ]);
    }

    /**
     * Instantiate Moodle's actual form, including inherited rendering/default handling.
     *
     * @return \MoodleQuickForm Form
     */
    private function form(): \MoodleQuickForm {
        global $PAGE;
        $PAGE->set_course($this->course);
        $current = (object) ['instance' => 0, 'course' => $this->course->id];
        $form = new \mod_tupmeet_mod_form($current, 0, null, $this->course);
        $property = new \ReflectionProperty($form, '_form');
        return $property->getValue($form);
    }

    /**
     * The eligible current teacher is selected; artifact controls and defaults remain intact.
     */
    public function test_teacher_form_default_and_phase3_controls(): void {
        $this->setUser($this->teacher);
        $this->assertSame((int) $this->teacher->id, cohost_identity::default_user((int) $this->course->id));
        $form = $this->form();
        $html = $form->getElement('cohostuserid')->toHtml();
        $this->assertStringContainsString($this->teacher->email, $html);
        $this->assertStringContainsString('value="' . $this->teacher->id . '" selected', $html);
        $this->assertTrue($form->elementExists('autorecord'));
        $this->assertTrue($form->elementExists('autotranscript'));
        $this->assertTrue($form->elementExists('publicationmode'));
        $this->assertEquals(1, $form->getElement('autorecord')->getValue());
        $this->assertEquals(0, $form->getElement('autotranscript')->getValue());
    }

    /**
     * An administrator without active course teaching enrolment must choose a teacher.
     */
    public function test_admin_is_not_default(): void {
        global $USER;
        $this->assertSame(0, cohost_identity::default_user((int) $this->course->id));
        $options = cohost_identity::options((int) $this->course->id);
        $this->assertArrayNotHasKey($USER->id, $options);
        $this->assertArrayHasKey($this->teacher->id, $options);
        $html = $this->form()->getElement('cohostuserid')->toHtml();
        $this->assertStringNotContainsString('value="' . $this->teacher->id . '" selected', $html);
    }

    /**
     * Server identity resolution rejects every ineligible user category.
     *
     * @param string $case Invalid identity
     * @dataProvider invalid_users
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_users')]
    public function test_invalid_user_is_rejected(string $case): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        if (!in_array($case, ['not-enrolled', 'missing'], true)) {
            $this->getDataGenerator()->enrol_user($user->id, $this->course->id, $case === 'student' ? 'student' : 'editingteacher');
        }
        switch ($case) {
            case 'missing':
                $user->id = 999999;
                break;
            case 'deleted':
            case 'suspended':
                $DB->set_field('user', $case, 1, ['id' => $user->id]);
                break;
            case 'invalid-email':
                $DB->set_field('user', 'email', 'invalid-address', ['id' => $user->id]);
                break;
            case 'empty-email':
                $DB->set_field('user', 'email', '', ['id' => $user->id]);
                break;
            case 'enrolment-suspended':
                $DB->set_field('user_enrolments', 'status', ENROL_USER_SUSPENDED, ['userid' => $user->id]);
                break;
            case 'expired':
                $DB->set_field('user_enrolments', 'timeend', time() - 60, ['userid' => $user->id]);
                break;
        }
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('cohostinvalid', 'tupmeet'));
        cohost_identity::resolve((int) $this->course->id, (int) $user->id);
    }

    /**
     * Invalid identities.
     *
     * @return array Cases
     */
    public static function invalid_users(): array {
        return [['missing'], ['deleted'], ['suspended'], ['not-enrolled'], ['student'],
            ['invalid-email'], ['empty-email'], ['enrolment-suspended'], ['expired']];
    }

    /**
     * Bypassing the browser/form cannot grant an unvalidated user or email.
     */
    public function test_direct_manager_forged_selection(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $before = $DB->count_records('tupmeet');
        try {
            (new meeting_manager())->create((object) [
                'course' => $this->course->id, 'name' => 'Forged', 'startdatetime' => time(),
                'enddatetime' => time() + 3600, 'cohostuserid' => $student->id, 'cohostemail' => $this->teacher->email,
            ]);
            $this->fail('Forged selection must fail');
        } catch (\moodle_exception $e) {
            $this->assertSame('cohostinvalid', $e->errorcode);
        }
        $this->assertEquals($before, $DB->count_records('tupmeet'));
    }

    /**
     * Create a real Moodle module in this test's enclosing rollback transaction (no HTTP).
     *
     * @return \stdClass Activity
     */
    private function activity(): \stdClass {
        return $this->getDataGenerator()->create_module('tupmeet', [
            'course' => $this->course->id, 'cohostuserid' => $this->teacher->id,
        ]);
    }

    /**
     * Teacher errors never hide Join or leak administration details to enrolled students.
     */
    public function test_cohost_error_visibility(): void {
        global $PAGE, $DB;
        $activity = $this->activity();
        $context = \context_module::instance($activity->cmid);
        $PAGE->set_context($context);
        $PAGE->set_url('/mod/tupmeet/view.php', ['id' => $activity->cmid]);
        $record = $DB->get_record('tupmeet', ['id' => $activity->id]);
        $record->syncstatus = 'ready';
        $record->meetconfigstatus = 'ready';
        $record->autorecord = 1;
        $record->autotranscript = 1;
        $record->meeturi = 'https://meet.google.com/abc-defg-hij';
        $record->cohoststatus = 'error';
        $this->setUser($this->teacher);
        $html = output\meeting_status::render($record, $context, (int) $activity->cmid);
        $this->assertStringContainsString(get_string('cohostfailed', 'tupmeet'), $html);
        $this->assertStringContainsString($record->meeturi, $html);
        $this->assertStringContainsString(get_string('autotranscript', 'tupmeet'), $html);
        $this->assertStringContainsString(get_string('autorecord', 'tupmeet'), $html);
        $this->assertStringContainsString('method="post"', $html);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->setUser($student);
        $html = output\meeting_status::render($record, $context, (int) $activity->cmid);
        $this->assertStringContainsString($record->meeturi, $html);
        $this->assertStringNotContainsString(get_string('cohostfailed', 'tupmeet'), $html);
        $this->assertStringNotContainsString(fullname($this->teacher), $html);
        $this->assertStringNotContainsString('retry.php', $html);
    }

    /**
     * Privacy discovers, exports and erases only approved user data without resetting remote privilege locks.
     */
    public function test_privacy_export_and_delete(): void {
        global $DB;
        $activity = $this->activity();
        $context = \context_module::instance($activity->cmid);
        $before = $DB->get_record('tupmeet', ['id' => $activity->id]);
        $contextids = provider::get_contexts_for_userid((int) $this->teacher->id)->get_contextids();
        $this->assertContains((int) $context->id, array_map('intval', $contextids));
        $users = new \core_privacy\local\request\userlist($context, 'mod_tupmeet');
        provider::get_users_in_context($users);
        $this->assertEquals([$this->teacher->id], $users->get_userids());
        $approved = new \core_privacy\local\request\approved_contextlist($this->teacher, 'mod_tupmeet', [$context->id]);
        provider::export_user_data($approved);
        $export = \core_privacy\local\request\writer::with_context($context)->get_data([get_string('cohostuserid', 'tupmeet')]);
        $this->assertSame($this->teacher->email, $export->cohostemail);
        $this->assertFalse(property_exists($export, 'accountid'));
        provider::delete_data_for_user($approved);
        $after = $DB->get_record('tupmeet', ['id' => $activity->id]);
        $this->assertEquals(0, $after->cohostuserid);
        $this->assertNull($after->cohostemail);
        $this->assertNull($after->cohostmembername);
        $this->assertEquals(1, $after->cohostlocked);
        $this->assertNotSame($before->cohostversion, $after->cohostversion);
        foreach (['accountid', 'syncstatus', 'meetconfigstatus', 'autorecord', 'autotranscript'] as $field) {
            $this->assertSame($before->{$field}, $after->{$field});
        }
        $this->expectException(\moodle_exception::class);
        (new meeting_manager())->update((object) ['id' => $activity->id, 'cohostuserid' => $this->teacher->id]);
    }

    /**
     * Bulk erasure rejects foreign users and contexts, then erases only the approved local data.
     */
    public function test_privacy_bulk_scope(): void {
        global $DB;
        $activity = $this->activity();
        $context = \context_module::instance($activity->cmid);
        $other = $this->getDataGenerator()->create_user();
        $unapproved = new \core_privacy\local\request\approved_userlist($context, 'mod_tupmeet', [$other->id]);
        provider::delete_data_for_users($unapproved);
        provider::delete_data_for_all_users_in_context(\context_course::instance($this->course->id));
        $this->assertEquals($this->teacher->id, $DB->get_field('tupmeet', 'cohostuserid', ['id' => $activity->id]));
        $approved = new \core_privacy\local\request\approved_userlist($context, 'mod_tupmeet', [$this->teacher->id]);
        provider::delete_data_for_users($approved);
        $this->assertEquals(0, $DB->get_field('tupmeet', 'cohostuserid', ['id' => $activity->id]));
        $this->assertSame('unconfigured', $DB->get_field('tupmeet', 'cohoststatus', ['id' => $activity->id]));
    }
}
