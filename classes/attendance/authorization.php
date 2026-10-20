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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Google Meet attendance interface and authorization.
 *
 * @package mod_googlemeet
 * @copyright 2026 MindFree
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_googlemeet\attendance;

defined('MOODLE_INTERNAL') || die();

/** Per-user Google authorization for attendance. */
class authorization {
    /** Identity-only scopes; never inherit Calendar/Drive issuer scopes. */
    const IDENTITY_SCOPES = 'openid email profile';
    /** Organizer read-only Meet scopes. */
    const ORGANIZER_SCOPES = 'openid email profile https://www.googleapis.com/auth/meetings.space.readonly';

    /** Check the initiating user, module, purpose, lifetime and unguessable state. */
    public static function validate_callback(?\stdClass $pending, string $nonce, int $cmid, bool $organizer): void {
        global $USER;
        if (!$pending || !hash_equals($pending->nonce, $nonce) || $pending->userid != $USER->id ||
                $pending->cmid != $cmid || $pending->organizer != $organizer || $pending->expires < time()) {
            throw new \moodle_exception('invalidsesskey');
        }
    }

    /** Enforce the connection purpose independently of Moodle's login provider. */
    public static function require_access(\context_module $context, bool $organizer): void {
        require_capability($organizer ? 'mod/googlemeet:manageattendance' : 'mod/googlemeet:viewownattendance', $context);
        if (isguestuser()) {
            throw new \required_capability_exception($context, 'mod/googlemeet:viewownattendance', 'nopermissions', '');
        }
    }

    /** A started attendance series keeps its original Google source. */
    public static function validate_organizer(\stdClass $meeting, int $issuerid, string $subject): void {
        global $DB;
        if (!empty($meeting->organizersub) &&
                ((int)$meeting->attendanceissuerid !== $issuerid || !hash_equals($meeting->organizersub, $subject)) &&
                $DB->record_exists_select('googlemeet_events',
                    'googlemeetid = :meeting AND eventdate >= :since AND eventdate <= :now',
                    ['meeting' => $meeting->id, 'since' => $meeting->attendancefrom, 'now' => time()])) {
            throw new \moodle_exception('attendancehistorylocked', 'googlemeet');
        }
    }

    /** Save one verified Google subject per Moodle user, globally unique across issuers. */
    public static function save_identity(\stdClass $info, int $issuerid): void {
        global $DB, $USER;
        if (empty($info->sub)) {
            throw new \moodle_exception('attendanceauthrequired', 'googlemeet');
        }
        $existing = $DB->get_record('googlemeet_identity', ['googlesub' => (string)$info->sub]);
        if ($existing && (int)$existing->userid !== (int)$USER->id) {
            throw new \moodle_exception('attendanceidentityused', 'googlemeet');
        }
        $identity = $DB->get_record('googlemeet_identity', ['userid' => $USER->id]);
        $record = (object)['userid' => $USER->id, 'issuerid' => $issuerid, 'googlesub' => (string)$info->sub,
            'email' => (string)($info->email ?? ''), 'timecreated' => time()];
        if ($identity) {
            $record->id = $identity->id;
            $record->timecreated = $identity->timecreated;
            $DB->update_record('googlemeet_identity', $record);
        } else {
            $DB->insert_record('googlemeet_identity', $record);
        }
    }

    /** Build a core user client with explicitly bounded scopes. */
    public static function client(int $issuerid, \moodle_url $returnurl, bool $organizer): \core\oauth2\client {
        try {
            $issuer = clone \core\oauth2\api::get_issuer($issuerid);
        } catch (\dml_missing_record_exception $e) {
            throw new \moodle_exception('invalidissuerid', 'googlemeet');
        }
        if (!$issuer->get('enabled')) {
            throw new \moodle_exception('attendanceauthrequired', 'googlemeet');
        }
        $scopes = $organizer ? self::ORGANIZER_SCOPES : self::IDENTITY_SCOPES;
        $issuer->set('loginscopes', $scopes);
        $issuer->set('loginscopesoffline', $scopes);
        $client = \core\oauth2\api::get_user_oauth_client($issuer, $returnurl, '', $organizer);
        if ($client->get_login_url()->get_host() !== 'accounts.google.com') {
            throw new \moodle_exception('invalidissuerid', 'googlemeet');
        }
        return $client;
    }

    /** Verify the provider's actual grants instead of trusting core's requested-scope cache. */
    public static function verify_scopes(\core\oauth2\client $client, bool $organizer): void {
        // Google Auth Library uses this POST with the token in the Authorization header.
        // The core client adds that header; never put credentials in URLs or error output.
        $body = $client->post('https://oauth2.googleapis.com/tokeninfo', '');
        self::check_response($client);
        $info = json_decode($body);
        if (!$info || empty($info->scope)) {
            throw new \RuntimeException('Google token information is unavailable');
        }
        $scopes = preg_split('/\s+/', trim($info->scope));
        $normalized = array_map(static function($scope) {
            return str_replace(['https://www.googleapis.com/auth/userinfo.email',
                'https://www.googleapis.com/auth/userinfo.profile'], ['email', 'profile'], $scope);
        }, $scopes);
        $required = explode(' ', $organizer ? self::ORGANIZER_SCOPES : self::IDENTITY_SCOPES);
        if (array_diff($required, $normalized) || (!$organizer && array_diff($normalized, $required))) {
            throw new \moodle_exception('attendanceauthrequired', 'googlemeet');
        }
    }

    /** Retrieve the stable OpenID subject; transport outages must remain retryable. */
    public static function userinfo(\core\oauth2\client $client): \stdClass {
        $info = $client->get_raw_userinfo();
        self::check_response($client);
        if (!$info) {
            throw new \RuntimeException('Google user information is unavailable');
        }
        if (empty($info->sub)) {
            throw new \moodle_exception('attendanceauthrequired', 'googlemeet');
        }
        return $info;
    }

    /** Keep authorization failures distinct from rate limits and transport failures. */
    private static function check_response(\core\oauth2\client $client): void {
        $info = $client->get_info();
        $status = (int)($info['http_code'] ?? 0);
        if (in_array($status, [401, 403], true)) {
            throw new \moodle_exception('attendanceauthrequired', 'googlemeet');
        }
        if ($client->get_errno() || !$status || $status === 429 || $status >= 500) {
            throw new \RuntimeException('Google authorization service is temporarily unavailable');
        }
        if ($status >= 400) {
            throw new \moodle_exception('attendanceauthrequired', 'googlemeet');
        }
    }

    /** Obtain the connected organizer client, including during an organizer cron session. */
    public static function organizer_client(\stdClass $googlemeet): \core\oauth2\client {
        global $USER;
        if (!empty($googlemeet->authrequired) || empty($googlemeet->organizerid) || (int)$USER->id !== (int)$googlemeet->organizerid ||
                empty($googlemeet->attendanceissuerid) || empty($googlemeet->organizersub)) {
            throw new \moodle_exception('attendanceauthrequired', 'googlemeet');
        }
        $client = self::client((int)$googlemeet->attendanceissuerid, new \moodle_url('/'), true);
        if (!$client->is_logged_in()) {
            $info = $client->get_info();
            if ($client->get_errno() || !empty($info['http_code'])) {
                self::check_response($client);
            }
            throw new \moodle_exception('attendanceauthrequired', 'googlemeet');
        }
        self::verify_scopes($client, true);
        $info = self::userinfo($client);
        if (!$info || empty($info->sub) || !hash_equals($googlemeet->organizersub, (string)$info->sub)) {
            throw new \moodle_exception('attendanceauthrequired', 'googlemeet');
        }
        return $client;
    }
}
