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

/**
 * Offline legacy fixtures with synthetic metadata only.
 * @package mod_tupmeet
 * @copyright 2026 Tecnologico Universitario Region Sureste
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait legacy_fixture {
    /** @var \stdClass Activity. */
    private \stdClass $meeting;
    /** @var \stdClass Module. */
    private \stdClass $cm;
    /** @var \context_module Context. */
    private \context_module $context;
    /** @var \stdClass Teacher. */
    private \stdClass $teacher;
    /** @var \stdClass Student. */
    private \stdClass $student;
    /** @var array Previous request globals. */
    private array $requestbefore;

    /**
     * Create local rows without provisioning.
     */
    protected function setUp(): void {
        global $DB, $PAGE;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->requestbefore = [$_POST, $_GET, $_SERVER['REQUEST_METHOD'] ?? null];
        $course = $this->getDataGenerator()->create_course();
        $id = $DB->insert_record('tupmeet', (object) ['course' => $course->id, 'name' => 'Synthetic course',
            'timezone' => 'America/Cancun', 'provisionmode' => 'meet']);
        $this->meeting = $DB->get_record('tupmeet', ['id' => $id]);
        $cmid = $DB->insert_record('course_modules', (object) ['course' => $course->id,
            'module' => $DB->get_field('modules', 'id', ['name' => 'tupmeet']), 'instance' => $id,
            'section' => $DB->get_field('course_sections', 'id', ['course' => $course->id, 'section' => 0])]);
        $this->cm = get_coursemodule_from_id('tupmeet', $cmid, 0, false, MUST_EXIST);
        $this->context = \context_module::instance($cmid);
        $PAGE->set_context($this->context);
        $PAGE->set_url('/mod/tupmeet/view.php', ['id' => $cmid]);
        $this->teacher = $this->getDataGenerator()->create_user();
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($this->student->id, $course->id, 'student');
        $this->post();
    }

    /**
     * Restore request globals.
     */
    public function tearDown(): void {
        [$_POST, $_GET, $method] = $this->requestbefore;
        if ($method === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $method;
        }
        parent::tearDown();
    }

    /**
     * Valid POST for the current synthetic user.
     */
    private function post(): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['sesskey' => sesskey()];
        $_GET = [];
    }

    /**
     * One-row CSV fixture.
     * @param array $changes Overrides
     * @return string
     */
    private function csv(array $changes = []): string {
        require_once(__DIR__ . '/../legacy_csv_test.php');
        return legacy_csv_test::csv([array_replace(legacy_csv_test::row(), $changes)]);
    }

    /**
     * Import one reference.
     * @param array $changes Overrides
     * @return \stdClass
     */
    private function imported(array $changes = []): \stdClass {
        global $DB;
        $plan = \mod_tupmeet\local\legacy\importer::preview($this->csv($changes));
        \mod_tupmeet\local\legacy\importer::confirm($plan['token']);
        $file = \mod_tupmeet\local\legacy\csv_validator::fileid(
            $changes['drive_url'] ?? legacy_csv_test::row()['drive_url']
        );
        return $DB->get_record('tupmeet_legacy_recordings', ['drivefileid' => $file], '*', MUST_EXIST);
    }
}
