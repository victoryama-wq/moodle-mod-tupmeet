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

use mod_tupmeet\local\account\oauth_client_factory;
use mod_tupmeet\local\google\calendar_service;
use mod_tupmeet\local\google\cohost_exception;
use mod_tupmeet\local\google\meet_service;
use mod_tupmeet\local\google\member_service;

/**
 * Isolated Meet-first experiment. Inherits only the unchanged Phase 3 artifact algorithm.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class service extends meet_service {
    /** @var string Official Meet origin. */
    private const MEET = 'https://meet.googleapis.com/v2/';
    /** @var string Owner's primary Calendar, exclusively for experimental events. */
    private const EVENTS = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';
    /** @var oauth_client_factory Native account boundary. */
    private oauth_client_factory $factory;

    /**
     * Reuse native authorization and the existing artifact implementation.
     *
     * @param oauth_client_factory|null $oauth Factory
     */
    public function __construct(?oauth_client_factory $oauth = null) {
        $this->factory = $oauth ?? new oauth_client_factory();
        parent::__construct($this->factory);
    }

    /**
     * Bounded transport used only by this experimental service, including inherited artifact requests.
     *
     * @param \core\oauth2\client $client Native client
     * @param string $method GET, POST or PATCH
     * @param string $url Internally constructed official endpoint
     * @param array|null $payload Body; an empty array is encoded as an empty JSON object
     * @return array Decoded response, never persisted verbatim
     */
    protected function request(\core\oauth2\client $client, string $method, string $url, ?array $payload = null): array {
        global $DB;
        if ($DB->is_transaction_started()) {
            throw new failure();
        }
        $status = 0;
        try {
            if (!in_array($method, ['GET', 'POST', 'PATCH'], true)) {
                throw new failure();
            }
            $options = ['CURLOPT_TIMEOUT' => 15, 'CURLOPT_CONNECTTIMEOUT' => 5,
                'CURLOPT_FOLLOWLOCATION' => false, 'CURLOPT_CUSTOMREQUEST' => $method];
            if ($method === 'GET') {
                $body = $client->get($url, [], $options);
            } else {
                $client->setHeader('Content-Type: application/json');
                $body = $client->post($url, json_encode($payload ?: new \stdClass(), JSON_THROW_ON_ERROR), $options);
            }
            $status = cohost_exception::normalize_http_status($client->get_info()['http_code'] ?? 0);
            if ($status < 200 || $status >= 300) {
                throw new failure($status);
            }
            $object = json_decode($body ?: '', false, 512, JSON_THROW_ON_ERROR);
            if (!is_object($object)) {
                throw new failure($status);
            }
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new failure($status);
        }
    }

    /**
     * Project a Space to bounded, allowlisted identifiers and basic configuration only.
     *
     * @param array $data Untrusted Google response
     * @return array Safe Space
     */
    public static function safe_space(array $data): array {
        $name = $data['name'] ?? null;
        $uri = $data['meetingUri'] ?? null;
        if (!is_string($name) || !self::valid_name($name) || !is_string($uri) || !calendar_service::valid_meet_uri($uri)) {
            throw new failure();
        }
        $code = basename($uri);
        if (basename($name) === $code || (isset($data['meetingCode']) && $data['meetingCode'] !== $code)) {
            throw new failure();
        }
        $result = ['name' => $name, 'meetingUri' => $uri];
        if (isset($data['meetingCode'])) {
            $result['meetingCode'] = $code;
        }
        $configvalues = ['accessType' => ['OPEN', 'TRUSTED', 'RESTRICTED'], 'entryPointAccess' => ['ALL', 'CREATOR_APP_ONLY']];
        foreach ($configvalues as $key => $values) {
            $value = $data['config'][$key] ?? null;
            if (in_array($value, $values, true)) {
                $result['config'][$key] = $value;
            }
        }
        return $result;
    }

    /**
     * A1: create exactly one minimal Space, with no Calendar interaction.
     *
     * @param \stdClass $account Verified current default
     * @return array Safe Space
     */
    public function create_space(\stdClass $account): array {
        return self::safe_space($this->request($this->factory->for_account($account), 'POST', self::MEET . 'spaces', []));
    }

    /**
     * A2: attempt one membership creation; verification is a separate explicit operation.
     *
     * @param \stdClass $account Owner
     * @param array $space Safe created Space
     * @param string $email Server-resolved teacher email
     * @return string Safe Member name
     */
    public function create_member(\stdClass $account, array $space, string $email): string {
        $space = self::safe_space($space);
        if (!validate_email($email)) {
            throw new failure();
        }
        $member = $this->request($this->factory->for_account($account), 'POST', self::MEET . $space['name'] . '/members', [
            'email' => $email, 'role' => 'COHOST',
        ]);
        return self::confirmed_member($member, $space['name'], $email);
    }

    /**
     * Require the exact parent's COHOST and authoritative email.
     *
     * @param array $member Response
     * @param string $space Canonical Space
     * @param string $email Selected identity
     * @return string Member name
     */
    private static function confirmed_member(array $member, string $space, string $email): string {
        if (
            !is_string($member['name'] ?? null) || !member_service::valid_name($member['name'], $space) ||
            !is_string($member['email'] ?? null) || strcasecmp($member['email'], $email) !== 0 ||
            ($member['role'] ?? '') !== 'COHOST'
        ) {
            throw new failure();
        }
        return $member['name'];
    }

    /**
     * A3: verify using bounded, paginated LIST, including recovery after an ambiguous POST result.
     *
     * @param \stdClass $account Owner
     * @param array $space Created Space
     * @param string $email Revalidated teacher
     * @return string Confirmed member
     */
    public function verify_member(\stdClass $account, array $space, string $email): string {
        $space = self::safe_space($space);
        $client = $this->factory->for_account($account);
        $token = '';
        $seen = [];
        $found = null;
        for ($page = 0; $page < 20; $page++) {
            $url = self::MEET . $space['name'] . '/members?pageSize=500';
            if ($token !== '') {
                $url .= '&pageToken=' . rawurlencode($token);
            }
            $data = $this->request($client, 'GET', $url);
            $members = $data['members'] ?? [];
            if (!is_array($members) || !array_is_list($members)) {
                throw new failure();
            }
            foreach ($members as $member) {
                if (!is_array($member) || !is_string($member['email'] ?? null)) {
                    throw new failure();
                }
                if (strcasecmp($member['email'], $email) === 0) {
                    if ($found !== null) {
                        throw new failure();
                    }
                    $found = self::confirmed_member($member, $space['name'], $email);
                }
            }
            $token = $data['nextPageToken'] ?? '';
            if (!is_string($token) || strlen($token) > 4096 || isset($seen[$token])) {
                throw new failure();
            }
            if ($token === '') {
                if ($found === null) {
                    throw new failure();
                }
                return $found;
            }
            $seen[$token] = true;
        }
        throw new failure();
    }

    /**
     * A4: reuse the unchanged Phase 3 comparison, field mask and confirmation algorithm.
     *
     * @param \stdClass $account Owner
     * @param array $space Created Space
     */
    public function configure_artifacts(\stdClass $account, array $space): void {
        $space = self::safe_space($space);
        parent::synchronize($account, (object) [
            'meetspacename' => $space['name'], 'meeturi' => $space['meetingUri'],
            'autorecord' => 1, 'autotranscript' => 0,
        ], fn(string $name): bool => $name === $space['name']);
    }

    /**
     * Experimental Calendar payload, never requesting conference creation or inventing a signature.
     *
     * @param array $run Isolated experiment
     * @param string $email Revalidated teacher email
     * @param bool $native Whether to attempt native representation
     * @return array Documented fields only
     */
    public static function calendar_payload(array $run, string $email, bool $native): array {
        $space = self::safe_space($run['space']);
        $key = $native ? 'native' : 'fallback';
        if (!preg_match('/^[a-f0-9]{32}$/D', $run['eventids'][$key]) || !validate_email($email)) {
            throw new failure();
        }
        $payload = [
            'id' => $run['eventids'][$key], 'summary' => 'TUP Meet PoC - ' . $key,
            'start' => ['dateTime' => gmdate('Y-m-d\TH:i:s\Z', $run['start']), 'timeZone' => $run['timezone']],
            'end' => ['dateTime' => gmdate('Y-m-d\TH:i:s\Z', $run['end']), 'timeZone' => $run['timezone']],
        ];
        if ($native) {
            $payload['conferenceData'] = [
                'conferenceId' => basename($space['meetingUri']),
                'conferenceSolution' => ['key' => ['type' => 'hangoutsMeet']],
                'entryPoints' => [['entryPointType' => 'video', 'uri' => $space['meetingUri']]],
            ];
        } else {
            $payload['attendees'] = [['email' => $email]];
            $payload['location'] = $space['meetingUri'];
        }
        return $payload;
    }

    /**
     * B: single INSERT followed by GET; return true only if the intended representation is confirmed.
     *
     * @param \stdClass $account Owner
     * @param array $run Isolated intent and fixed event IDs
     * @param string $email Revalidated teacher
     * @param bool $native Native or fallback
     * @param callable $remember Remember a successful INSERT even if subsequent verification fails
     * @return bool Same Meet confirmed; false represents unsupported/changed conference data
     */
    public function create_event(\stdClass $account, array $run, string $email, bool $native, callable $remember): bool {
        $client = $this->factory->for_account($account);
        $payload = self::calendar_payload($run, $email, $native);
        $response = $this->request($client, 'POST', self::EVENTS . '?conferenceDataVersion=1&sendUpdates=' .
            ($native ? 'none' : 'all'), $payload);
        if (($response['id'] ?? '') !== $payload['id']) {
            throw new failure();
        }
        $remember($payload['id']);
        $event = $this->request($client, 'GET', self::EVENTS . '/' . $payload['id']);
        if (($event['id'] ?? '') !== $payload['id'] || ($event['status'] ?? '') === 'cancelled') {
            throw new failure();
        }
        $uri = $run['space']['meetingUri'];
        if ($native) {
            $data = $event['conferenceData'] ?? [];
            $points = $data['entryPoints'] ?? [];
            if (!is_array($points)) {
                return false;
            }
            $video = array_values(array_filter($points, fn($point) => is_array($point) &&
                ($point['entryPointType'] ?? '') === 'video'));
            return ($data['conferenceSolution']['key']['type'] ?? '') === 'hangoutsMeet' &&
                ($data['conferenceId'] ?? '') === basename($uri) && count($video) === 1 &&
                ($video[0]['uri'] ?? '') === $uri && (!isset($event['hangoutLink']) || $event['hangoutLink'] === $uri);
        }
        if (!empty($event['conferenceData']) || !empty($event['hangoutLink']) || ($event['location'] ?? '') !== $uri) {
            return false;
        }
        foreach ($event['attendees'] ?? [] as $attendee) {
            if (is_string($attendee['email'] ?? null) && strcasecmp($attendee['email'], $email) === 0) {
                return true;
            }
        }
        return false;
    }
}
