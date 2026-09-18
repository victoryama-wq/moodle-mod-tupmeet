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
use mod_tupmeet\local\meeting\schedule;
use mod_tupmeet\local\meeting\provisioning;
use mod_tupmeet\local\meeting\cohost_identity;

/**
 * Google Calendar primary-calendar operations through Moodle's native system client.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class calendar_service {
    /** @var string Least privilege for events owned on the institutional primary calendar. */
    public const SCOPE = 'https://www.googleapis.com/auth/calendar.events.owned';
    /** @var string Fixed API endpoint; neither the host nor calendar is user supplied. */
    private const EVENTS = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';
    /** @var oauth_client_factory Native account and identity checks. */
    private oauth_client_factory $oauth;

    /**
     * Configure the native authorization boundary.
     *
     * @param oauth_client_factory|null $oauth Native system client factory
     */
    public function __construct(?oauth_client_factory $oauth = null) {
        $this->oauth = $oauth ?? new oauth_client_factory();
    }

    /**
     * Issue a bounded native OAuth HTTP request and discard sensitive error bodies.
     *
     * @param \core\oauth2\client $client Verified system client
     * @param string $method HTTP method
     * @param string $url Fixed Calendar endpoint
     * @param array|null $payload JSON request
     * @return array HTTP status and decoded successful body
     */
    protected function request(\core\oauth2\client $client, string $method, string $url, ?array $payload = null): array {
        $options = ['CURLOPT_TIMEOUT' => 15, 'CURLOPT_CONNECTTIMEOUT' => 5];
        try {
            if ($method === 'GET') {
                $body = $client->get($url, [], $options);
            } else {
                $client->setHeader('Content-Type: application/json');
                $options['CURLOPT_CUSTOMREQUEST'] = $method;
                $body = $client->post($url, json_encode($payload, JSON_THROW_ON_ERROR), $options);
            }
            $info = $client->get_info();
            $status = (int) ($info['http_code'] ?? 0);
            return [$status, $status >= 200 && $status < 300 ? json_decode($body ?: '', true) : null];
        } catch (\Throwable $e) {
            // Never propagate the provider body, native token, URL query, or exception debug info.
            throw new \moodle_exception('calendarfailed', 'mod_tupmeet');
        }
    }

    /**
     * Read an event; absence is meaningful only before the first confirmed synchronization.
     *
     * @param \core\oauth2\client $client Verified client
     * @param string $eventid Stable Calendar ID
     * @return array|null Event or HTTP 404
     */
    public function get_event(\core\oauth2\client $client, string $eventid): ?array {
        [$status, $event] = $this->request($client, 'GET', self::EVENTS . '/' . rawurlencode($eventid));
        if ($status === 404) {
            return null;
        }
        return $this->checked($status, $event);
    }

    /**
     * Reject errors and malformed bodies with a fixed, safe message.
     *
     * @param int $status HTTP status
     * @param array|null $event Decoded body
     * @return array Event
     */
    private function checked(int $status, ?array $event): array {
        if ($status < 200 || $status >= 300 || empty($event['id']) || ($event['status'] ?? '') === 'cancelled') {
            throw new \moodle_exception('calendarfailed', 'mod_tupmeet');
        }
        return $event;
    }

    /**
     * Insert exactly one event using the precommitted ID and conference request.
     *
     * @param \core\oauth2\client $client Verified client
     * @param \stdClass $meeting Precommitted desired state
     * @return array Created event or the already existing event after a conflict
     */
    public function create_event(\core\oauth2\client $client, \stdClass $meeting): array {
        $payload = schedule::payload($meeting);
        $payload['id'] = $meeting->calendareventid;
        $payload['extendedProperties']['private']['tupmeet'] = $meeting->creationkey;
        $payload['conferenceData'] = $this->conference_request($meeting);
        [$status, $event] = $this->request($client, 'POST', self::EVENTS . '?conferenceDataVersion=1', $payload);
        if ($status === 409) {
            $event = $this->get_event($client, $meeting->calendareventid);
            if ($event !== null) {
                $this->assert_event($meeting, $event);
                return $event;
            }
        }
        return $this->checked($status, $event);
    }

    /**
     * Update the entire series while preserving unrelated fields and existing conference data.
     *
     * @param \core\oauth2\client $client Verified client
     * @param \stdClass $meeting Desired state
     * @param array $event Existing event
     * @return array Updated event
     */
    public function update_event(\core\oauth2\client $client, \stdClass $meeting, array $event): array {
        $payload = schedule::payload($meeting);
        // Repair absent conference data on the same event. A failed request requires a new ID.
        if (
            empty($event['conferenceData']) ||
                ($event['conferenceData']['createRequest']['status']['statusCode'] ?? '') === 'failure'
        ) {
            $payload['conferenceData'] = $this->conference_request($meeting);
        }
        [$status, $result] = $this->request(
            $client,
            'PATCH',
            self::EVENTS . '/' . rawurlencode($meeting->calendareventid) . '?conferenceDataVersion=1',
            $payload
        );
        return $this->checked($status, $result);
    }

    /**
     * Create a stable request within a revision; edits or explicit retries can renew failures.
     *
     * @param \stdClass $meeting Desired state
     * @return array Conference request
     */
    private function conference_request(\stdClass $meeting): array {
        return ['createRequest' => [
            'requestId' => hash('sha256', $meeting->creationkey . $meeting->syncversion),
            'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
        ]];
    }

    /**
     * Prevent adopting a foreign event or following a provider-supplied ID.
     *
     * @param \stdClass $meeting Desired state
     * @param array $event API event
     */
    private function assert_event(\stdClass $meeting, array $event): void {
        if (
            ($event['id'] ?? '') !== $meeting->calendareventid ||
                ($event['extendedProperties']['private']['tupmeet'] ?? '') !== $meeting->creationkey
        ) {
            throw new \moodle_exception('calendarfailed', 'mod_tupmeet');
        }
    }

    /**
     * Accept only ordinary HTTPS Google Meet join links.
     *
     * @param string $uri Candidate link
     * @return bool
     */
    public static function valid_meet_uri(string $uri): bool {
        return (bool) preg_match('~^https://meet\.google\.com/[a-z]{3}-[a-z]{4}-[a-z]{3}$~D', $uri);
    }

    /**
     * Reconcile desired state idempotently with the historical account.
     *
     * @param \stdClass $account Immutable institutional owner
     * @param \stdClass $meeting Persisted desired state
     * @return array Sanitized local identifiers and readiness
     */
    public function synchronize(\stdClass $account, \stdClass $meeting): array {
        if (provisioning::is_meet($meeting)) {
            return $this->synchronize_meet($account, $meeting);
        }
        $client = $this->oauth->for_account($account);
        $event = $this->get_event($client, $meeting->calendareventid);
        if ($event === null) {
            if ($meeting->lastsync) {
                throw new \moodle_exception('calendarfailed', 'mod_tupmeet');
            }
            $event = $this->create_event($client, $meeting);
        } else {
            $this->assert_event($meeting, $event);
            if (!empty($meeting->meeturi)) {
                $sameuri = false;
                foreach ($event['conferenceData']['entryPoints'] ?? [] as $point) {
                    if (($point['entryPointType'] ?? '') === 'video' && ($point['uri'] ?? '') === $meeting->meeturi) {
                        $sameuri = true;
                    }
                }
                if (!$sameuri) {
                    // Never replace a known historical conference just because remote data disappeared.
                    throw new \moodle_exception('calendarfailed', 'mod_tupmeet');
                }
            }
            $event = $this->update_event($client, $meeting, $event);
        }
        $this->assert_event($meeting, $event);
        // Conference creation is asynchronous. One immediate read; Moodle cron handles further polling.
        if (empty($event['conferenceData']['entryPoints'])) {
            $event = $this->get_event($client, $meeting->calendareventid) ?? $event;
            $this->assert_event($meeting, $event);
        }
        $uri = null;
        foreach ($event['conferenceData']['entryPoints'] ?? [] as $entry) {
            if (($entry['entryPointType'] ?? '') === 'video' && self::valid_meet_uri($entry['uri'] ?? '')) {
                $uri = $entry['uri'];
                break;
            }
        }
        return [
            'meeturi' => $uri,
            'meetingcode' => $uri ? basename($uri) : null,
            // Calendar conferenceId is the meeting code, not a Meet REST space resource name.
            'syncstatus' => $uri ? 'ready' : 'pending',
            'lastsync' => time(),
        ];
    }

    /**
     * Native Calendar representation of the existing Space, without requesting another conference.
     *
     * @param \stdClass $meeting Confirmed Meet-first activity
     * @return array Documented conference fields; no createRequest or invented signature
     */
    public static function native_conference(\stdClass $meeting): array {
        $ids = space_service::identifiers(['name' => $meeting->meetspacename, 'meetingUri' => $meeting->meeturi,
            'meetingCode' => $meeting->meetingcode]);
        return ['conferenceId' => $ids['meetingcode'], 'conferenceSolution' => ['key' => ['type' => 'hangoutsMeet']],
            'entryPoints' => [['entryPointType' => 'video', 'uri' => $ids['meeturi']]]];
    }

    /**
     * Confirm the exact remote conference; successful HTTP alone never confirms synchronization.
     *
     * @param \stdClass $meeting Expected identity
     * @param array $event Remote event
     */
    private function confirm_native(\stdClass $meeting, array $event): void {
        $this->assert_event($meeting, $event);
        $conference = $event['conferenceData'] ?? [];
        $points = $conference['entryPoints'] ?? [];
        $video = is_array($points) ? array_values(array_filter($points, static fn($point) =>
            is_array($point) && ($point['entryPointType'] ?? '') === 'video')) : [];
        if (
            ($conference['conferenceId'] ?? '') !== $meeting->meetingcode ||
            ($conference['conferenceSolution']['key']['type'] ?? '') !== 'hangoutsMeet' ||
            count($video) !== 1 || ($video[0]['uri'] ?? '') !== $meeting->meeturi ||
            (isset($event['hangoutLink']) && $event['hangoutLink'] !== $meeting->meeturi)
        ) {
            throw new \moodle_exception('calendarfailed', 'mod_tupmeet');
        }
    }

    /**
     * Verify that Google retained the authoritative attendee, not merely a submitted arbitrary address.
     *
     * @param \stdClass $meeting Validated identity
     * @param array $event Event
     */
    private function confirm_attendee(\stdClass $meeting, array $event): void {
        if (!is_array($event['attendees'] ?? null)) {
            throw new \moodle_exception('calendarfailed', 'mod_tupmeet');
        }
        foreach ($event['attendees'] ?? [] as $attendee) {
            if (is_string($attendee['email'] ?? null) && strcasecmp($attendee['email'], $meeting->cohostemail) === 0) {
                return;
            }
        }
        throw new \moodle_exception('calendarfailed', 'mod_tupmeet');
    }

    /**
     * Reconcile a native event/series using one precreated Space and the immutable historical owner.
     *
     * @param \stdClass $account Owner
     * @param \stdClass $meeting Desired state
     * @return array Calendar-only readiness; never replace Space identifiers
     */
    private function synchronize_meet(\stdClass $account, \stdClass $meeting): array {
        global $DB;
        if ($DB->is_transaction_started()) {
            throw new \coding_exception('Calendar synchronization must run after the database commit.');
        }
        if (!provisioning::space_ready($meeting)) {
            throw new \moodle_exception('calendarfailed', 'mod_tupmeet');
        }
        $teacher = cohost_identity::resolve((int) $meeting->course, (int) $meeting->cohostuserid);
        if ($teacher->email !== $meeting->cohostemail) {
            throw new \moodle_exception('cohostinvalid', 'mod_tupmeet');
        }
        $client = $this->oauth->for_account($account);
        $event = $this->get_event($client, $meeting->calendareventid);
        // Recheck after the read and immediately before any invitation write.
        $teacher = cohost_identity::resolve((int) $meeting->course, (int) $meeting->cohostuserid);
        if ($teacher->email !== $meeting->cohostemail) {
            throw new \moodle_exception('cohostinvalid', 'mod_tupmeet');
        }
        $payload = schedule::payload($meeting);
        $payload['extendedProperties']['private'] = ['tupmeet' => $meeting->creationkey,
            'tupmeetversion' => $meeting->syncversion];
        $payload['attendees'] = [['email' => $teacher->email]];
        if ($event === null) {
            if ($meeting->lastsync) {
                throw new \moodle_exception('calendarfailed', 'mod_tupmeet');
            }
            $payload['id'] = $meeting->calendareventid;
            $payload['conferenceData'] = self::native_conference($meeting);
            [$status, $result] = $this->request(
                $client,
                'POST',
                self::EVENTS . '?conferenceDataVersion=1&sendUpdates=all',
                $payload
            );
            if ($status !== 409) {
                $this->assert_event($meeting, $this->checked($status, $result));
            }
        } else {
            $this->confirm_native($meeting, $event);
            if (($event['extendedProperties']['private']['tupmeetversion'] ?? '') === $meeting->syncversion) {
                // Recover a successful write whose response/local confirmation was lost, without resending mail.
                $this->confirm_attendee($meeting, $event);
                return ['syncstatus' => 'ready', 'lastsync' => time()];
            }
            // Preserve existing RSVP data and manually added attendees. Do not resend conferenceData/signature.
            $payload['attendees'] = $event['attendees'] ?? [];
            if (!is_array($payload['attendees'])) {
                throw new \moodle_exception('calendarfailed', 'mod_tupmeet');
            }
            $found = false;
            foreach ($payload['attendees'] as $attendee) {
                if (is_string($attendee['email'] ?? null) && strcasecmp($attendee['email'], $teacher->email) === 0) {
                    $found = true;
                }
            }
            if (!$found) {
                $payload['attendees'][] = ['email' => $teacher->email];
            }
            [$status, $result] = $this->request($client, 'PATCH', self::EVENTS . '/' . rawurlencode($meeting->calendareventid) .
                '?conferenceDataVersion=1&sendUpdates=all', $payload);
            $this->assert_event($meeting, $this->checked($status, $result));
        }
        $confirmed = $this->get_event($client, $meeting->calendareventid);
        if ($confirmed === null) {
            throw new \moodle_exception('calendarfailed', 'mod_tupmeet');
        }
        $this->confirm_native($meeting, $confirmed);
        $this->confirm_attendee($meeting, $confirmed);
        if (($confirmed['extendedProperties']['private']['tupmeetversion'] ?? '') !== $meeting->syncversion) {
            throw new \moodle_exception('calendarfailed', 'mod_tupmeet');
        }
        return ['syncstatus' => 'ready', 'lastsync' => time()];
    }
}
