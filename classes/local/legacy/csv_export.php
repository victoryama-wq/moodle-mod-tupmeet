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

namespace mod_tupmeet\local\legacy;

/**
 * Moodle CSV exports with formula-safe cells and no Google metadata.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class csv_export {
    /**
     * Export headers or the local Meet-first catalog using Moodle CSV APIs.
     * @param bool $catalog Include eligible activity rows
     * @return \csv_export_writer Download-ready writer
     */
    public static function writer(bool $catalog): \csv_export_writer {
        global $CFG;
        importer::authorize();
        require_once($CFG->libdir . '/csvlib.class.php');
        $writer = new \csv_export_writer('comma', '"', 'text/csv', true);
        $writer->set_filename($catalog ? 'tupmeet-catalog' : 'tupmeet-template');
        $writer->add_data($catalog ? ['tupmeetid', 'courseid', 'course_shortname', 'activity_name',
            'timezone', 'publicationmode'] : csv_validator::HEADERS);
        if ($catalog) {
            $activities = importer::activities();
            try {
                foreach ($activities as $activity) {
                    $writer->add_data(array_map([self::class, 'cell'], [$activity->id, $activity->course,
                        $activity->shortname, $activity->name, $activity->timezone, $activity->publicationmode]));
                }
            } finally {
                $activities->close();
            }
        }
        return $writer;
    }

    /**
     * Neutralize formulas even when preceded by whitespace/control characters.
     * @param mixed $value Text or integer
     * @return string
     */
    public static function cell($value): string {
        $text = (string) $value;
        return preg_match('/^[\s\x00-\x20]*[=+@-]/u', $text) ? "'" . $text : $text;
    }
}
