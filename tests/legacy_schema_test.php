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
require_once(__DIR__ . '/fixtures/legacy.php');
require_once(__DIR__ . '/legacy_csv_test.php');
require_once(__DIR__ . '/../db/upgrade.php');

/**
 * Actual XMLDB upgrade, unique identity and transaction rollback.
 * @package mod_tupmeet
 * @copyright 2026 Tecnologico Universitario Region Sureste
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('xmldb_tupmeet_upgrade')]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_tupmeet\local\legacy\importer::class)]
final class legacy_schema_test extends \advanced_testcase {
    use legacy_fixture;

    /**
     * Fresh schema and upgrade both create every specified field, key and index.
     */
    public function test_schema_and_upgrade(): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/upgradelib.php');
        $this->preventResetByRollback();
        $xml = new \xmldb_file(__DIR__ . '/../db/install.xml');
        $this->assertTrue($xml->loadXMLStructure());
        $definition = $xml->getStructure()->getTable('tupmeet_legacy_recordings');
        $this->assertCount(15, $definition->getFields());
        $metadata = \mod_tupmeet\privacy\provider::get_metadata(new \core_privacy\local\metadata\collection('mod_tupmeet'));
        $tables = [];
        foreach ($metadata->get_collection() as $item) {
            $tables[$item->get_name()] = $item;
        }
        $this->assertArrayHasKey('tupmeet_legacy_recordings', $tables);
        $this->assertEqualsCanonicalizing(
            ['sessionname', 'sessionstart', 'partnumber', 'drivefileid', 'exporturi', 'originalfilename',
                'studentvisible', 'visibilityuserid', 'visibilitymodified', 'importeduserid', 'importedat',
                'timecreated', 'timemodified'],
            array_keys($tables['tupmeet_legacy_recordings']->get_privacy_fields())
        );
        $dbman = $DB->get_manager();
        foreach ($definition->getFields() as $field) {
            $this->assertTrue($dbman->field_exists($definition, $field));
        }
        $before = $DB->get_record('tupmeet', ['id' => $this->meeting->id]);
        $dbman->drop_table($definition);
        set_config('version', 2026092101, 'mod_tupmeet');
        $this->assertTrue(xmldb_tupmeet_upgrade(2026092101));
        $this->assertEquals(2026092200, get_config('mod_tupmeet', 'version'));
        foreach ($definition->getFields() as $field) {
            $this->assertTrue($dbman->field_exists($definition, $field));
        }
        foreach ($definition->getIndexes() as $index) {
            $this->assertTrue($dbman->index_exists($definition, $index));
        }
        $keys = $definition->getKeys();
        $this->assertCount(2, $keys);
        $this->assertEquals($before, $DB->get_record('tupmeet', ['id' => $this->meeting->id]));
        $this->assertSame(0, $DB->count_records('task_adhoc'));
        $this->assertSame(0, $DB->count_records('tupmeet_legacy_recordings'));
    }

    /**
     * The database rejects duplicate Drive identity independent of application checks.
     */
    public function test_unique_identity(): void {
        global $DB;
        $record = $this->imported();
        unset($record->id);
        $this->expectException(\dml_write_exception::class);
        $DB->insert_record('tupmeet_legacy_recordings', $record);
    }

    /**
     * A write failure after the first insert rolls back all newly inserted references.
     */
    public function test_atomic_rollback(): void {
        global $DB;
        $this->preventResetByRollback();
        $first = legacy_csv_test::row();
        $second = array_replace($first, ['drive_url' => 'https://drive.google.com/file/d/Synthetic_second/view']);
        $plan = \mod_tupmeet\local\legacy\importer::preview(legacy_csv_test::csv([$first, $second]));
        $writer = new class extends \mod_tupmeet\local\legacy\importer {
            /** @var int Number of attempted inserts. */
            private static int $writes = 0;

            /**
             * Inject a database failure at the persistence boundary.
             * @param \stdClass $record Validated reference
             * @return int
             */
            protected static function insert(\stdClass $record): int {
                if (++self::$writes === 2) {
                    throw new \dml_write_exception('Synthetic database failure');
                }
                return parent::insert($record);
            }
        };
        try {
            $writer::confirm($plan['token']);
            $this->fail('Injected database error was ignored');
        } catch (\dml_write_exception $e) {
            $this->assertSame(0, $DB->count_records('tupmeet_legacy_recordings'));
        }
        // Locks and original preview remain usable after rollback.
        $this->assertSame(
            ['importedcount' => 2, 'duplicatecount' => 0],
            \mod_tupmeet\local\legacy\importer::confirm($plan['token'])
        );
    }

    /**
     * Preview output escapes names and never serializes records into hidden fields.
     */
    public function test_safe_preview_and_endpoint(): void {
        $plan = \mod_tupmeet\local\legacy\importer::preview($this->csv([
            'session_name' => '<script>synthetic</script>', 'tupmeetid' => (string) $this->meeting->id,
        ]));
        $html = \mod_tupmeet\output\legacy_preview::render($plan);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('Synthetic_file-1', $html);
        $this->assertStringNotContainsString('drive.google.com', $html);
        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringContainsString('sesskey', $html);
        $code = file_get_contents(__DIR__ . '/../legacy.php');
        $this->assertStringContainsString('require_login();', $code);
        $this->assertStringContainsString("require_capability('moodle/site:config'", $code);
        $this->assertStringContainsString("'tupmeetlegacy'", $code);
        $this->assertStringNotContainsString('new curl', $code);
        $this->assertStringNotContainsString('oauth', $code);
    }
}
