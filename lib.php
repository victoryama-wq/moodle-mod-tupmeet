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
 * Core callbacks for TUP Meet.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Describe supported activity features.
 *
 * @param string $feature Moodle feature name
 * @return mixed Feature support
 */
function tupmeet_supports($feature) {
    if (defined('FEATURE_MOD_PURPOSE') && $feature === FEATURE_MOD_PURPOSE) {
        return defined('MOD_PURPOSE_COMMUNICATION') ? MOD_PURPOSE_COMMUNICATION : MOD_ARCHETYPE_OTHER;
    }

    switch ($feature) {
        case FEATURE_MOD_ARCHETYPE:
            return MOD_ARCHETYPE_OTHER;
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_GROUPS:
        case FEATURE_GROUPINGS:
            return false;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return false;
        default:
            return null;
    }
}

/**
 * Create a local activity with the configured institutional owner.
 *
 * @param stdClass $data Activity data
 * @param moodleform|null $mform Activity form
 * @return int Activity ID
 */
function tupmeet_add_instance($data, $mform = null) {
    $data->recurrencedays = tupmeet_encode_recurrence_days($data);
    $data->timecreated = time();
    $data->timemodified = $data->timecreated;

    return (new \mod_tupmeet\local\meeting\meeting_manager())->create($data);
}

/**
 * Update activity data while preserving ownership.
 *
 * @param stdClass $data Activity data
 * @param moodleform|null $mform Activity form
 * @return bool
 */
function tupmeet_update_instance($data, $mform = null) {
    $data->id = $data->instance;
    // Activity ownership is immutable, even if a caller supplies accountid.
    unset($data->accountid);
    $hasdays = isset($data->recurrencedays);
    foreach (array_keys(\mod_tupmeet\local\meeting\schedule::DAYS) as $day) {
        $hasdays = $hasdays || property_exists($data, 'day' . $day);
    }
    if ($hasdays) {
        $data->recurrencedays = tupmeet_encode_recurrence_days($data);
    }
    $data->timemodified = time();

    return (new \mod_tupmeet\local\meeting\meeting_manager())->update($data);
}

/**
 * Delete local activity data only.
 *
 * @param int $id Activity ID
 * @return bool
 */
function tupmeet_delete_instance($id) {
    global $DB;

    if (!$DB->record_exists('tupmeet', ['id' => $id])) {
        return false;
    }

    $lock = \core\lock\lock_config::get_lock_factory('mod_tupmeet')->get_lock('meeting:' . $id, 0);
    if (!$lock) {
        throw new moodle_exception('recordingsbusy', 'mod_tupmeet');
    }
    try {
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('tupmeet_legacy_recordings', ['tupmeetid' => $id]);
        $DB->delete_records('tupmeet_recordings', ['tupmeetid' => $id]);
        $DB->delete_records('tupmeet_conferences', ['tupmeetid' => $id]);
        $DB->delete_records('tupmeet', ['id' => $id]);
        $transaction->allow_commit();
        return true;
    } finally {
        $lock->release();
    }
}

/**
 * Record an activity view and completion.
 *
 * @param stdClass $tupmeet Activity
 * @param stdClass $course Course
 * @param stdClass $cm Course module
 * @param context_module $context Module context
 */
function tupmeet_view($tupmeet, $course, $cm, $context) {
    $params = [
        'context' => $context,
        'objectid' => $tupmeet->id,
    ];

    $event = \mod_tupmeet\event\course_module_viewed::create($params);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('tupmeet', $tupmeet);
    $event->trigger();

    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}

/**
 * Encode scheduling controls for local storage.
 *
 * @param stdClass $data Activity form data, with weekday controls removed in place
 * @return string JSON weekdays
 */
function tupmeet_encode_recurrence_days($data) {
    $days = [];
    $hascontrols = false;
    foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) {
        $property = 'day' . $day;
        $hascontrols = $hascontrols || property_exists($data, $property);
        if (!empty($data->{$property})) {
            $days[] = $day;
        }
        unset($data->{$property});
    }

    return !$hascontrols && isset($data->recurrencedays) ? $data->recurrencedays : json_encode($days);
}

/**
 * Add Calendar and Meet settings scopes only to Google issuers registered as TUP Meet owners.
 *
 * @param \core\oauth2\issuer $issuer Native issuer
 * @return string Space-delimited extra system scopes
 */
function tupmeet_oauth2_system_scopes(\core\oauth2\issuer $issuer): string {
    global $DB;
    if (
        $issuer->get('servicetype') !== 'google' ||
            !$DB->get_manager()->table_exists('tupmeet_accounts') ||
            !$DB->record_exists('tupmeet_accounts', ['issuerid' => $issuer->get('id')])
    ) {
        return '';
    }
    return \mod_tupmeet\local\google\calendar_service::SCOPE . ' ' .
        \mod_tupmeet\local\google\meet_service::SCOPE . ' ' . \mod_tupmeet\local\google\member_service::SCOPE . ' ' .
        \mod_tupmeet\local\google\member_service::READONLY_SCOPE . ' ' .
        \mod_tupmeet\local\google\drive_metadata_service::SCOPE;
}
