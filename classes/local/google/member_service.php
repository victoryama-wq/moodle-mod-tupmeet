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

use mod_tupmeet\local\account\oauth_client_factory;

/**
 * Official Meet members API; no deletion, space creation or artifact setting mutations.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class member_service {
    /** @var string Official members write scope, including its Calendar-space limitations. */
    public const SCOPE = 'https://www.googleapis.com/auth/meetings.space.created';
    /** @var string Read pre-meeting members on Calendar-created spaces. */
    public const READONLY_SCOPE = 'https://www.googleapis.com/auth/meetings.space.readonly';
    /** @var string Fixed Google origin. */
    private const API = 'https://meet.googleapis.com/v2/';
    /** @var oauth_client_factory Historical owner's native OAuth client factory. */
    private oauth_client_factory $oauth;

    /**
     * Configure the native authorization boundary.
     *
     * @param oauth_client_factory|null $oauth Client factory
     */
    public function __construct(?oauth_client_factory $oauth = null) {
        $this->oauth = $oauth ?? new oauth_client_factory();
    }

    /**
     * Validate a member's resource belongs to exactly the expected permanent space.
     *
     * @param string $name Member resource name
     * @param string $space Space name
     * @return bool
     */
    public static function valid_name(string $name, string $space): bool {
        return meet_service::valid_name($space) && strlen($name) <= 255 &&
            (bool) preg_match('~^' . preg_quote($space, '~') . '/members/[A-Za-z0-9_-]+$~D', $name);
    }

    /**
     * Native HTTP; expose only status codes internally, never upstream bodies in errors.
     *
     * @param \core\oauth2\client $client Verified owner
     * @param string $method GET, POST or PATCH
     * @param string $url Fixed origin and validated path
     * @param array|null $payload Member data
     * @return array HTTP status and decoded object
     */
    private function request(\core\oauth2\client $client, string $method, string $url, ?array $payload = null): array {
        global $DB;
        if ($DB->is_transaction_started()) {
            throw new \coding_exception('Meet members HTTP must run after commit.');
        }
        $status = 0;
        $stage = ['GET' => 'list', 'POST' => 'create', 'PATCH' => 'patch'][$method] ?? 'unknown';
        try {
            $options = [
                'CURLOPT_TIMEOUT' => 15, 'CURLOPT_CONNECTTIMEOUT' => 5,
                'CURLOPT_FOLLOWLOCATION' => false, 'CURLOPT_CUSTOMREQUEST' => $method,
            ];
            if ($method === 'GET') {
                $body = $client->get($url, [], $options);
            } else {
                $client->setHeader('Content-Type: application/json');
                $body = $client->post($url, json_encode($payload, JSON_THROW_ON_ERROR), $options);
            }
            $status = cohost_exception::normalize_http_status($client->get_info()['http_code'] ?? 0);
            if ($method === 'POST' && $status === 409) {
                return [409, []];
            }
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException();
            }
            $object = json_decode($body ?: '', false, 512, JSON_THROW_ON_ERROR);
            if (!is_object($object)) {
                throw new \RuntimeException();
            }
            return [$status, json_decode($body, true, 512, JSON_THROW_ON_ERROR)];
        } catch (\Throwable $e) {
            throw new cohost_exception($stage, $status);
        }
    }

    /**
     * Strict validation also applies to unrelated members encountered while listing.
     *
     * @param array $member Remote member
     * @param string $space Expected parent
     */
    private function validate(array $member, string $space): void {
        if (
            !is_string($member['name'] ?? null) || !self::valid_name($member['name'], $space) ||
                !is_string($member['email'] ?? null) || !validate_email($member['email']) ||
                !in_array($member['role'] ?? 'ROLE_UNSPECIFIED', ['ROLE_UNSPECIFIED', 'COHOST'], true)
        ) {
            throw new \moodle_exception('cohostfailed', 'mod_tupmeet');
        }
    }

    /**
     * Find an unambiguous email across all pages, with bounded requests and token-cycle detection.
     *
     * @param \core\oauth2\client $client Owner
     * @param string $space Permanent space
     * @param string $email Server-resolved snapshot
     * @param callable $current Check the committed revision before each request
     * @return array|null Matching member
     */
    private function find(\core\oauth2\client $client, string $space, string $email, callable $current): ?array {
        $token = '';
        $seen = [];
        $found = null;
        for ($page = 0; $page < 20; $page++) {
            if (!$current()) {
                throw new \moodle_exception('cohostfailed', 'mod_tupmeet');
            }
            $url = self::API . $space . '/members?pageSize=500';
            if ($token !== '') {
                $url .= '&pageToken=' . rawurlencode($token);
            }
            [, $data] = $this->request($client, 'GET', $url);
            $members = $data['members'] ?? [];
            if (!is_array($members) || !array_is_list($members)) {
                throw new \moodle_exception('cohostfailed', 'mod_tupmeet');
            }
            foreach ($members as $member) {
                if (!is_array($member)) {
                    throw new \moodle_exception('cohostfailed', 'mod_tupmeet');
                }
                $this->validate($member, $space);
                if (strcasecmp($member['email'], $email) === 0) {
                    if ($found !== null) {
                        throw new \moodle_exception('cohostfailed', 'mod_tupmeet');
                    }
                    $found = $member;
                }
            }
            $token = $data['nextPageToken'] ?? '';
            if (!is_string($token) || strlen($token) > 4096 || isset($seen[$token])) {
                throw new \moodle_exception('cohostfailed', 'mod_tupmeet');
            }
            if ($token === '') {
                return $found;
            }
            $seen[$token] = true;
        }
        throw new \moodle_exception('cohostfailed', 'mod_tupmeet');
    }

    /**
     * Reconcile one teacher against the existing Calendar space; preserve every other setting/member.
     *
     * @param \stdClass $account Historical owner
     * @param \stdClass $meeting Saved activity and identity snapshot
     * @param callable $remember Persist the canonical Space before member writes
     * @param callable $current Reject stale work and revalidate the selected teacher
     * @return string Confirmed member resource
     */
    public function synchronize(\stdClass $account, \stdClass $meeting, callable $remember, callable $current): string {
        global $DB;
        if ($DB->is_transaction_started()) {
            throw new \coding_exception('Cohost synchronization must run after commit.');
        }
        $stage = 'identity';
        try {
            $client = $this->oauth->for_account($account);
            $stage = 'space';
            $space = $meeting->meetspacename ?? '';
            if ($space === '') {
                $resolved = (new meet_service($this->oauth))->get_space($client, $meeting);
                $space = $resolved['name'];
            }
            if (!meet_service::valid_name($space) || !$remember($space)) {
                throw new \RuntimeException();
            }
            $email = $meeting->cohostemail;
            $stage = 'list';
            $member = $this->find($client, $space, $email, $current);
            $written = false;
            if (!$current()) {
                throw new \RuntimeException();
            }
            if ($member === null) {
                $stage = 'create';
                [$status, $member] = $this->request($client, 'POST', self::API . $space . '/members', [
                    'email' => $email, 'role' => 'COHOST',
                ]);
                $written = $status !== 409;
                if ($status === 409) {
                    $stage = 'list';
                    $member = $this->find($client, $space, $email, $current);
                    // A conflict is not permission to mutate a different or unconfirmed role.
                    if (($member['role'] ?? '') !== 'COHOST') {
                        throw new \RuntimeException();
                    }
                }
            } else if (($member['role'] ?? '') !== 'COHOST') {
                $stage = 'patch';
                [, $result] = $this->request($client, 'PATCH', self::API . $member['name'] . '?updateMask=role', [
                    'name' => $member['name'], 'role' => 'COHOST',
                ]);
                if (($result['name'] ?? '') !== $member['name']) {
                    throw new \RuntimeException();
                }
                $member = $result;
                $written = true;
            }
            $this->validate($member, $space);
            if (strcasecmp($member['email'], $email) !== 0 || ($member['role'] ?? '') !== 'COHOST') {
                throw new \RuntimeException();
            }
            if ($written && \mod_tupmeet\local\meeting\provisioning::is_meet($meeting)) {
                $stage = 'list';
                $confirmed = $this->find($client, $space, $email, $current);
                if (($confirmed['name'] ?? '') !== $member['name'] || ($confirmed['role'] ?? '') !== 'COHOST') {
                    throw new \RuntimeException();
                }
            }
            return $member['name'];
        } catch (cohost_exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new cohost_exception($stage);
        }
    }
}
