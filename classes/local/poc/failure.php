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

namespace mod_tupmeet\local\poc;

use mod_tupmeet\local\google\cohost_exception;

/**
 * Safe experimental failure: no upstream text or chained exception.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class failure extends \moodle_exception {
    /** @var int Normalized HTTP status, zero if unknown. */
    public readonly int $httpstatus;

    /**
     * Construct a fixed, localized failure.
     *
     * @param mixed $status Native HTTP status only
     */
    public function __construct(mixed $status = 0) {
        $this->httpstatus = cohost_exception::normalize_http_status($status);
        parent::__construct('pocfailed', 'mod_tupmeet');
    }
}
