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
 * Meet conference/recording discovery by permanent Space, never by Drive search.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_service extends recording_transport {
    /** @var string Fixed Meet API. */
    private const API = 'https://meet.googleapis.com/v2/';
    /** @var int Defensive ceiling per list, supporting up to 10000 items. */
    public const MAX_PAGES = 100;

    /**
     * Accept permanent names only, never a meeting-code alias.
     * @param string $name Space name
     * @return bool
     */
    public static function valid_space(string $name): bool {
        return meet_service::valid_name($name) && !preg_match('~^spaces/[a-z]{3}-[a-z]{4}-[a-z]{3}$~D', $name);
    }

    /**
     * Validate conference names.
     * @param mixed $name Candidate
     * @return bool
     */
    public static function valid_conference($name): bool {
        return is_string($name) && strlen($name) <= 255 &&
            (bool) preg_match('~^conferenceRecords/[A-Za-z0-9_-]+$~D', $name);
    }

    /**
     * Validate a recording under exactly the requested parent.
     * @param mixed $name Candidate
     * @param string $parent Conference name
     * @return bool
     */
    public static function valid_recording($name, string $parent): bool {
        return self::valid_conference($parent) && is_string($name) && strlen($name) <= 255 &&
            (bool) preg_match('~^' . preg_quote($parent, '~') . '/recordings/[A-Za-z0-9_-]+$~D', $name);
    }

    /**
     * Bound Drive identity before URL interpolation.
     * @param mixed $id Candidate
     * @return bool
     */
    public static function valid_file($id): bool {
        return is_string($id) && (bool) preg_match('/^[A-Za-z0-9_-]{1,200}$/D', $id);
    }

    /**
     * Accept the documented Drive playback URL for this exact file, without rebuilding it.
     * @param mixed $uri Candidate
     * @param string $file Expected file ID
     * @return bool
     */
    public static function valid_export($uri, string $file): bool {
        if (!self::valid_file($file) || !is_string($uri) || strlen($uri) > 2000) {
            return false;
        }
        return (bool) preg_match('~^https://drive\.google\.com/file/d/' . preg_quote($file, '~') .
            '/(?:view|preview)(?:\?[A-Za-z0-9_=&%.-]{1,1000})?$~D', $uri);
    }

    /**
     * Parse explicit RFC3339 timestamps; reject normalization of invalid dates.
     * @param mixed $value Timestamp
     * @param bool $optional Missing end of an ongoing resource
     * @return int Unix timestamp
     */
    public static function timestamp($value, bool $optional = false): int {
        if ($optional && $value === null) {
            return 0;
        }
        if (
            !is_string($value) ||
            !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,9})?(?:Z|[+-]\d\d:\d\d)$/D', $value)
        ) {
            throw new recording_exception();
        }
        try {
            // PHP supports microseconds; protobuf permits nanoseconds.
            $date = new \DateTimeImmutable(preg_replace('/(\.\d{6})\d+/', '$1', $value));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date->getTimestamp() <= 0 || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
                throw new recording_exception();
            }
            return $date->getTimestamp();
        } catch (\Throwable $e) {
            throw new recording_exception();
        }
    }

    /**
     * Exhaust a paginated list. Cycles/limits are errors, never a successful partial listing.
     * @param \core\oauth2\client $client Client
     * @param string $path Validated path
     * @param string $key Response list field
     * @param array $params Fixed query
     * @return array Resources
     */
    private function listing(\core\oauth2\client $client, string $path, string $key, array $params = []): array {
        $seen = [];
        $items = [];
        $token = '';
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $query = ['pageSize' => 100] + $params;
            if ($token !== '') {
                $query['pageToken'] = $token;
            }
            $result = $this->request($client, self::API . $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
            $list = $result[$key] ?? [];
            if (!is_array($list) || !array_is_list($list)) {
                throw new recording_exception();
            }
            foreach ($list as $item) {
                if (!is_array($item)) {
                    throw new recording_exception();
                }
                $items[] = $item;
            }
            $token = $result['nextPageToken'] ?? '';
            if (!is_string($token) || strlen($token) > 4096 || isset($seen[$token])) {
                throw new recording_exception();
            }
            if ($token === '') {
                return $items;
            }
            $seen[$token] = true;
        }
        throw new recording_exception();
    }

    /**
     * Discover conferences with their complete recording lists, yielding one validated conference at a time.
     * @param \stdClass $account Historical owner
     * @param string $space Canonical identity
     * @return \Generator Safe conference/recording metadata
     */
    public function discover(\stdClass $account, string $space): \Generator {
        if (!self::valid_space($space)) {
            throw new recording_exception();
        }
        $client = $this->client($account);
        $filter = ['filter' => 'space.name = "' . $space . '"'];
        $conferences = $this->listing($client, 'conferenceRecords', 'conferenceRecords', $filter);
        $seen = [];
        foreach ($conferences as $conference) {
            $name = $conference['name'] ?? null;
            if (!self::valid_conference($name) || ($conference['space'] ?? null) !== $space || isset($seen[$name])) {
                throw new recording_exception();
            }
            $seen[$name] = true;
            $start = self::timestamp($conference['startTime'] ?? null);
            $end = self::timestamp($conference['endTime'] ?? null, true);
            if ($end && $end < $start) {
                throw new recording_exception();
            }
            $rows = [];
            foreach ($this->listing($client, $name . '/recordings', 'recordings') as $recording) {
                $rname = $recording['name'] ?? null;
                $state = $recording['state'] ?? null;
                if (
                    !self::valid_recording($rname, $name) || isset($rows[$rname]) ||
                    !in_array($state, ['STARTED', 'ENDED', 'FILE_GENERATED'], true)
                ) {
                    throw new recording_exception();
                }
                $row = ['recordingname' => $rname, 'state' => $state,
                    'starttime' => self::timestamp($recording['startTime'] ?? null),
                    'endtime' => self::timestamp($recording['endTime'] ?? null, $state === 'STARTED')];
                preg_match('/\.(\d{1,9})/', $recording['startTime'], $fraction);
                $row['startnanos'] = (int) str_pad($fraction[1] ?? '', 9, '0');
                if ($row['starttime'] < $start || ($row['endtime'] && $row['endtime'] < $row['starttime'])) {
                    throw new recording_exception();
                }
                if ($state === 'FILE_GENERATED') {
                    $file = $recording['driveDestination']['file'] ?? null;
                    $uri = $recording['driveDestination']['exportUri'] ?? null;
                    if (!self::valid_file($file) || !self::valid_export($uri, $file)) {
                        throw new recording_exception();
                    }
                    $row += ['drivefileid' => $file, 'exporturi' => $uri];
                }
                $rows[$rname] = $row;
            }
            // Explicit ordering remains correct even if pages arrive in an unexpected order.
            uasort($rows, static fn($a, $b) =>
                [$a['starttime'], $a['startnanos'], $a['recordingname']] <=>
                [$b['starttime'], $b['startnanos'], $b['recordingname']]);
            yield ['conferencename' => $name, 'starttime' => $start, 'endtime' => $end, 'recordings' => $rows];
        }
    }
}
