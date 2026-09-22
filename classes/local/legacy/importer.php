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
 * Session-bound review and atomic, local-only import of historical references.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class importer {
    /** @var int Preview validity, independently checked even if the cache backend retains it. */
    public const TTL = 900;

    /**
     * Shared service guard, including direct callers.
     * @param bool $post Whether the operation requires POST and sesskey
     */
    public static function authorize(bool $post = false): void {
        require_capability('moodle/site:config', \context_system::instance());
        if ($post) {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                throw new \moodle_exception('invalidrequest');
            }
            require_sesskey();
        }
    }

    /**
     * Stream real Meet-first course modules, with only local matching/catalog metadata.
     * @return \moodle_recordset
     */
    public static function activities(): \moodle_recordset {
        global $DB;
        self::authorize();
        return $DB->get_recordset_sql(
            "SELECT t.id, t.course, t.name, t.timezone, t.publicationmode, cm.id AS cmid, c.shortname
               FROM {tupmeet} t JOIN {course} c ON c.id = t.course
               JOIN {course_modules} cm ON cm.instance = t.id AND cm.course = t.course
               JOIN {modules} m ON m.id = cm.module
              WHERE m.name = :module AND t.provisionmode = :mode AND cm.deletioninprogress = 0
              ORDER BY t.id",
            ['module' => 'tupmeet', 'mode' => 'meet']
        );
    }

    /**
     * Rebuild a read-only plan from original bytes and current local state.
     * @param string $csv Original CSV
     * @return array Rows, summary and destination fingerprint
     */
    public static function plan(string $csv): array {
        global $DB;
        self::authorize();
        $input = csv_validator::parse($csv);
        $wanted = [];
        $explicit = [];
        foreach ($input as $row) {
            $wanted[csv_validator::name($row['session_name'])] = [];
            if (ctype_digit($row['tupmeetid'])) {
                $explicit[(int) $row['tupmeetid']] = null;
            }
        }
        $activities = self::activities();
        try {
            foreach ($activities as $activity) {
                $name = csv_validator::name($activity->name);
                if (isset($wanted[$name]) && count($wanted[$name]) < 2) {
                    $wanted[$name][] = $activity;
                }
                if (array_key_exists((int) $activity->id, $explicit)) {
                    $explicit[(int) $activity->id] = $activity;
                }
            }
        } finally {
            $activities->close();
        }
        $files = [];
        foreach ($input as $row) {
            try {
                $files[] = csv_validator::fileid($row['drive_url']);
            } catch (\moodle_exception $e) {
                // The row validator reports invalid URLs without querying their identifiers.
                continue;
            }
        }
        $duplicates = [];
        foreach (array_chunk(array_unique($files), 500) as $chunk) {
            [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            foreach (['tupmeet_legacy_recordings', 'tupmeet_recordings'] as $table) {
                foreach ($DB->get_fieldset_select($table, 'drivefileid', 'drivefileid ' . $insql, $inparams) as $file) {
                    $duplicates[strtolower($file)] = true;
                }
            }
        }
        $rows = [];
        $seen = [];
        $resolution = [];
        $summary = array_fill_keys(['correct', 'duplicate', 'warning', 'missing', 'ambiguous', 'error'], 0);
        foreach ($input as $number => $row) {
            $entry = ['line' => $number + 2, 'session' => $row['session_name'],
                'datetime' => $row['session_date'] . ' ' . $row['session_time'], 'part' => $row['part'],
                'visible' => $row['visible'] !== '0', 'target' => '', 'status' => 'correct', 'message' => '', 'record' => null];
            $matches = $wanted[csv_validator::name($row['session_name'])];
            $activity = null;
            if ($row['tupmeetid'] !== '') {
                if (preg_match('/^[1-9][0-9]{0,9}$/D', $row['tupmeetid'])) {
                    $activity = $explicit[(int) $row['tupmeetid']] ?? null;
                }
                if (!$activity) {
                    $entry['status'] = 'error';
                    $entry['message'] = 'legacytargetinvalid';
                }
            } else if (count($matches) !== 1) {
                $entry['status'] = $matches ? 'ambiguous' : 'missing';
            } else {
                $activity = reset($matches);
            }
            if ($activity) {
                $entry['target'] = $activity->name . ' (#' . $activity->id . ', ' . $activity->shortname . ')';
                try {
                    $entry['record'] = csv_validator::record($row, $activity);
                    if (csv_validator::name($row['session_name']) !== csv_validator::name($activity->name)) {
                        $entry['status'] = 'warning';
                        $entry['message'] = 'legacytitlewarning';
                    }
                    // Conservative across database collations: do not force a case-only identity collision.
                    $key = strtolower($entry['record']['drivefileid']);
                    if (isset($seen[$key]) || isset($duplicates[$key])) {
                        $entry['status'] = 'duplicate';
                    }
                    $seen[$key] = true;
                } catch (\moodle_exception $e) {
                    $entry['status'] = 'error';
                    $entry['message'] = $e->errorcode;
                }
            }
            // Duplicate state may advance between preview and confirmation; destinations may not.
            $resolution[] = [$entry['record'], $activity, in_array($entry['status'], ['error', 'missing', 'ambiguous'], true)];
            $summary[$entry['status']]++;
            $rows[] = $entry;
        }
        return ['rows' => $rows, 'summary' => $summary,
            'blocking' => $summary['missing'] + $summary['ambiguous'] + $summary['error'],
            'fingerprint' => hash('sha256', json_encode($resolution))];
    }

    /**
     * Replace the single session preview; uploading never writes recording tables.
     * @param string $csv Original uploaded bytes
     * @return array Plan and opaque confirmation token
     */
    public static function preview(string $csv): array {
        global $USER;
        self::authorize(true);
        $plan = self::plan($csv);
        $token = bin2hex(random_bytes(32));
        \cache::make('mod_tupmeet', 'legacy_preview')->set('current', [
            'csv' => $csv, 'hash' => hash('sha256', $csv), 'token' => $token, 'userid' => (int) $USER->id,
            'expires' => time() + self::TTL, 'fingerprint' => $plan['fingerprint'],
        ]);
        return $plan + ['token' => $token];
    }

    /**
     * Reload the current session preview for read-only pagination.
     * @return array|null Current revalidated plan
     */
    public static function current(): ?array {
        self::authorize();
        $cached = \cache::make('mod_tupmeet', 'legacy_preview')->get('current');
        if (!$cached) {
            return null;
        }
        $stored = self::stored($cached['token']);
        $plan = self::plan($stored['csv']);
        if (!hash_equals($stored['fingerprint'], $plan['fingerprint'])) {
            throw new \moodle_exception('legacychanged', 'tupmeet');
        }
        return $plan + ['token' => $stored['token']];
    }

    /**
     * Validate the session-bound preview without ever accepting browser-supplied rows.
     * @param string $token Random confirmation token
     * @return array Original server-side preview
     */
    private static function stored(string $token): array {
        global $USER;
        $cache = \cache::make('mod_tupmeet', 'legacy_preview');
        $stored = $cache->get('current');
        if (!$stored || $stored['expires'] <= time()) {
            $cache->delete('current');
            throw new \moodle_exception('legacypreviewexpired', 'tupmeet');
        }
        if (
            (int) $stored['userid'] !== (int) $USER->id || !hash_equals($stored['token'], $token) ||
                !hash_equals($stored['hash'], hash('sha256', $stored['csv']))
        ) {
            throw new \moodle_exception('legacypreviewexpired', 'tupmeet');
        }
        return $stored;
    }

    /**
     * Confirm a freshly resolved batch under importer and destination locks.
     * @param string $token Session token, never CSV/row data
     * @return array Imported and skipped counts
     */
    public static function confirm(string $token): array {
        global $DB, $USER;
        self::authorize(true);
        $stored = self::stored($token);
        $factory = \core\lock\lock_config::get_lock_factory('mod_tupmeet');
        $locks = [];
        try {
            $lock = $factory->get_lock('legacy-import', 0);
            if (!$lock) {
                throw new \moodle_exception('legacybusy', 'tupmeet');
            }
            $locks[] = $lock;
            $plan = self::plan($stored['csv']);
            self::check_plan($stored, $plan);
            $ids = array_unique(array_column(array_column($plan['rows'], 'record'), 'tupmeetid'));
            sort($ids, SORT_NUMERIC);
            foreach ($ids as $id) {
                $lock = $factory->get_lock('meeting:' . $id, 0);
                if (!$lock) {
                    throw new \moodle_exception('legacybusy', 'tupmeet');
                }
                $locks[] = $lock;
            }
            $transaction = $DB->start_delegated_transaction();
            // Parent row locks also serialize deletion wrapped in an outer Moodle transaction.
            // Supported validation database: MariaDB 10.11 (also PostgreSQL FOR UPDATE syntax).
            foreach ($ids as $id) {
                $DB->get_record_sql('SELECT id FROM {tupmeet} WHERE id = :id FOR UPDATE', ['id' => $id], MUST_EXIST);
            }
            $plan = self::plan($stored['csv']);
            self::check_plan($stored, $plan);
            $result = ['importedcount' => 0, 'duplicatecount' => 0];
            $now = time();
            foreach ($plan['rows'] as $row) {
                $record = $row['record'];
                if ($row['status'] === 'duplicate') {
                    $result['duplicatecount']++;
                    continue;
                }
                static::insert((object) ($record + [
                    'importeduserid' => $USER->id, 'importedat' => $now, 'timecreated' => $now, 'timemodified' => $now,
                    'visibilityuserid' => 0, 'visibilitymodified' => 0,
                ]));
                $result['importedcount']++;
            }
            \mod_tupmeet\event\legacy_recordings_imported::create([
                'context' => \context_system::instance(), 'other' => $result,
            ])->trigger();
            $transaction->allow_commit();
            \cache::make('mod_tupmeet', 'legacy_preview')->delete('current');
            return $result;
        } catch (\Throwable $e) {
            if (isset($transaction)) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }

    /**
     * Local persistence boundary, overridable for deterministic database-failure tests.
     * @param \stdClass $record Validated local fields
     * @return int Local ID
     */
    protected static function insert(\stdClass $record): int {
        global $DB;
        return $DB->insert_record('tupmeet_legacy_recordings', $record);
    }

    /**
     * Reject all blocked batches and destination drift before inserts.
     * @param array $stored Preview
     * @param array $plan Recomputed plan
     */
    private static function check_plan(array $stored, array $plan): void {
        if ($plan['blocking']) {
            throw new \moodle_exception('legacyblocked', 'tupmeet');
        }
        if (!hash_equals($stored['fingerprint'], $plan['fingerprint'])) {
            throw new \moodle_exception('legacychanged', 'tupmeet');
        }
    }
}
