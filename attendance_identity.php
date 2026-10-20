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
/** Explicit, session-bound account connection, separate from Moodle login. */
require(__DIR__ . '/../../config.php');

use mod_googlemeet\attendance\authorization;
use mod_googlemeet\attendance\service;

$id = required_param('id', PARAM_INT);
$organizer = optional_param('organizer', false, PARAM_BOOL);
$cm = get_coursemodule_from_id('googlemeet', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$meet = $DB->get_record('googlemeet', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($id);
authorization::require_access($context, $organizer);
$url = new moodle_url('/mod/googlemeet/attendance_identity.php', ['id' => $id, 'organizer' => (int)$organizer]);
$return = new moodle_url('/mod/googlemeet/attendance.php', ['id' => $id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title(get_string('attendanceidentity', 'googlemeet'));
$PAGE->set_heading(format_string($course->fullname));
$action = optional_param('action', '', PARAM_ALPHA);
$callback = optional_param('callback', false, PARAM_BOOL);
if ($action === 'disconnect' && data_submitted()) {
    require_sesskey();
    if ($organizer) {
        if ((int)$meet->organizerid !== (int)$USER->id) {
            throw new moodle_exception('attendanceauthrequired', 'googlemeet');
        }
        $DB->set_field('googlemeet', 'authrequired', 1, ['id' => $meet->id]);

    } else {
        $DB->delete_records('googlemeet_identity', ['userid' => $USER->id]);
    }
    $DB->insert_record('googlemeet_audit', (object)['googlemeetid' => $meet->id, 'eventid' => 0,
        'participantid' => 0, 'userid' => $USER->id, 'action' => 'identitydisconnected', 'timecreated' => time()]);
    redirect($return);
}
if (($action === 'connect' && data_submitted()) || $callback) {
    require_sesskey();
    if ($callback) {
        $nonce = required_param('nonce', PARAM_ALPHANUM);
        $pending = $SESSION->googlemeetattendanceoauth ?? null;
        unset($SESSION->googlemeetattendanceoauth);
        authorization::validate_callback($pending, $nonce, $id, $organizer);
        $issuerid = $pending->issuerid;
    } else {
        $issuerid = !empty($meet->attendanceissuerid) ? (int)$meet->attendanceissuerid :
            (int)get_config('googlemeet', 'issuerid');
        $nonce = bin2hex(random_bytes(24));
        $SESSION->googlemeetattendanceoauth = (object)['nonce' => $nonce, 'userid' => $USER->id, 'cmid' => $id,
            'organizer' => $organizer, 'issuerid' => $issuerid, 'expires' => time() + 600];
    }
    $callbackurl = new moodle_url($url, ['callback' => 1, 'nonce' => $nonce, 'sesskey' => sesskey()]);
    // Clear only the shared session access token, never another flow's stored refresh token.
    $sessionclient = authorization::client($issuerid, $callbackurl, false);
    $sessionclient->log_out();
    $client = authorization::client($issuerid, $callbackurl, $organizer);
    if (!$callback) {
        // Force fresh explicit authorization; do not reuse a token with broader activity scopes.
        $login = $client->get_login_url();
        $login->param('prompt', 'consent select_account');
        $login->param('include_granted_scopes', 'false');
        redirect($login);
    }
    required_param('oauth2code', PARAM_RAW);
    if (!$client->is_logged_in()) {
        throw new moodle_exception('attendanceauthrequired', 'googlemeet');
    }
    authorization::verify_scopes($client, $organizer);
    $info = authorization::userinfo($client);
    if (!$info || empty($info->sub)) {
        throw new moodle_exception('attendanceauthrequired', 'googlemeet');
    }
    $factory = \core\lock\lock_config::get_lock_factory('mod_googlemeet_attendance');
    $lock = $factory->get_lock('sync_' . $meet->id, 10);
    if (!$lock) {
        throw new moodle_exception('locktimeout');
    }
    try {
        $transaction = $DB->start_delegated_transaction();
        if ($organizer) {
            $meet = $DB->get_record('googlemeet', ['id' => $meet->id], '*', MUST_EXIST);
            authorization::validate_organizer($meet, $issuerid, (string)$info->sub);
            $DB->update_record('googlemeet', (object)['id' => $meet->id, 'organizerid' => $USER->id,
                'attendanceissuerid' => $issuerid, 'organizersub' => (string)$info->sub, 'authrequired' => 0]);
        } else {
            authorization::save_identity($info, $issuerid);
            // Recalculate only activities with this issuer and unresolved matching subjects.
            // The service resolves eligible identities without overwriting historical manual decisions.
            $affected = $DB->get_fieldset_sql('SELECT DISTINCT c.googlemeetid
                FROM {googlemeet_conference} c
                JOIN {googlemeet_participant} p ON p.conferenceid = c.id
                JOIN {googlemeet} g ON g.id = c.googlemeetid
                WHERE p.googlesub = :subject AND p.mappingstatus = :status AND g.attendanceissuerid = :issuer',
                ['subject' => (string)$info->sub, 'status' => 'pending', 'issuer' => $issuerid]);
        }
        $DB->insert_record('googlemeet_audit', (object)['googlemeetid' => $meet->id, 'eventid' => 0,
            'participantid' => 0, 'userid' => $USER->id, 'action' => $organizer ? 'organizerconnected' : 'identityconnected',
            'timecreated' => time()]);
        $transaction->allow_commit();
    } finally {
        $lock->release();
    }
    foreach ($affected ?? [] as $affectedid) {
        service::recalculate((int)$affectedid);
    }
    if (!$organizer) {
        $client->log_out();
    }
    redirect($return);
}
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('attendanceidentity', 'googlemeet'));
echo $OUTPUT->notification(get_string('attendanceidentitynotice', 'googlemeet'), 'info');
foreach (['connect', 'disconnect'] as $operation) {
    echo $OUTPUT->single_button(new moodle_url($url, ['action' => $operation]),
        get_string('attendance' . $operation, 'googlemeet'), 'post');
}
echo $OUTPUT->footer();
