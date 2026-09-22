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

use mod_tupmeet\local\recording\filename;
use mod_tupmeet\local\recording\polling;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../db/upgrade.php');

/**
 * Deterministic filename, scheduling and upgrade contracts for recording metadata.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(filename::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(polling::class)]
final class recording_policy_test extends \advanced_testcase {
    /**
     * Localized names preserve accents and use actual session time rather than PHP's timezone.
     * @param string $title Title
     * @param string $zone Saved zone
     * @param string $instant Actual start
     * @param int $part Segment
     * @param string $expected Filename
     * @dataProvider filenames
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('filenames')]
    public function test_filename(string $title, string $zone, string $instant, int $part, string $expected): void {
        $this->assertSame($expected, filename::make($title, strtotime($instant), $zone, $part));
    }

    /**
     * File policy examples, including both sides of DST.
     * @return array
     */
    public static function filenames(): array {
        return [
            ['Farmacología: UCI / Grupo A', 'America/Cancun', '2026-09-19T22:00:00Z', 1,
                'Farmacología - UCI - Grupo A - 2026-09-19 - 17-00.mp4'],
            ["  Niño\t\n  mañana  . ", 'America/Cancun', '2026-09-19T22:00:00Z', 2,
                'Niño mañana - 2026-09-19 - 17-00 - Parte 2.mp4'],
            ['東京', 'America/Cancun', '2026-09-20T03:00:00Z', 3, '東京 - 2026-09-19 - 22-00 - Parte 3.mp4'],
            ['', 'UTC', '2026-09-19T22:00:00Z', 1, 'Meet - 2026-09-19 - 22-00.mp4'],
            [' ... ', 'UTC', '2026-09-19T22:00:00Z', 1, 'Meet - 2026-09-19 - 22-00.mp4'],
            ['Clase', 'America/New_York', '2026-10-31T21:00:00Z', 1, 'Clase - 2026-10-31 - 17-00.mp4'],
            ['Clase', 'America/New_York', '2026-11-07T22:00:00Z', 1, 'Clase - 2026-11-07 - 17-00.mp4'],
        ];
    }

    /**
     * Every unsafe character is replaced, controls removed, and byte limits never split Unicode.
     */
    public function test_filename_unicode_limit_and_reserved_suffix(): void {
        $name = filename::make(str_repeat('ñ漢á', 300) . '\\/:*?"<>|' . "\0", 1790000000, 'America/Cancun', 9999);
        $this->assertLessThanOrEqual(240, strlen($name));
        $this->assertSame(1, preg_match('//u', $name));
        $this->assertSame(0, preg_match('~[\\\\/:*?"<>|\p{Cc}]~u', $name));
        $this->assertStringEndsWith(' - Parte 9999.mp4', $name);
        $this->assertStringStartsWith('ñ漢á', $name);
        $short = filename::make('A\\B/C:D*E?F"G<H>I|J', 1790000000, 'UTC');
        $this->assertStringStartsWith('A - B - C - D - E - F - G - H - I - J - ', $short);
    }

    /**
     * An explicit timezone makes PHP's ambient default irrelevant.
     */
    public function test_filename_ignores_php_timezone(): void {
        $before = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Auckland');
            $name = filename::make('Clase', strtotime('2026-09-19T22:00:00Z'), 'America/Cancun');
            date_default_timezone_set('America/Los_Angeles');
            $this->assertSame($name, filename::make('Clase', strtotime('2026-09-19T22:00:00Z'), 'America/Cancun'));
        } finally {
            date_default_timezone_set($before);
        }
    }

    /**
     * Base schedule for pure polling tests.
     * @return \stdClass
     */
    private function meeting(): \stdClass {
        return (object) ['name' => 'Clase', 'timezone' => 'America/Cancun',
            'startdatetime' => strtotime('2026-09-19T22:00:00Z'), 'enddatetime' => strtotime('2026-09-19T23:00:00Z'),
            'isrecurring' => 0, 'recurrenceinterval' => 1, 'recurrencedays' => '["sat"]',
            'recurrenceuntil' => strtotime('2026-10-03T05:00:00Z')];
    }

    /**
     * Pending recordings are checked rapidly; finished recordings reduce polling and old series go dormant.
     */
    public function test_dynamic_polling(): void {
        $meeting = $this->meeting();
        $now = strtotime('2026-09-20T00:00:00Z');
        $this->assertEquals($now + 600, polling::next($meeting, [], [], $now));
        $record = (object) ['starttime' => $now - 3600, 'state' => 'STARTED'];
        $this->assertEquals($now + 300, polling::next($meeting, [], [$record], $now));
        $record->state = 'ENDED';
        $this->assertEquals($now + 600, polling::next($meeting, [], [$record], $now));
        $record->state = 'FILE_GENERATED';
        $this->assertEquals($now + DAYSECS, polling::next($meeting, [], [$record], $now));
        $this->assertEquals(0, polling::next($meeting, [], [$record], $now + 31 * DAYSECS));
        $this->assertEquals(
            $meeting->enddatetime + 300,
            polling::next($meeting, [], [], $meeting->startdatetime - DAYSECS)
        );
    }

    /**
     * Recurrence preserves local end time across a DST transition without changing schedule::next_session.
     */
    public function test_recurring_polling_across_dst(): void {
        $meeting = $this->meeting();
        $meeting->timezone = 'America/New_York';
        $meeting->isrecurring = 1;
        $meeting->startdatetime = strtotime('2026-10-31T21:00:00Z');
        $meeting->enddatetime = strtotime('2026-10-31T22:00:00Z');
        $meeting->recurrenceuntil = strtotime('2026-11-14T05:00:00Z');
        // Initial future backfill schedules around the first end, not an artificial per-minute throttle.
        $this->assertEquals(
            strtotime('2026-10-31T22:05:00Z'),
            polling::next($meeting, [], [], strtotime('2026-10-30T00:00:00Z'))
        );
        // After the retention window ends there is no endless polling.
        $this->assertEquals(0, polling::next($meeting, [], [], strtotime('2027-01-01T00:00:00Z')));
        $next = local\meeting\schedule::next_session($meeting, strtotime('2026-11-06T00:00:00Z'));
        $this->assertEquals(strtotime('2026-11-07T22:00:00Z'), $next);
        $completed = (object) ['state' => 'FILE_GENERATED', 'starttime' => $meeting->startdatetime];
        $this->assertEquals(
            strtotime('2026-11-07T23:05:00Z'),
            polling::next($meeting, [], [$completed], strtotime('2026-11-06T00:00:00Z'))
        );
    }

    /**
     * Retry delays increase while remaining bounded.
     */
    public function test_backoff_bounds(): void {
        $this->assertEquals(60, polling::backoff(1));
        $this->assertEquals(120, polling::backoff(2));
        $this->assertEquals(960, polling::backoff(5));
        $this->assertEquals(3600, polling::backoff(100));
    }

    /**
     * Recreate a genuine pre-Phase-4 schema and compare every new field/index against fresh installation.
     */
    public function test_upgrade_preserves_history_and_has_no_tasks(): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/upgradelib.php');
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $dbman = $DB->get_manager();
        $dbman->drop_table(new \xmldb_table('tupmeet_recordings'));
        $dbman->drop_table(new \xmldb_table('tupmeet_conferences'));
        $table = new \xmldb_table('tupmeet');
        $dbman->drop_index($table, new \xmldb_index(
            'recordingsdue',
            XMLDB_INDEX_NOTUNIQUE,
            ['recordingsyncstatus', 'recordingsnextsync']
        ));
        foreach (
            ['recordingsyncstatus', 'recordingslastsync', 'recordingsnextsync', 'recordingsyncattempts',
                'recordingsyncversion', 'recordingshttpstatus', 'recordingsyncqueued', 'recordingscheckedversion'] as $field
        ) {
            $dbman->drop_field($table, new \xmldb_field($field));
        }
        $ids = [];
        foreach (['meet', 'calendar', 'legacy'] as $mode) {
            $id = $DB->insert_record('tupmeet', (object) ['name' => 'Historical ' . $mode, 'accountid' => 876,
                'provisionmode' => $mode, 'meetspacename' => 'spaces/Original',
                'meeturi' => 'https://meet.google.com/abc-defg-hij']);
            $ids[$id] = $DB->get_record('tupmeet', ['id' => $id]);
        }
        $tasks = $DB->count_records('task_adhoc');
        set_config('version', 2026091802, 'mod_tupmeet');
        $this->assertTrue(xmldb_tupmeet_upgrade(2026091802));
        $this->assertEquals(2026092200, get_config('mod_tupmeet', 'version'));
        $this->assertEquals($tasks, $DB->count_records('task_adhoc'));
        foreach ($ids as $id => $before) {
            $after = $DB->get_record('tupmeet', ['id' => $id]);
            foreach ($before as $field => $value) {
                $this->assertEquals($value, $after->{$field}, $field);
            }
            $this->assertSame('idle', $after->recordingsyncstatus);
            $this->assertEquals(0, $after->recordingsnextsync);
        }
        $file = new \xmldb_file(__DIR__ . '/../db/install.xml');
        $this->assertTrue($file->loadXMLStructure());
        foreach ($file->getStructure()->getTables() as $definition) {
            $this->assertTrue($dbman->table_exists($definition));
            foreach ($definition->getFields() as $field) {
                $this->assertTrue($dbman->field_exists($definition, $field), $field->getName());
            }
            foreach ($definition->getIndexes() as $index) {
                $this->assertTrue($dbman->index_exists($definition, $index), $index->getName());
            }
        }
    }

    /**
     * Both privacy tables and metadata-only external locations are declared.
     */
    public function test_privacy_metadata(): void {
        $metadata = privacy\provider::get_metadata(new \core_privacy\local\metadata\collection('mod_tupmeet'));
        $names = array_map(static fn($item) => $item->get_name(), $metadata->get_collection());
        foreach (['tupmeet_conferences', 'tupmeet_recordings', 'googlemeetrecordings', 'googledrivemetadata'] as $name) {
            $this->assertContains($name, $names);
        }
    }
}
