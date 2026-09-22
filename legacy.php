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
 * Site-admin historical CSV review and explicit local import.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());
admin_externalpage_setup('tupmeetlegacy');
$action = optional_param('action', '', PARAM_ALPHA);
if (in_array($action, ['template', 'catalog'], true)) {
    \mod_tupmeet\local\legacy\csv_export::writer($action === 'catalog')->download_file();
    exit;
}
$form = new \mod_tupmeet\form\legacy_import_form(new moodle_url('/mod/tupmeet/legacy.php'));
$plan = null;
$message = '';
$error = '';
try {
    if ($action === 'confirm') {
        $result = \mod_tupmeet\local\legacy\importer::confirm(required_param('token', PARAM_ALPHANUM));
        $message = get_string('legacyimportresult', 'tupmeet', (object) $result);
    } else if ($form->get_data()) {
        $plan = \mod_tupmeet\local\legacy\importer::preview($form->get_file_content('csvfile'));
    } else {
        $plan = \mod_tupmeet\local\legacy\importer::current();
    }
} catch (moodle_exception $e) {
    // No raw CSV, URLs, SQL error messages or browser-supplied text in error output.
    $allowed = ['legacyinvalidcsv', 'legacyheaders', 'legacypreviewexpired', 'legacyblocked', 'legacychanged', 'legacybusy'];
    $error = get_string(in_array($e->errorcode, $allowed, true) ? $e->errorcode : 'legacyfailed', 'tupmeet');
}
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('legacytitle', 'tupmeet'));
echo html_writer::div(get_string('legacyinstructions', 'tupmeet'));
foreach (['template', 'catalog'] as $download) {
    echo html_writer::div(html_writer::link(
        new moodle_url('/mod/tupmeet/legacy.php', ['action' => $download]),
        get_string('legacy' . $download, 'tupmeet')
    ));
}
if ($message) {
    echo $OUTPUT->notification($message, 'success');
}
if ($error) {
    echo $OUTPUT->notification($error, 'error');
}
$form->display();
if ($plan) {
    echo \mod_tupmeet\output\legacy_preview::render($plan, optional_param('page', 0, PARAM_INT));
}
echo $OUTPUT->footer();
