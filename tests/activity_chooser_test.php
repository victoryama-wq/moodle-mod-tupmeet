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

use core_course\local\entity\content_item;
use core_course\local\entity\string_title;
use core_course\local\repository\content_item_readonly_repository;
use core_course\local\service\content_item_service;

/**
 * Native Activity Chooser identity, help and favourites.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class activity_chooser_test extends \advanced_testcase {
    /**
     * Return the exact object supplied by core without changing its identity or presentation.
     */
    public function test_callback_preserves_default_item(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/tupmeet/lib.php');
        $link = new \moodle_url('/course/mod.php', ['id' => 42, 'add' => 'tupmeet']);
        $originalurl = $link->out(false);
        $defaultitem = new content_item(
            37,
            'tupmeet',
            new string_title('TUP Meet'),
            $link,
            '<span>Native icon</span>',
            'Native help',
            MOD_ARCHETYPE_OTHER,
            'mod_tupmeet',
            MOD_PURPOSE_COMMUNICATION
        );
        $items = tupmeet_get_course_content_items($defaultitem, (object) ['id' => 7], (object) ['id' => 42]);
        $this->assertSame([$defaultitem], $items);
        $this->assertSame(37, $items[0]->get_id());
        $this->assertSame('mod_tupmeet', $items[0]->get_component_name());
        $this->assertSame($link, $items[0]->get_link());
        $this->assertSame($originalurl, $items[0]->get_link()->out(false));
        $this->assertSame(MOD_PURPOSE_COMMUNICATION, $items[0]->get_purpose());
        $this->assertSame('<span>Native icon</span>', $items[0]->get_icon());
        $this->assertSame('Native help', $items[0]->get_help());
    }

    /**
     * The core repository exposes plugin help and preserves its communication purpose.
     */
    public function test_core_item_has_help_and_communication_purpose(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/tupmeet/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $items = (new content_item_readonly_repository())->find_all_for_course($course, get_admin());
        $matching = array_values(array_filter($items, static fn($item) => $item->get_component_name() === 'mod_tupmeet'));
        $this->assertCount(1, $matching);
        $this->assertNotEmpty($matching[0]->get_help());
        $this->assertSame(get_string('modulename_help', 'mod_tupmeet'), $matching[0]->get_help());
        $this->assertSame(MOD_PURPOSE_COMMUNICATION, $matching[0]->get_purpose());
        $this->assertSame(MOD_PURPOSE_COMMUNICATION, tupmeet_supports(FEATURE_MOD_PURPOSE));
    }

    /**
     * Core can store, reload and remove this module's favourite for a course teacher.
     */
    public function test_core_favourite_round_trip(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($user);
        $service = new content_item_service(new content_item_readonly_repository());
        $select = function (array $items): \stdClass {
            $matching = array_values(array_filter($items, static fn($item) => $item->componentname === 'mod_tupmeet'));
            $this->assertCount(1, $matching);
            return $matching[0];
        };
        $item = $select($service->get_content_items_for_user_in_course($user, $course));
        $this->assertEquals($DB->get_field('modules', 'id', ['name' => 'tupmeet'], MUST_EXIST), $item->id);
        $this->assertFalse($item->legacyitem);
        $this->assertFalse($item->favourite);
        $added = $service->add_to_user_favourites($user, 'mod_tupmeet', $item->id);
        $this->assertEquals($item->id, $added->id);
        $this->assertTrue($added->favourite);
        $this->assertTrue($select($service->get_content_items_for_user_in_course($user, $course))->favourite);
        $removed = $service->remove_from_user_favourites($user, 'mod_tupmeet', $item->id);
        $this->assertFalse($removed->favourite);
        $this->assertFalse($select($service->get_content_items_for_user_in_course($user, $course))->favourite);
    }
}
