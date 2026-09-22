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
 * Upload only; the submit action creates a preview, never imports.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class legacy_import_form extends \moodleform {
    /**
     * Define a bounded Moodle draft upload.
     */
    public function definition() {
        $form = $this->_form;
        $form->addElement('filepicker', 'csvfile', get_string('legacycsvfile', 'tupmeet'), null, [
            'accepted_types' => ['.csv'], 'maxbytes' => \mod_tupmeet\local\legacy\csv_validator::MAX_BYTES,
        ]);
        $form->addRule('csvfile', null, 'required');
        $this->add_action_buttons(false, get_string('legacyreview', 'tupmeet'));
    }
}
