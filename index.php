<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Lists TUP Meet activities in a course.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

$id = required_param('id', PARAM_INT);
$course = get_course($id);
require_course_login($course);

$PAGE->set_url('/mod/tupmeet/index.php', ['id' => $course->id]);
$PAGE->set_title(get_string('modulenameplural', 'tupmeet'));
$PAGE->set_heading(format_string($course->fullname));

$instances = get_all_instances_in_course('tupmeet', $course);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'tupmeet'));

if (!$instances) {
    notice(get_string('thereareno', 'moodle', get_string('modulenameplural', 'tupmeet')), new moodle_url('/course/view.php', ['id' => $course->id]));
}

$table = new html_table();
$table->head = [get_string('name'), get_string('startdatetime', 'tupmeet')];

foreach ($instances as $instance) {
    $link = html_writer::link(
        new moodle_url('/mod/tupmeet/view.php', ['id' => $instance->coursemodule]),
        format_string($instance->name)
    );
    $table->data[] = [$link, userdate($instance->startdatetime)];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
