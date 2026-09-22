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
 * Escaped, paginated admin preview without file IDs, original URLs or hidden row data.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class legacy_preview {
    /**
     * Render a plan and its protected confirmation control.
     * @param array $plan Server-generated plan
     * @param int $page Zero-based preview page
     * @return string HTML
     */
    public static function render(array $plan, int $page = 0): string {
        global $OUTPUT;
        \mod_tupmeet\local\legacy\importer::authorize();
        $html = $OUTPUT->heading(get_string('legacyreview', 'tupmeet'), 3);
        $html .= \html_writer::div(get_string('legacyrows', 'tupmeet', count($plan['rows'])));
        foreach ($plan['summary'] as $state => $count) {
            $html .= \html_writer::div(get_string('legacystate' . $state, 'tupmeet') . ': ' . $count);
        }
        $table = new \html_table();
        $table->head = array_map(
            static fn($key) => get_string($key, 'tupmeet'),
            ['legacyrow', 'recordingssession', 'legacydatetime', 'legacypartlabel', 'healthactivity',
            'healthvisibility',
            'legacyresult']
        );
        $page = max(0, min($page, (int) floor((count($plan['rows']) - 1) / 50)));
        foreach (array_slice($plan['rows'], $page * 50, 50) as $row) {
            $result = get_string('legacystate' . $row['status'], 'tupmeet');
            if ($row['message']) {
                $result .= ': ' . get_string($row['message'], 'tupmeet');
            }
            $table->data[] = [$row['line'], s($row['session']), s($row['datetime']), s($row['part']), s($row['target']),
                get_string($row['visible'] ? 'recordingvisible' : 'recordinghidden', 'tupmeet'), s($result)];
        }
        $html .= \html_writer::table($table);
        $html .= $OUTPUT->paging_bar(count($plan['rows']), $page, 50, new \moodle_url('/mod/tupmeet/legacy.php'));
        if (!$plan['blocking']) {
            $html .= $OUTPUT->single_button(new \moodle_url('/mod/tupmeet/legacy.php', [
                'action' => 'confirm', 'token' => $plan['token'],
            ]), get_string('legacyconfirm', 'tupmeet'), 'post');
        } else {
            $html .= $OUTPUT->notification(get_string('legacyblocked', 'tupmeet'), 'warning');
        }
        return $html;
    }
}
