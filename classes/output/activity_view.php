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

namespace mod_tupmeet\output;

/**
 * Compose academic content with a separate, capability-gated technical panel.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_view {
    /**
     * Render local content only after view.php has checked module access.
     * @param \stdClass $meeting Activity
     * @param \context_module $context Module context
     * @param int $cmid Module ID
     * @param int $page Recording page
     * @return string
     */
    public static function render(\stdClass $meeting, \context_module $context, int $cmid, int $page = 0): string {
        global $OUTPUT;
        $html = session_summary::render($meeting) . recording_list::render($meeting, $context, $cmid, $page);
        if (has_capability('moodle/course:manageactivities', $context)) {
            $html .= $OUTPUT->render_from_template('mod_tupmeet/technical_panel', [
                'content' => meeting_status::render($meeting, $context, $cmid, false) .
                    recording_diagnostics::render($meeting, $context, $cmid, $page),
            ]);
        }
        return \html_writer::div($html, 'mod-tupmeet');
    }
}
