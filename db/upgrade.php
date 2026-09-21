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
    if ($oldversion < 2026091702) {
        $table = new xmldb_table('tupmeet');
        $fields = [
            new xmldb_field('cohosterrorstage', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'unknown', 'cohostlocked'),
            new xmldb_field('cohosthttpstatus', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '0', 'cohosterrorstage'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        // Existing errors have no known stage. Preserve identities, statuses and retry budgets; no HTTP or tasks.
        upgrade_mod_savepoint(true, 2026091702, 'tupmeet');
    }
    if ($oldversion < 2026091801) {
        $table = new xmldb_table('tupmeet');
        $fields = [
            new xmldb_field('provisionmode', XMLDB_TYPE_CHAR, '8', null, XMLDB_NOTNULL, null, 'calendar', 'cohosthttpstatus'),
            new xmldb_field('spacestatus', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'pending', 'provisionmode'),
            new xmldb_field('spaceversion', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'legacy', 'spacestatus'),
            new xmldb_field('spaceattempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'spaceversion'),
            new xmldb_field('spacemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'spaceattempts'),
            new xmldb_field('spacenextattempt', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'spacemodified'),
            new xmldb_field('spacehttpstatus', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '0', 'spacenextattempt'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        // Classify only. Never infer a Space, queue work, call HTTP, or alter historical identifiers/preferences.
        $DB->set_field('tupmeet', 'provisionmode', 'calendar');
        $DB->set_field('tupmeet', 'provisionmode', 'legacy', ['syncstatus' => 'legacy']);
        upgrade_mod_savepoint(true, 2026091801, 'tupmeet');
    }
    if ($oldversion < 2026091900) {
        $table = new xmldb_table('tupmeet');
        $fields = [
            new xmldb_field('recordingsyncstatus', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'idle'),
            new xmldb_field('recordingslastsync', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('recordingsnextsync', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('recordingsyncattempts', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('recordingsyncversion', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'idle'),
            new xmldb_field('recordingshttpstatus', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('recordingsyncqueued', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('recordingscheckedversion', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'idle'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $index = new xmldb_index('recordingsdue', XMLDB_INDEX_NOTUNIQUE, ['recordingsyncstatus', 'recordingsnextsync']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        $table = new xmldb_table('tupmeet_conferences');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('tupmeetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('conferencename', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('starttime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('endtime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('firstseen', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('lastseen', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('tupmeetid', XMLDB_KEY_FOREIGN, ['tupmeetid'], 'tupmeet', ['id']);
        $table->add_index('conferencename', XMLDB_INDEX_UNIQUE, ['conferencename']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        $table = new xmldb_table('tupmeet_recordings');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('tupmeetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('conferenceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('recordingname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('state', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'STARTED');
        $table->add_field('starttime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('startnanos', XMLDB_TYPE_INTEGER, '9', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('endtime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('drivefileid', XMLDB_TYPE_CHAR, '200', null, null, null, null);
        $table->add_field('exporturi', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('desiredfilename', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('drivefilename', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('originalfilename', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('partnumber', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('renamestatus', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'unavailable');
        $table->add_field('renameattempts', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('renamehttpstatus', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('renamedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('renameversion', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'idle');
        $table->add_field('renamenextattempt', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('renamequeued', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('firstseen', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('lastseen', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('tupmeetid', XMLDB_KEY_FOREIGN, ['tupmeetid'], 'tupmeet', ['id']);
        $table->add_key('conferenceid', XMLDB_KEY_FOREIGN, ['conferenceid'], 'tupmeet_conferences', ['id']);
        $table->add_index('recordingname', XMLDB_INDEX_UNIQUE, ['recordingname']);
        $table->add_index('renamedue', XMLDB_INDEX_NOTUNIQUE, ['renamestatus', 'renamenextattempt']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        // Idle rows are backfilled in bounded scheduled batches. No HTTP, tasks or owner changes here.
        upgrade_mod_savepoint(true, 2026091900, 'tupmeet');
    }
    if ($oldversion < 2026091901) {
        $table = new xmldb_table('tupmeet_recordings');
        $visibility = new xmldb_field('studentvisible', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $visibility)) {
            $dbman->add_field($table, $visibility);
        }
        foreach (['visibilitymodified', 'visibilityuserid'] as $name) {
            $field = new xmldb_field($name, XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $indexes = ['studentlist' => ['tupmeetid', 'studentvisible'], 'visibilityuserid' => ['visibilityuserid']];
        foreach ($indexes as $name => $fields) {
            $index = new xmldb_index($name, XMLDB_INDEX_NOTUNIQUE, $fields);
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }
        // Repeat safely if schema creation was interrupted before the savepoint.
        // Moodle runs upgrades in maintenance mode; no manual visibility actions exist yet.
        $DB->set_field('tupmeet_recordings', 'studentvisible', 0);
        $DB->execute(
            "UPDATE {tupmeet_recordings} SET studentvisible = 1
               WHERE tupmeetid IN (SELECT id FROM {tupmeet} WHERE publicationmode = :mode)",
            ['mode' => 'automatic']
        );
        // No Google access, new tasks or change to existing activity preferences.
        upgrade_mod_savepoint(true, 2026091901, 'tupmeet');
    }
    if ($oldversion < 2026092101) {
        // Structural default only: preserve every historical activity preference.
        $table = new xmldb_table('tupmeet');
        $field = new xmldb_field('publicationmode', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'automatic');
        $dbman->change_field_default($table, $field);
        upgrade_mod_savepoint(true, 2026092101, 'tupmeet');
    }
    return true;
}
