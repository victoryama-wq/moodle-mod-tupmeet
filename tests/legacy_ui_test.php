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

/**
 * Academic projection, explicit visibility actions and personal attribution.
 * @package mod_tupmeet
 * @copyright 2026 Tecnologico Universitario Region Sureste
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_tupmeet\output\recording_list::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_tupmeet\local\legacy\visibility::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_tupmeet\privacy\provider::class)]
final class legacy_ui_test extends \core_privacy\tests\provider_testcase {
    use legacy_fixture;

    /**
     * Hidden legacy metadata never reaches a student template or HTML.
     * @param int $visible Visibility
     * @dataProvider visibility_cases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('visibility_cases')]
    public function test_student_projection(int $visible): void {
        $record = $this->imported(['visible' => (string) $visible]);
        $this->setUser($this->student);
        $data = \mod_tupmeet\output\recording_list::data($this->meeting, $this->context, (int) $this->cm->id);
        $html = \mod_tupmeet\output\recording_list::render($this->meeting, $this->context, (int) $this->cm->id);
        $this->assertCount($visible, $data['rows']);
        $this->assertFalse($data['manage']);
        foreach (['origin', 'drivefileid', 'originalfilename', 'studentvisible', 'importeduserid', 'legacy'] as $key) {
            $this->assertStringNotContainsString($key, json_encode($data));
        }
        $this->assertStringNotContainsString($record->originalfilename, $html);
        if ($visible) {
            $this->assertSame(get_string('recordingsstateFILE_GENERATED', 'tupmeet'), $data['rows'][0]['state']);
            $this->assertStringContainsString($record->exporturi, $html);
            $this->assertStringContainsString('target="_blank"', $html);
            $this->assertStringContainsString('rel="noopener noreferrer"', $html);
            $this->assertStringContainsString(get_string('recordingwatch', 'tupmeet'), $html);
        } else {
            $this->assertStringNotContainsString($record->drivefileid, $html);
            $this->assertStringNotContainsString($record->exporturi, $html);
        }
    }

    /**
     * Both visibility decisions.
     * @return array
     */
    public static function visibility_cases(): array {
        return [[0], [1]];
    }

    /**
     * Native and legacy local IDs may overlap; chronological order is still global.
     */
    public function test_combined_order_and_pagination(): void {
        global $DB;
        $row = $this->imported();
        $second = clone $row;
        unset($second->id);
        $second->drivefileid = 'Synthetic_second';
        $second->exporturi = 'https://drive.google.com/file/d/Synthetic_second/view';
        $second->partnumber = 2;
        $DB->insert_record('tupmeet_legacy_recordings', $second);
        $conferenceid = $DB->insert_record('tupmeet_conferences', (object) ['tupmeetid' => $this->meeting->id,
            'conferencename' => 'conferenceRecords/NativeSynthetic', 'starttime' => $row->sessionstart + 86400]);
        $DB->insert_record('tupmeet_recordings', (object) ['tupmeetid' => $this->meeting->id, 'conferenceid' => $conferenceid,
            'recordingname' => 'conferenceRecords/NativeSynthetic/recordings/Synthetic', 'state' => 'FILE_GENERATED',
            'drivefileid' => 'Native_synthetic', 'exporturi' => 'https://drive.google.com/file/d/Native_synthetic/view',
            'studentvisible' => 1, 'partnumber' => 1]);
        $this->setUser($this->student);
        $data = \mod_tupmeet\output\recording_list::data($this->meeting, $this->context, (int) $this->cm->id);
        $this->assertCount(3, $data['rows']);
        $this->assertStringContainsString('Native_synthetic', $data['rows'][0]['url']);
        $this->assertSame('', $data['rows'][1]['part']);
        $this->assertSame(get_string('recordingpart', 'tupmeet', 2), $data['rows'][2]['part']);
        for ($i = 0; $i < 51; $i++) {
            $copy = clone $second;
            $copy->drivefileid = 'Hidden_' . $i;
            $copy->exporturi = 'https://drive.google.com/file/d/Hidden_' . $i . '/view';
            $copy->studentvisible = 0;
            $DB->insert_record('tupmeet_legacy_recordings', $copy);
        }
        $this->assertCount(
            3,
            \mod_tupmeet\output\recording_list::data($this->meeting, $this->context, (int) $this->cm->id)['rows']
        );
        $this->setUser($this->teacher);
        $this->assertCount(
            50,
            \mod_tupmeet\output\recording_list::data($this->meeting, $this->context, (int) $this->cm->id)['rows']
        );
        $this->assertCount(
            4,
            \mod_tupmeet\output\recording_list::data($this->meeting, $this->context, (int) $this->cm->id, 1)['rows']
        );
    }

    /**
     * Explicit eye actions update only attribution and visibility, emitting local IDs.
     * @param int $initial Initial visibility
     * @dataProvider visibility_cases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('visibility_cases')]
    public function test_eye(int $initial): void {
        global $DB, $OUTPUT;
        $record = $this->imported(['visible' => (string) $initial]);
        $this->setUser($this->teacher);
        $this->post();
        $row = \mod_tupmeet\output\recording_list::data($this->meeting, $this->context, (int) $this->cm->id)['rows'][0];
        $this->assertSame($initial ? 'hidelegacy' : 'showlegacy', $row['action']);
        $this->assertSame($OUTPUT->pix_icon($initial ? 't/hide' : 't/show', '', 'moodle'), $row['visibilityicon']);
        $sink = $this->redirectEvents();
        $this->assertTrue(
            \mod_tupmeet\local\recording\actions::execute($this->cm, $this->context, $row['action'], (int) $record->id)
        );
        $after = $DB->get_record('tupmeet_legacy_recordings', ['id' => $record->id]);
        $this->assertEquals(1 - $initial, $after->studentvisible);
        $this->assertEquals($this->teacher->id, $after->visibilityuserid);
        $this->assertGreaterThan(0, $after->visibilitymodified);
        foreach ($record as $field => $value) {
            if (!in_array($field, ['studentvisible', 'visibilityuserid', 'visibilitymodified'], true)) {
                $this->assertEquals($value, $after->{$field});
            }
        }
        $event = $sink->get_events()[0];
        $this->assertSame('tupmeet_legacy_recordings', $event->objecttable);
        $this->assertEquals($record->id, $event->objectid);
        $this->assertEquals(['visible' => 1 - $initial, 'activityid' => $this->meeting->id], $event->other);
        \mod_tupmeet\local\recording\actions::execute($this->cm, $this->context, $row['action'], (int) $record->id);
        $this->assertCount(1, $sink->get_events());
        $this->assertSame(0, $DB->count_records('task_adhoc'));
    }

    /**
     * All guards are checked even when requesting a known local recording ID.
     * @param string $case Invalid request
     * @dataProvider forbidden_actions
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('forbidden_actions')]
    public function test_eye_security(string $case): void {
        global $DB;
        $record = $this->imported();
        $this->setUser($case === 'student' ? $this->student : $this->teacher);
        $this->post();
        if ($case === 'get') {
            $_SERVER['REQUEST_METHOD'] = 'GET';
        } else if ($case === 'sesskey') {
            $_POST['sesskey'] = 'wrong';
        } else if ($case === 'ownership') {
            $DB->set_field('tupmeet_legacy_recordings', 'tupmeetid', 999999, ['id' => $record->id]);
        } else if ($case === 'context') {
            $this->cm->id++;
        }
        $this->expectException(\moodle_exception::class);
        \mod_tupmeet\local\recording\actions::execute($this->cm, $this->context, 'hidelegacy', (int) $record->id);
    }

    /**
     * Request guard cases.
     * @return array
     */
    public static function forbidden_actions(): array {
        return [['student'], ['get'], ['sesskey'], ['ownership'], ['context']];
    }

    /**
     * Historic title and relationship survive later activity edits.
     */
    public function test_title_and_local_delete(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/tupmeet/lib.php');
        $record = $this->imported();
        $DB->set_field('tupmeet', 'name', 'Updated local title', ['id' => $this->meeting->id]);
        $this->assertEquals($record, $DB->get_record('tupmeet_legacy_recordings', ['id' => $record->id]));
        $this->assertTrue(tupmeet_delete_instance($this->meeting->id));
        $this->assertSame(0, $DB->count_records('tupmeet_legacy_recordings'));
        $this->assertSame(0, $DB->count_records('task_adhoc'));
    }

    /**
     * Export and erasure isolate importer/visibility attribution from institutional metadata.
     * @param string $method Erasure entrypoint
     * @dataProvider privacy_cases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('privacy_cases')]
    public function test_privacy(string $method): void {
        global $DB;
        $record = $this->imported();
        $DB->update_record('tupmeet_legacy_recordings', (object) ['id' => $record->id,
            'visibilityuserid' => $this->teacher->id, 'visibilitymodified' => time(), 'importeduserid' => $this->student->id]);
        foreach ([$this->teacher, $this->student] as $actor) {
            $this->assertContains((int) $this->context->id, array_map(
                'intval',
                \mod_tupmeet\privacy\provider::get_contexts_for_userid((int) $actor->id)->get_contextids()
            ));
        }
        $users = new \core_privacy\local\request\userlist($this->context, 'mod_tupmeet');
        \mod_tupmeet\privacy\provider::get_users_in_context($users);
        $this->assertEqualsCanonicalizing([$this->teacher->id, $this->student->id], $users->get_userids());
        $approved = new \core_privacy\local\request\approved_contextlist($this->student, 'mod_tupmeet', [$this->context->id]);
        \mod_tupmeet\privacy\provider::export_user_data($approved);
        $data = \core_privacy\local\request\writer::with_context($this->context)->get_data([
            get_string('legacyattribution', 'tupmeet'), 'importeduserid']);
        $this->assertCount(1, $data->recordings);
        $this->assertEqualsCanonicalizing(['id', 'importeduserid', 'importedat'], array_keys((array) $data->recordings[0]));
        if ($method === 'user') {
            \mod_tupmeet\privacy\provider::delete_data_for_user($approved);
        } else if ($method === 'users') {
            \mod_tupmeet\privacy\provider::delete_data_for_users(new \core_privacy\local\request\approved_userlist(
                $this->context,
                'mod_tupmeet',
                [$this->teacher->id, $this->student->id]
            ));
        } else {
            \mod_tupmeet\privacy\provider::delete_data_for_all_users_in_context($this->context);
        }
        $after = $DB->get_record('tupmeet_legacy_recordings', ['id' => $record->id]);
        $this->assertEquals(0, $after->importeduserid);
        $this->assertEquals($method === 'user' ? $this->teacher->id : 0, $after->visibilityuserid);
        if ($method !== 'user') {
            $this->assertEquals(0, $after->visibilitymodified);
        }
        foreach ($record as $field => $value) {
            if (!in_array($field, ['importeduserid', 'visibilityuserid', 'visibilitymodified'], true)) {
                $this->assertEquals($value, $after->{$field}, $field);
            }
        }
    }

    /**
     * Privacy deletion entrypoints.
     * @return array
     */
    public static function privacy_cases(): array {
        return [['user'], ['users'], ['context']];
    }
}
