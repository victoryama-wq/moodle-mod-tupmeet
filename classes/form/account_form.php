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
require_once($CFG->libdir . '/formslib.php');

/**
 * Register metadata for an existing Moodle OAuth2 issuer.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class account_form extends \moodleform {
    /**
     * Define administrator input.
     */
    public function definition() {
        $mform = $this->_form;
        $mform->addElement('header', 'registeraccount', get_string('registeraccount', 'mod_tupmeet'));
        $mform->addElement('text', 'displayname', get_string('accountdisplayname', 'mod_tupmeet'), ['maxlength' => 255]);
        $mform->setType('displayname', PARAM_TEXT);
        $mform->addRule('displayname', null, 'required', null, 'client');
        $mform->addRule('displayname', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->addElement('select', 'issuerid', get_string('oauthissuer', 'mod_tupmeet'), $this->_customdata['issuers']);
        $mform->setType('issuerid', PARAM_INT);
        $mform->addRule('issuerid', null, 'required', null, 'client');
        $this->add_action_buttons(false, get_string('registeraccount', 'mod_tupmeet'));
    }
}
