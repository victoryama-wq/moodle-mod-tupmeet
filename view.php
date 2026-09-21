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

echo $OUTPUT->header();

if (!empty($tupmeet->intro)) {
    echo $OUTPUT->box(format_module_intro('tupmeet', $tupmeet, $cm->id), 'generalbox mod_introbox');
}

echo \mod_tupmeet\output\activity_view::render(
    $tupmeet,
    $context,
    (int) $cm->id,
    optional_param('recordingpage', 0, PARAM_INT)
);
echo $OUTPUT->footer();
