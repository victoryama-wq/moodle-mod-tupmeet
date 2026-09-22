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
require_once(__DIR__ . '/../db/upgrade.php');

/**
 * Upgrade publication metadata without changing preferences, identities or names.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('xmldb_tupmeet_upgrade')]
final class publication_upgrade_test extends \advanced_testcase {
    /**
     * Recreate Phase 4 recordings then apply the real XMLDB upgrade.
     */
    public function test_upgrade_initializes_only_visibility(): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/upgradelib.php');
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('tupmeet_recordings');
        $dbman->drop_index($table, new \xmldb_index('studentlist', XMLDB_INDEX_NOTUNIQUE, ['tupmeetid', 'studentvisible']));
        $dbman->drop_index($table, new \xmldb_index('visibilityuserid', XMLDB_INDEX_NOTUNIQUE, ['visibilityuserid']));
        foreach (['studentvisible', 'visibilitymodified', 'visibilityuserid'] as $name) {
            $dbman->drop_field($table, new \xmldb_field($name));
        }
        $before = [];
        foreach (['automatic', 'manual'] as $mode) {
            $id = $DB->insert_record('tupmeet', (object) ['name' => $mode, 'publicationmode' => $mode]);
            $conferenceid = $DB->insert_record('tupmeet_conferences', (object) [
                'tupmeetid' => $id, 'conferencename' => 'conferenceRecords/' . $mode,
            ]);
            foreach (['STARTED', 'ENDED', 'FILE_GENERATED'] as $state) {
                $recordingid = $DB->insert_record('tupmeet_recordings', (object) [
                    'tupmeetid' => $id, 'conferenceid' => $conferenceid, 'state' => $state,
                    'recordingname' => 'conferenceRecords/' . $mode . '/recordings/' . $state,
                    'desiredfilename' => 'Frozen.mp4', 'drivefileid' => 'File_' . $state,
                    'exporturi' => 'https://drive.google.com/file/d/File_' . $state . '/view',
                ]);
                $before[$recordingid] = [$mode, $DB->get_record('tupmeet_recordings', ['id' => $recordingid])];
            }
        }
        set_config('version', 2026091900, 'mod_tupmeet');
        $this->assertTrue(xmldb_tupmeet_upgrade(2026091900));
        $this->assertEquals(2026092200, get_config('mod_tupmeet', 'version'));
        foreach ($before as $id => [$mode, $row]) {
            $after = $DB->get_record('tupmeet_recordings', ['id' => $id]);
            foreach ($row as $field => $value) {
                $this->assertEquals($value, $after->{$field}, $field);
            }
            $this->assertEquals($mode === 'automatic' ? 1 : 0, $after->studentvisible);
            $this->assertEquals(0, $after->visibilitymodified);
            $this->assertEquals(0, $after->visibilityuserid);
            $this->assertSame($mode, $DB->get_field('tupmeet', 'publicationmode', ['id' => $row->tupmeetid]));
        }
        $this->assertEquals(6, $DB->count_records('tupmeet_recordings'));
        $this->assertEquals(0, $DB->count_records('task_adhoc'));
        // A later invocation at the installed version cannot reset manual decisions.
        $id = array_key_first($before);
        $DB->set_field('tupmeet_recordings', 'studentvisible', 0, ['id' => $id]);
        $this->assertTrue(xmldb_tupmeet_upgrade(2026092200));
        $this->assertEquals(0, $DB->get_field('tupmeet_recordings', 'studentvisible', ['id' => $id]));
        $xml = new \xmldb_file(__DIR__ . '/../db/install.xml');
        $this->assertTrue($xml->loadXMLStructure());
        $definition = $xml->getStructure()->getTable('tupmeet_recordings');
        foreach ($definition->getFields() as $field) {
            $this->assertTrue($dbman->field_exists($table, $field));
        }
        foreach ($definition->getIndexes() as $index) {
            $this->assertTrue($dbman->index_exists($table, $index));
        }
    }
}
