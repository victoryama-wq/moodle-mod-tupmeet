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

use mod_tupmeet\local\account\account_manager;
use mod_tupmeet\local\account\oauth_client_factory;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/fixtures/issuer.php');
require_once(__DIR__ . '/../lib.php');

/**
 * Ownership rules against the real Moodle database and lock/transaction APIs.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(account_manager::class)]
final class account_manager_test extends \advanced_testcase {
    /** @var account_manager Account service. */
    private account_manager $manager;
    /** @var array Fake live identities indexed by issuer. */
    private array $identities;
    /** @var bool Simulate an OAuth failure. */
    private bool $oauthfails;

    /**
     * Set up a mocked network boundary, retaining actual issuer validation.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $this->identities = [];
        $this->oauthfails = false;
        $oauth = $this->getMockBuilder(oauth_client_factory::class)->onlyMethods(['verify'])->getMock();
        $oauth->method('verify')->willReturnCallback(function (int $issuerid) {
            if ($this->oauthfails) {
                throw new \moodle_exception('connectionfailed', 'mod_tupmeet');
            }
            return $this->identities[$issuerid];
        });
        $this->manager = new account_manager($oauth);
    }

    /**
     * Register a fixture account, optionally verifying it.
     *
     * @param bool $verify Verify first
     * @return int Account ID
     */
    private function account(bool $verify = true): int {
        $issuerid = (int) \mod_tupmeet\testing\issuer::create()->get('id');
        $this->identities[$issuerid] = (object) [
            'googlesub' => 'subject-' . $issuerid, 'googleemail' => 'account' . $issuerid . '@example.invalid',
        ];
        $id = $this->manager->register('Test account', $issuerid);
        if ($verify) {
            $this->manager->verify($id);
        }
        return $id;
    }

    /**
     * The first default requires a verified email and explicit selection.
     */
    public function test_first_account_requires_explicit_default(): void {
        $id = $this->account();
        $this->assertEquals(0, $this->manager->get_account($id)->isdefault);
        $this->manager->set_default($id);
        $this->assertEquals(1, $this->manager->get_account($id)->isdefault);
    }

    /**
     * Moodle's module lifecycle uses the configured owner, including inside its transaction.
     */
    public function test_moodle_activity_lifecycle(): void {
        global $DB;
        $id = $this->account();
        $this->manager->set_default($id);
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('tupmeet', ['course' => $course->id]);
        $this->assertEquals($id, $activity->accountid);
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $activity->cmid]));
        tupmeet_delete_instance($activity->id);
        $this->assertTrue($DB->record_exists('tupmeet_accounts', ['id' => $id]));
    }

    /**
     * Established identities are retained even after all their activities were deleted.
     */
    public function test_verified_account_is_retained_without_current_references(): void {
        $id = $this->account();
        $this->manager->set_enabled($id, false);
        $this->expectExceptionMessage(get_string('accountinuse', 'mod_tupmeet'));
        $this->manager->delete_unused($id);
    }

    /**
     * Unverified accounts cannot be selected, even if enabled.
     */
    public function test_unverified_default_is_rejected(): void {
        $id = $this->account(false);
        $this->expectExceptionMessage(get_string('accountnotverified', 'mod_tupmeet'));
        $this->manager->set_default($id);
    }

    /**
     * Switching defaults, edits and disabling never reassign prior activities.
     */
    public function test_switch_preserves_ownership_and_single_default(): void {
        global $DB;
        $first = $this->account();
        $second = $this->account();
        $this->manager->set_default($first);
        $activity = $this->manager->add_activity((object) [
            'name' => 'First activity', 'startdatetime' => time(), 'enddatetime' => time() + HOURSECS,
        ]);
        $this->manager->set_default($second);
        $next = $this->manager->add_activity((object) ['name' => 'Second activity', 'accountid' => $first]);
        $this->assertEquals($first, $DB->get_field('tupmeet', 'accountid', ['id' => $activity]));
        $this->assertEquals($second, $DB->get_field('tupmeet', 'accountid', ['id' => $next]));
        $this->assertEquals(1, $DB->count_records('tupmeet_accounts', ['isdefault' => 1]));
        tupmeet_update_instance((object) ['instance' => $activity, 'accountid' => $second, 'name' => 'Edited']);
        $this->assertEquals($first, $DB->get_field('tupmeet', 'accountid', ['id' => $activity]));
        $this->manager->set_enabled($first, false);
        $this->assertEquals($first, $DB->get_field('tupmeet', 'accountid', ['id' => $activity]));
        $this->assertEquals(1, $this->manager->get_account($second)->isdefault);
        // Repeated selections remain idempotent.
        $this->manager->set_default($second);
        $this->assertEquals(1, $DB->count_records('tupmeet_accounts', ['isdefault' => 1]));
    }

    /**
     * Disabling the default blocks new activities; old activities remain.
     */
    public function test_disabling_default_pauses_creation(): void {
        global $DB;
        $id = $this->account();
        $this->manager->set_default($id);
        $activity = $this->manager->add_activity((object) ['name' => 'Existing']);
        $this->manager->set_enabled($id, false);
        $this->assertEquals(0, $this->manager->get_account($id)->isdefault);
        $this->assertEquals($id, $DB->get_field('tupmeet', 'accountid', ['id' => $activity]));
        $this->expectExceptionMessage(get_string('nodefaultaccount', 'mod_tupmeet'));
        tupmeet_add_instance((object) ['name' => 'Blocked']);
    }

    /**
     * A used account cannot be removed even after it is disabled.
     */
    public function test_used_account_cannot_be_deleted(): void {
        $id = $this->account();
        $this->manager->set_default($id);
        $this->manager->add_activity((object) ['name' => 'Existing']);
        $this->manager->set_enabled($id, false);
        $this->expectExceptionMessage(get_string('accountinuse', 'mod_tupmeet'));
        $this->manager->delete_unused($id);
    }

    /**
     * An unused, unverified disabled registration may be removed, leaving its issuer intact.
     */
    public function test_unused_account_can_be_removed(): void {
        global $DB;
        $id = $this->account(false);
        $issuerid = $this->manager->get_account($id)->issuerid;
        $this->manager->set_enabled($id, false);
        $this->manager->delete_unused($id);
        $this->assertFalse($DB->record_exists('tupmeet_accounts', ['id' => $id]));
        $this->assertTrue($DB->record_exists('oauth2_issuer', ['id' => $issuerid]));
    }

    /**
     * One issuer cannot represent multiple historical institutional accounts.
     */
    public function test_duplicate_issuer_is_rejected(): void {
        $id = $this->account();
        $this->expectExceptionMessage(get_string('issuerinuse', 'mod_tupmeet'));
        $this->manager->register('Duplicate', (int) $this->manager->get_account($id)->issuerid);
    }

    /**
     * An invalid issuer is rejected before registration is persisted.
     */
    public function test_invalid_issuer_is_rejected(): void {
        $this->expectExceptionMessage(get_string('invalidissuer', 'mod_tupmeet'));
        $this->manager->register('Invalid', -1);
    }

    /**
     * A provider failure rolls back any account/default changes.
     */
    public function test_oauth_error_preserves_prior_state(): void {
        $first = $this->account();
        $second = $this->account();
        $this->manager->set_default($first);
        $before = $this->manager->get_accounts();
        $this->oauthfails = true;
        try {
            $this->manager->set_default($second);
            $this->fail('Expected OAuth failure');
        } catch (\moodle_exception $e) {
            $this->assertSame('connectionfailed', $e->errorcode);
        }
        $this->assertEquals($before, $this->manager->get_accounts());
    }

    /**
     * Reusing an email with a different Google subject cannot take over history.
     */
    public function test_reconnection_to_other_identity_is_rejected(): void {
        $id = $this->account();
        $this->manager->set_default($id);
        $before = $this->manager->get_account($id);
        $this->identities[$before->issuerid]->googlesub = 'different-subject';
        try {
            $this->manager->verify($id);
            $this->fail('Expected identity mismatch');
        } catch (\moodle_exception $e) {
            $this->assertSame('identitychanged', $e->errorcode);
        }
        $this->assertEquals($before, $this->manager->get_account($id));
    }

    /**
     * Only site configuration administrators can mutate accounts.
     */
    public function test_non_admin_cannot_register(): void {
        $issuerid = (int) \mod_tupmeet\testing\issuer::create()->get('id');
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        $this->manager->register('Not allowed', $issuerid);
    }
}
