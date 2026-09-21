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

namespace mod_tupmeet\output;

use mod_tupmeet\local\diagnostic\health_service;

/**
 * Native Moodle output for safe local diagnostics, independent of academic presentation.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class health_dashboard {
    /**
     * Pair native icons with explicit state text; never communicate by colour alone.
     * @param string $message Translated safe message
     * @param string $severity Native notification type
     * @return string
     */
    private static function notice(string $message, string $severity): string {
        global $OUTPUT;
        $icon = match ($severity) {
            'success' => 'i/checked',
            'warning', 'error' => 'i/warning',
            default => 'i/info',
        };
        return $OUTPUT->notification($OUTPUT->pix_icon($icon, '') . $message, $severity);
    }

    /**
     * Format an existing timestamp; absence is information, not failure.
     * @param int $time Local evidence timestamp
     * @return string
     */
    private static function date(int $time): string {
        return $time ? userdate($time) : get_string('healthnoevidence', 'tupmeet');
    }

    /**
     * Responsive semantic table with its own visible caption.
     * @param string $caption Language key
     * @param array $head Column labels
     * @param array $rows Escaped cells or Moodle-rendered HTML
     * @return string
     */
    private static function table(string $caption, array $head, array $rows): string {
        $table = new \html_table();
        $table->caption = get_string($caption, 'tupmeet');
        $table->head = $head;
        $table->data = $rows;
        return \html_writer::div(\html_writer::table($table), 'table-responsive');
    }

    /**
     * Render only safe projections after enforcing system capability again.
     * @param array $data health_service snapshot
     * @return string HTML
     */
    public static function render(array $data): string {
        global $OUTPUT;
        require_capability('moodle/site:config', \context_system::instance());
        $label = static fn($key) => get_string('health' . $key, 'tupmeet');
        $html = self::notice($label('localonly'), 'info');
        $html .= \html_writer::tag('p', $label('version') . ': ' . s($data['version']) . ' / ' . s($data['release']));
        $accounts = $data['accounts'];
        $html .= $OUTPUT->heading($label('accounts'), 3);
        $html .= self::notice(
            $label($accounts['configured'] ? 'configured' : 'accountwarning'),
            $accounts['configured'] ? 'success' : 'warning'
        );
        $html .= \html_writer::tag('p', $label('verifieddate') . ': ' . self::date($accounts['lastverified']));
        $rows = [];
        foreach (['total', 'enabled', 'verified', 'defaults'] as $key) {
            $rows[] = [$label('account' . $key), $accounts[$key]];
        }
        $html .= self::table('healthaccounts', [$label('metric'), $label('count')], $rows);
        $html .= \html_writer::tag('p', \html_writer::link(new \moodle_url('/mod/tupmeet/accounts.php'), $label('manageaccounts')));
        $html .= $OUTPUT->heading($label('metrics'), 3);
        $html .= \html_writer::tag('p', $label('activities') . ': ' . (int) $data['activities']);
        $rows = [];
        foreach ($data['metrics'] as $key => $metric) {
            $counts = [];
            foreach ($metric['counts'] as $state => $count) {
                $counts[] = $label('state' . $state) . ': ' . (int) $count;
            }
            $rows[] = [$label($key), implode('<br>', $counts) ?: '0',
                $key === 'recordings' ? '—' : self::date($metric['latest'])];
        }
        $rows[] = [$label('visibility'), $label('visible') . ': ' . $data['visibility']['visible'] . '<br>' .
            $label('hidden') . ': ' . $data['visibility']['hidden'], '—'];
        $html .= self::table('healthmetrics', [$label('subsystem'), $label('count'), $label('evidence')], $rows);
        $html .= \html_writer::tag('p', $label('metrichelp'));
        $html .= $OUTPUT->heading($label('tasks'), 3);
        $cron = $data['tasks']['cron'];
        $severity = match ($cron['status']) {
            'missing', 'disabled' => 'error',
            'late', 'backoff' => 'warning',
            'recent' => 'success',
            default => 'info',
        };
        $html .= self::notice('discover_recordings: ' . $label('cron' . $cron['status']), $severity);
        $html .= self::table('healthscheduled', [$label('metric'), $label('value')], [
            [$label('lasttask'), self::date($cron['last'])], [$label('nexttask'), self::date($cron['next'])],
            [$label('faildelay'), $cron['faildelay']],
        ]);
        $rows = [];
        foreach (['total', 'pending', 'running', 'waiting', 'due', 'backoff', 'late'] as $key) {
            $rows[] = [$label('queue' . $key), $data['tasks']['queue'][$key]];
        }
        $html .= self::table('healthadhoc', [$label('metric'), $label('count')], $rows);
        if ($data['tasks']['queue']['excessive']) {
            $html .= self::notice($label('queuewarning'), 'warning');
        }
        if ($data['tasks']['queue']['late']) {
            $html .= self::notice($label('queuelatehelp'), 'warning');
        }
        $html .= \html_writer::tag('p', $label('taskhelp'));
        $html .= $OUTPUT->heading($label('attention'), 3);
        $rows = [];
        foreach ($data['attention']['rows'] as $row) {
            // Names are formatted by Moodle without filters; escape again to keep this administrative table text-only.
            $rows[] = [s(html_entity_decode(strip_tags($row['course']), ENT_QUOTES, 'UTF-8')),
                s(html_entity_decode(strip_tags($row['activity']), ENT_QUOTES, 'UTF-8')),
                $label($row['subsystem']), $OUTPUT->pix_icon('i/warning', '') . $label('state' . $row['state']),
                $row['http'] ?: '—', self::date($row['modified']),
                \html_writer::link(new \moodle_url('/mod/tupmeet/view.php', ['id' => $row['cmid']]), $label('open'))];
        }
        $html .= $rows ? self::table('healthattention', [get_string('course'), $label('activity'), $label('subsystem'),
            $label('state'), 'HTTP', $label('localdate'), $label('action')], $rows) :
            self::notice($label('noattention'), 'info');
        $html .= $OUTPUT->paging_bar(
            $data['attention']['total'],
            $data['attention']['page'],
            health_service::PAGE_SIZE,
            new \moodle_url('/mod/tupmeet/health.php')
        );
        return $html . \html_writer::tag('p', $label('attentionhelp'));
    }
}
