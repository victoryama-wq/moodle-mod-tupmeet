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

namespace mod_tupmeet\local\meeting;

use mod_tupmeet\local\google\space_service;

/**
 * Immutable provisioning mode and independent dependent-worker snapshot conditions.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provisioning {
    /**
     * Missing mode represents an old in-memory Calendar snapshot, never a new Meet request.
     *
     * @param \stdClass $record Activity
     * @return bool Meet-first
     */
    public static function is_meet(\stdClass $record): bool {
        return ($record->provisionmode ?? 'calendar') === 'meet';
    }

    /**
     * Meet-dependent reconciliations require a confirmed, valid permanent Space.
     *
     * @param \stdClass $record Activity
     * @return bool Valid Space ready
     */
    public static function space_ready(\stdClass $record): bool {
        if (!self::is_meet($record) || ($record->spacestatus ?? '') !== 'ready') {
            return false;
        }
        try {
            space_service::identifiers(['name' => $record->meetspacename, 'meetingUri' => $record->meeturi,
                'meetingCode' => $record->meetingcode]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Artifacts retain the historical Calendar prerequisite only for historical activities.
     *
     * @param \stdClass $record Activity
     * @return bool Ready for artifact reconciliation
     */
    public static function artifacts_ready(\stdClass $record): bool {
        return self::is_meet($record) ? self::space_ready($record) : $record->syncstatus === 'ready';
    }

    /**
     * Meet-first revisions are independent of Calendar edits, but bound to the exact Space and owner.
     *
     * @param \stdClass $record Snapshot
     * @param string $revision Internal revision field
     * @return array Conditional DB fields
     */
    public static function conditions(\stdClass $record, string $revision): array {
        $conditions = ['id' => $record->id, $revision => $record->{$revision}];
        if (self::is_meet($record)) {
            return $conditions + ['provisionmode' => 'meet', 'spacestatus' => 'ready',
                'spaceversion' => $record->spaceversion, 'meetspacename' => $record->meetspacename,
                'accountid' => $record->accountid];
        }
        return $conditions + ['syncversion' => $record->syncversion, 'syncstatus' => 'ready'];
    }
}
