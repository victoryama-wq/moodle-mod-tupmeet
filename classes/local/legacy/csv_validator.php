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
 * Bounded CSV parsing and strict local-only validation.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class csv_validator {
    /** @var int Maximum upload bytes. */
    public const MAX_BYTES = 5 * 1024 * 1024;
    /** @var int Maximum data rows. */
    public const MAX_ROWS = 5000;
    /** @var array Ordered official headings. */
    public const HEADERS = ['session_name', 'session_date', 'session_time', 'part', 'drive_url',
        'original_filename', 'visible', 'tupmeetid'];

    /**
     * Parse a bounded UTF-8 stream without rewriting names that resemble formulas.
     * Core csv_import_reader round-trips through the formula-escaping exporter.
     * Native fgetcsv preserves the original cell data, including quoted commas/newlines.
     * @param string $content Original bytes
     * @return array Rows keyed by official headings
     */
    public static function parse(string $content): array {
        if (strlen($content) > self::MAX_BYTES || !mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            throw new \moodle_exception('legacyinvalidcsv', 'tupmeet');
        }
        $content = \core_text::trim_utf8_bom($content);
        $stream = fopen('php://temp/maxmemory:5242880', 'w+');
        try {
            fwrite($stream, $content);
            rewind($stream);
            $header = fgetcsv($stream, 0, ',', '"', '');
            if ($header !== self::HEADERS) {
                throw new \moodle_exception('legacyheaders', 'tupmeet');
            }
            $rows = [];
            while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                if ($cells === [null]) {
                    continue;
                }
                if (count($cells) !== count(self::HEADERS) || count($rows) >= self::MAX_ROWS) {
                    throw new \moodle_exception('legacyinvalidcsv', 'tupmeet');
                }
                $row = array_combine(self::HEADERS, $cells);
                foreach ($row as $key => $value) {
                    if (!in_array($key, ['session_name', 'original_filename'], true)) {
                        $row[$key] = trim($value);
                    }
                }
                $rows[] = $row;
            }
            if (!$rows) {
                throw new \moodle_exception('legacyinvalidcsv', 'tupmeet');
            }
            return $rows;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Exact case-sensitive name matching with whitespace normalization only.
     * @param string $name Original name
     * @return string
     */
    public static function name(string $name): string {
        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * Extract only a bounded Drive file ID; discard all incoming query parameters.
     * @param string $url Input
     * @return string
     */
    public static function fileid(string $url): string {
        if (
            strlen($url) > 2000 || !preg_match(
                '~^https://drive\.google\.com/file/d/([A-Za-z0-9_-]{1,200})(?:/[^\s?#]*)?(?:\?[^\s#]*)?(?:#[^\s]*)?$~D',
                $url,
                $match
            )
        ) {
            throw new \moodle_exception('legacyurl', 'tupmeet');
        }
        return $match[1];
    }

    /**
     * Strict civil time in the activity timezone; reject DST gaps and ambiguous folds.
     * @param string $date YYYY-MM-DD
     * @param string $time HH:MM
     * @param string $timezone Saved IANA zone
     * @return int Unix timestamp
     */
    public static function timestamp(string $date, string $time, string $timezone): int {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || !preg_match('/^\d{2}:\d{2}$/D', $time)) {
            throw new \moodle_exception('legacydate', 'tupmeet');
        }
        try {
            $zone = new \DateTimeZone($timezone);
            $input = $date . ' ' . $time;
            $instant = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $input, $zone);
            if (!$instant || $instant->format('Y-m-d H:i') !== $input || $instant->getTimestamp() <= 0) {
                throw new \Exception();
            }
            // A CSV without an offset cannot disambiguate two instants with the same local clock.
            $stamp = $instant->getTimestamp();
            foreach ($zone->getTransitions($stamp - 86400, $stamp + 86400) ?: [] as $transition) {
                $candidate = $stamp + $instant->getOffset() - $transition['offset'];
                if (
                    $candidate !== $stamp && (new \DateTimeImmutable('@' . $candidate))->setTimezone($zone)
                        ->format('Y-m-d H:i') === $input
                ) {
                    throw new \Exception();
                }
            }
            return $stamp;
        } catch (\Throwable $e) {
            throw new \moodle_exception('legacydate', 'tupmeet');
        }
    }

    /**
     * Project one valid CSV row to legacy fields; identity never comes from hidden inputs.
     * @param array $row CSV cells
     * @param \stdClass $activity Resolved activity
     * @return array Safe insert fields
     */
    public static function record(array $row, \stdClass $activity): array {
        foreach (['session_name', 'original_filename'] as $key) {
            if (trim($row[$key]) === '' || \core_text::strlen($row[$key]) > 255 || preg_match('/[\x00-\x1f\x7f]/', $row[$key])) {
                throw new \moodle_exception('legacytext', 'tupmeet');
            }
        }
        if (!preg_match('/^[1-9][0-9]{0,3}$/D', $row['part'])) {
            throw new \moodle_exception('legacypart', 'tupmeet');
        }
        if (!in_array($row['visible'], ['', '0', '1'], true)) {
            throw new \moodle_exception('legacyvisible', 'tupmeet');
        }
        $file = self::fileid($row['drive_url']);
        return ['tupmeetid' => (int) $activity->id, 'sessionname' => $row['session_name'],
            'sessionstart' => self::timestamp($row['session_date'], $row['session_time'], $activity->timezone),
            'partnumber' => (int) $row['part'], 'drivefileid' => $file,
            'exporturi' => 'https://drive.google.com/file/d/' . $file . '/view',
            'originalfilename' => $row['original_filename'], 'studentvisible' => $row['visible'] === '0' ? 0 : 1];
    }
}
