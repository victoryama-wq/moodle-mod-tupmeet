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
 * Explicit teacher retry after correcting authorization or a conference failure.
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
require_login($course, true, $cm);
require_capability('moodle/course:manageactivities', context_module::instance($cm->id));
require_sesskey();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new moodle_exception('invalidrequest', 'mod_tupmeet');
}
$target = optional_param('target', 'all', PARAM_ALPHA);
if (!in_array($target, ['all', 'cohost'], true)) {
    throw new moodle_exception('invalidrequest', 'mod_tupmeet');
}
if ($target === 'cohost') {
    \mod_tupmeet\local\meeting\cohost_manager::retry((int) $cm->instance);
} else {
    $manager = new \mod_tupmeet\local\meeting\meeting_manager();
    $manager->update((object) ['id' => $cm->instance]);
    $manager->synchronize((int) $cm->instance);
    (new \mod_tupmeet\local\meeting\meet_config_manager())->synchronize((int) $cm->instance);
}
(new \mod_tupmeet\local\meeting\cohost_manager())->synchronize((int) $cm->instance);
redirect(new moodle_url('/mod/tupmeet/view.php', ['id' => $cm->id]));
