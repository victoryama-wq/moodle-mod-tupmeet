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

use mod_tupmeet\local\google\calendar_service;
use mod_tupmeet\local\google\cohost_exception;
use mod_tupmeet\local\meeting\provisioning;

/**
 * Separate joining availability from teacher-only artifact configuration details.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class meeting_status {
    /**
     * Render stored state only; viewing never contacts Google or queues work.
     *
     * @param \stdClass $meeting Activity
     * @param \context_module $context Capability context
     * @param int $cmid Course module ID
     * @return string HTML
     */
    public static function render(\stdClass $meeting, \context_module $context, int $cmid): string {
        global $OUTPUT, $DB;
        $manage = has_capability('moodle/course:manageactivities', $context);
        $meetfirst = provisioning::is_meet($meeting);
        $ready = ($meetfirst ? ($meeting->spacestatus ?? '') === 'ready' : $meeting->syncstatus === 'ready') &&
            calendar_service::valid_meet_uri($meeting->meeturi ?? '');
        $retryurl = new \moodle_url('/mod/tupmeet/retry.php', ['id' => $cmid]);
        $html = '';
        if ($ready) {
            $html .= \html_writer::link(new \moodle_url($meeting->meeturi), get_string('joinmeet', 'tupmeet'), [
                'class' => 'btn btn-primary', 'target' => '_blank', 'rel' => 'noopener noreferrer',
            ]);
        } else if (!$meetfirst) {
            $status = in_array($meeting->syncstatus, ['pending', 'error', 'legacy'], true) ? $meeting->syncstatus : 'error';
            $html .= $OUTPUT->notification(get_string('sync' . $status, 'tupmeet'), $status === 'error' ? 'warning' : 'info');
            if ($manage && $status !== 'legacy') {
                $html .= $OUTPUT->single_button($retryurl, get_string('retrysync', 'tupmeet'), 'post');
            }
        } else {
            $html .= $OUTPUT->notification(get_string('meetingunavailable', 'tupmeet'), 'info');
        }
        if (!$manage) {
            return $html;
        }
        $status = in_array($meeting->meetconfigstatus, ['pending', 'ready', 'error', 'unconfigured'], true)
            ? $meeting->meetconfigstatus : 'error';
        $table = new \html_table();
        if (!$meetfirst) {
            $table->data[] = [
                get_string('meetsettings', 'tupmeet'), get_string($ready ? 'meetingavailable' : 'syncpending', 'tupmeet'),
            ];
        }
        if ($meetfirst) {
            $spacestatus = in_array($meeting->spacestatus, ['pending', 'creating', 'ready', 'error', 'uncertain'], true)
                ? $meeting->spacestatus : 'uncertain';
            $table->data[] = [get_string('spacelabel', 'tupmeet'), get_string('space' . $spacestatus, 'tupmeet')];
            $calendarstatus = in_array($meeting->syncstatus, ['pending', 'ready', 'error'], true)
                ? $meeting->syncstatus : 'error';
            $table->data[] = ['Calendar', get_string('calendar' . $calendarstatus, 'tupmeet')];
            if ($spacestatus === 'uncertain') {
                $html .= $OUTPUT->notification(get_string('spaceuncertainnotice', 'tupmeet'), 'warning');
            } else if ($spacestatus === 'error') {
                $spaceurl = new \moodle_url('/mod/tupmeet/retry.php', ['id' => $cmid, 'target' => 'space']);
                $html .= $OUTPUT->single_button($spaceurl, get_string('retryspace', 'tupmeet'), 'post');
            }
            if ($ready && $calendarstatus === 'error') {
                $html .= $OUTPUT->notification(get_string('calendarindependenterror', 'tupmeet'), 'warning');
                $html .= $OUTPUT->single_button($retryurl, get_string('retrysync', 'tupmeet'), 'post');
            }
        } else {
            $html .= $OUTPUT->notification(get_string('cohosthistorical', 'tupmeet'), 'info');
        }
        foreach (['autorecord', 'autotranscript'] as $field) {
            $label = $status === 'ready' ? (empty($meeting->{$field}) ? 'artifactoff' : 'artifacton') : 'meetconfig' . $status;
            $table->data[] = [get_string($field, 'tupmeet'), get_string($label, 'tupmeet')];
        }
        $cohoststatus = $meeting->cohoststatus ?? 'unconfigured';
        if (!in_array($cohoststatus, ['unconfigured', 'pending', 'ready', 'error'], true)) {
            $cohoststatus = 'error';
        }
        $teacher = empty($meeting->cohostuserid) ? null : $DB->get_record('user', ['id' => $meeting->cohostuserid]);
        $table->data[] = [get_string('cohostuserid', 'tupmeet'), $teacher ? s(fullname($teacher)) : '—'];
        $table->data[] = [get_string('cohoststatus', 'tupmeet'), get_string('cohost' . $cohoststatus, 'tupmeet')];
        if ($cohoststatus === 'error') {
            $stage = cohost_exception::normalize_stage($meeting->cohosterrorstage ?? 'unknown');
            $table->data[] = [get_string('cohosterrorstage', 'tupmeet'), get_string('cohoststage' . $stage, 'tupmeet')];
            $httpstatus = cohost_exception::normalize_http_status($meeting->cohosthttpstatus ?? 0);
            if ($httpstatus) {
                $table->data[] = [get_string('cohosthttpstatus', 'tupmeet'), $httpstatus];
            }
        }
        $html .= \html_writer::table($table);
        if ($ready && in_array($status, ['error', 'unconfigured'], true)) {
            $html .= $OUTPUT->notification(get_string('meetconfig' . $status . 'notice', 'tupmeet'), 'warning');
            $html .= $OUTPUT->single_button($retryurl, get_string('retryartifactconfig', 'tupmeet'), 'post');
        }
        if ($meetfirst && $ready && $cohoststatus === 'error' && !empty($meeting->cohostuserid)) {
            $html .= $OUTPUT->notification(get_string('cohostfailed', 'tupmeet'), 'warning');
            $cohosturl = new \moodle_url('/mod/tupmeet/retry.php', ['id' => $cmid, 'target' => 'cohost']);
            $html .= $OUTPUT->single_button($cohosturl, get_string('retrycohost', 'tupmeet'), 'post');
        }
        $html .= $OUTPUT->notification(get_string('cohostnotice', 'tupmeet'), 'info');
        $html .= $OUTPUT->notification(get_string('artifactnotice', 'tupmeet'), 'info');
        return $html;
    }
}
