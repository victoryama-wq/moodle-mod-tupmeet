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

namespace mod_tupmeet\local\google;

/**
 * Allowlisted cohost diagnostics, with no upstream message, URL, body or chained exception.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cohost_exception extends \moodle_exception {
    /** @var string Safe operation identifier. */
    public readonly string $stage;
    /** @var int Verified HTTP status, or zero when unavailable. */
    public readonly int $httpstatus;

    /**
     * Accept only bounded diagnostic values, never upstream exception details.
     *
     * @param mixed $stage Operation identifier
     * @param mixed $httpstatus HTTP status
     */
    public function __construct(mixed $stage = 'unknown', mixed $httpstatus = 0) {
        $this->stage = self::normalize_stage($stage);
        $this->httpstatus = self::normalize_http_status($httpstatus);
        parent::__construct('cohostfailed', 'mod_tupmeet');
    }

    /**
     * Normalize untrusted persisted or internal stage values before storage/display.
     *
     * @param mixed $stage Candidate
     * @return string Fixed identifier
     */
    public static function normalize_stage(mixed $stage): string {
        return in_array($stage, ['identity', 'space', 'list', 'create', 'patch', 'unknown'], true)
            ? $stage : 'unknown';
    }

    /**
     * Accept only integers or their exact three-digit representation within the HTTP range.
     *
     * @param mixed $status Candidate
     * @return int Status or zero, never text or a partially parsed value
     */
    public static function normalize_http_status(mixed $status): int {
        if (is_string($status) && preg_match('/^[1-5][0-9]{2}$/D', $status)) {
            $status = (int) $status;
        }
        return is_int($status) && $status >= 100 && $status <= 599 ? $status : 0;
    }
}
