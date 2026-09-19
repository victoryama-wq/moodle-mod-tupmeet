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

/**
 * Rename one validated MP4 using metadata only. No search, media, sharing or parent writes.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class drive_metadata_service extends recording_transport {
    /** @var string Restricted scope, requested only for registered TUP issuers. */
    public const SCOPE = 'https://www.googleapis.com/auth/drive.metadata';
    /** @var string Minimal metadata projection; parents are checked in memory only. */
    private const FIELDS = 'id,name,mimeType,parents,trashed,capabilities(canRename)';

    /**
     * Validate metadata before and after rename.
     * @param array $file Remote metadata
     * @param string $id Expected recording destination
     */
    private function check(array $file, string $id): void {
        if (
            ($file['id'] ?? null) !== $id || ($file['mimeType'] ?? null) !== 'video/mp4' ||
            ($file['trashed'] ?? null) !== false || !is_string($file['name'] ?? null) ||
            !is_array($file['parents'] ?? []) ||
            (array_key_exists('capabilities', $file) && !is_array($file['capabilities']))
        ) {
            throw new recording_exception();
        }
    }

    /**
     * Converge the exact desired filename after a previous lost response if necessary.
     * @param \stdClass $account Historical owner
     * @param \stdClass $recording Persisted FILE_GENERATED recording
     * @param callable $checkpoint Persist original metadata and confirm current activity before PATCH
     * @return array Safe local result
     */
    public function rename(\stdClass $account, \stdClass $recording, callable $checkpoint): array {
        if (
            $recording->state !== 'FILE_GENERATED' || !recording_service::valid_file($recording->drivefileid ?? '') ||
            !recording_service::valid_export($recording->exporturi ?? '', $recording->drivefileid ?? '') ||
            empty($recording->desiredfilename) || strlen($recording->desiredfilename) > 240
        ) {
            throw new recording_exception();
        }
        $client = $this->client($account);
        $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($recording->drivefileid) .
            '?fields=' . rawurlencode(self::FIELDS);
        $file = $this->request($client, $url);
        $this->check($file, $recording->drivefileid);
        if (!$checkpoint($file['name'])) {
            throw new recording_exception();
        }
        if ($file['name'] === $recording->desiredfilename) {
            return ['renamestatus' => 'ready', 'drivefilename' => $file['name']];
        }
        if (array_key_exists('canRename', $file['capabilities'] ?? []) && $file['capabilities']['canRename'] !== true) {
            return ['renamestatus' => 'skipped', 'drivefilename' => \core_text::substr($file['name'], 0, 255)];
        }
        $result = $this->request($client, $url, ['name' => $recording->desiredfilename]);
        $this->check($result, $recording->drivefileid);
        $parents = $file['parents'] ?? [];
        $afterparents = $result['parents'] ?? [];
        sort($parents);
        sort($afterparents);
        if ($result['name'] !== $recording->desiredfilename || $parents !== $afterparents) {
            throw new recording_exception();
        }
        return ['renamestatus' => 'ready', 'drivefilename' => $result['name']];
    }
}
