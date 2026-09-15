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

namespace mod_tupmeet\local\account;

/**
 * Account metadata and ownership rules. No OAuth credentials are stored here.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class account_manager {
    /** @var oauth_client_factory Native OAuth boundary. */
    private oauth_client_factory $oauth;

    /**
     * Constructor.
     *
     * @param oauth_client_factory|null $oauth Injectable OAuth boundary
     */
    public function __construct(?oauth_client_factory $oauth = null) {
        $this->oauth = $oauth ?? new oauth_client_factory();
    }

    /**
     * Serialize account mutations and activity creation across requests/nodes.
     *
     * @param callable $operation Operation inside a database transaction
     * @return mixed Operation result
     */
    private function mutate(callable $operation) {
        global $DB;
        $factory = \core\lock\lock_config::get_lock_factory('mod_tupmeet');
        $lock = $factory->get_lock('accounts', 10);
        if (!$lock) {
            throw new \moodle_exception('accountbusy', 'mod_tupmeet');
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $result = $operation();
                $transaction->allow_commit();
                return $result;
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Get account history, including disabled accounts and unavailable issuers.
     *
     * @return array Account records
     */
    public function get_accounts(): array {
        global $DB;
        return $DB->get_records('tupmeet_accounts', null, 'id ASC');
    }

    /**
     * Get a historical account.
     *
     * @param int $id Account ID
     * @return \stdClass
     */
    public function get_account(int $id): \stdClass {
        global $DB;
        return $DB->get_record('tupmeet_accounts', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Register metadata, pending verification and explicit default selection.
     *
     * @param string $displayname Administrator's descriptive label
     * @param int $issuerid Moodle issuer ID
     * @return int Account ID
     */
    public function register(string $displayname, int $issuerid): int {
        global $DB;
        require_capability('moodle/site:config', \context_system::instance());
        $this->oauth->get_issuer($issuerid);
        $displayname = trim($displayname);
        if ($displayname === '' || \core_text::strlen($displayname) > 255) {
            throw new \moodle_exception('invalidaccountname', 'mod_tupmeet');
        }
        return $this->mutate(function () use ($DB, $displayname, $issuerid) {
            if ($DB->record_exists('tupmeet_accounts', ['issuerid' => $issuerid])) {
                throw new \moodle_exception('issuerinuse', 'mod_tupmeet');
            }
            $now = time();
            return (int) $DB->insert_record('tupmeet_accounts', (object) [
                'displayname' => $displayname,
                'googleemail' => '',
                'issuerid' => $issuerid,
                'enabled' => 1,
                'isdefault' => 0,
                'connectionstatus' => 'pending',
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        });
    }

    /**
     * Verify without changing the default/enabled state or an established identity.
     *
     * @param int $id Account ID
     * @return \stdClass Verified account
     */
    public function verify(int $id): \stdClass {
        global $DB;
        require_capability('moodle/site:config', \context_system::instance());
        $original = $this->get_account($id);
        // Let Moodle persist its token refresh before starting a plugin metadata transaction.
        $identity = $this->oauth->verify((int) $original->issuerid);
        return $this->mutate(function () use ($DB, $id, $identity) {
            $account = $this->get_account($id);
            $this->oauth->assert_identity($account, $identity);
            $account->googlesub = $identity->googlesub;
            // Preserve the original email spelling once verified.
            $account->googleemail = $account->googleemail ?: $identity->googleemail;
            $account->connectionstatus = 'verified';
            $account->timeverified = time();
            $account->timemodified = $account->timeverified;
            $DB->update_record('tupmeet_accounts', $account);
            return $account;
        });
    }

    /**
     * Require a successful verification already displayed to the administrator.
     *
     * @param \stdClass $account Account metadata
     */
    private function require_verified(\stdClass $account): void {
        if ($account->connectionstatus !== 'verified' || empty($account->googlesub) || empty($account->googleemail)) {
            throw new \moodle_exception('accountnotverified', 'mod_tupmeet');
        }
    }

    /**
     * Atomically select one enabled default. Historical activities are untouched.
     *
     * @param int $id Account ID
     */
    public function set_default(int $id): void {
        global $DB;
        require_capability('moodle/site:config', \context_system::instance());
        $original = $this->get_account($id);
        $this->require_verified($original);
        $identity = $this->oauth->verify((int) $original->issuerid);
        $this->mutate(function () use ($DB, $id, $identity) {
            $account = $this->get_account($id);
            $this->require_verified($account);
            if (!$account->enabled) {
                throw new \moodle_exception('accountdisabled', 'mod_tupmeet');
            }
            $this->oauth->assert_identity($account, $identity);
            $now = time();
            $DB->set_field('tupmeet_accounts', 'timemodified', $now, ['isdefault' => 1]);
            $DB->set_field('tupmeet_accounts', 'isdefault', 0, ['isdefault' => 1]);
            $DB->update_record('tupmeet_accounts', (object) [
                'id' => $id, 'isdefault' => 1, 'timeverified' => $now, 'timemodified' => $now,
            ]);
        });
    }

    /**
     * Enable/disable use for new activities; disabling the default leaves none.
     *
     * @param int $id Account ID
     * @param bool $enabled Desired state
     */
    public function set_enabled(int $id, bool $enabled): void {
        global $DB;
        require_capability('moodle/site:config', \context_system::instance());
        $identity = null;
        if ($enabled) {
            $original = $this->get_account($id);
            $this->require_verified($original);
            $identity = $this->oauth->verify((int) $original->issuerid);
        }
        $this->mutate(function () use ($DB, $id, $enabled, $identity) {
            $account = $this->get_account($id);
            if ($enabled) {
                $this->require_verified($account);
                $this->oauth->assert_identity($account, $identity);
            }
            $DB->update_record('tupmeet_accounts', (object) [
                'id' => $id, 'enabled' => (int) $enabled,
                'isdefault' => $enabled ? $account->isdefault : 0, 'timemodified' => time(),
            ]);
        });
    }

    /**
     * Remove an unused, disabled, never-verified registration only.
     *
     * @param int $id Account ID
     */
    public function delete_unused(int $id): void {
        global $DB;
        require_capability('moodle/site:config', \context_system::instance());
        $this->mutate(function () use ($DB, $id) {
            $account = $this->get_account($id);
            // A verified account may be referenced by an activity in an outer Moodle transaction.
            // Keep every established identity, even before that activity becomes visible here.
            if (
                $account->enabled || $account->isdefault || !empty($account->googlesub) ||
                    $DB->record_exists('tupmeet', ['accountid' => $id])
            ) {
                throw new \moodle_exception('accountinuse', 'mod_tupmeet');
            }
            $DB->delete_records('tupmeet_accounts', ['id' => $id]);
        });
    }

    /**
     * Create a local activity with its permanent owner; never creates Google data.
     *
     * @param \stdClass $data Activity form data
     * @return int Activity ID
     */
    public function add_activity(\stdClass $data): int {
        global $DB;
        return $this->mutate(function () use ($DB, $data) {
            $defaults = $DB->get_records('tupmeet_accounts', ['enabled' => 1, 'isdefault' => 1]);
            if (count($defaults) !== 1) {
                throw new \moodle_exception('nodefaultaccount', 'mod_tupmeet');
            }
            $account = reset($defaults);
            $this->require_verified($account);
            $this->oauth->get_issuer((int) $account->issuerid);
            $data->accountid = $account->id;
            return (int) $DB->insert_record('tupmeet', $data);
        });
    }
}
