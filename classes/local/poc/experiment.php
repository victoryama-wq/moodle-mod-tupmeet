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

use mod_tupmeet\local\meeting\cohost_identity;
use mod_tupmeet\local\meeting\schedule;

/**
 * Administrator-only, temporary PoC state machine. Never writes normal meeting or account records.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class experiment {
    /** @var array Explicit external operations in smoke order. */
    public const STAGES = ['create' => 'spaces.create', 'member' => 'members.create', 'verify' => 'members.verify',
        'artifacts' => 'artifactConfig', 'native' => 'calendar.native', 'fallback' => 'calendar.fallback'];
    /** @var service Isolated HTTP boundary. */
    private service $service;
    /** @var \core_cache\cache Temporary native cache, never the normal meeting tables. */
    private \core_cache\cache $cache;

    /**
     * Configure experimental operations.
     *
     * @param service|null $service Native transport, injectable for offline tests
     */
    public function __construct(?service $service = null) {
        $this->service = $service ?? new service();
        $this->cache = \core_cache\cache::make('mod_tupmeet', 'meet_first_poc');
    }

    /**
     * A delegated site capability alone does not grant access to the experiment.
     */
    public static function require_admin(): void {
        if (!isloggedin() || isguestuser() || !is_siteadmin()) {
            throw new \moodle_exception('pocadminonly', 'mod_tupmeet');
        }
    }

    /**
     * Read safe temporary state without contacting Google or starting an experiment.
     *
     * @return array|null Current administrator's run
     */
    public function state(): ?array {
        global $USER;
        self::require_admin();
        $state = $this->cache->get((string) $USER->id);
        return is_array($state) ? $state : null;
    }

    /**
     * Persist the attempt marker before HTTP so replay cannot repeat an ambiguous creation.
     *
     * @param array $state Safe state
     */
    private function save(array $state): void {
        global $USER;
        if (!$this->cache->set((string) $USER->id, $state)) {
            throw new failure();
        }
    }

    /**
     * Require exactly one currently enabled, verified institutional default.
     *
     * @return \stdClass Default owner
     */
    private function account(): \stdClass {
        global $DB;
        $defaults = $DB->get_records('tupmeet_accounts', ['enabled' => 1, 'isdefault' => 1]);
        if (count($defaults) !== 1) {
            throw new failure();
        }
        $account = reset($defaults);
        if ($account->connectionstatus !== 'verified' || empty($account->googlesub) || empty($account->googleemail)) {
            throw new failure();
        }
        return $account;
    }

    /**
     * Validate and store local inputs; this action makes no external request.
     *
     * @param array $data Submitted course, user ID, schedule and staging acknowledgment
     * @return array New run
     */
    private function initialise(array $data): array {
        if (empty($data['confirmed'])) {
            throw new failure();
        }
        $courseid = (int) ($data['courseid'] ?? 0);
        $userid = (int) ($data['userid'] ?? 0);
        $teacher = cohost_identity::resolve($courseid, $userid);
        $account = $this->account();
        $start = (int) ($data['startdatetime'] ?? 0);
        $end = (int) ($data['enddatetime'] ?? 0);
        if ($start <= time() || $end <= $start || $end - $start > DAYSECS) {
            throw new failure();
        }
        $state = [
            'runid' => bin2hex(random_bytes(16)), 'courseid' => $courseid, 'userid' => $userid,
            'emailhash' => hash('sha256', $teacher->email), 'accountid' => (int) $account->id,
            'start' => $start, 'end' => $end, 'timezone' => schedule::timezone()->getName(),
            'expires' => time() + DAYSECS,
            'eventids' => ['native' => bin2hex(random_bytes(16)), 'fallback' => bin2hex(random_bytes(16))],
            'events' => [],
        ];
        foreach (self::STAGES as $action => $label) {
            $state['stages'][$action] = ['status' => 'NOT_RUN', 'httpstatus' => 0];
        }
        return $state;
    }

    /**
     * Check ordering and prohibit repeated writes even after a timeout, browser reload or double click.
     *
     * @param array $state Current run
     * @param string $action Explicit button
     * @param array $data Submitted acknowledgment for fallback
     */
    private function check_step(array $state, string $action, array $data): void {
        if (!isset(self::STAGES[$action]) || $state['expires'] <= time()) {
            throw new failure();
        }
        if ($state['stages'][$action]['status'] !== 'NOT_RUN' && $action !== 'verify') {
            throw new \moodle_exception('pocnorepeat', 'mod_tupmeet');
        }
        $required = ['member' => 'create', 'verify' => 'create', 'artifacts' => 'verify',
            'native' => 'artifacts', 'fallback' => 'artifacts'];
        if (isset($required[$action]) && $state['stages'][$required[$action]]['status'] !== 'PASS') {
            throw new failure();
        }
        if ($action === 'verify' && $state['stages']['member']['status'] === 'NOT_RUN') {
            throw new failure();
        }
        if ($action === 'fallback' && ($state['stages']['native']['status'] !== 'FAIL' || empty($data['confirmed']))) {
            throw new failure();
        }
    }

    /**
     * Display only steps whose ordering and attempt budget permit execution.
     *
     * @param array $state Current run
     * @param string $action Step
     * @return bool Whether to offer its explicit POST button
     */
    public function available(array $state, string $action): bool {
        try {
            $this->check_step($state, $action, ['confirmed' => 1]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Sole entry for mutations. Every operation requires real administrator, POST and valid sesskey.
     *
     * @param string $action Fixed action name
     * @param array $data Local form values; remote identifiers are never accepted
     * @param string $method Actual HTTP request method from the page
     * @param string $sesskey Submitted CSRF token
     */
    public function dispatch(string $action, array $data, string $method, string $sesskey): void {
        global $USER, $DB;
        self::require_admin();
        if ($method !== 'POST' || $sesskey === '' || !confirm_sesskey($sesskey) || $DB->is_transaction_started()) {
            throw new \moodle_exception('invalidrequest', 'mod_tupmeet');
        }
        $lock = \core\lock\lock_config::get_lock_factory('mod_tupmeet_poc')->get_lock('admin:' . $USER->id, 0);
        if (!$lock) {
            throw new failure();
        }
        try {
            $state = $this->state();
            if ($action === 'start') {
                if ($state !== null) {
                    throw new \moodle_exception('pocnorepeat', 'mod_tupmeet');
                }
                $this->save($this->initialise($data));
                return;
            }
            if (!$state || !is_string($data['runid'] ?? null) || !hash_equals($state['runid'], $data['runid'])) {
                throw new failure();
            }
            if ($action === 'clear') {
                if (empty($data['confirmed'])) {
                    throw new failure();
                }
                $this->cache->delete((string) $USER->id);
                return;
            }
            $this->check_step($state, $action, $data);
            $state['stages'][$action] = ['status' => 'FAIL', 'httpstatus' => 0];
            $this->save($state);
            try {
                $account = $this->account();
                $teacher = cohost_identity::resolve($state['courseid'], $state['userid']);
                if (
                    (int) $account->id !== $state['accountid'] ||
                    !hash_equals($state['emailhash'], hash('sha256', $teacher->email))
                ) {
                    throw new failure();
                }
                switch ($action) {
                    case 'create':
                        $state['space'] = $this->service->create_space($account);
                        break;
                    case 'member':
                        $state['member'] = $this->service->create_member($account, $state['space'], $teacher->email);
                        break;
                    case 'verify':
                        $state['member'] = $this->service->verify_member($account, $state['space'], $teacher->email);
                        break;
                    case 'artifacts':
                        $this->service->configure_artifacts($account, $state['space']);
                        break;
                    case 'native':
                    case 'fallback':
                        $confirmed = $this->service->create_event(
                            $account,
                            $state,
                            $teacher->email,
                            $action === 'native',
                            function (string $eventid) use (&$state, $action): void {
                                $state['events'][$action] = $eventid;
                                $this->save($state);
                            }
                        );
                        if (!$confirmed) {
                            throw new failure();
                        }
                        break;
                }
                $state['stages'][$action]['status'] = 'PASS';
            } catch (\Throwable $e) {
                $state['stages'][$action]['httpstatus'] = $e instanceof failure ? $e->httpstatus : 0;
            }
            $this->save($state);
        } finally {
            $lock->release();
        }
    }
}
