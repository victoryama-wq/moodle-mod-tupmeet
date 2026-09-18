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
 * Minimal production Space creation. No PoC cache, retries, Calendar or deletion.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class space_service {
    /** @var oauth_client_factory Native historical identity boundary. */
    private oauth_client_factory $oauth;

    /**
     * Configure OAuth.
     *
     * @param oauth_client_factory|null $oauth Native client factory
     */
    public function __construct(?oauth_client_factory $oauth = null) {
        $this->oauth = $oauth ?? new oauth_client_factory();
    }

    /**
     * Validate permanent identity and project only the three identifiers needed by normal activities.
     *
     * @param array $space Google response
     * @return array Safe database fields
     */
    public static function identifiers(array $space): array {
        $name = $space['name'] ?? null;
        $uri = $space['meetingUri'] ?? null;
        $code = $space['meetingCode'] ?? null;
        if (
            !is_string($name) || !meet_service::valid_name($name) ||
            preg_match('~^spaces/[a-z]{3}-[a-z]{4}-[a-z]{3}$~D', $name) ||
            !is_string($uri) || !calendar_service::valid_meet_uri($uri) ||
            ($code !== null && (!is_string($code) || $code !== basename($uri)))
        ) {
            throw new space_exception();
        }
        return ['meetspacename' => $name, 'meeturi' => $uri, 'meetingcode' => basename($uri)];
    }

    /**
     * Authorize first, durably checkpoint immediately before POST, then make exactly one request.
     *
     * @param \stdClass $account Historical owner
     * @param callable $beforepost Persist attempt outside a transaction; false cancels stale work
     * @return array|null Safe identifiers, or null if cancelled before sending
     */
    public function create(\stdClass $account, callable $beforepost): ?array {
        global $DB;
        if ($DB->is_transaction_started()) {
            throw new \coding_exception('Space creation must run after the database commit.');
        }
        $client = $this->oauth->for_account($account);
        $client->setHeader('Content-Type: application/json');
        if (!$beforepost()) {
            return null;
        }
        if ($DB->is_transaction_started()) {
            throw new space_exception();
        }
        $status = 0;
        try {
            $body = $client->post('https://meet.googleapis.com/v2/spaces', '{}', [
                'CURLOPT_CUSTOMREQUEST' => 'POST', 'CURLOPT_TIMEOUT' => 15,
                'CURLOPT_CONNECTTIMEOUT' => 5, 'CURLOPT_FOLLOWLOCATION' => false,
            ]);
            if ($client->get_errno()) {
                throw new space_exception();
            }
            $status = cohost_exception::normalize_http_status($client->get_info()['http_code'] ?? 0);
            if ($status < 200 || $status >= 300) {
                throw new space_exception($status);
            }
            $space = json_decode($body ?: '', true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($space)) {
                throw new space_exception();
            }
            return self::identifiers($space);
        } catch (\Throwable $e) {
            // A malformed successful response is ambiguous, not evidence that nothing was created.
            throw new space_exception($status);
        }
    }
}
