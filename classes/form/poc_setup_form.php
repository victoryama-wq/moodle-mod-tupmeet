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

namespace mod_tupmeet\form;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Local PoC preparation; no remote operations occur on submission of this form.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class poc_setup_form extends \moodleform {
    /**
     * Use Moodle user IDs and date selectors, never a submitted email or Google identifier.
     */
    public function definition(): void {
        $mform = $this->_form;
        $mform->addElement('hidden', 'action', 'start');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('hidden', 'courseid', $this->_customdata['courseid']);
        $mform->setType('courseid', PARAM_INT);
        $mform->addElement('select', 'userid', get_string('cohostuserid', 'tupmeet'), $this->_customdata['teachers']);
        $mform->setType('userid', PARAM_INT);
        $mform->addRule('userid', null, 'required', null, 'server');
        $mform->addElement('date_time_selector', 'startdatetime', get_string('startdatetime', 'tupmeet'));
        $mform->addElement('date_time_selector', 'enddatetime', get_string('enddatetime', 'tupmeet'));
        $mform->setDefault('startdatetime', time() + HOURSECS);
        $mform->setDefault('enddatetime', time() + 2 * HOURSECS);
        $mform->addElement('advcheckbox', 'confirmed', get_string('pocstagingack', 'tupmeet'));
        $this->add_action_buttons(false, get_string('pocstart', 'tupmeet'));
    }
}
