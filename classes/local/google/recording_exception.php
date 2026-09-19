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
 * Safe discovery/metadata failure: no provider text, headers or nested exceptions.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_exception extends \moodle_exception {
    /** @var int Sanitized HTTP status, zero when unavailable. */
    public int $httpstatus;
    /** @var bool Temporary errors may be retried within the persisted budget. */
    public bool $retryable;

    /**
     * Construct a safe failure.
     * @param int $status HTTP status
     * @param bool $retryable Retry transport and temporary errors only
     */
    public function __construct(int $status = 0, bool $retryable = false) {
        $this->httpstatus = cohost_exception::normalize_http_status($status);
        $this->retryable = $retryable;
        parent::__construct('recordingfailed', 'mod_tupmeet');
    }
}
