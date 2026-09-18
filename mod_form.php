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
        $timezone = \mod_tupmeet\local\meeting\schedule::timezone($this->current->timezone ?? null)->getName();
        $dateoptions = ['timezone' => $timezone];
        $mform->addElement('hidden', 'creationkey');
        $mform->setType('creationkey', PARAM_ALPHANUM);
        $mform->setDefault('creationkey', \mod_tupmeet\local\meeting\meeting_manager::new_key());

        $mform->addElement('header', 'general', get_string('general'));
        $mform->addElement('text', 'name', get_string('meetingname', 'tupmeet'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements(get_string('description'));

        $mform->addElement('header', 'scheduling', get_string('scheduling', 'tupmeet'));
        $mform->addElement('static', 'schedulingzone', get_string('timezone', 'tupmeet'), s($timezone));
        $mform->addElement('date_time_selector', 'startdatetime', get_string('startdatetime', 'tupmeet'), $dateoptions);
        $mform->addElement('date_time_selector', 'enddatetime', get_string('enddatetime', 'tupmeet'), $dateoptions);

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

        $mform->addElement('date_selector', 'recurrenceuntil', get_string('recurrenceuntil', 'tupmeet'), $dateoptions);
        $mform->hideIf('recurrenceuntil', 'isrecurring', 'notchecked');

        $mform->addElement('header', 'meetsettings', get_string('meetsettings', 'tupmeet'));
        $mform->addElement('static', 'preferencenotice', '', get_string('artifactnotice', 'tupmeet'));
        $mform->addElement('advcheckbox', 'autorecord', get_string('autorecord', 'tupmeet'));
        $mform->setDefault('autorecord', 1);
        $mform->addElement('advcheckbox', 'autotranscript', get_string('autotranscript', 'tupmeet'));
        $mform->setDefault('autotranscript', 0);

        // A stored selection is immutable, including uncertain remote outcomes and privacy erasure.
        if (!empty($this->current->instance) && !\mod_tupmeet\local\meeting\provisioning::is_meet($this->current)) {
            $mform->addElement('static', 'cohosthistorical', '', get_string('cohosthistorical', 'tupmeet'));
        } else if (!empty($this->current->cohostlocked)) {
            $mform->addElement(
                'static',
                'cohostlockednotice',
                get_string('cohostuserid', 'tupmeet'),
                get_string('cohostlocked', 'tupmeet')
            );
        } else {
            $courseid = (int) $this->get_course()->id;
            $options = \mod_tupmeet\local\meeting\cohost_identity::options($courseid);
            $mform->addElement('select', 'cohostuserid', get_string('cohostuserid', 'tupmeet'), $options);
            $mform->setType('cohostuserid', PARAM_INT);
            if (empty($this->current->instance)) {
                $mform->setDefault('cohostuserid', \mod_tupmeet\local\meeting\cohost_identity::default_user($courseid));
            }
            $mform->addHelpButton('cohostuserid', 'cohostuserid', 'tupmeet');
        }
        $mform->addElement('static', 'cohostnotice', '', get_string('cohostnotice', 'tupmeet'));
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

        $meeting = (object) $data;
        $meeting->timezone = \mod_tupmeet\local\meeting\schedule::timezone($this->current->timezone ?? null)->getName();
        $days = [];
        foreach (array_keys(\mod_tupmeet\local\meeting\schedule::DAYS) as $day) {
            if (!empty($data['day' . $day])) {
                $days[] = $day;
            }
        }
        $meeting->recurrencedays = json_encode($days);
        $errors = array_merge($errors, \mod_tupmeet\local\meeting\schedule::errors($meeting));
        if (empty($this->current->instance)) {
            global $DB;
            if (empty($data['creationkey']) || !preg_match('/^[a-f0-9]{64}$/D', $data['creationkey'])) {
                $errors['name'] = get_string('invalidrequest', 'tupmeet');
            } else if ($DB->record_exists('tupmeet', ['creationkey' => $data['creationkey']])) {
                $errors['name'] = get_string('duplicatesubmission', 'tupmeet');
            }
        }
        if (isset($data['cohostuserid']) || empty($this->current->instance)) {
            try {
                if (
                    !empty($this->current->cohostlocked) &&
                        (int) ($data['cohostuserid'] ?? 0) !== (int) $this->current->cohostuserid
                ) {
                    throw new \moodle_exception('cohostlocked', 'mod_tupmeet');
                }
                if (!empty($data['cohostuserid']) || empty($this->current->instance)) {
                    \mod_tupmeet\local\meeting\cohost_identity::resolve(
                        (int) $this->get_course()->id,
                        (int) ($data['cohostuserid'] ?? 0)
                    );
                }
            } catch (\moodle_exception $e) {
                $errors['cohostuserid'] = get_string('cohostinvalid', 'tupmeet');
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
