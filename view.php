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
 * View page for TUP Meet.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('tupmeet', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$tupmeet = $DB->get_record('tupmeet', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/tupmeet:view', $context);

tupmeet_view($tupmeet, $course, $cm, $context);

$PAGE->set_url('/mod/tupmeet/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($tupmeet->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$recurrencedays = json_decode($tupmeet->recurrencedays ?? '[]', true) ?: [];
$daylabels = [];
$map = [
    'mon' => get_string('monday', 'calendar'),
    'tue' => get_string('tuesday', 'calendar'),
    'wed' => get_string('wednesday', 'calendar'),
    'thu' => get_string('thursday', 'calendar'),
    'fri' => get_string('friday', 'calendar'),
    'sat' => get_string('saturday', 'calendar'),
    'sun' => get_string('sunday', 'calendar'),
];
foreach ($recurrencedays as $day) {
    if (isset($map[$day])) {
        $daylabels[] = $map[$day];
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($tupmeet->name));

if (!empty($tupmeet->intro)) {
    echo $OUTPUT->box(format_module_intro('tupmeet', $tupmeet, $cm->id), 'generalbox mod_introbox');
}

$table = new html_table();
$table->attributes['class'] = 'generaltable';
$next = \mod_tupmeet\local\meeting\schedule::next_session($tupmeet, time());
$table->data[] = [
    get_string('nextsession', 'tupmeet'),
    $next === null ? get_string('nosession', 'tupmeet') : userdate($next, '', $tupmeet->timezone),
];
$table->data[] = [get_string('timezone', 'tupmeet'), s($tupmeet->timezone)];
$table->data[] = [get_string('startdatetime', 'tupmeet'), userdate($tupmeet->startdatetime, '', $tupmeet->timezone)];
$table->data[] = [get_string('enddatetime', 'tupmeet'), userdate($tupmeet->enddatetime, '', $tupmeet->timezone)];
$table->data[] = [get_string('isrecurring', 'tupmeet'), $tupmeet->isrecurring ? get_string('yes') : get_string('no')];
if ($tupmeet->isrecurring) {
    $table->data[] = [get_string('recurrencedays', 'tupmeet'), implode(', ', $daylabels)];
    $table->data[] = [get_string('recurrenceinterval', 'tupmeet'), (int) $tupmeet->recurrenceinterval];
    $table->data[] = [
        get_string('recurrenceuntil', 'tupmeet'),
        userdate($tupmeet->recurrenceuntil, get_string('strftimedatefullshort', 'langconfig'), $tupmeet->timezone),
    ];
}
echo html_writer::table($table);
echo \mod_tupmeet\output\meeting_status::render($tupmeet, $context, (int) $cm->id);
echo $OUTPUT->footer();
