<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Core callbacks for TUP Meet.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

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

function tupmeet_add_instance($data, $mform = null) {
    global $DB;

    $data->recurrencedays = tupmeet_encode_recurrence_days($data);
    $data->accountid = 0;
    $data->timecreated = time();
    $data->timemodified = $data->timecreated;

    return $DB->insert_record('tupmeet', $data);
}

function tupmeet_update_instance($data, $mform = null) {
    global $DB;

    $data->id = $data->instance;
    $data->recurrencedays = tupmeet_encode_recurrence_days($data);
    $data->timemodified = time();

    return $DB->update_record('tupmeet', $data);
}

function tupmeet_delete_instance($id) {
    global $DB;

    if (!$DB->record_exists('tupmeet', ['id' => $id])) {
        return false;
    }

    $DB->delete_records('tupmeet', ['id' => $id]);
    return true;
}

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

function tupmeet_encode_recurrence_days($data) {
    $days = [];
    foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) {
        $property = 'day' . $day;
        if (!empty($data->{$property})) {
            $days[] = $day;
        }
        unset($data->{$property});
    }

    return json_encode($days);
}
