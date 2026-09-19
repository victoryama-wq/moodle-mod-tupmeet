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
 * Metadata-only transport shared by the two Phase 4 services.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_transport {
    /** @var oauth_client_factory Historical native authorization. */
    protected oauth_client_factory $oauth;

    /**
     * Configure native OAuth.
     * @param oauth_client_factory|null $oauth Factory
     */
    public function __construct(?oauth_client_factory $oauth = null) {
        $this->oauth = $oauth ?? new oauth_client_factory();
    }

    /**
     * Authorize outside transactions, sanitizing even identity errors.
     * @param \stdClass $account Historical owner
     * @return \core\oauth2\client
     */
    protected function client(\stdClass $account): \core\oauth2\client {
        global $DB;
        if ($DB->is_transaction_started()) {
            throw new \coding_exception('Recording HTTP must run after commit.');
        }
        try {
            return $this->oauth->for_account($account);
        } catch (\Throwable $e) {
            throw new recording_exception();
        }
    }

    /**
     * Read metadata or PATCH only its name at a fixed, internally constructed API URL.
     * @param \core\oauth2\client $client Verified client
     * @param string $url Internal URL
     * @param array|null $payload Null for GET, exactly name for PATCH
     * @return array Decoded JSON object
     */
    protected function request(\core\oauth2\client $client, string $url, ?array $payload = null): array {
        global $DB;
        if ($DB->is_transaction_started()) {
            throw new \coding_exception('Recording HTTP must run after commit.');
        }
        if ($payload !== null && array_keys($payload) !== ['name']) {
            throw new recording_exception();
        }
        $options = ['CURLOPT_TIMEOUT' => 15, 'CURLOPT_CONNECTTIMEOUT' => 5, 'CURLOPT_FOLLOWLOCATION' => false,
            'CURLOPT_CUSTOMREQUEST' => $payload === null ? 'GET' : 'PATCH'];
        try {
            if ($payload === null) {
                $body = $client->get($url, [], $options);
            } else {
                $client->setHeader('Content-Type: application/json');
                $body = $client->post($url, json_encode($payload, JSON_THROW_ON_ERROR), $options);
            }
        } catch (\Throwable $e) {
            throw new recording_exception(0, true);
        }
        if ($client->get_errno()) {
            throw new recording_exception(0, true);
        }
        $status = cohost_exception::normalize_http_status($client->get_info()['http_code'] ?? 0);
        if ($status < 200 || $status >= 300) {
            throw new recording_exception($status, $status === 0 || $status === 429 || $status >= 500);
        }
        try {
            $result = json_decode($body ?: '', true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($result) || !str_starts_with(ltrim($body), '{')) {
                throw new recording_exception();
            }
            return $result;
        } catch (\Throwable $e) {
            throw new recording_exception($status);
        }
    }
}
