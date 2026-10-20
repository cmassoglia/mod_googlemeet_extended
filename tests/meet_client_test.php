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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * Meet transport tests.
 *
 * @package mod_googlemeet
 * @copyright 2026 MindFree
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_googlemeet;
use mod_googlemeet\attendance\meet_client;
defined('MOODLE_INTERNAL') || die();

/** @covers \mod_googlemeet\attendance\meet_client */
final class meet_client_test extends \basic_testcase {
    public function test_pages_and_overlapping_conferences(): void {
        $transport = new class {
            public $errno = 0;
            public $calls = [];
            public function get($url, $params) {
                $this->calls[] = [$url, $params];
                if (count($this->calls) === 1) {
                    return '{"conferenceRecords":[{"name":"conferenceRecords/a"}],"nextPageToken":"next"}';
                }
                return '{"conferenceRecords":[{"name":"conferenceRecords/b"}]}';
            }
            public function get_info() {
                return ['http_code' => 200];
            }
        };
        $client = new meet_client($transport);
        $this->assertCount(2, $client->conferences('abc-defg-hij', 100, 200));
        $this->assertCount(3, $transport->calls);
        $this->assertSame('next', $transport->calls[1][1]['pageToken']);
        $this->assertStringContainsString('end_time >', $transport->calls[0][1]['filter']);
        $this->assertStringNotContainsString('start_time >', $transport->calls[0][1]['filter']);
        $this->assertStringContainsString('end_time IS NULL', $transport->calls[2][1]['filter']);
    }

    public function test_http_failure_retains_status_without_upstream_secrets(): void {
        $transport = new class {
            public $errno = 0;
            public function get($url, $params) {
                return '{"error":"private token"}';
            }
            public function get_info() {
                return ['http_code' => 403];
            }
        };
        $this->expectException(\runtimeexception::class);
        $this->expectExceptionCode(403);
        $this->expectExceptionMessage('Meet API request failed (HTTP 403)');
        (new meet_client($transport))->participants('conferenceRecords/a');
    }

    public function test_invalid_meeting_code_throws_standard_exception(): void {
        $this->expectException(\InvalidArgumentException::class);
        (new meet_client(null))->conferences('invalid-code', 100, 200);
    }

    public function test_resource_path_is_validated(): void {
        $this->expectException(\unexpectedvalueexception::class);
        (new meet_client(null))->sessions('https://attacker.invalid/');
    }

    public function test_repeating_page_token_fails_instead_of_hanging(): void {
        $transport = new class {
            public $errno = 0;
            public function get($url, $params) {
                return '{"participants":[],"nextPageToken":"same"}';
            }
            public function get_info() {
                return ['http_code' => 200];
            }
        };
        $this->expectException(\unexpectedvalueexception::class);
        (new meet_client($transport))->participants('conferenceRecords/a');
    }
}
