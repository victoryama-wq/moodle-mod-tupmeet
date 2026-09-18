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
 * Upgrade regression for previously installed Phase 0 records.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('xmldb_tupmeet_upgrade')]
final class upgrade_test extends \advanced_testcase {
    /**
     * The fresh-install XMLDB schema loads without automatic repairs and creates valid tables.
     */
    public function test_fresh_install_schema(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $file = new \xmldb_file(__DIR__ . '/../db/install.xml');
        $this->assertTrue($file->loadXMLStructure());
        $dbman = $DB->get_manager();
        foreach ($file->getStructure()->getTables() as $table) {
            $table->setName($table->getName() . '_installtest');
            $dbman->create_table($table);
            try {
                $this->assertTrue($dbman->table_exists($table));
                if ($table->getName() === 'tupmeet_accounts_installtest') {
                    $id = $DB->insert_record($table->getName(), (object) ['displayname' => 'Pending', 'googleemail' => '']);
                    $record = $DB->get_record($table->getName(), ['id' => $id]);
                    $this->assertNull($record->googlesub);
                    $this->assertEquals(0, $record->timeverified);
                } else if ($table->getName() === 'tupmeet_installtest') {
                    $id = $DB->insert_record($table->getName(), (object) ['name' => 'Fresh']);
                    $record = $DB->get_record($table->getName(), ['id' => $id]);
                    $this->assertSame('UTC', $record->timezone);
                    $this->assertNull($record->creationkey);
                    $this->assertSame('legacy', $record->syncstatus);
                    $this->assertSame('legacy', $record->syncversion);
                    $this->assertSame('unconfigured', $record->meetconfigstatus);
                    $this->assertSame('legacy', $record->meetconfigversion);
                    $this->assertEquals(0, $record->meetconfigattempts);
                    $this->assertEquals(0, $record->meetconfigmodified);
                    $this->assertSame('unconfigured', $record->cohoststatus);
                    $this->assertEquals(0, $record->cohostuserid);
                    $this->assertNull($record->cohostemail);
                    $this->assertSame('unknown', $record->cohosterrorstage);
                    $this->assertEquals(0, $record->cohosthttpstatus);
                }
            } finally {
                $dbman->drop_table($table);
            }
        }
    }

    /**
     * Upgrade fills new fields without changing activity owners or losing accounts.
     */
    public function test_phase0_upgrade_retains_history(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('tupmeet_accounts');
        // Restore the actual Phase 0 table shape in this disposable test database.
        $dbman->drop_field($table, new \xmldb_field('googlesub'));
        $dbman->drop_field($table, new \xmldb_field('timeverified'));
        $accountid = $DB->insert_record('tupmeet_accounts', (object) [
            'displayname' => 'Historical', 'googleemail' => 'history@example.invalid',
            'issuerid' => 123, 'enabled' => 1, 'isdefault' => 1,
        ]);
        $owned = $DB->insert_record('tupmeet', (object) ['name' => 'Owned', 'accountid' => $accountid]);
        $unassigned = $DB->insert_record('tupmeet', (object) ['name' => 'Phase 0 unassigned', 'accountid' => 0]);
        set_config('version', 2026091400, 'mod_tupmeet');
        $this->assertTrue(xmldb_tupmeet_upgrade(2026091400));
        $account = $DB->get_record('tupmeet_accounts', ['id' => $accountid], '*', MUST_EXIST);
        $this->assertNull($account->googlesub);
        $this->assertEquals(0, $account->timeverified);
        $this->assertEquals(0, $account->isdefault);
        $this->assertSame('pending', $account->connectionstatus);
        $this->assertSame('history@example.invalid', $account->googleemail);
        $this->assertEquals(123, $account->issuerid);
        $this->assertEquals($accountid, $DB->get_field('tupmeet', 'accountid', ['id' => $owned]));
        $this->assertEquals(0, $DB->get_field('tupmeet', 'accountid', ['id' => $unassigned]));
        $this->assertTrue($dbman->field_exists($table, 'googlesub'));
        $this->assertTrue($dbman->field_exists($table, 'timeverified'));
    }

    /**
     * Phase 1 upgrades preserve verified defaults, ownership and absolute timestamps.
     */
    public function test_phase1_upgrade_preserves_verified_default(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $accountid = $DB->insert_record('tupmeet_accounts', (object) [
            'displayname' => 'Verified fixture', 'googleemail' => 'verified@example.invalid',
            'googlesub' => 'subject-fixture', 'issuerid' => 123, 'enabled' => 1, 'isdefault' => 1,
            'connectionstatus' => 'verified', 'timeverified' => 1789398000,
        ]);
        $id = $DB->insert_record('tupmeet', (object) [
            'name' => 'Historical schedule', 'accountid' => $accountid,
            'startdatetime' => 1789398000, 'enddatetime' => 1789401600,
        ]);
        $before = $DB->get_record('tupmeet_accounts', ['id' => $accountid]);
        $table = new \xmldb_table('tupmeet');
        $dbman = $DB->get_manager();
        $dbman->drop_index($table, new \xmldb_index('creationkey', XMLDB_INDEX_UNIQUE, ['creationkey']));
        foreach (['syncstatus', 'syncversion', 'creationkey', 'timezone'] as $field) {
            $dbman->drop_field($table, new \xmldb_field($field));
        }
        set_config('timezone', 'America/Cancun');
        set_config('version', 2026091500, 'mod_tupmeet');
        $this->assertTrue(xmldb_tupmeet_upgrade(2026091500));
        $this->assertEquals($before, $DB->get_record('tupmeet_accounts', ['id' => $accountid]));
        $meeting = $DB->get_record('tupmeet', ['id' => $id]);
        $this->assertEquals($accountid, $meeting->accountid);
        $this->assertEquals(1789398000, $meeting->startdatetime);
        $this->assertEquals(1789401600, $meeting->enddatetime);
        $this->assertSame('America/Cancun', $meeting->timezone);
        $this->assertSame('legacy', $meeting->syncstatus);
        $this->assertNull($meeting->calendareventid);
        $this->assertEquals(0, $DB->count_records('task_adhoc', ['component' => 'mod_tupmeet']));
    }
    /**
     * Phase 2 activities and owners are retained without automatically enabling real artifacts.
     */
    public function test_phase2_upgrade_preserves_ready_meeting(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $table = new \xmldb_table('tupmeet');
        foreach (['meetconfigstatus', 'meetconfigversion', 'meetconfigmodified', 'meetconfigattempts'] as $field) {
            $DB->get_manager()->drop_field($table, new \xmldb_field($field));
        }
        $accountid = $DB->insert_record('tupmeet_accounts', (object) [
            'displayname' => 'Historical', 'googleemail' => 'historical@example.invalid',
            'googlesub' => 'synthetic-subject', 'enabled' => 1, 'isdefault' => 1,
        ]);
        $saved = (object) [
            'name' => 'Phase 2', 'accountid' => $accountid, 'calendareventid' => 'stableevent',
            'meeturi' => 'https://meet.google.com/abc-defg-hij', 'meetingcode' => 'abc-defg-hij',
            'meetspacename' => 'spaces/Permanent', 'syncstatus' => 'ready', 'syncversion' => 'oldrevision',
            'autorecord' => 1, 'autotranscript' => 1, 'timezone' => 'America/Cancun',
        ];
        $saved->id = $DB->insert_record('tupmeet', $saved);
        set_config('version', 2026091502, 'mod_tupmeet');
        $this->assertTrue(xmldb_tupmeet_upgrade(2026091502));
        $after = $DB->get_record('tupmeet', ['id' => $saved->id]);
        foreach ((array) $saved as $field => $value) {
            $this->assertEquals($value, $after->{$field}, $field);
        }
        $this->assertSame('unconfigured', $after->meetconfigstatus);
        $this->assertSame('legacy', $after->meetconfigversion);
        $this->assertEquals(0, $after->meetconfigattempts);
        $this->assertEquals(0, $after->meetconfigmodified);
        $this->assertEquals(1, $DB->get_field('tupmeet_accounts', 'isdefault', ['id' => $accountid]));
        $this->assertEquals(0, $DB->count_records('task_adhoc', ['component' => 'mod_tupmeet']));
        $this->assertEquals(2026091702, get_config('mod_tupmeet', 'version'));
    }
    /**
     * A Phase 3 upgrade preserves all known metadata and never selects or synchronizes a teacher.
     */
    public function test_phase3_upgrade_cohost_is_unconfigured(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $fields = ['cohostuserid', 'cohostemail', 'cohostmembername', 'cohoststatus', 'cohostversion',
            'cohostattempts', 'cohostmodified', 'cohostlocked'];
        $table = new \xmldb_table('tupmeet');
        foreach (array_reverse($fields) as $field) {
            $DB->get_manager()->drop_field($table, new \xmldb_field($field));
        }
        $id = $DB->insert_record('tupmeet', (object) [
            'name' => 'Phase 3', 'accountid' => 321, 'meetspacename' => 'spaces/Stable_1',
            'calendareventid' => 'stableevent', 'meeturi' => 'https://meet.google.com/abc-defg-hij',
            'meetingcode' => 'abc-defg-hij', 'syncstatus' => 'ready', 'meetconfigstatus' => 'ready',
            'autorecord' => 1, 'autotranscript' => 1, 'meetconfigversion' => 'oldrevision',
        ]);
        $before = $DB->get_record('tupmeet', ['id' => $id]);
        set_config('version', 2026091700, 'mod_tupmeet');
        $this->assertTrue(xmldb_tupmeet_upgrade(2026091700));
        $after = $DB->get_record('tupmeet', ['id' => $id]);
        foreach ((array) $before as $field => $value) {
            $this->assertSame($value, $after->{$field}, $field);
        }
        $this->assertEquals(0, $after->cohostuserid);
        $this->assertNull($after->cohostemail);
        $this->assertNull($after->cohostmembername);
        $this->assertSame('unconfigured', $after->cohoststatus);
        $this->assertSame('legacy', $after->cohostversion);
        $this->assertEquals(0, $after->cohostlocked);
        $this->assertEquals(0, $after->cohostattempts);
        $this->assertEquals(0, $after->cohostmodified);
        $this->assertEquals(0, $DB->count_records('task_adhoc', ['component' => 'mod_tupmeet']));
        $this->assertEquals(2026091702, get_config('mod_tupmeet', 'version'));
    }
    /**
     * Diagnostic upgrade preserves the failed locked teacher and every existing meeting field.
     */
    public function test_phase32_upgrade_only_adds_diagnostics(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $table = new \xmldb_table('tupmeet');
        foreach (['cohosthttpstatus', 'cohosterrorstage'] as $field) {
            $DB->get_manager()->drop_field($table, new \xmldb_field($field));
        }
        $id = $DB->insert_record('tupmeet', (object) [
            'name' => 'Phase 3.2', 'accountid' => 321, 'meetspacename' => 'spaces/Stable_1',
            'calendareventid' => 'stableevent', 'meeturi' => 'https://meet.google.com/abc-defg-hij',
            'syncstatus' => 'ready', 'meetconfigstatus' => 'ready', 'autorecord' => 1, 'autotranscript' => 1,
            'cohostuserid' => 123, 'cohostemail' => 'teacher@example.invalid', 'cohostlocked' => 1,
            'cohoststatus' => 'error', 'cohostattempts' => 5, 'cohostversion' => 'savedrevision',
        ]);
        $before = $DB->get_record('tupmeet', ['id' => $id]);
        set_config('version', 2026091701, 'mod_tupmeet');
        $this->assertTrue(xmldb_tupmeet_upgrade(2026091701));
        $after = $DB->get_record('tupmeet', ['id' => $id]);
        foreach ((array) $before as $field => $value) {
            $this->assertSame($value, $after->{$field}, $field);
        }
        $this->assertSame('unknown', $after->cohosterrorstage);
        $this->assertEquals(0, $after->cohosthttpstatus);
        $this->assertEquals(2026091702, get_config('mod_tupmeet', 'version'));
        $this->assertEquals(0, $DB->count_records('task_adhoc', ['component' => 'mod_tupmeet']));
    }
}
