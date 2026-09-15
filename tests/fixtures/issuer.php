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

namespace mod_tupmeet\testing;

/**
 * Offline issuer fixture. No real credentials or network discovery.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class issuer {
    /**
     * Create an eligible issuer using per-run random, nonfunctional credentials.
     *
     * @return \core\oauth2\issuer
     */
    public static function create(): \core\oauth2\issuer {
        $issuer = \core\oauth2\service\google::init();
        $issuer->set('clientid', bin2hex(random_bytes(16)));
        $issuer->set('clientsecret', bin2hex(random_bytes(16)));
        $issuer->set('enabled', 1);
        $issuer->set('showonloginpage', \core\oauth2\issuer::SERVICEONLY);
        $issuer->create();
        $endpoints = [
            'authorization' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token' => 'https://oauth2.googleapis.com/token',
            'userinfo' => 'https://openidconnect.googleapis.com/v1/userinfo',
        ];
        foreach ($endpoints as $name => $url) {
            (new \core\oauth2\endpoint(0, (object) [
                'issuerid' => $issuer->get('id'), 'name' => $name . '_endpoint', 'url' => $url,
            ]))->create();
        }
        return $issuer;
    }
}
