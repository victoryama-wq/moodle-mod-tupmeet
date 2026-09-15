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
 * Activity configuration form for TUP Meet.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * Scheduling and preferences form for local TUP Meet activities.
 */
class mod_tupmeet_mod_form extends moodleform_mod {
    /**
     * Define activity scheduling controls.
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general'));
        $mform->addElement('text', 'name', get_string('meetingname', 'tupmeet'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements(get_string('description'));

        $mform->addElement('header', 'scheduling', get_string('scheduling', 'tupmeet'));
        $mform->addElement('date_time_selector', 'startdatetime', get_string('startdatetime', 'tupmeet'));
        $mform->addElement('date_time_selector', 'enddatetime', get_string('enddatetime', 'tupmeet'));

        $mform->addElement('advcheckbox', 'isrecurring', get_string('isrecurring', 'tupmeet'));
        $mform->setDefault('isrecurring', 0);

        $mform->addElement('text', 'recurrenceinterval', get_string('recurrenceinterval', 'tupmeet'), ['size' => 3]);
        $mform->setType('recurrenceinterval', PARAM_INT);
        $mform->setDefault('recurrenceinterval', 1);
        $mform->hideIf('recurrenceinterval', 'isrecurring', 'notchecked');

        $days = [
            'mon' => get_string('monday', 'calendar'),
            'tue' => get_string('tuesday', 'calendar'),
            'wed' => get_string('wednesday', 'calendar'),
            'thu' => get_string('thursday', 'calendar'),
            'fri' => get_string('friday', 'calendar'),
            'sat' => get_string('saturday', 'calendar'),
            'sun' => get_string('sunday', 'calendar'),
        ];
        foreach ($days as $key => $label) {
            $mform->addElement('advcheckbox', 'day' . $key, $label);
            $mform->hideIf('day' . $key, 'isrecurring', 'notchecked');
        }

        $mform->addElement('date_selector', 'recurrenceuntil', get_string('recurrenceuntil', 'tupmeet'));
        $mform->hideIf('recurrenceuntil', 'isrecurring', 'notchecked');

        $mform->addElement('header', 'meetsettings', get_string('meetsettings', 'tupmeet'));
        $mform->addElement('advcheckbox', 'autorecord', get_string('autorecord', 'tupmeet'));
        $mform->setDefault('autorecord', 1);
        $mform->addElement('advcheckbox', 'autotranscript', get_string('autotranscript', 'tupmeet'));
        $mform->setDefault('autotranscript', 0);

        $publicationoptions = [
            'manual' => get_string('publicationmanual', 'tupmeet'),
            'automatic' => get_string('publicationautomatic', 'tupmeet'),
        ];
        $mform->addElement('select', 'publicationmode', get_string('publicationmode', 'tupmeet'), $publicationoptions);
        $mform->setDefault('publicationmode', 'manual');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Validate scheduling inputs.
     *
     * @param array $data Submitted form data
     * @param array $files Submitted files
     * @return array Validation errors
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (
            !empty($data['startdatetime']) && !empty($data['enddatetime']) &&
                $data['enddatetime'] <= $data['startdatetime']
        ) {
            $errors['enddatetime'] = get_string('errorendbeforestart', 'tupmeet');
        }

        if (!empty($data['isrecurring'])) {
            if (empty($data['recurrenceinterval']) || (int) $data['recurrenceinterval'] < 1) {
                $errors['recurrenceinterval'] = get_string('errorrecurrenceinterval', 'tupmeet');
            }

            $hasday = false;
            foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) {
                if (!empty($data['day' . $day])) {
                    $hasday = true;
                    break;
                }
            }
            if (!$hasday) {
                $errors['daymon'] = get_string('errorrecurrenceday', 'tupmeet');
            }

            if (
                !empty($data['recurrenceuntil']) && !empty($data['startdatetime']) &&
                    $data['recurrenceuntil'] < strtotime('today', $data['startdatetime'])
            ) {
                $errors['recurrenceuntil'] = get_string('errorrecurrenceuntil', 'tupmeet');
            }
        }

        return $errors;
    }

    /**
     * Restore weekday controls for an existing activity.
     *
     * @param array $defaultvalues Stored activity values
     */
    public function data_preprocessing(&$defaultvalues) {
        if (empty($defaultvalues['recurrencedays'])) {
            return;
        }

        $days = json_decode($defaultvalues['recurrencedays'], true);
        if (!is_array($days)) {
            return;
        }

        foreach ($days as $day) {
            if (in_array($day, ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], true)) {
                $defaultvalues['day' . $day] = 1;
            }
        }
    }
}
