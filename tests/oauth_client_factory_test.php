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

namespace mod_tupmeet;

use core\oauth2\client;
use core\oauth2\issuer;
use mod_tupmeet\local\account\oauth_client_factory;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/fixtures/issuer.php');

/**
 * Native issuer validation and Google identity verification without network calls.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(oauth_client_factory::class)]
final class oauth_client_factory_test extends \advanced_testcase {
    /**
     * Prepare a resettable Moodle database.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * A configured Google internal-service issuer is eligible.
     */
    public function test_eligible_issuer_and_native_connection_url(): void {
        $issuer = \mod_tupmeet\testing\issuer::create();
        $id = (int) $issuer->get('id');
        $factory = new oauth_client_factory();
        $this->assertEquals($id, $factory->get_issuer($id)->get('id'));
        $this->assertArrayHasKey($id, $factory->get_issuer_options());
        $expected = new \moodle_url('/admin/tool/oauth2/issuers.php', ['id' => $id, 'action' => 'auth']);
        $this->assertTrue($expected->compare($factory->get_connection_url($id)));
    }

    /**
     * Nonexistent issuers fail safely.
     */
    public function test_nonexistent_issuer(): void {
        $this->expectExceptionMessage(get_string('invalidissuer', 'mod_tupmeet'));
        (new oauth_client_factory())->get_issuer(999999);
    }

    /**
     * All disabled, non-Google, incomplete and login-only configurations fail.
     */
    public function test_ineligible_issuers(): void {
        foreach (
            [
            ['enabled', 0], ['servicetype', 'microsoft'],
            ['showonloginpage', issuer::LOGINONLY], ['clientsecret', ''],
            ] as [$field, $value]
        ) {
            $issuer = \mod_tupmeet\testing\issuer::create();
            $issuer->set($field, $value);
            $issuer->update();
            try {
                (new oauth_client_factory())->get_issuer((int) $issuer->get('id'));
                $this->fail('Expected invalid issuer: ' . $field);
            } catch (\moodle_exception $e) {
                $this->assertSame('invalidissuer', $e->errorcode);
            }
        }
    }

    /**
     * Missing userinfo cannot confirm the account that was authorized.
     */
    public function test_missing_userinfo_is_rejected(): void {
        $issuer = \mod_tupmeet\testing\issuer::create();
        $endpoint = \core\oauth2\endpoint::get_record([
            'issuerid' => $issuer->get('id'), 'name' => 'userinfo_endpoint',
        ]);
        $endpoint->delete();
        $this->expectExceptionMessage(get_string('invalidissuer', 'mod_tupmeet'));
        (new oauth_client_factory())->get_issuer((int) $issuer->get('id'));
    }

    /**
     * An issuer without a connected native system account fails safely.
     */
    public function test_missing_system_account(): void {
        $issuer = \mod_tupmeet\testing\issuer::create();
        $this->expectExceptionMessage(get_string('connectionfailed', 'mod_tupmeet'));
        (new oauth_client_factory())->verify((int) $issuer->get('id'));
    }

    /**
     * Inject a native client mock at the network boundary.
     *
     * @param mixed $info Userinfo response
     * @return oauth_client_factory
     */
    private function factory($info): oauth_client_factory {
        $client = $this->getMockBuilder(client::class)->disableOriginalConstructor()
            ->onlyMethods(['get_raw_userinfo'])->getMock();
        $client->method('get_raw_userinfo')->willReturn($info);
        $factory = $this->getMockBuilder(oauth_client_factory::class)->onlyMethods(['get_system_client'])->getMock();
        $factory->method('get_system_client')->willReturn($client);
        return $factory;
    }

    /**
     * Verified OpenID metadata is returned without tokens or unrelated profile data.
     */
    public function test_verified_workspace_identity(): void {
        $issuer = \mod_tupmeet\testing\issuer::create();
        $factory = $this->factory((object) [
            'sub' => 'test-subject', 'email' => 'institution@example.invalid',
            'email_verified' => true, 'hd' => 'example.invalid', 'name' => 'Not stored',
        ]);
        $identity = $factory->verify((int) $issuer->get('id'));
        $this->assertEquals((object) [
            'googlesub' => 'test-subject', 'googleemail' => 'institution@example.invalid',
        ], $identity);
        $account = (object) [
            'issuerid' => $issuer->get('id'), 'googlesub' => $identity->googlesub,
            'googleemail' => $identity->googleemail, 'enabled' => 0,
        ];
        $this->assertInstanceOf(client::class, $factory->for_account($account));
    }

    /**
     * Missing, malformed, unverified or consumer identities cannot be accepted.
     */
    public function test_invalid_userinfo(): void {
        $issuer = \mod_tupmeet\testing\issuer::create();
        $valid = ['sub' => 'test-subject', 'email' => 'institution@example.invalid',
            'email_verified' => true, 'hd' => 'example.invalid'];
        $responses = [false, (object) [], (object) array_merge($valid, ['email_verified' => false]),
            (object) array_merge($valid, ['email' => 'invalid']), (object) array_merge($valid, ['hd' => '']),
            (object) array_merge($valid, ['sub' => ''])];
        foreach ($responses as $response) {
            try {
                $this->factory($response)->verify((int) $issuer->get('id'));
                $this->fail('Expected invalid identity');
            } catch (\moodle_exception $e) {
                $this->assertSame('invalididentity', $e->errorcode);
            }
        }
    }

    /**
     * Moodle issuer domain restrictions also apply to institutional accounts.
     */
    public function test_domain_restriction(): void {
        $issuer = \mod_tupmeet\testing\issuer::create();
        $issuer->set('alloweddomains', 'allowed.invalid');
        $issuer->update();
        $factory = $this->factory((object) [
            'sub' => 'test-subject', 'email' => 'institution@example.invalid',
            'email_verified' => true, 'hd' => 'example.invalid',
        ]);
        $this->expectExceptionMessage(get_string('invalididentity', 'mod_tupmeet'));
        $factory->verify((int) $issuer->get('id'));
    }

    /**
     * A substituted native system account is never returned to future consumers.
     */
    public function test_historical_client_rejects_identity_drift(): void {
        $issuer = \mod_tupmeet\testing\issuer::create();
        $factory = $this->factory((object) [
            'sub' => 'other-subject', 'email' => 'institution@example.invalid',
            'email_verified' => true, 'hd' => 'example.invalid',
        ]);
        $this->expectExceptionMessage(get_string('identitychanged', 'mod_tupmeet'));
        $factory->for_account((object) [
            'issuerid' => $issuer->get('id'), 'googlesub' => 'original-subject',
            'googleemail' => 'institution@example.invalid',
        ]);
    }
}
