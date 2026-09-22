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

/**
 * Local preview, matching, idempotency and import authorization.
 * @package mod_tupmeet
 * @copyright 2026 Tecnologico Universitario Region Sureste
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_tupmeet\local\legacy\importer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_tupmeet\local\legacy\csv_export::class)]
final class legacy_import_test extends \advanced_testcase {
    use legacy_fixture;

    /**
     * Preview never inserts; import is exact and reimport never resets visibility.
     */
    public function test_import_and_idempotency(): void {
        global $DB, $USER;
        $before = $DB->get_record('tupmeet', ['id' => $this->meeting->id]);
        $plan = \mod_tupmeet\local\legacy\importer::preview($this->csv());
        $this->assertSame(1, $plan['summary']['correct']);
        $this->assertSame(0, $DB->count_records('tupmeet_legacy_recordings'));
        $events = $this->redirectEvents();
        $result = \mod_tupmeet\local\legacy\importer::confirm($plan['token']);
        $this->assertSame(['importedcount' => 1, 'duplicatecount' => 0], $result);
        $row = $DB->get_record('tupmeet_legacy_recordings', ['tupmeetid' => $this->meeting->id]);
        $this->assertEquals($USER->id, $row->importeduserid);
        $this->assertGreaterThanOrEqual(time() - 5, $row->importedat);
        $this->assertEquals(strtotime('2026-09-19T22:00:00Z'), $row->sessionstart);
        $this->assertSame('Synthetic recording.mp4', $row->originalfilename);
        $this->assertSame('https://drive.google.com/file/d/Synthetic_file-1/view', $row->exporturi);
        $this->assertEquals(1, $row->studentvisible);
        $this->assertEquals($before, $DB->get_record('tupmeet', ['id' => $this->meeting->id]));
        foreach (['tupmeet_recordings', 'tupmeet_conferences', 'task_adhoc'] as $table) {
            $this->assertSame(0, $DB->count_records($table));
        }
        $event = $events->get_events()[0];
        $this->assertInstanceOf(\mod_tupmeet\event\legacy_recordings_imported::class, $event);
        $this->assertSame($result, $event->other);
        $this->assertNull(\mod_tupmeet\local\legacy\importer::current());
        $plan = \mod_tupmeet\local\legacy\importer::preview($this->csv());
        $this->assertSame(1, $plan['summary']['duplicate']);
        $this->assertSame(
            ['importedcount' => 0, 'duplicatecount' => 1],
            \mod_tupmeet\local\legacy\importer::confirm($plan['token'])
        );
        $this->assertEquals($row, $DB->get_record('tupmeet_legacy_recordings', ['id' => $row->id]));
    }

    /**
     * Matching never guesses or selects a non-Meet activity.
     * @param string $case Scenario
     * @param string $status Expected status
     * @dataProvider matching_cases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('matching_cases')]
    public function test_matching(string $case, string $status): void {
        global $DB;
        $changes = [];
        if ($case === 'space') {
            $changes['session_name'] = '  Synthetic    course  ';
        } else if ($case === 'missing') {
            $changes['session_name'] = 'Other synthetic';
        } else if ($case === 'case') {
            $changes['session_name'] = 'synthetic course';
        } else if ($case === 'ambiguous') {
            $copy = clone $this->meeting;
            unset($copy->id);
            $id = $DB->insert_record('tupmeet', $copy);
            $cm = clone $this->cm;
            unset($cm->id);
            $cm->instance = $id;
            $DB->insert_record('course_modules', $cm);
        } else if ($case === 'invalid') {
            $changes['tupmeetid'] = '99999999';
        } else if ($case === 'explicit' || $case === 'warning') {
            $changes['tupmeetid'] = (string) $this->meeting->id;
            if ($case === 'warning') {
                $changes['session_name'] = 'Historical title';
            }
        } else if (in_array($case, ['calendar', 'legacy'], true)) {
            $changes['tupmeetid'] = (string) $this->meeting->id;
            $DB->set_field('tupmeet', 'provisionmode', $case, ['id' => $this->meeting->id]);
        } else if ($case === 'wrongmodule') {
            $DB->set_field(
                'course_modules',
                'module',
                $DB->get_field('modules', 'id', ['name' => 'page']),
                ['id' => $this->cm->id]
            );
        }
        $plan = \mod_tupmeet\local\legacy\importer::plan($this->csv($changes));
        $this->assertSame($status, $plan['rows'][0]['status']);
        $this->assertSame((int) in_array($status, ['error', 'ambiguous', 'missing'], true), $plan['blocking']);
    }

    /**
     * Matching cases.
     * @return array
     */
    public static function matching_cases(): array {
        return [['exact', 'correct'], ['space', 'correct'], ['case', 'missing'], ['missing', 'missing'],
            ['ambiguous', 'ambiguous'], ['explicit', 'correct'], ['warning', 'warning'], ['invalid', 'error'],
            ['legacy', 'error'], ['calendar', 'error'], ['wrongmodule', 'missing']];
    }

    /**
     * A blocking row stops the complete batch.
     */
    public function test_blocking_batch(): void {
        global $DB;
        $valid = legacy_csv_test::row();
        $invalid = array_replace($valid, ['session_name' => 'Missing']);
        $plan = \mod_tupmeet\local\legacy\importer::preview(legacy_csv_test::csv([$valid, $invalid]));
        $this->assertSame(1, $plan['summary']['correct']);
        $this->assertSame(1, $plan['summary']['missing']);
        try {
            \mod_tupmeet\local\legacy\importer::confirm($plan['token']);
            $this->fail('Blocked batch imported');
        } catch (\moodle_exception $e) {
            $this->assertSame('legacyblocked', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('tupmeet_legacy_recordings'));
    }

    /**
     * Duplicates within the CSV are omitted without blocking.
     */
    public function test_duplicate_csv(): void {
        $row = legacy_csv_test::row();
        $plan = \mod_tupmeet\local\legacy\importer::preview(legacy_csv_test::csv([$row, $row]));
        $this->assertSame(1, $plan['summary']['duplicate']);
        $this->assertSame(
            ['importedcount' => 1, 'duplicatecount' => 1],
            \mod_tupmeet\local\legacy\importer::confirm($plan['token'])
        );
    }

    /**
     * Recheck native duplicates that appeared after preview.
     */
    public function test_native_duplicate_revalidated(): void {
        global $DB;
        $plan = \mod_tupmeet\local\legacy\importer::preview($this->csv());
        $DB->insert_record('tupmeet_recordings', (object) ['tupmeetid' => $this->meeting->id,
            'recordingname' => 'conferenceRecords/Synthetic/recordings/Synthetic', 'drivefileid' => 'Synthetic_file-1']);
        $this->assertSame(
            ['importedcount' => 0, 'duplicatecount' => 1],
            \mod_tupmeet\local\legacy\importer::confirm($plan['token'])
        );
        $this->assertSame(1, \mod_tupmeet\local\legacy\importer::plan($this->csv())['summary']['duplicate']);
    }

    /**
     * Changed destination metadata invalidates confirmation.
     * @param string $field Field
     * @param string $value Changed value
     * @dataProvider changes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('changes')]
    public function test_resolution_change(string $field, string $value): void {
        global $DB;
        $plan = \mod_tupmeet\local\legacy\importer::preview($this->csv(['tupmeetid' => (string) $this->meeting->id]));
        $DB->set_field('tupmeet', $field, $value, ['id' => $this->meeting->id]);
        $this->expectException(\moodle_exception::class);
        \mod_tupmeet\local\legacy\importer::confirm($plan['token']);
    }

    /**
     * Destination drift.
     * @return array
     */
    public static function changes(): array {
        return [['name', 'Renamed synthetic'], ['timezone', 'America/New_York'], ['provisionmode', 'calendar']];
    }

    /**
     * Tokens, content hash, actor and expiry are checked server-side.
     * @param string $case Corruption
     * @dataProvider tampering
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tampering')]
    public function test_preview_tampering(string $case): void {
        $plan = \mod_tupmeet\local\legacy\importer::preview($this->csv());
        $cache = \cache::make('mod_tupmeet', 'legacy_preview');
        $stored = $cache->get('current');
        switch ($case) {
            case 'csv':
                $stored['csv'] .= 'corrupted';
                break;
            case 'user':
                $stored['userid']++;
                break;
            case 'expiry':
                $stored['expires'] = time() - 1;
                break;
            case 'token':
                $plan['token'] = str_repeat('0', 64);
                break;
        }
        $cache->set('current', $stored);
        $this->expectException(\moodle_exception::class);
        \mod_tupmeet\local\legacy\importer::confirm($plan['token']);
    }

    /**
     * Corrupted preview cases.
     * @return array
     */
    public static function tampering(): array {
        return [['csv'], ['user'], ['expiry'], ['token']];
    }

    /**
     * Hidden browser rows never replace the server CSV.
     */
    public function test_hidden_rows_ignored(): void {
        global $DB;
        $plan = \mod_tupmeet\local\legacy\importer::preview($this->csv(['visible' => '0']));
        $_POST += ['rows' => [['studentvisible' => 1]], 'tupmeetid' => 999999, 'drive_url' => 'https://evil.example'];
        \mod_tupmeet\local\legacy\importer::confirm($plan['token']);
        $this->assertEquals(0, $DB->get_field('tupmeet_legacy_recordings', 'studentvisible', ['tupmeetid' => $this->meeting->id]));
    }

    /**
     * Authorization is enforced by the service.
     * @param string $case Invalid request
     * @dataProvider request_failures
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('request_failures')]
    public function test_request_security(string $case): void {
        $plan = \mod_tupmeet\local\legacy\importer::preview($this->csv());
        if ($case === 'get') {
            $_SERVER['REQUEST_METHOD'] = 'GET';
        } else if ($case === 'sesskey') {
            $_POST['sesskey'] = 'invalid';
        } else {
            $this->setUser($case === 'teacher' ? $this->teacher : $this->student);
            $this->post();
        }
        $this->expectException(\moodle_exception::class);
        \mod_tupmeet\local\legacy\importer::confirm($plan['token']);
    }

    /**
     * Forbidden requests.
     * @return array
     */
    public static function request_failures(): array {
        return [['get'], ['sesskey'], ['teacher'], ['student']];
    }

    /**
     * Template and six-column catalog have safe cells and no Google identifiers.
     */
    public function test_exports(): void {
        global $DB;
        $DB->set_field('tupmeet', 'name', '=1+1', ['id' => $this->meeting->id]);
        $csv = \mod_tupmeet\local\legacy\csv_export::writer(true)->print_csv_data(true);
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString('tupmeetid,courseid,course_shortname,activity_name,timezone,publicationmode', $csv);
        foreach (['accountid', 'spaces/', 'meetingUri', 'drivefileid', 'cohostemail', 'Calendar'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $csv);
        }
        foreach (['=1', '+1', '-1', '@x', " \t=1"] as $value) {
            $this->assertSame("'" . $value, \mod_tupmeet\local\legacy\csv_export::cell($value));
        }
        $template = \mod_tupmeet\local\legacy\csv_export::writer(false)->print_csv_data(true);
        $this->assertSame(
            implode(',', \mod_tupmeet\local\legacy\csv_validator::HEADERS),
            trim(\core_text::trim_utf8_bom($template))
        );
    }

    /**
     * Concurrent importer lock causes a safe retry without inserts.
     */
    public function test_import_lock(): void {
        global $CFG;
        $CFG->lock_factory = '\core\lock\db_record_lock_factory';
        $plan = \mod_tupmeet\local\legacy\importer::preview($this->csv());
        $lock = \core\lock\lock_config::get_lock_factory('mod_tupmeet')->get_lock('legacy-import', 0);
        try {
            $this->expectException(\moodle_exception::class);
            \mod_tupmeet\local\legacy\importer::confirm($plan['token']);
        } finally {
            $lock->release();
        }
    }

    /**
     * Hundreds of distinct files require bounded duplicate queries, not one query per row.
     */
    public function test_batched_duplicate_queries(): void {
        global $DB;
        $rows = [];
        for ($i = 0; $i < 501; $i++) {
            $rows[] = array_replace(legacy_csv_test::row(), [
                'drive_url' => 'https://drive.google.com/file/d/Synthetic_batch_' . $i . '/view',
            ]);
        }
        $before = $DB->perf_get_queries();
        $plan = \mod_tupmeet\local\legacy\importer::plan(legacy_csv_test::csv($rows));
        $this->assertSame(501, $plan['summary']['correct']);
        $this->assertLessThan(20, $DB->perf_get_queries() - $before);
        $this->assertSame(0, $DB->count_records('tupmeet_legacy_recordings'));
    }

    /**
     * An explicit historical title warning can be imported without renaming the destination.
     */
    public function test_warning_import_preserves_title(): void {
        global $DB;
        $record = $this->imported(['session_name' => 'Previous synthetic title',
            'tupmeetid' => (string) $this->meeting->id]);
        $this->assertSame('Previous synthetic title', $record->sessionname);
        $this->assertSame('Synthetic course', $DB->get_field('tupmeet', 'name', ['id' => $this->meeting->id]));
    }

    /**
     * A newer review invalidates old browser tabs in the same session.
     */
    public function test_only_one_session_preview(): void {
        $first = \mod_tupmeet\local\legacy\importer::preview($this->csv());
        \mod_tupmeet\local\legacy\importer::preview($this->csv(['visible' => '0']));
        $this->expectException(\moodle_exception::class);
        \mod_tupmeet\local\legacy\importer::confirm($first['token']);
    }

    /**
     * CSV explicitly decides visibility even when native publication is manual.
     */
    public function test_manual_activity_keeps_csv_decision(): void {
        global $DB;
        $DB->set_field('tupmeet', 'publicationmode', 'manual', ['id' => $this->meeting->id]);
        $record = $this->imported(['visible' => '1']);
        $this->assertEquals(1, $record->studentvisible);
        $this->assertSame('manual', $DB->get_field('tupmeet', 'publicationmode', ['id' => $this->meeting->id]));
    }
}
