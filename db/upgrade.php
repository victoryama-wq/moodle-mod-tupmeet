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
    if ($oldversion < 2026091501) {
        $table = new xmldb_table('tupmeet');
        $fields = [
            new xmldb_field('timezone', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, 'UTC', 'lastsync'),
            new xmldb_field('creationkey', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'timezone'),
            new xmldb_field('syncversion', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'legacy', 'creationkey'),
            new xmldb_field('syncstatus', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'legacy', 'syncversion'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $index = new xmldb_index('creationkey', XMLDB_INDEX_UNIQUE, ['creationkey']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        // Older phases did not record the author's timezone. Preserve all instants and owners.
        // Use the configured Moodle site timezone for legacy schedules, without creating events.
        $DB->set_field('tupmeet', 'timezone', \core_date::get_server_timezone(), ['syncstatus' => 'legacy']);
        upgrade_mod_savepoint(true, 2026091501, 'tupmeet');
    }
    if ($oldversion < 2026091700) {
        $table = new xmldb_table('tupmeet');
        $fields = [
            new xmldb_field('meetconfigstatus', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'unconfigured', 'syncstatus'),
            new xmldb_field('meetconfigversion', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'legacy', 'meetconfigstatus'),
            new xmldb_field('meetconfigmodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'meetconfigversion'),
            new xmldb_field('meetconfigattempts', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0', 'meetconfigmodified'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        // Preserve all preferences and Google identities. A save/retry explicitly adopts Phase 3.
        // Do not queue work or contact Google during upgrade.
        upgrade_mod_savepoint(true, 2026091700, 'tupmeet');
    }
    if ($oldversion < 2026091701) {
        $table = new xmldb_table('tupmeet');
        $fields = [
            new xmldb_field('cohostuserid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'meetconfigattempts'),
            new xmldb_field('cohostemail', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'cohostuserid'),
            new xmldb_field('cohostmembername', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'cohostemail'),
            new xmldb_field('cohoststatus', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'unconfigured', 'cohostmembername'),
            new xmldb_field('cohostversion', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'legacy', 'cohoststatus'),
            new xmldb_field('cohostattempts', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0', 'cohostversion'),
            new xmldb_field('cohostmodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'cohostattempts'),
            new xmldb_field('cohostlocked', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'cohostmodified'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        // Historical activities remain unconfigured. No identity inference, tasks or Google HTTP.
        upgrade_mod_savepoint(true, 2026091701, 'tupmeet');
    }
    return true;
}
