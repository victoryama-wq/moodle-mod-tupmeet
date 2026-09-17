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
 * Resolve existing Calendar-created spaces and manage only automatic artifact settings.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class meet_service {
    /** @var string Scope for existing spaces, including those created through Calendar. */
    public const SCOPE = 'https://www.googleapis.com/auth/meetings.space.settings';
    /** @var string Only the two leaf fields owned by this plugin may be patched. */
    public const UPDATE_MASK = 'config.artifactConfig.recordingConfig.autoRecordingGeneration,' .
        'config.artifactConfig.transcriptionConfig.autoTranscriptionGeneration';
    /** @var string Fixed API origin. */
    private const API = 'https://meet.googleapis.com/v2/';
    /** @var oauth_client_factory Native identity-checked OAuth boundary. */
    private oauth_client_factory $oauth;

    /**
     * Configure authorization.
     *
     * @param oauth_client_factory|null $oauth Native client factory
     */
    public function __construct(?oauth_client_factory $oauth = null) {
        $this->oauth = $oauth ?? new oauth_client_factory();
    }

    /**
     * Validate a canonical resource name before it can form part of an API URL.
     *
     * @param string $name Resource name
     * @return bool
     */
    public static function valid_name(string $name): bool {
        return strlen($name) <= 255 && (bool) preg_match('~^spaces/[A-Za-z0-9_-]+$~D', $name);
    }

    /**
     * Native authenticated HTTP with bounded timeouts and sanitized errors.
     *
     * @param \core\oauth2\client $client Verified historical owner's client
     * @param string $method GET or PATCH
     * @param string $url Fixed Meet origin and validated resource
     * @param array|null $payload Patch body
     * @return array Valid JSON object
     */
    protected function request(\core\oauth2\client $client, string $method, string $url, ?array $payload = null): array {
        global $DB;
        if ($DB->is_transaction_started()) {
            throw new \coding_exception('Meet HTTP must run after the database commit.');
        }
        $options = [
            'CURLOPT_TIMEOUT' => 15, 'CURLOPT_CONNECTTIMEOUT' => 5,
            'CURLOPT_FOLLOWLOCATION' => false, 'CURLOPT_CUSTOMREQUEST' => $method,
        ];
        try {
            if ($method === 'GET') {
                $body = $client->get($url, [], $options);
            } else {
                $client->setHeader('Content-Type: application/json');
                $body = $client->post($url, json_encode($payload, JSON_THROW_ON_ERROR), $options);
            }
            $status = (int) ($client->get_info()['http_code'] ?? 0);
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException();
            }
            $result = json_decode($body ?: '', true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($result)) {
                throw new \RuntimeException();
            }
            return $result;
        } catch (\Throwable $e) {
            // No response body, token, nested exception or debug info may reach task logs/UI.
            throw new \moodle_exception('meetconfigfailed', 'mod_tupmeet');
        }
    }

    /**
     * Get an existing space by its permanent name, or resolve its Calendar meeting code once.
     *
     * @param \core\oauth2\client $client Verified system client
     * @param \stdClass $meeting Saved identifiers
     * @return array Validated Space
     */
    public function get_space(\core\oauth2\client $client, \stdClass $meeting): array {
        $name = $meeting->meetspacename ?? '';
        if ($name !== '') {
            if (!self::valid_name($name)) {
                throw new \moodle_exception('meetconfigfailed', 'mod_tupmeet');
            }
        } else {
            $code = $meeting->meetingcode ?? '';
            if (!calendar_service::valid_meet_uri($meeting->meeturi ?? '') || basename($meeting->meeturi) !== $code) {
                throw new \moodle_exception('meetconfigfailed', 'mod_tupmeet');
            }
            $name = 'spaces/' . $code;
        }
        $space = $this->request($client, 'GET', self::API . $name);
        $this->check_name($space);
        if (!empty($meeting->meetspacename)) {
            if ($space['name'] !== $meeting->meetspacename) {
                throw new \moodle_exception('meetconfigfailed', 'mod_tupmeet');
            }
        } else if (($space['meetingCode'] ?? '') !== $code || ($space['meetingUri'] ?? '') !== $meeting->meeturi) {
            throw new \moodle_exception('meetconfigfailed', 'mod_tupmeet');
        }
        return $space;
    }

    /**
     * Reject malformed or foreign resource names.
     *
     * @param array $space API response
     */
    private function check_name(array $space): void {
        if (!is_string($space['name'] ?? null) || !self::valid_name($space['name'])) {
            throw new \moodle_exception('meetconfigfailed', 'mod_tupmeet');
        }
    }

    /**
     * Compare only managed fields; omitted/default/unknown values require explicit confirmation.
     *
     * @param array $space API state
     * @param array $desired Desired artifactConfig
     * @return bool
     */
    private function matches(array $space, array $desired): bool {
        $artifacts = $space['config']['artifactConfig'] ?? [];
        return ($artifacts['recordingConfig']['autoRecordingGeneration'] ?? null) ===
            $desired['recordingConfig']['autoRecordingGeneration'] &&
            ($artifacts['transcriptionConfig']['autoTranscriptionGeneration'] ?? null) ===
            $desired['transcriptionConfig']['autoTranscriptionGeneration'];
    }

    /**
     * Resolve, durably remember the identity, compare and PATCH only if necessary. Never create a space.
     *
     * @param \stdClass $account Historical institutional owner
     * @param \stdClass $meeting Committed desired revision
     * @param callable $remember Persist canonical name before PATCH; return false if revision became stale
     */
    public function synchronize(\stdClass $account, \stdClass $meeting, callable $remember): void {
        $client = $this->oauth->for_account($account);
        $space = $this->get_space($client, $meeting);
        if (!$remember($space['name'])) {
            throw new \moodle_exception('meetconfigfailed', 'mod_tupmeet');
        }
        $desired = [
            'recordingConfig' => ['autoRecordingGeneration' => empty($meeting->autorecord) ? 'OFF' : 'ON'],
            'transcriptionConfig' => ['autoTranscriptionGeneration' => empty($meeting->autotranscript) ? 'OFF' : 'ON'],
        ];
        if ($this->matches($space, $desired)) {
            return;
        }
        $result = $this->request(
            $client,
            'PATCH',
            self::API . $space['name'] . '?updateMask=' . rawurlencode(self::UPDATE_MASK),
            ['name' => $space['name'], 'config' => ['artifactConfig' => $desired]]
        );
        $this->check_name($result);
        if ($result['name'] !== $space['name'] || !$this->matches($result, $desired)) {
            throw new \moodle_exception('meetconfigfailed', 'mod_tupmeet');
        }
    }
}
