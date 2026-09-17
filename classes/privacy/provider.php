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
 * Institutional metadata is not linked to a Moodle user ID.
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
        $collection->add_subsystem_link('core_oauth2', [], 'privacy:metadata:oauth2');
        $collection->add_external_location_link('googlecalendar', [
            'name' => 'privacy:metadata:calendarname',
            'intro' => 'privacy:metadata:calendarintro',
            'schedule' => 'privacy:metadata:calendarschedule',
        ], 'privacy:metadata:googlecalendar');
        $collection->add_external_location_link('googlemeet', [
            'artifactconfig' => 'privacy:metadata:artifactconfig',
        ], 'privacy:metadata:googlemeet');
        return $collection;
    }

    /**
     * Shared institutional accounts have no Moodle user relationship.
     *
     * @param int $userid Moodle user ID
     * @return \core_privacy\local\request\contextlist
     */
    public static function get_contexts_for_userid(int $userid): \core_privacy\local\request\contextlist {
        return new \core_privacy\local\request\contextlist();
    }

    /**
     * No Moodle users are associated with institutional account records.
     *
     * @param \core_privacy\local\request\userlist $userlist User list
     */
    public static function get_users_in_context(\core_privacy\local\request\userlist $userlist) {
    }

    /**
     * No user-owned records to export.
     *
     * @param \core_privacy\local\request\approved_contextlist $contextlist Approved contexts
     */
    public static function export_user_data(\core_privacy\local\request\approved_contextlist $contextlist) {
    }

    /**
     * No user-owned records; shared ownership history must be retained.
     *
     * @param \context $context Context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
    }

    /**
     * No user-owned records to delete.
     *
     * @param \core_privacy\local\request\approved_contextlist $contextlist Approved contexts
     */
    public static function delete_data_for_user(\core_privacy\local\request\approved_contextlist $contextlist) {
    }

    /**
     * No user-owned records to delete.
     *
     * @param \core_privacy\local\request\approved_userlist $userlist Approved users
     */
    public static function delete_data_for_users(\core_privacy\local\request\approved_userlist $userlist) {
    }
}
