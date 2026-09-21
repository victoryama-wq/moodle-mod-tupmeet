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

namespace mod_tupmeet\local\diagnostic;

/**
 * Capability-gated, read-only local evidence. Never constructs a Google client.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class health_service {
    /** @var int Maximum attention rows returned per page. */
    public const PAGE_SIZE = 50;
    /** @var int Scheduled execution warning threshold, seconds. */
    public const CRON_AGE = 900;
    /** @var int Adhoc lateness threshold, excluding normal backoff. */
    public const QUEUE_AGE = 1800;
    /** @var int Queue size warning, not a limit or throttle. */
    public const QUEUE_WARNING = 1000;
    /** @var array Fixed subsystem/field definitions; never supplied by the request. */
    private const METRICS = [
        'space' => ['tupmeet', 'spacestatus', 'spacemodified', "provisionmode = 'meet'"],
        'calendar' => ['tupmeet', 'syncstatus', 'lastsync', '1 = 1'],
        'artifacts' => ['tupmeet', 'meetconfigstatus', 'meetconfigmodified', '1 = 1'],
        'cohost' => ['tupmeet', 'cohoststatus', 'cohostmodified', '1 = 1'],
        'discovery' => ['tupmeet', 'recordingsyncstatus', 'recordingslastsync', '1 = 1'],
        'recordings' => ['tupmeet_recordings', 'state', 'lastseen', '1 = 1'],
        'rename' => ['tupmeet_recordings', 'renamestatus', 'renamedat', '1 = 1'],
    ];

    /**
     * Project only displayable, local evidence, with a fixed query count independent of activity volume.
     * @param int $page Attention page
     * @return array Safe display projection
     */
    public static function snapshot(int $page = 0): array {
        global $DB;
        require_capability('moodle/site:config', \context_system::instance());
        $data = ['version' => (string) get_config('mod_tupmeet', 'version'),
            'release' => \core_plugin_manager::instance()->get_plugin_info('mod_tupmeet')->release,
            'activities' => $DB->count_records('tupmeet'), 'accounts' => self::accounts(), 'metrics' => []];
        // Map corrupt/unrecognized values to one bucket; even GROUP BY results are bounded.
        $states = "'pending', 'creating', 'syncing', 'ready', 'configured', 'error', 'uncertain',
            'idle', 'legacy', 'unconfigured', 'skipped', 'unavailable', 'STARTED', 'ENDED', 'FILE_GENERATED'";
        foreach (self::METRICS as $key => [$table, $field, $date, $where]) {
            $expression = "CASE WHEN $field IN ($states) THEN $field ELSE 'unknown' END";
            $groups = $DB->get_records_sql("SELECT $expression AS state, COUNT(1) AS total,
                    MAX($date) AS latest FROM {{$table}} WHERE $where GROUP BY $expression");
            $counts = [];
            $latest = 0;
            foreach ($groups as $group) {
                $counts[$group->state] = (int) $group->total;
                if (in_array($group->state, ['ready', 'configured'], true)) {
                    $latest = max($latest, (int) $group->latest);
                }
            }
            ksort($counts);
            $data['metrics'][$key] = ['counts' => $counts, 'latest' => $latest];
        }
        $visibility = $DB->get_record_sql('SELECT COUNT(1) AS total,
            COALESCE(SUM(CASE WHEN studentvisible = 1 THEN 1 ELSE 0 END), 0) AS visible FROM {tupmeet_recordings}');
        $data['visibility'] = ['visible' => (int) $visibility->visible,
            'hidden' => (int) $visibility->total - (int) $visibility->visible];
        $data['attention'] = self::attention($page);
        $data['tasks'] = self::tasks();
        return $data;
    }

    /**
     * Aggregate identities without retrieving OAuth credentials, subjects or email addresses.
     * @return array Account counts and evidence
     */
    private static function accounts(): array {
        global $DB;
        $row = $DB->get_record_sql("SELECT COUNT(1) AS total,
            COALESCE(SUM(a.enabled), 0) AS enabled, COALESCE(SUM(a.isdefault), 0) AS defaults,
            COALESCE(SUM(CASE WHEN a.connectionstatus = 'verified' THEN 1 ELSE 0 END), 0) AS verified,
            COALESCE(SUM(CASE WHEN a.enabled = 1 AND a.isdefault = 1 THEN 1 ELSE 0 END), 0) AS active,
            COALESCE(SUM(CASE WHEN a.enabled = 1 AND a.isdefault = 1 AND a.connectionstatus = 'verified'
                AND i.id IS NOT NULL THEN 1 ELSE 0 END), 0) AS valid,
            MAX(CASE WHEN a.enabled = 1 AND a.isdefault = 1 THEN a.timeverified ELSE 0 END) AS lastverified
            FROM {tupmeet_accounts} a LEFT JOIN {oauth2_issuer} i ON i.id = a.issuerid");
        $data = array_map('intval', (array) $row);
        $data['configured'] = $data['defaults'] === 1 && $data['active'] === 1 && $data['valid'] === 1;
        return $data;
    }

    /**
     * Union only attention states, joining names and local module links once for the bounded page.
     * Dates are existing subsystem timestamps, not a newly invented error history.
     * @param int $page Requested page
     * @return array Count, actual page and safe rows
     */
    private static function attention(int $page): array {
        global $DB;
        $parts = [];
        $fields = [
            'space' => ['spacestatus', 'spacemodified', 'spacehttpstatus', "AND provisionmode = 'meet'"],
            'calendar' => ['syncstatus', 'timemodified', '0', ''],
            'artifacts' => ['meetconfigstatus', 'meetconfigmodified', '0', ''],
            'cohost' => ['cohoststatus', 'cohostmodified', 'cohosthttpstatus', ''],
            'discovery' => ['recordingsyncstatus', 'recordingsyncqueued', 'recordingshttpstatus', ''],
        ];
        foreach ($fields as $key => [$state, $date, $http, $where]) {
            $condition = $key === 'space' ? "IN ('error', 'uncertain')" : "= 'error'";
            $parts[] = "SELECT id AS activityid, 0 AS recordingid, '$key' AS subsystem,
                $state AS state, $date AS modified, $http AS http FROM {tupmeet} WHERE $state $condition $where";
        }
        $parts[] = "SELECT tupmeetid AS activityid, id AS recordingid, 'rename' AS subsystem,
            renamestatus AS state, timemodified AS modified, renamehttpstatus AS http
            FROM {tupmeet_recordings} WHERE renamestatus = 'error'";
        $from = '(' . implode(' UNION ALL ', $parts) . ') issue
            JOIN {tupmeet} t ON t.id = issue.activityid
            JOIN {course} c ON c.id = t.course
            JOIN {modules} m ON m.name = :module
            JOIN {course_modules} cm ON cm.instance = t.id AND cm.module = m.id AND cm.course = c.id';
        $params = ['module' => 'tupmeet'];
        $total = $DB->count_records_sql('SELECT COUNT(1) FROM ' . $from, $params);
        $page = max(0, min($page, (int) floor(max(0, $total - 1) / self::PAGE_SIZE)));
        $records = $DB->get_recordset_sql(
            'SELECT c.fullname AS course, t.name AS activity, cm.id AS cmid,
            issue.subsystem, issue.state, issue.http, issue.modified FROM ' . $from . '
            ORDER BY issue.modified DESC, issue.activityid, issue.subsystem, issue.recordingid',
            $params,
            $page * self::PAGE_SIZE,
            self::PAGE_SIZE
        );
        $rows = [];
        foreach ($records as $record) {
            // Explicit context and no filters: formatting cannot introduce per-row lookups or external content.
            $options = ['context' => \context_system::instance(), 'filter' => false];
            $rows[] = ['course' => format_string($record->course, true, $options),
                'activity' => format_string($record->activity, true, $options),
                'cmid' => (int) $record->cmid, 'subsystem' => $record->subsystem, 'state' => $record->state,
                'http' => (int) $record->http >= 100 && (int) $record->http <= 599 ? (int) $record->http : 0,
                'modified' => (int) $record->modified];
        }
        $records->close();
        return ['total' => $total, 'page' => $page, 'rows' => $rows];
    }

    /**
     * Public scheduled-task API plus bounded SQL aggregates for adhoc work. Never mutates core tables.
     * @return array Queue evidence without task customdata, hostnames or logs
     */
    private static function tasks(): array {
        global $DB;
        $now = time();
        $task = \core\task\manager::get_scheduled_task('\\mod_tupmeet\\task\\discover_recordings');
        $cron = ['status' => 'missing', 'last' => 0, 'next' => 0, 'faildelay' => 0];
        if ($task) {
            $cron['last'] = (int) $task->get_last_run_time();
            $cron['next'] = (int) $task->get_next_run_time();
            $cron['faildelay'] = (int) $task->get_fail_delay();
            if ($task->get_disabled()) {
                $cron['status'] = 'disabled';
            } else if (!$cron['last']) {
                $cron['status'] = 'never';
            } else if ($cron['faildelay']) {
                $cron['status'] = 'backoff';
            } else {
                $cron['status'] = $now - $cron['last'] > self::CRON_AGE ? 'late' : 'recent';
            }
        }
        $names = array_map(
            static fn($name) => '\\mod_tupmeet\\task\\' . $name,
            ['sync_recordings', 'rename_recording', 'sync_space', 'sync_meeting', 'sync_cohost', 'sync_meet_config']
        );
        [$sql, $params] = $DB->get_in_or_equal($names, SQL_PARAMS_NAMED, 'task');
        $params += ['now' => $now, 'late' => $now - self::QUEUE_AGE, 'component' => 'mod_tupmeet'];
        $row = $DB->get_record_sql("SELECT COUNT(1) AS total,
            COALESCE(SUM(CASE WHEN timestarted > 0 THEN 1 ELSE 0 END), 0) AS running,
            COALESCE(SUM(CASE WHEN (timestarted IS NULL OR timestarted = 0) AND faildelay > 0
                THEN 1 ELSE 0 END), 0) AS backoff,
            COALESCE(SUM(CASE WHEN (timestarted IS NULL OR timestarted = 0) AND COALESCE(faildelay, 0) = 0
                AND nextruntime <= :now THEN 1 ELSE 0 END), 0) AS due,
            COALESCE(SUM(CASE WHEN (timestarted IS NULL OR timestarted = 0) AND COALESCE(faildelay, 0) = 0
                AND nextruntime < :late THEN 1 ELSE 0 END), 0) AS late
            FROM {task_adhoc} WHERE component = :component OR classname $sql", $params);
        $queue = array_map('intval', (array) $row);
        $queue['pending'] = $queue['total'] - $queue['running'];
        $queue['waiting'] = $queue['pending'] - $queue['backoff'] - $queue['due'];
        $queue['excessive'] = $queue['pending'] > self::QUEUE_WARNING;
        return ['cron' => $cron, 'queue' => $queue];
    }
}
