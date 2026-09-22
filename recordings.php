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
 * Protected metadata-only actions. No synchronous Google operations in web requests.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

$id = required_param('id', PARAM_INT);
$action = required_param('action', PARAM_ALPHA);
$recordingid = optional_param('recordingid', 0, PARAM_INT);
$cm = get_coursemodule_from_id('tupmeet', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/tupmeet:view', $context);
$accepted = \mod_tupmeet\local\recording\actions::execute($cm, $context, $action, $recordingid);
redirect(
    new moodle_url('/mod/tupmeet/view.php', ['id' => $cm->id]),
    get_string(in_array($action, ['hide', 'show', 'hidelegacy', 'showlegacy'], true) ? 'visibilitysaved' :
        ($accepted ? 'recordingsqueued' : 'recordingsnotqueued'), 'tupmeet')
);
