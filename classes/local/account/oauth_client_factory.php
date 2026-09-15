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

use core\oauth2\api;
use core\oauth2\client;
use core\oauth2\issuer;

/**
 * Native Moodle system clients, verified against an immutable Google identity.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class oauth_client_factory {
    /**
     * Resolve a configured Google issuer available to internal services.
     *
     * @param int $issuerid Moodle issuer ID
     * @return issuer
     */
    public function get_issuer(int $issuerid): issuer {
        try {
            if ($issuerid <= 0) {
                throw new \moodle_exception('invalidissuer', 'mod_tupmeet');
            }
            $issuer = api::get_issuer($issuerid);
            if (
                $issuer->get('servicetype') !== 'google' || !$issuer->get('enabled') ||
                    !$issuer->is_configured() ||
                    !in_array((int) $issuer->get('showonloginpage'), [issuer::EVERYWHERE, issuer::SERVICEONLY], true)
            ) {
                throw new \moodle_exception('invalidissuer', 'mod_tupmeet');
            }
            foreach (['authorization', 'token', 'userinfo'] as $endpoint) {
                if (!$issuer->get_endpoint_url($endpoint)) {
                    throw new \moodle_exception('invalidissuer', 'mod_tupmeet');
                }
            }
            return $issuer;
        } catch (\moodle_exception $e) {
            // Do not expose core exception details, which may contain configuration data.
            throw new \moodle_exception('invalidissuer', 'mod_tupmeet');
        }
    }

    /**
     * List eligible issuers without exposing their credentials.
     *
     * @return array ID => name
     */
    public function get_issuer_options(): array {
        $options = [];
        foreach (api::get_all_issuers() as $issuer) {
            try {
                $this->get_issuer((int) $issuer->get('id'));
                $options[$issuer->get('id')] = $issuer->get('name');
            } catch (\moodle_exception $e) {
                continue;
            }
        }
        return $options;
    }

    /**
     * Link to Moodle's own connect/reconnect confirmation and OAuth callback.
     *
     * @param int $issuerid Issuer ID
     * @return \moodle_url
     */
    public function get_connection_url(int $issuerid): \moodle_url {
        $this->get_issuer($issuerid);
        return new \moodle_url('/admin/tool/oauth2/issuers.php', ['id' => $issuerid, 'action' => 'auth']);
    }

    /**
     * Obtain an authenticated native system client; Moodle owns refresh and caching.
     *
     * @param issuer $issuer Issuer
     * @return client
     */
    protected function get_system_client(issuer $issuer): client {
        try {
            if (!$issuer->is_system_account_connected()) {
                throw new \moodle_exception('connectionfailed', 'mod_tupmeet');
            }
            $client = api::get_system_oauth_client($issuer);
            if (!$client) {
                throw new \moodle_exception('connectionfailed', 'mod_tupmeet');
            }
            return $client;
        } catch (\moodle_exception $e) {
            throw new \moodle_exception('connectionfailed', 'mod_tupmeet');
        }
    }

    /**
     * Read Google OpenID userinfo, independent of editable Moodle field mappings.
     *
     * @param client $client Authenticated client
     * @param issuer $issuer Issuer and optional domain restrictions
     * @return \stdClass Verified subject and email only
     */
    protected function read_identity(client $client, issuer $issuer): \stdClass {
        try {
            $info = $client->get_raw_userinfo();
            if (
                !is_object($info) || empty($info->sub) || !is_string($info->sub) || strlen($info->sub) > 255 ||
                    empty($info->email) || !is_string($info->email) || strlen($info->email) > 255 ||
                    !validate_email($info->email) || ($info->email_verified ?? false) !== true ||
                    empty($info->hd) || !$issuer->is_valid_login_domain($info->email)
            ) {
                throw new \moodle_exception('invalididentity', 'mod_tupmeet');
            }
            return (object) ['googlesub' => $info->sub, 'googleemail' => $info->email];
        } catch (\moodle_exception $e) {
            throw new \moodle_exception('invalididentity', 'mod_tupmeet');
        }
    }

    /**
     * Verify the presently connected Google Workspace identity.
     *
     * @param int $issuerid Issuer ID
     * @return \stdClass Verified metadata, never tokens
     */
    public function verify(int $issuerid): \stdClass {
        $issuer = $this->get_issuer($issuerid);
        return $this->read_identity($this->get_system_client($issuer), $issuer);
    }

    /**
     * Refuse a substituted system identity, including reassigned email addresses.
     *
     * @param \stdClass $account Historical plugin record
     * @param \stdClass $identity Live identity
     */
    public function assert_identity(\stdClass $account, \stdClass $identity): void {
        if (
            (!empty($account->googlesub) && $account->googlesub !== $identity->googlesub) ||
                (!empty($account->googleemail) && strcasecmp($account->googleemail, $identity->googleemail) !== 0)
        ) {
            throw new \moodle_exception('identitychanged', 'mod_tupmeet');
        }
    }

    /**
     * Client for a historical owner. Disabled accounts remain usable for history.
     *
     * @param \stdClass $account Historical account
     * @return client Authenticated, identity-checked Moodle client
     */
    public function for_account(\stdClass $account): client {
        if (empty($account->googlesub) || empty($account->googleemail)) {
            throw new \moodle_exception('accountnotverified', 'mod_tupmeet');
        }
        $issuer = $this->get_issuer((int) $account->issuerid);
        $client = $this->get_system_client($issuer);
        $this->assert_identity($account, $this->read_identity($client, $issuer));
        return $client;
    }
}
