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

namespace mod_tupmeet;

use mod_tupmeet\local\google\cohost_exception;

/**
 * Diagnostic values never accept provider text, partial integers or exception chains.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(cohost_exception::class)]
final class cohost_exception_test extends \advanced_testcase {
    /**
     * Only fixed stages and normalized HTTP integers survive.
     */
    public function test_diagnostic_allowlists(): void {
        foreach (['identity', 'space', 'list', 'create', 'patch', 'unknown'] as $stage) {
            $error = new cohost_exception($stage, 403);
            $this->assertSame($stage, $error->stage);
            $this->assertSame(403, $error->httpstatus);
            $this->assertNull($error->getPrevious());
            $this->assertNull($error->debuginfo);
        }
        foreach ([403, '403', 404, 409, 429, 500, 200, 599] as $status) {
            $this->assertSame((int) $status, cohost_exception::normalize_http_status($status));
        }
        foreach (
            [null, false, true, [], new \stdClass(), 403.5, '403 private', ' 403', '403 ',
                '4e2', '0403', '<script>', -1, 99, 600, 999, PHP_INT_MAX] as $status
        ) {
            $this->assertSame(0, cohost_exception::normalize_http_status($status));
        }
        foreach ([null, [], new \stdClass(), 403, 'CREATE', 'synthetic private', ''] as $stage) {
            $this->assertSame('unknown', (new cohost_exception($stage))->stage);
        }
    }
}
