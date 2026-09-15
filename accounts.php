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

/**
 * Master-account administration. OAuth and persistence belong to service classes.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use mod_tupmeet\local\account\account_manager;
use mod_tupmeet\local\account\oauth_client_factory;

require_login();
require_capability('moodle/site:config', context_system::instance());
admin_externalpage_setup('modsettingtupmeet');

$url = new moodle_url('/mod/tupmeet/accounts.php');
$oauth = new oauth_client_factory();
$manager = new account_manager($oauth);
$accounts = $manager->get_accounts();
$issuers = $oauth->get_issuer_options();
foreach ($accounts as $account) {
    unset($issuers[$account->issuerid]);
}
$form = $issuers ? new \mod_tupmeet\form\account_form($url, ['issuers' => $issuers]) : null;
$error = '';

try {
    if ($form && ($data = $form->get_data())) {
        $manager->register($data->displayname, (int) $data->issuerid);
        redirect($url, get_string('accountregistered', 'mod_tupmeet'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    $action = optional_param('action', '', PARAM_ALPHA);
    if ($action !== '') {
        // Links and GET requests cannot mutate plugin account metadata.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new moodle_exception('invalidrequest', 'mod_tupmeet');
        }
        require_sesskey();
        $id = required_param('accountid', PARAM_INT);
        switch ($action) {
            case 'verify':
                $manager->verify($id);
                break;
            case 'default':
                $manager->set_default($id);
                break;
            case 'disable':
                $manager->set_enabled($id, false);
                break;
            case 'enable':
                $manager->set_enabled($id, true);
                break;
            default:
                throw new moodle_exception('invalidrequest', 'mod_tupmeet');
        }
        redirect($url, get_string('accountupdated', 'mod_tupmeet'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
} catch (moodle_exception $e) {
    // Never display/log provider response bodies, URLs, or credential-bearing exceptions.
    $error = $e->module === 'mod_tupmeet' ? get_string($e->errorcode, 'mod_tupmeet') :
        get_string('accountoperationfailed', 'mod_tupmeet');
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('masteraccounts', 'mod_tupmeet'));
echo $OUTPUT->notification(get_string('accountinstructions', 'mod_tupmeet'), 'info');
if ($error !== '') {
    echo $OUTPUT->notification($error, 'error');
}

$accounts = $manager->get_accounts();
$hasdefault = false;
$table = new html_table();
$table->head = [
    get_string('accountdisplayname', 'mod_tupmeet'), get_string('googleemail', 'mod_tupmeet'),
    get_string('oauthissuer', 'mod_tupmeet'), get_string('accountstate', 'mod_tupmeet'),
    get_string('lastverification', 'mod_tupmeet'), get_string('actions'),
];
foreach ($accounts as $account) {
    $hasdefault = $hasdefault || ($account->enabled && $account->isdefault);
    $buttons = '';
    $issuerlabel = (string) $account->issuerid;
    try {
        $issuer = $oauth->get_issuer((int) $account->issuerid);
        $issuerlabel = $issuer->get('name') . ' (#' . $account->issuerid . ')';
        $buttons .= html_writer::link(
            $oauth->get_connection_url((int) $account->issuerid),
            get_string('connectaccount', 'mod_tupmeet'),
            ['class' => 'btn btn-secondary']
        );
    } catch (moodle_exception $e) {
        $issuerlabel .= ' — ' . get_string('issuerunavailable', 'mod_tupmeet');
    }
    $actions = ['verify' => 'verifyaccount'];
    if ($account->enabled) {
        if ($account->connectionstatus === 'verified' && !$account->isdefault) {
            $actions['default'] = 'setdefaultaccount';
        }
        $actions['disable'] = 'disableaccount';
    } else if ($account->connectionstatus === 'verified') {
        $actions['enable'] = 'enableaccount';
    }
    foreach ($actions as $action => $label) {
        $actionurl = new moodle_url($url, ['action' => $action, 'accountid' => $account->id, 'sesskey' => sesskey()]);
        $buttons .= $OUTPUT->single_button($actionurl, get_string($label, 'mod_tupmeet'), 'post');
    }
    $state = get_string($account->enabled ? 'accountenabled' : 'accountdisabledlabel', 'mod_tupmeet');
    if ($account->isdefault) {
        $state .= ' / ' . get_string('defaultaccount', 'mod_tupmeet');
    }
    $verified = $account->timeverified ? userdate($account->timeverified) : get_string('notverified', 'mod_tupmeet');
    $table->data[] = [s($account->displayname), s($account->googleemail), s($issuerlabel), s($state), s($verified), $buttons];
}
if (!$hasdefault) {
    echo $OUTPUT->notification(get_string('nodefaultaccount', 'mod_tupmeet'), 'warning');
}
echo html_writer::table($table);
echo html_writer::link(new moodle_url('/admin/tool/oauth2/issuers.php'), get_string('manageissuers', 'mod_tupmeet'));
if ($form) {
    $form->display();
} else {
    echo $OUTPUT->notification(get_string('noavailableissuers', 'mod_tupmeet'), 'info');
}
echo $OUTPUT->footer();
