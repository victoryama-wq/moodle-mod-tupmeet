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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_tupmeet;

defined('MOODLE_INTERNAL') || die();

/**
 * Release installation and metadata-only upgrade through Moodle's actual updater.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class release_candidate_test extends \advanced_testcase {
    /**
     * The core updater must preserve every plugin field, including historical and hidden rows.
     *
     * @dataProvider previous_versions
     * @param int $previousversion Installed version before the metadata upgrade
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('previous_versions')]
    public function test_metadata_upgrade_preserves_all_five_tables(int $previousversion): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $actor = $this->getDataGenerator()->create_user();
        $owner = $DB->insert_record('tupmeet_accounts', (object) [
            'displayname' => 'Synthetic historical owner', 'googleemail' => 'owner@example.invalid',
            'googlesub' => 'synthetic-subject', 'issuerid' => 123, 'enabled' => 0,
            'connectionstatus' => 'verified', 'timeverified' => 100,
        ]);
        foreach (['meet', 'calendar', 'legacy'] as $offset => $mode) {
            $id = $DB->insert_record('tupmeet', (object) [
                'course' => $course->id, 'name' => 'Synthetic ' . $mode, 'accountid' => $owner,
                'provisionmode' => $mode, 'publicationmode' => $offset ? 'manual' : 'automatic',
                'calendareventid' => 'syntheticevent' . $offset, 'meetspacename' => 'spaces/Synthetic' . $offset,
                'meeturi' => 'https://meet.google.com/abc-defg-hij', 'meetingcode' => 'abc-defg-hij',
                'cohostuserid' => $actor->id, 'cohostemail' => 'teacher@example.invalid',
                'cohostmembername' => 'spaces/Synthetic/members/Synthetic', 'cohostlocked' => 1,
                'cohoststatus' => 'ready', 'spacestatus' => 'ready', 'syncstatus' => 'error',
                'autorecord' => 1, 'autotranscript' => 1, 'timezone' => 'America/Cancun',
            ]);
            $conferenceid = $DB->insert_record('tupmeet_conferences', (object) [
                'tupmeetid' => $id, 'conferencename' => 'conferenceRecords/Synthetic' . $offset,
                'starttime' => 100, 'endtime' => 200, 'lastseen' => 300,
            ]);
            $DB->insert_record('tupmeet_recordings', (object) [
                'tupmeetid' => $id, 'conferenceid' => $conferenceid,
                'recordingname' => 'conferenceRecords/Synthetic/recordings/Synthetic' . $offset,
                'state' => 'FILE_GENERATED', 'drivefileid' => 'synthetic-native-' . $offset,
                'exporturi' => 'https://drive.google.com/file/d/synthetic-native-' . $offset . '/view',
                'renamestatus' => 'error', 'renameattempts' => 5, 'renamehttpstatus' => 403,
                'desiredfilename' => 'Synthetic.mp4', 'drivefilename' => 'Original.mp4',
                'renameversion' => 'syntheticrevision', 'studentvisible' => $offset % 2,
                'visibilityuserid' => $actor->id, 'visibilitymodified' => 400,
            ]);
            $DB->insert_record('tupmeet_legacy_recordings', (object) [
                'tupmeetid' => $id, 'sessionname' => 'Synthetic historical session', 'sessionstart' => 100,
                'drivefileid' => 'synthetic-legacy-' . $offset,
                'exporturi' => 'https://drive.google.com/file/d/synthetic-legacy-' . $offset . '/view',
                'originalfilename' => 'Synthetic old.mp4', 'studentvisible' => $offset % 2,
                'importeduserid' => $actor->id, 'importedat' => 200, 'timecreated' => 200, 'timemodified' => 300,
                'visibilityuserid' => $actor->id, 'visibilitymodified' => 300,
            ]);
        }
        $tables = ['tupmeet', 'tupmeet_accounts', 'tupmeet_conferences', 'tupmeet_recordings', 'tupmeet_legacy_recordings'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $DB->get_records($table, [], 'id');
        }
        $tasksbefore = $DB->get_records('task_adhoc', ['component' => 'mod_tupmeet'], 'id');
        set_config('version', $previousversion, 'mod_tupmeet');
        $upgraded = [];
        upgrade_plugins_modules(static function ($component, $install) use (&$upgraded): void {
            $upgraded[$component] = $install;
        }, static function (): void {
        }, false);
        $this->assertSame(['mod_tupmeet' => false], $upgraded);
        $this->assertEquals(2026092202, get_config('mod_tupmeet', 'version'));
        foreach ($before as $table => $rows) {
            $this->assertEquals($rows, $DB->get_records($table, [], 'id'), $table);
        }
        $this->assertEquals($tasksbefore, $DB->get_records('task_adhoc', ['component' => 'mod_tupmeet'], 'id'));
    }

    /**
     * Exercise both the previous alpha and RC1 upgrade paths.
     *
     * @return array Metadata-only upgrade origins retained for RC2
     */
    public static function previous_versions(): array {
        return ['alpha' => [2026092200], 'rc1' => [2026092201]];
    }

    /**
     * A clean Moodle install registers the complete schema and operational metadata.
     */
    public function test_installed_manifest(): void {
        global $DB;
        $this->resetAfterTest();
        $plugin = new \stdClass();
        require(__DIR__ . '/../version.php');
        $this->assertSame(MATURITY_RC, $plugin->maturity);
        $this->assertSame('0.9.0-rc2', $plugin->release);
        $this->assertEquals($plugin->version, get_config('mod_tupmeet', 'version'));
        $file = new \xmldb_file(__DIR__ . '/../db/install.xml');
        $this->assertTrue($file->loadXMLStructure());
        $this->assertCount(5, $file->getStructure()->getTables());
        foreach ($file->getStructure()->getTables() as $table) {
            $this->assertTrue($DB->get_manager()->table_exists($table));
            foreach ($table->getFields() as $field) {
                $this->assertTrue($DB->get_manager()->field_exists($table, $field), $field->getName());
            }
            foreach ($table->getIndexes() as $index) {
                $this->assertTrue($DB->get_manager()->index_exists($table, $index), $index->getName());
            }
        }
        $this->assertCount(3, $DB->get_records('capabilities', ['component' => 'mod_tupmeet']));
        $task = \core\task\manager::get_scheduled_task('mod_tupmeet\\task\\discover_recordings');
        $this->assertSame('*/5', $task->get_minute());
        $this->assertFalse($task->get_disabled());
        $this->assertInstanceOf(\cache::class, \cache::make('mod_tupmeet', 'legacy_preview'));
        $this->assertTrue(is_subclass_of(
            \mod_tupmeet\privacy\provider::class,
            \core_privacy\local\metadata\provider::class
        ));
    }
}
