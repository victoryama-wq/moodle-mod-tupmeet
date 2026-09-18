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
 * Sanitized Space creation outcome, never an upstream message or exception chain.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class space_exception extends \moodle_exception {
    /** @var int Exact normalized response code, zero without a response. */
    public readonly int $httpstatus;

    /**
     * Record only trusted transport metadata.
     *
     * @param mixed $status Actual response status
     */
    public function __construct(mixed $status = 0) {
        $this->httpstatus = cohost_exception::normalize_http_status($status);
        parent::__construct('spacefailed', 'mod_tupmeet');
    }

    /**
     * Only explicit request/auth rejection is definitive; 408, 409 and server errors are ambiguous.
     *
     * @return bool No creation was accepted
     */
    public function rejected(): bool {
        return in_array($this->httpstatus, [400, 401, 403, 404, 405, 422, 429], true);
    }
}
