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
 * Upgrade steps from the installed Phase 0 baseline.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade plugin metadata without reassigning historical activities.
 *
 * @param int $oldversion Installed plugin version
 * @return bool
 */
function xmldb_tupmeet_upgrade($oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();
    if ($oldversion < 2026091500) {
        $table = new xmldb_table('tupmeet_accounts');
        $field = new xmldb_field('googlesub', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'googleemail');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('timeverified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'connectionstatus');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Phase 0 had no verified identity. Require verification and explicit default selection.
        // Keep every account row, email, issuer and activity accountid exactly as installed.
        $DB->set_field('tupmeet_accounts', 'isdefault', 0);
        $DB->set_field('tupmeet_accounts', 'connectionstatus', 'pending');
        upgrade_mod_savepoint(true, 2026091500, 'tupmeet');
    }
    return true;
}
