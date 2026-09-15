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

/**
 * Immediate best-effort synchronization after Moodle commits the module save.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Dispatch only TUP Meet changes; the durable task survives interrupted observers.
     *
     * @param \core\event\base $event Committed module creation or update
     */
    public static function module_saved(\core\event\base $event): void {
        if (($event->other['modulename'] ?? '') !== 'tupmeet') {
            return;
        }
        try {
            (new \mod_tupmeet\local\meeting\meeting_manager())->synchronize((int) $event->other['instanceid']);
        } catch (\Throwable $e) {
            // Pending state and the committed task remain. Never expose sensitive upstream errors.
            return;
        }
    }
}
