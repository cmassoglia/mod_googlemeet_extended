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
 * Tests for per-user authorization boundaries, without real Google credentials.
 *
 * @package mod_googlemeet
 * @copyright 2026 MindFree
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_googlemeet;

use mod_googlemeet\attendance\authorization;

defined('MOODLE_INTERNAL') || die();

/** @covers \mod_googlemeet\attendance\authorization */
final class authorization_test extends \advanced_testcase {
    /** Issuer defaults cannot widen the student flow or persist offline grants. */
    public function test_configured_issuer_scopes_are_not_inherited(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $issuer = new \core\oauth2\issuer(0, (object)['name' => 'Test Google', 'image' => '',
            'clientid' => 'client', 'clientsecret' => 'secret', 'enabled' => 1,
            'loginscopes' => 'openid https://www.googleapis.com/auth/drive',
            'loginscopesoffline' => 'openid https://www.googleapis.com/auth/calendar.events']);
        $issuer->create();
        $endpoint = new \core\oauth2\endpoint(0, (object)['issuerid' => $issuer->get('id'),
            'name' => 'authorization_endpoint', 'url' => 'https://accounts.google.com/o/oauth2/v2/auth']);
        $endpoint->create();
        $client = authorization::client($issuer->get('id'), new \moodle_url('/'), false);
        $this->assertSame(authorization::IDENTITY_SCOPES, $client->get_login_url()->get_param('scope'));
        // In-memory scope isolation does not alter the administrator's configured issuer.
        $saved = \core\oauth2\api::get_issuer($issuer->get('id'));
        $this->assertStringContainsString('/drive', $saved->get('loginscopes'));
    }

    /** Scope validation checks actual provider grants, including canonical identity scope aliases. */
    public function test_identity_scope_isolation(): void {
        $client = $this->createMock(\core\oauth2\client::class);
        $client->method('get_info')->willReturn(['http_code' => 200]);
        $client->method('get_errno')->willReturn(0);
        $client->expects($this->once())->method('post')->with('https://oauth2.googleapis.com/tokeninfo', '')
            ->willReturn(json_encode(['scope' => 'openid https://www.googleapis.com/auth/userinfo.email ' .
                'https://www.googleapis.com/auth/userinfo.profile']));
        authorization::verify_scopes($client, false);
    }

    /** Teacher grants must not be accepted as a student identity token. */
    public function test_identity_rejects_meet_grant(): void {
        $client = $this->createMock(\core\oauth2\client::class);
        $client->method('get_info')->willReturn(['http_code' => 200]);
        $client->method('get_errno')->willReturn(0);
        $client->method('post')->willReturn(json_encode(['scope' => authorization::ORGANIZER_SCOPES]));
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('attendanceauthrequired', 'googlemeet'));
        authorization::verify_scopes($client, false);
    }

    /** A stale organizer token without Meet permission requires reconnection. */
    public function test_organizer_requires_meet_grant(): void {
        $client = $this->createMock(\core\oauth2\client::class);
        $client->method('get_info')->willReturn(['http_code' => 200]);
        $client->method('get_errno')->willReturn(0);
        $client->method('post')->willReturn(json_encode(['scope' => authorization::IDENTITY_SCOPES]));
        $this->expectException(\moodle_exception::class);
        authorization::verify_scopes($client, true);
    }

    /** Network errors must not disconnect the organizer. */
    public function test_transient_userinfo_failure(): void {
        $client = $this->createMock(\core\oauth2\client::class);
        $client->method('get_raw_userinfo')->willReturn(false);
        $client->method('get_info')->willReturn(['http_code' => 503]);
        $client->method('get_errno')->willReturn(0);
        $this->expectException(\RuntimeException::class);
        authorization::userinfo($client);
    }

    /** A callback is bound to user, context, purpose and nonce. */
    public function test_callback_cannot_cross_context(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $pending = (object)['nonce' => 'validnonce', 'userid' => $user->id, 'cmid' => 20,
            'organizer' => false, 'expires' => time() + 600];
        authorization::validate_callback($pending, 'validnonce', 20, false);
        $this->expectException(\moodle_exception::class);
        authorization::validate_callback($pending, 'validnonce', 21, false);
    }

    /** Expired state cannot be replayed. */
    public function test_expired_callback(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;
        $pending = (object)['nonce' => 'validnonce', 'userid' => $USER->id, 'cmid' => 20,
            'organizer' => false, 'expires' => time() - 1];
        $this->expectException(\moodle_exception::class);
        authorization::validate_callback($pending, 'validnonce', 20, false);
    }

    /** Student users may link themselves but cannot connect an organizer. */
    public function test_student_cannot_connect_organizer(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $module = $this->getDataGenerator()->create_module('label', ['course' => $course->id]);
        $studentrole = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $studentrole);
        $this->setUser($user);
        $context = \context_module::instance($module->cmid);
        authorization::require_access($context, false);
        $this->expectException(\required_capability_exception::class);
        authorization::require_access($context, true);
    }

    /** The subject is globally unique, including across different issuers. */
    public function test_identity_subject_cannot_be_claimed_by_another_user(): void {
        $this->resetAfterTest();
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();
        $this->setUser($first);
        authorization::save_identity((object)['sub' => 'unique-google-sub', 'email' => 'first@example.org'], 1);
        $this->setUser($second);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('attendanceidentityused', 'googlemeet'));
        authorization::save_identity((object)['sub' => 'unique-google-sub'], 2);
    }

    /** Relinking keeps one identity per Moodle user. */
    public function test_relink_updates_own_identity_only(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        authorization::save_identity((object)['sub' => 'old-sub'], 1);
        authorization::save_identity((object)['sub' => 'new-sub'], 1);
        $this->assertEquals(1, $DB->count_records('googlemeet_identity'));
        $this->assertTrue($DB->record_exists('googlemeet_identity', ['googlesub' => 'new-sub']));
    }
}
