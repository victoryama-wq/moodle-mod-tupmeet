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

defined('MOODLE_INTERNAL') || die();

/**
 * Endpoint guard audit paired with native role checks; action-level tests cover the actual POST mutations.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class endpoint_security_test extends \advanced_testcase {
    /**
     * Protect the existing ordering of route guards before any data or mutation dispatch.
     * @param string $file Route
     * @param string $capability Required capability
     * @param string $boundary Protected operation
     * @param bool $mutation POST/sesskey expected
     * @dataProvider routes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function test_route_guards(string $file, string $capability, string $boundary, bool $mutation): void {
        $code = file_get_contents(dirname(__DIR__) . '/' . $file);
        $login = strpos($code, $capability === '' ? 'require_course_login(' : 'require_login(');
        $cap = $capability === '' ? $login : strpos($code, "require_capability('" . $capability . "'");
        $operation = strpos($code, $boundary);
        $this->assertNotFalse($login);
        $this->assertNotFalse($cap);
        $this->assertNotFalse($operation);
        if ($capability !== '') {
            $this->assertLessThan($cap, $login);
        }
        $this->assertLessThan($operation, $cap);
        if ($mutation) {
            $this->assertLessThan($operation, strpos($code, 'require_sesskey()'));
            $this->assertLessThan($operation, strpos($code, "!== 'POST'"));
        }
    }

    /**
     * Guard locations; recordings.php delegates to actions::execute, tested with actual POST calls separately.
     * @return array
     */
    public static function routes(): array {
        return [
            ['view.php', 'mod/tupmeet:view', 'activity_view::render', false],
            ['accounts.php', 'moodle/site:config', "$" . 'manager->verify(', true],
            ['retry.php', 'moodle/course:manageactivities', 'space_manager::retry(', true],
            ['health.php', 'moodle/site:config', 'health_service::snapshot(', false],
            ['legacy.php', 'moodle/site:config', 'importer::confirm(', false],
            ['recordings.php', 'mod/tupmeet:view', 'actions::execute(', false],
            ['index.php', '', 'get_all_instances_in_course(', false],
        ];
    }

    /**
     * A student's valid POST session cannot pass the exact native capability required by retry.php.
     */
    public function test_student_cannot_pass_retry_gate(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        $context = \context_course::instance($course->id);
        $this->expectException(\required_capability_exception::class);
        require_capability('moodle/course:manageactivities', $context);
    }

    /**
     * Existing editing teachers retain management while only site-config users receive global diagnostics.
     */
    public function test_existing_teacher_and_admin_rights(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $this->assertTrue(has_capability('moodle/course:manageactivities', \context_course::instance($course->id)));
        $this->assertFalse(has_capability('moodle/site:config', \context_system::instance()));
        $this->setAdminUser();
        $this->assertTrue(has_capability('moodle/site:config', \context_system::instance()));
    }

    /**
     * The admin tree retains separate account and health destinations and no PoC registration.
     */
    public function test_admin_navigation(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->libdir . '/adminlib.php');
        $tree = admin_get_root(true, false);
        $category = $tree->locate('modtupmeet');
        $this->assertInstanceOf(\admin_category::class, $category);
        $accounts = $category->locate('modsettingtupmeet');
        $health = $category->locate('tupmeethealth');
        $this->assertInstanceOf(\admin_externalpage::class, $accounts);
        $this->assertInstanceOf(\admin_externalpage::class, $health);
        $this->assertTrue($accounts->check_access());
        $this->assertTrue($health->check_access());
        $this->assertEmpty($tree->locate('tupmeetpoc'));
    }
}
