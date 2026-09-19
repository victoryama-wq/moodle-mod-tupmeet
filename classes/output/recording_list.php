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
use mod_tupmeet\local\recording\recording_manager;

/**
 * Capability-gated metadata catalog. No recording information or link reaches students.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_list {
    /**
     * A protected POST form, with Moodle's session key.
     * @param int $cmid Module ID
     * @param string $action Action
     * @param int $recordingid Local recording ID
     * @return string
     */
    private static function button(int $cmid, string $action, int $recordingid = 0): string {
        global $OUTPUT;
        $url = new \moodle_url('/mod/tupmeet/recordings.php', [
            'id' => $cmid, 'action' => $action, 'recordingid' => $recordingid,
        ]);
        $label = get_string($action === 'sync' ? 'recordingssync' : 'recordingsretry', 'tupmeet');
        return $OUTPUT->single_button($url, $label, 'post');
    }

    /**
     * Render bounded, paginated local metadata without any HTTP.
     * @param \stdClass $meeting Activity
     * @param \context_module $context Module context
     * @param int $cmid Module ID
     * @param int $page Zero-based page
     * @return string HTML
     */
    public static function render(\stdClass $meeting, \context_module $context, int $cmid, int $page = 0): string {
        global $DB, $OUTPUT;
        if (!has_capability('moodle/course:manageactivities', $context)) {
            return '';
        }
        $html = $OUTPUT->heading(get_string('recordings', 'tupmeet'), 3);
        $status = in_array($meeting->recordingsyncstatus, ['idle', 'pending', 'syncing', 'ready', 'error'], true) ?
            $meeting->recordingsyncstatus : 'error';
        $html .= \html_writer::div(get_string('recordingssync' . $status, 'tupmeet'));
        if ($meeting->recordingslastsync) {
            $html .= \html_writer::div(get_string('recordingslastsync', 'tupmeet') . ': ' .
                userdate($meeting->recordingslastsync, '', $meeting->timezone));
        }
        if ($status === 'error') {
            $html .= $OUTPUT->notification(get_string('recordingssyncerrorhelp', 'tupmeet') .
                ($meeting->recordingshttpstatus ? ' HTTP ' . (int) $meeting->recordingshttpstatus : ''), 'warning');
        }
        if (recording_manager::eligible($meeting)) {
            $html .= self::button($cmid, 'sync');
        }
        if ($meeting->provisionmode === 'calendar') {
            $html .= \html_writer::div(get_string('recordingshistorical', 'tupmeet'));
        }
        $total = $DB->count_records('tupmeet_recordings', ['tupmeetid' => $meeting->id]);
        if (!$total) {
            return $html . \html_writer::div(get_string('recordingsnone', 'tupmeet'));
        }
        $page = max(0, min($page, (int) floor(($total - 1) / 50)));
        $records = $DB->get_records_sql(
            'SELECT r.*, c.starttime AS conferencestart FROM {tupmeet_recordings} r
               JOIN {tupmeet_conferences} c ON c.id = r.conferenceid
              WHERE r.tupmeetid = :id ORDER BY c.starttime DESC, r.starttime, r.startnanos, r.recordingname',
            ['id' => $meeting->id],
            $page * 50,
            50
        );
        $table = new \html_table();
        $table->head = array_map(
            static fn($key) => get_string($key, 'tupmeet'),
            ['recordingssession', 'recordingsstate', 'recordingsfile', 'recordingsrename']
        );
        foreach ($records as $record) {
            $state = in_array($record->state, ['STARTED', 'ENDED', 'FILE_GENERATED'], true) ? $record->state : 'ENDED';
            $file = s($record->drivefilename ?? $record->desiredfilename ?? '');
            if ($record->originalfilename !== null) {
                $file .= \html_writer::div(get_string('recordingsoriginal', 'tupmeet') . ': ' . s($record->originalfilename));
            }
            if (
                $record->state === 'FILE_GENERATED' &&
                recording_service::valid_export($record->exporturi, $record->drivefileid ?? '')
            ) {
                $file .= \html_writer::div(\html_writer::link(
                    $record->exporturi,
                    get_string('recordingsopen', 'tupmeet'),
                    ['target' => '_blank', 'rel' => 'noopener noreferrer']
                ));
            }
            $rename = in_array($record->renamestatus, ['unavailable', 'pending', 'ready', 'error', 'skipped'], true) ?
                $record->renamestatus : 'error';
            $renametext = get_string('recordingsrename' . $rename, 'tupmeet');
            if ($record->renamehttpstatus) {
                $renametext .= ' HTTP ' . (int) $record->renamehttpstatus;
            }
            if (
                in_array($rename, ['error', 'skipped'], true) && $record->desiredfilename &&
                $meeting->provisionmode === 'meet'
            ) {
                $renametext .= self::button($cmid, 'rename', (int) $record->id);
            }
            $table->data[] = [userdate($record->conferencestart, '', $meeting->timezone),
                get_string('recordingsstate' . $state, 'tupmeet'), $file, $renametext];
        }
        return $html . \html_writer::table($table) . $OUTPUT->paging_bar(
            $total,
            $page,
            50,
            new \moodle_url('/mod/tupmeet/view.php', ['id' => $cmid]),
            'recordingpage'
        );
    }
}
