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

namespace mod_tupmeet\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\provider as metadata_provider;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\plugin\provider as plugin_provider;

/**
 * Institutional ownership and minimal Moodle teacher membership metadata.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements core_userlist_provider, metadata_provider, plugin_provider {
    /**
     * Declare institutional identities and the native authorization subsystem.
     *
     * @param \core_privacy\local\metadata\collection $collection Metadata
     * @return \core_privacy\local\metadata\collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('tupmeet_accounts', [
            'displayname' => 'privacy:metadata:displayname',
            'googleemail' => 'privacy:metadata:googleemail',
            'googlesub' => 'privacy:metadata:googlesub',
            'timeverified' => 'privacy:metadata:timeverified',
        ], 'privacy:metadata:accounts');
        $collection->add_database_table('tupmeet', [
            'cohostuserid' => 'privacy:metadata:cohostuserid',
            'cohostemail' => 'privacy:metadata:cohostemail',
            'cohostmembername' => 'privacy:metadata:cohostmembername',
            'cohoststatus' => 'privacy:metadata:cohoststatus',
            'cohostmodified' => 'privacy:metadata:cohostmodified',
            'cohosterrorstage' => 'privacy:metadata:cohosterrorstage',
            'cohosthttpstatus' => 'privacy:metadata:cohosthttpstatus',
        ], 'privacy:metadata:cohost');
        $collection->add_subsystem_link('core_oauth2', [], 'privacy:metadata:oauth2');
        $collection->add_subsystem_link('core_cache', [], 'privacy:metadata:poccache');
        $collection->add_external_location_link('googlecalendar', [
            'name' => 'privacy:metadata:calendarname',
            'intro' => 'privacy:metadata:calendarintro',
            'schedule' => 'privacy:metadata:calendarschedule',
            'cohostemail' => 'privacy:metadata:calendarattendee',
            'pocattendee' => 'privacy:metadata:pocattendee',
        ], 'privacy:metadata:googlecalendar');
        $collection->add_external_location_link('googlemeet', [
            'artifactconfig' => 'privacy:metadata:artifactconfig',
            'email' => 'privacy:metadata:cohostemail',
        ], 'privacy:metadata:googlemeet');
        $collection->add_database_table('tupmeet_conferences', [
            'conferencename' => 'privacy:metadata:conferencename',
            'starttime' => 'privacy:metadata:recordingtimes',
            'endtime' => 'privacy:metadata:recordingtimes',
            'firstseen' => 'privacy:metadata:recordingtimes',
            'lastseen' => 'privacy:metadata:recordingtimes',
        ], 'privacy:metadata:conferences');
        $collection->add_database_table('tupmeet_recordings', [
            'recordingname' => 'privacy:metadata:recordingname',
            'starttime' => 'privacy:metadata:recordingtimes',
            'endtime' => 'privacy:metadata:recordingtimes',
            'drivefileid' => 'privacy:metadata:recordingdestination',
            'exporturi' => 'privacy:metadata:recordingdestination',
            'desiredfilename' => 'privacy:metadata:recordingfilename',
            'drivefilename' => 'privacy:metadata:recordingfilename',
            'originalfilename' => 'privacy:metadata:recordingfilename',
            'state' => 'privacy:metadata:recordingstate',
            'renamestatus' => 'privacy:metadata:recordingstate',
            'firstseen' => 'privacy:metadata:recordingtimes',
            'lastseen' => 'privacy:metadata:recordingtimes',
        ], 'privacy:metadata:recordings');
        $collection->add_external_location_link('googlemeetrecordings', [
            'meetspacename' => 'privacy:metadata:recordingsmeet',
        ], 'privacy:metadata:recordingsmeet');
        $collection->add_external_location_link('googledrivemetadata', [
            'drivefileid' => 'privacy:metadata:recordingdestination',
            'name' => 'privacy:metadata:recordingfilename',
        ], 'privacy:metadata:drive');
        return $collection;
    }

    /**
     * Find module contexts containing the teacher's stored identity.
     *
     * @param int $userid Moodle user
     * @return \core_privacy\local\request\contextlist
     */
    public static function get_contexts_for_userid(int $userid): \core_privacy\local\request\contextlist {
        $contexts = new \core_privacy\local\request\contextlist();
        $contexts->add_from_sql(
            'SELECT ctx.id FROM {context} ctx
               JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :level
               JOIN {modules} m ON m.id = cm.module AND m.name = :module
               JOIN {tupmeet} t ON t.id = cm.instance WHERE t.cohostuserid = :userid',
            ['level' => CONTEXT_MODULE, 'module' => 'tupmeet', 'userid' => $userid]
        );
        return $contexts;
    }

    /**
     * Resolve only this plugin's module context, never a same-numbered instance in another module.
     *
     * @param \context $context Approved context
     * @return \stdClass|null Activity
     */
    private static function activity(\context $context): ?\stdClass {
        global $DB;
        if (!$context instanceof \context_module) {
            return null;
        }
        $cm = get_coursemodule_from_id('tupmeet', $context->instanceid);
        return $cm ? ($DB->get_record('tupmeet', ['id' => $cm->instance]) ?: null) : null;
    }

    /**
     * Register the selected teacher in the module context.
     *
     * @param \core_privacy\local\request\userlist $userlist User list
     */
    public static function get_users_in_context(\core_privacy\local\request\userlist $userlist) {
        $record = self::activity($userlist->get_context());
        if (!empty($record->cohostuserid)) {
            $userlist->add_user((int) $record->cohostuserid);
        }
    }

    /**
     * Export only the approved teacher's membership data, not the institutional owner's identity.
     *
     * @param \core_privacy\local\request\approved_contextlist $contextlist Approved contexts
     */
    public static function export_user_data(\core_privacy\local\request\approved_contextlist $contextlist) {
        foreach ($contextlist->get_contexts() as $context) {
            $record = self::activity($context);
            if ($record && (int) $record->cohostuserid === (int) $contextlist->get_user()->id) {
                $data = (object) array_intersect_key((array) $record, array_flip([
                    'cohostuserid', 'cohostemail', 'cohostmembername', 'cohoststatus', 'cohostmodified',
                    'cohosterrorstage', 'cohosthttpstatus',
                ]));
                \core_privacy\local\request\writer::with_context($context)->export_data(
                    [get_string('cohostuserid', 'tupmeet')],
                    $data
                );
            }
        }
    }

    /**
     * Erase local personal data and invalidate workers. Never revoke remote privileges implicitly.
     *
     * @param \stdClass $record Approved activity snapshot
     */
    private static function erase(\stdClass $record): void {
        global $DB;
        // Keep the nonpersonal lock tombstone: erasure must not enable granting a second cohost.
        $DB->execute(
            'UPDATE {tupmeet} SET cohostuserid = 0, cohostemail = NULL, cohostmembername = NULL,
                cohoststatus = :status, cohostversion = :version, cohostattempts = 0, cohostmodified = 0,
                cohosterrorstage = :errorstage, cohosthttpstatus = 0
              WHERE id = :id AND cohostuserid = :userid',
            ['status' => 'unconfigured', 'version' => bin2hex(random_bytes(16)), 'errorstage' => 'unknown',
                'id' => $record->id, 'userid' => $record->cohostuserid]
        );
    }

    /**
     * Erase teacher identity in an approved module without touching Calendar/artifacts or accounts.
     *
     * @param \context $context Approved context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        $record = self::activity($context);
        if ($record && $record->cohostuserid) {
            self::erase($record);
        }
    }

    /**
     * Erase only the approved user's local cohost data.
     *
     * @param \core_privacy\local\request\approved_contextlist $contextlist Approved contexts
     */
    public static function delete_data_for_user(\core_privacy\local\request\approved_contextlist $contextlist) {
        foreach ($contextlist->get_contexts() as $context) {
            $record = self::activity($context);
            if ($record && (int) $record->cohostuserid === (int) $contextlist->get_user()->id) {
                self::erase($record);
            }
        }
    }

    /**
     * Erase only listed users within the approved context.
     *
     * @param \core_privacy\local\request\approved_userlist $userlist Approved users
     */
    public static function delete_data_for_users(\core_privacy\local\request\approved_userlist $userlist) {
        $record = self::activity($userlist->get_context());
        if ($record && in_array((int) $record->cohostuserid, $userlist->get_userids())) {
            self::erase($record);
        }
    }
}
