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

use mod_tupmeet\local\meeting\meeting_manager;
use mod_tupmeet\local\meeting\schedule;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once(__DIR__ . '/../mod_form.php');

/**
 * Moodle's real form date controls use the explicit meeting timezone.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_tupmeet_mod_form::class)]
final class mod_form_test extends \advanced_testcase {
    /**
     * Submitted civil dates remain Cancun dates even when PHP runs in UTC.
     */
    public function test_submitted_dates_in_cancun(): void {
        global $USER, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $USER->timezone = 'America/Cancun';
        set_config('forcetimezone', 99);
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $PAGE->set_course($course);
        $previous = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $key = meeting_manager::new_key();
            \mod_tupmeet_mod_form::mock_submit([
                'cohostuserid' => $teacher->id,
                'name' => 'Form timezone fixture', 'creationkey' => $key,
                'visible' => 1, 'modulename' => 'tupmeet', 'instance' => 0, 'coursemodule' => 0,
                'course' => $course->id, 'section' => 0, 'completion' => 0, 'cmidnumber' => '',
                'availabilityconditionsjson' => '',
                'startdatetime' => ['year' => 2026, 'month' => 9, 'day' => 14, 'hour' => 23, 'minute' => 0],
                'enddatetime' => ['year' => 2026, 'month' => 9, 'day' => 15, 'hour' => 0, 'minute' => 0],
                'isrecurring' => 1, 'recurrenceinterval' => 1, 'daymon' => 1,
                'recurrenceuntil' => ['year' => 2026, 'month' => 9, 'day' => 14],
            ]);
            $form = new \mod_tupmeet_mod_form((object) ['instance' => 0], 0, null, $course);
            $data = $form->get_data();
            $this->assertNotFalse($data);
            $this->assertNotNull($data);
            $this->assertSame($key, $data->creationkey);
            $this->assertSame(
                '2026-09-14T23:00:00-05:00',
                schedule::date($data->startdatetime, 'America/Cancun')->format(DATE_RFC3339)
            );
            $this->assertSame(
                '2026-09-14T00:00:00-05:00',
                schedule::date($data->recurrenceuntil, 'America/Cancun')->format(DATE_RFC3339)
            );
        } finally {
            date_default_timezone_set($previous);
        }
    }
}
