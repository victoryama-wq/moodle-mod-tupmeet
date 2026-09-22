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

namespace mod_tupmeet\output;

use mod_tupmeet\local\google\recording_service;

/**
 * Server-filtered academic catalog; contexts contain only display values.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_list {
    /**
     * Query permitted rows before constructing HTML.
     * @param \stdClass $meeting Activity
     * @param \context_module $context Module context
     * @param int $cmid Module ID
     * @param int $page Zero-based page
     * @return array Safe template data
     */
    public static function data(\stdClass $meeting, \context_module $context, int $cmid, int $page = 0): array {
        global $DB, $OUTPUT;
        require_capability('mod/tupmeet:view', $context);
        $manage = has_capability('moodle/course:manageactivities', $context);
        $visible = $manage ? '' : ' AND r.studentvisible = 1';
        $params = ['nativeid' => $meeting->id, 'legacyid' => $meeting->id];
        $nativekey = $DB->sql_concat("'n'", $DB->sql_cast_to_char('r.id'));
        $legacykey = $DB->sql_concat("'l'", $DB->sql_cast_to_char('r.id'));
        $nativefields = $manage ? ', r.studentvisible, r.drivefilename, r.desiredfilename' : '';
        $legacyfields = $manage ? ", r.studentvisible, '' AS drivefilename, '' AS desiredfilename" : '';
        $union = "SELECT $nativekey AS rowkey, r.id, 'native' AS origin, r.state, r.drivefileid, r.exporturi,
                        r.partnumber, c.starttime AS conferencestart, r.starttime, r.startnanos, r.recordingname
                        $nativefields
                   FROM {tupmeet_recordings} r JOIN {tupmeet_conferences} c ON c.id = r.conferenceid
                  WHERE r.tupmeetid = :nativeid $visible
                  UNION ALL
                 SELECT $legacykey AS rowkey, r.id, 'legacy' AS origin, 'FILE_GENERATED' AS state,
                        r.drivefileid, r.exporturi, r.partnumber, r.sessionstart AS conferencestart,
                        r.sessionstart AS starttime, 0 AS startnanos, '' AS recordingname $legacyfields
                   FROM {tupmeet_legacy_recordings} r WHERE r.tupmeetid = :legacyid $visible";
        $total = $DB->count_records_sql('SELECT COUNT(1) FROM (' . $union . ') combined', $params);
        $page = max(0, min($page, (int) floor(max(0, $total - 1) / 50)));
        $records = $DB->get_records_sql('SELECT * FROM (' . $union . ') combined
            ORDER BY conferencestart DESC, partnumber, starttime, startnanos, recordingname, rowkey', $params, $page * 50, 50);
        $rows = [];
        foreach ($records as $record) {
            $state = in_array($record->state, ['STARTED', 'ENDED', 'FILE_GENERATED'], true) ? $record->state : 'ENDED';
            $row = [
                'date' => userdate($record->conferencestart, get_string('recordingdateformat', 'tupmeet'), $meeting->timezone),
                'hours' => userdate($record->conferencestart, '%H:%M', $meeting->timezone),
                'state' => get_string('recordingsstate' . $state, 'tupmeet'),
                'part' => $record->partnumber > 1 ? get_string('recordingpart', 'tupmeet', $record->partnumber) : '',
            ];
            if ($state === 'FILE_GENERATED' && recording_service::valid_export($record->exporturi, $record->drivefileid ?? '')) {
                $row['url'] = $record->exporturi;
            }
            if ($manage) {
                $visible = (bool) $record->studentvisible;
                $row += [
                    'recordingid' => (int) $record->id,
                    'filename' => $record->drivefilename ?? $record->desiredfilename ?? '',
                    'action' => ($visible ? 'hide' : 'show') . ($record->origin === 'legacy' ? 'legacy' : ''),
                    'visibilitylabel' => get_string($visible ? 'recordinghide' : 'recordingshow', 'tupmeet'),
                    'visibilitystate' => get_string($visible ? 'recordingvisible' : 'recordinghidden', 'tupmeet'),
                    'visibilityicon' => $OUTPUT->pix_icon($visible ? 't/hide' : 't/show', '', 'moodle'),
                ];
            }
            $rows[] = $row;
        }
        $data = ['rows' => $rows, 'hasrows' => !empty($rows), 'manage' => $manage,
            'paging' => $OUTPUT->paging_bar(
                $total,
                $page,
                50,
                new \moodle_url('/mod/tupmeet/view.php', ['id' => $cmid]),
                'recordingpage'
            )];
        if ($manage) {
            $data += ['actionurl' => (new \moodle_url('/mod/tupmeet/recordings.php'))->out(false),
                'cmid' => $cmid, 'sesskey' => sesskey()];
        }
        return $data;
    }

    /**
     * Render local academic data.
     * @param \stdClass $meeting Activity
     * @param \context_module $context Module context
     * @param int $cmid Module ID
     * @param int $page Zero-based page
     * @return string
     */
    public static function render(\stdClass $meeting, \context_module $context, int $cmid, int $page = 0): string {
        global $OUTPUT;
        return $OUTPUT->render_from_template('mod_tupmeet/recordings_table', self::data($meeting, $context, $cmid, $page));
    }
}
