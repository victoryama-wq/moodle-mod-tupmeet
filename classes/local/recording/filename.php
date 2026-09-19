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

namespace mod_tupmeet\local\recording;

use mod_tupmeet\local\meeting\schedule;

/**
 * Pure Unicode-safe recording names from the actual conference start in the saved timezone.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class filename {
    /** @var int UTF-8 byte ceiling, including the reserved date/part/extension suffix. */
    public const MAX_BYTES = 240;

    /**
     * Build a safe filename without transliteration or a dependency on PHP's timezone.
     * @param string $activity Activity title at the first rename preparation
     * @param int $start Actual conference start
     * @param string $timezone Saved Moodle timezone
     * @param int $part One-based segment, ordered by recording start
     * @return string MP4 filename
     */
    public static function make(string $activity, int $start, string $timezone, int $part = 1): string {
        if ($start <= 0 || $part < 1 || $part > 10000) {
            throw new \coding_exception('Invalid recording filename inputs.');
        }
        $base = preg_replace('/[\p{Cc}\p{Cf}]/u', '', $activity);
        $base = preg_replace('~[\\\\/:*?"<>|]~u', ' - ', $base ?? '');
        $base = trim(preg_replace('/\s+/u', ' ', $base), " .-");
        if ($base === '') {
            $base = 'Meet';
        }
        $suffix = ' - ' . schedule::date($start, $timezone)->format('Y-m-d - H-i') .
            ($part > 1 ? ' - Parte ' . $part : '') . '.mp4';
        $base = \core_text::substr($base, 0, self::MAX_BYTES - strlen($suffix));
        while (strlen($base . $suffix) > self::MAX_BYTES) {
            $base = \core_text::substr($base, 0, \core_text::strlen($base) - 1);
        }
        return rtrim($base, " .") . $suffix;
    }
}
