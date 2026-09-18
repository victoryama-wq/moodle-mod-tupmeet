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

/**
 * EXPERIMENTAL / STAGING ONLY. Explicit administrator-operated Meet-first experiment.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use mod_tupmeet\form\poc_setup_form;
use mod_tupmeet\local\meeting\cohost_identity;
use mod_tupmeet\local\poc\experiment;

require_login();
experiment::require_admin();
admin_externalpage_setup('tupmeetpoc');
$url = new moodle_url('/mod/tupmeet/poc_meet_first.php');
$experiment = new experiment();
$state = $experiment->state();
$courseid = optional_param('courseid', 0, PARAM_INT);
$form = null;
$error = '';
try {
    if (!$state && $courseid > 0) {
        $form = new poc_setup_form(new moodle_url($url, ['courseid' => $courseid]), [
            'courseid' => $courseid, 'teachers' => cohost_identity::options($courseid),
        ]);
    }
    $action = optional_param('action', '', PARAM_ALPHA);
    if ($action !== '') {
        $data = ['runid' => optional_param('runid', '', PARAM_ALPHANUM), 'confirmed' => optional_param('confirmed', 0, PARAM_BOOL)];
        if ($action === 'start') {
            $submitted = $form ? $form->get_data() : false;
            if (!$submitted) {
                throw new moodle_exception('invalidrequest', 'mod_tupmeet');
            }
            $data = (array) $submitted;
        }
        $experiment->dispatch($action, $data, $_SERVER['REQUEST_METHOD'], optional_param('sesskey', '', PARAM_RAW));
        redirect($url);
    }
} catch (Throwable $e) {
    // Never render exception messages, debug info, Google bodies or stack traces.
    $error = get_string('pocfailed', 'tupmeet');
}
$state = $experiment->state();
echo $OUTPUT->header();
echo $OUTPUT->heading('EXPERIMENTAL / STAGING ONLY');
echo $OUTPUT->notification(get_string('pocwarning', 'tupmeet'), 'warning');
if ($error !== '') {
    echo $OUTPUT->notification($error, 'error');
}
if (!$state) {
    echo html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out(false)]);
    echo html_writer::tag('label', get_string('poccourseid', 'tupmeet'), ['for' => 'poccourseid']);
    echo html_writer::empty_tag('input', ['id' => 'poccourseid', 'name' => 'courseid', 'type' => 'number', 'min' => 1,
        'value' => $courseid ?: '', 'required' => 'required']);
    echo html_writer::tag('button', get_string('pocloadteachers', 'tupmeet'), ['type' => 'submit']);
    echo html_writer::end_tag('form');
    if ($form) {
        $form->display();
    }
} else {
    $table = new html_table();
    $table->head = [get_string('pocstage', 'tupmeet'), get_string('pocstatus', 'tupmeet'),
        get_string('cohosthttpstatus', 'tupmeet')];
    foreach (experiment::STAGES as $action => $label) {
        $step = $state['stages'][$action];
        $table->data[] = [s($label), s($step['status']), (int) $step['httpstatus'] ?: '—'];
    }
    echo html_writer::table($table);
    $apass = $state['stages']['create']['status'] === 'PASS' && $state['stages']['verify']['status'] === 'PASS';
    echo html_writer::tag('p', 'POC A = ' . ($apass ? 'PASS' : 'NOT_CONFIRMED'));
    $native = $state['stages']['native']['status'];
    echo html_writer::tag('p', 'NATIVE_CALENDAR = ' . ($native === 'FAIL' ? 'UNSUPPORTED / NOT_CONFIRMED' : $native));
    echo html_writer::tag('p', 'FALLBACK_CALENDAR = ' . s($state['stages']['fallback']['status']));
    $resources = new html_table();
    $resources->data[] = [get_string('pocownerid', 'tupmeet'), (int) $state['accountid']];
    $resources->data[] = [get_string('pocuserid', 'tupmeet'), (int) $state['userid']];
    $resources->data[] = [get_string('timezone'), s($state['timezone'])];
    $resources->data[] = [get_string('startdatetime', 'tupmeet'), userdate($state['start'], '', $state['timezone'])];
    $resources->data[] = [get_string('enddatetime', 'tupmeet'), userdate($state['end'], '', $state['timezone'])];
    if (!empty($state['space'])) {
        foreach (['name', 'meetingUri', 'meetingCode'] as $key) {
            if (isset($state['space'][$key])) {
                $resources->data[] = [s($key), s($state['space'][$key])];
            }
        }
        foreach ($state['space']['config'] ?? [] as $key => $value) {
            $resources->data[] = [s($key), s($value)];
        }
    }
    if (isset($state['member'])) {
        $resources->data[] = ['Member', s($state['member'])];
    }
    if ($state['stages']['artifacts']['status'] === 'PASS') {
        $resources->data[] = ['autoRecordingGeneration / autoTranscriptionGeneration', 'ON / OFF'];
    }
    foreach (['native', 'fallback'] as $key) {
        if ($state['stages'][$key]['status'] !== 'NOT_RUN') {
            $resources->data[] = [get_string('poceventid', 'tupmeet') . ' (' . $key . ')', s($state['eventids'][$key])];
        }
    }
    echo html_writer::table($resources);
    echo $OUTPUT->notification(get_string('poccleanup', 'tupmeet'), 'info');
    foreach (experiment::STAGES + ['clear' => get_string('pocclear', 'tupmeet')] as $action => $label) {
        if ($action !== 'clear' && !$experiment->available($state, $action)) {
            continue;
        }
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
        foreach (['sesskey' => sesskey(), 'action' => $action, 'runid' => $state['runid']] as $key => $value) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
        }
        if (in_array($action, ['fallback', 'clear'], true)) {
            echo html_writer::tag('label', html_writer::empty_tag('input', [
                'type' => 'checkbox', 'name' => 'confirmed', 'value' => 1, 'required' => 'required',
            ]) . get_string($action === 'fallback' ? 'pocfallbackack' : 'pocclearack', 'tupmeet'));
        }
        echo html_writer::tag('button', s($label), ['type' => 'submit', 'class' => 'btn btn-secondary']);
        echo html_writer::end_tag('form');
    }
}
echo $OUTPUT->footer();
