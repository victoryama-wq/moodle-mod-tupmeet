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
 * Separate institutional accounts and local operational diagnostics.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$settings = null;
$ADMIN->add('modsettings', new admin_category('modtupmeet', get_string('pluginname', 'mod_tupmeet')));
$ADMIN->add('modtupmeet', new admin_externalpage(
    'modsettingtupmeet',
    get_string('healthaccounts', 'mod_tupmeet'),
    new moodle_url('/mod/tupmeet/accounts.php'),
    'moodle/site:config'
));
$ADMIN->add('modtupmeet', new admin_externalpage(
    'tupmeethealth',
    get_string('healthtitle', 'mod_tupmeet'),
    new moodle_url('/mod/tupmeet/health.php'),
    'moodle/site:config'
));
