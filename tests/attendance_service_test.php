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
 * Persistence tests.
 *
 * @package mod_googlemeet
 * @copyright 2026 MindFree
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_googlemeet;
use mod_googlemeet\attendance\meet_client;
use mod_googlemeet\attendance\service;
defined('MOODLE_INTERNAL') || die();

/** @covers \mod_googlemeet\attendance\service */
final class attendance_service_test extends \advanced_testcase {
    /** Create an instance directly to avoid production OAuth in module creation. */
    private function fixture(): array {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $start = time() - 7200;
        $meeting = (object)['course' => $course->id, 'name' => 'Attendance', 'originalname' => 'Attendance',
            'url' => 'https://meet.google.com/abc-defg-hij', 'intro' => '', 'attendanceenabled' => 1,
            'attendancefrom' => $start - 100, 'attendanceissuerid' => 42, 'attendancethreshold' => 75,
            'completionattendance' => 0, 'organizerid' => 2, 'organizersub' => 'organizer', 'authrequired' => 0];
        $meeting->id = $DB->insert_record('googlemeet', $meeting);
        $event = (object)['googlemeetid' => $meeting->id, 'eventdate' => $start, 'duration' => 3600,
            'snapshotat' => time(), 'syncstatus' => 'pending'];
        $event->id = $DB->insert_record('googlemeet_events', $event);
        $DB->insert_record('googlemeet_roster', ['eventid' => $event->id, 'userid' => $student->id]);
        $DB->insert_record('googlemeet_identity', ['userid' => $student->id, 'issuerid' => 42,
            'googlesub' => 'student-sub', 'timecreated' => time()]);
        return [$meeting, $event, $student];
    }

    /** API double includes overlapping devices and a conference predating class start. */
    private function api(int $start, string $sub = 'student-sub'): meet_client {
        return new class($start, $sub) extends meet_client {
            private $start;
            private $sub;
            public function __construct($start, $sub) {
                $this->start = $start; $this->sub = $sub;
            }
            public function conferences(string $code, int $start, int $end): array {
                return [(object)['name' => 'conferenceRecords/a', 'space' => 'spaces/a',
                    'startTime' => gmdate('c', $this->start - 100), 'endTime' => gmdate('c', $this->start + 3700)]];
            }
            public function participants(string $conference): array {
                return [(object)['name' => 'conferenceRecords/a/participants/' . ($this->sub === 'organizer' ? 'host' : 'p'),
                    'signedinUser' => (object)['user' => 'users/' . $this->sub, 'displayName' => 'Student']]];
            }
            public function sessions(string $participant): array {
                return [(object)['name' => $participant . '/participantSessions/s1',
                    'startTime' => gmdate('c', $this->start - 100), 'endTime' => gmdate('c', $this->start + 3000)],
                    (object)['name' => $participant . '/participantSessions/s2',
                    'startTime' => gmdate('c', $this->start + 2000), 'endTime' => gmdate('c', $this->start + 3600)]];
            }
        };
    }

    public function test_sync_is_idempotent_and_clips_window(): void {
        global $DB;
        [$meeting, $event, $student] = $this->fixture();
        $api = $this->api($event->eventdate);
        service::sync($meeting->id, $api);
        $DB->set_field('googlemeet_events', 'lastsync', 0, ['id' => $event->id]);
        service::sync($meeting->id, $api);
        $this->assertSame(1, $DB->count_records('googlemeet_conference'));
        $this->assertSame(1, $DB->count_records('googlemeet_participant'));
        $this->assertSame(2, $DB->count_records('googlemeet_session'));
        $report = service::event_report($event->id, $student->id);
        $this->assertSame('attended', $report['rows'][0]['status']);
        $this->assertSame(3600, $report['rows'][0]['seconds']);
        $this->assertSame(3700, $report['rows'][0]['total']);
        $this->assertSame(1, service::event_report($event->id)['summary']['peak']);
    }

    public function test_unknown_identity_stays_pending_and_cannot_complete(): void {
        global $DB;
        [$meeting, $event, $student] = $this->fixture();
        service::sync($meeting->id, $this->api($event->eventdate, 'unlinked'));
        $DB->set_field('googlemeet', 'completionattendance', 80, ['id' => $meeting->id]);
        $this->assertSame('pending', service::event_report($event->id)['rows'][0]['status']);
        $this->assertFalse(service::completion_state($meeting->id, $student->id));
    }

    public function test_empty_response_is_pending_not_absent(): void {
        [$meeting, $event] = $this->fixture();
        $api = new class(null) extends meet_client {
            public function conferences(string $code, int $start, int $end): array {
                return [];
            }
        };
        service::sync($meeting->id, $api);
        $this->assertSame('pending', service::event_report($event->id)['rows'][0]['status']);
    }

    public function test_completion_waits_until_last_class_and_excludes_cancellation(): void {
        global $DB;
        [$meeting, $event, $student] = $this->fixture();
        service::sync($meeting->id, $this->api($event->eventdate));
        $DB->set_field('googlemeet', 'completionattendance', 80, ['id' => $meeting->id]);
        $this->assertTrue(service::completion_state($meeting->id, $student->id));
        $futureid = $DB->insert_record('googlemeet_events', ['googlemeetid' => $meeting->id,
            'eventdate' => time() + 3600, 'duration' => 3600]);
        $this->assertFalse(service::completion_state($meeting->id, $student->id));
        $DB->set_field('googlemeet_events', 'cancelled', 1, ['id' => $futureid]);
        $this->assertTrue(service::completion_state($meeting->id, $student->id));
    }

    public function test_partial_fetch_does_not_write_or_mark_absence(): void {
        global $DB;
        [$meeting, $event] = $this->fixture();
        $api = new class($event->eventdate) extends meet_client {
            private $start;
            public function __construct($start) {
                $this->start = $start;
            }
            public function conferences(string $code, int $start, int $end): array {
                return [(object)['name' => 'conferenceRecords/a', 'startTime' => gmdate('c', $this->start)]];
            }
            public function participants(string $conference): array {
                throw new \runtimeexception('HTTP 429', 429);
            }
        };
        try {
            service::sync($meeting->id, $api);
            $this->fail('Expected transient failure');
        } catch (\runtimeexception $e) {
            $this->assertSame(429, $e->getCode());
        }
        $this->assertSame(0, $DB->count_records('googlemeet_conference'));
        $this->assertSame('error', $DB->get_field('googlemeet_events', 'syncstatus', ['id' => $event->id]));
        $this->assertSame('pending', service::event_report($event->id)['rows'][0]['status']);
    }

    public function test_auth_failure_stops_until_reauthorization(): void {
        global $DB;
        [$meeting, $event] = $this->fixture();
        $api = new class(null) extends meet_client {
            public function conferences(string $code, int $start, int $end): array {
                throw new \runtimeexception('HTTP 403', 403);
            }
        };
        service::sync($meeting->id, $api);
        $this->assertEquals(1, $DB->get_field('googlemeet', 'authrequired', ['id' => $meeting->id]));
        $this->assertFalse(service::queue_sync($meeting->id));
    }

    public function test_threshold_uses_exact_seconds_and_recognises_organizer(): void {
        global $DB;
        [$meeting, $event, $student] = $this->fixture();
        service::sync($meeting->id, $this->api($event->eventdate));
        $sessions = array_values($DB->get_records('googlemeet_session', [], 'id'));
        $DB->delete_records('googlemeet_session', ['id' => $sessions[1]->id]);
        $DB->update_record('googlemeet_session', (object)['id' => $sessions[0]->id,
            'timestart' => $event->eventdate, 'timeend' => $event->eventdate + 2700]);
        $this->assertSame('attended', service::event_report($event->id)['rows'][0]['status']);
        $DB->set_field('googlemeet_session', 'timeend', $event->eventdate + 2699, ['id' => $sessions[0]->id]);
        $this->assertSame('absent', service::event_report($event->id)['rows'][0]['status']);
        $DB->set_field('googlemeet_events', 'lastsync', 0, ['id' => $event->id]);
        service::sync($meeting->id, $this->api($event->eventdate, 'organizer'));
        $person = $DB->get_record('googlemeet_participant', ['googlesub' => 'organizer'], '*', MUST_EXIST);
        $this->assertSame('auto', $person->mappingstatus);
        $this->assertEquals($meeting->organizerid, $person->userid);
    }

    public function test_conference_without_participants_cannot_mark_absence(): void {
        [$meeting, $event] = $this->fixture();
        $api = new class($event->eventdate) extends meet_client {
            private $start;
            public function __construct($start) {
                $this->start = $start;
            }
            public function conferences(string $code, int $start, int $end): array {
                return [(object)['name' => 'conferenceRecords/a', 'startTime' => gmdate('c', $this->start),
                    'endTime' => gmdate('c', $this->start + 3600)]];
            }
            public function participants(string $conference): array {
                return [];
            }
        };
        service::sync($meeting->id, $api);
        $this->assertSame('pending', service::event_report($event->id)['rows'][0]['status']);
    }

    public function test_later_identity_link_resolves_local_evidence_without_google(): void {
        global $DB;
        [$meeting, $event, $student] = $this->fixture();
        $identity = $DB->get_record('googlemeet_identity', ['userid' => $student->id], '*', MUST_EXIST);
        $DB->delete_records('googlemeet_identity', ['id' => $identity->id]);
        service::sync($meeting->id, $this->api($event->eventdate));
        $this->assertSame('pending', service::event_report($event->id)['rows'][0]['status']);
        unset($identity->id);
        $DB->insert_record('googlemeet_identity', $identity);
        service::recalculate($meeting->id);
        $this->assertSame('attended', service::event_report($event->id)['rows'][0]['status']);
    }

    public function test_delayed_roster_excludes_enrolments_created_or_starting_after_class(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $meeting = $this->getDataGenerator()->create_module('googlemeet', ['course' => $course->id]);
        $event = $DB->get_record('googlemeet_events', ['googlemeetid' => $meeting->id], '*', MUST_EXIST);
        $start = time() - 7200;
        $DB->update_record('googlemeet_events', (object)['id' => $event->id, 'eventdate' => $start]);
        $DB->update_record('googlemeet', (object)['id' => $meeting->id,
            'attendanceenabled' => 1, 'attendancefrom' => $start - 100]);
        $students = [];
        for ($i = 0; $i < 3; $i++) {
            $student = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
            $students[] = $student;
        }
        $DB->set_field('user_enrolments', 'timecreated', $start - 100, ['userid' => $students[0]->id]);
        $DB->set_field('user_enrolments', 'timecreated', $start - 100, ['userid' => $students[2]->id]);
        $DB->set_field('user_enrolments', 'timestart', $start + 100, ['userid' => $students[2]->id]);
        service::capture_rosters($meeting->id);
        $this->assertTrue($DB->record_exists('googlemeet_roster', ['eventid' => $event->id, 'userid' => $students[0]->id]));
        $this->assertFalse($DB->record_exists('googlemeet_roster', ['eventid' => $event->id, 'userid' => $students[1]->id]));
        $this->assertFalse($DB->record_exists('googlemeet_roster', ['eventid' => $event->id, 'userid' => $students[2]->id]));
        // Once captured, later changes never rewrite membership.
        $DB->set_field('user_enrolments', 'timecreated', $start - 100, ['userid' => $students[1]->id]);
        service::capture_rosters($meeting->id);
        $this->assertSame(1, $DB->count_records('googlemeet_roster', ['eventid' => $event->id]));
    }

    public function test_failed_refresh_preserves_resolved_attendance_and_completion(): void {
        global $DB;
        [$meeting, $event, $student] = $this->fixture();
        service::sync($meeting->id, $this->api($event->eventdate));
        $DB->set_field('googlemeet', 'completionattendance', 80, ['id' => $meeting->id]);
        foreach ([429, 403] as $status) {
            $DB->set_field('googlemeet_events', 'lastsync', 0, ['id' => $event->id]);
            $api = new class($status) extends meet_client {
                private $status;
                public function __construct($status) {
                    $this->status = $status;
                }
                public function conferences(string $code, int $start, int $end): array {
                    throw new \runtimeexception('Refresh failed', $this->status);
                }
            };
            try {
                service::sync($meeting->id, $api);
                $this->assertSame(403, $status);
            } catch (\runtimeexception $error) {
                $this->assertSame(429, $error->getCode());
            }
            $report = service::event_report($event->id);
            $this->assertSame('resolved', $report['event']->syncstatus);
            $this->assertNotEmpty($report['event']->syncerror);
            $this->assertSame('attended', $report['rows'][0]['status']);
            $this->assertEquals(3600, $report['summary']['meanseconds']);
            $this->assertTrue(service::completion_state($meeting->id, $student->id));
        }
        $this->assertEquals(1, $DB->get_field('googlemeet', 'authrequired', ['id' => $meeting->id]));
    }

    public function test_recalculate_batches_series_queries_for_one_hundred_students(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $meeting = $this->getDataGenerator()->create_module('googlemeet',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC]);
        $DB->update_record('googlemeet', (object)['id' => $meeting->id, 'attendanceenabled' => 1,
            'attendancefrom' => 0, 'attendanceissuerid' => 42, 'completionattendance' => 80]);
        $DB->delete_records('googlemeet_events', ['googlemeetid' => $meeting->id]);
        $events = [];
        for ($i = 0; $i < 30; $i++) {
            $events[] = $DB->insert_record('googlemeet_events', ['googlemeetid' => $meeting->id,
                'eventdate' => time() - (32 - $i) * HOURSECS, 'duration' => 1800,
                'snapshotat' => time(), 'syncstatus' => 'resolved']);
        }
        $students = [];
        for ($i = 0; $i < 100; $i++) {
            $student = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
            $students[] = $student;
            $DB->insert_record('course_modules_completion', ['coursemoduleid' => $meeting->cmid,
                'userid' => $student->id, 'completionstate' => COMPLETION_COMPLETE, 'timemodified' => time()]);
            $DB->insert_record('googlemeet_identity', ['userid' => $student->id,
                'issuerid' => 42, 'googlesub' => 'student-' . $student->id, 'timecreated' => time()]);
            foreach ($events as $eventid) {
                $DB->insert_record('googlemeet_roster', ['eventid' => $eventid, 'userid' => $student->id]);
            }
        }
        rebuild_course_cache($course->id, true);
        $before = $DB->perf_get_queries();
        service::recalculate($meeting->id);
        $queries = $DB->perf_get_queries() - $before;
        // Allows Moodle's linear per-user writes; rejects reloading the 100-person roster 100 times per class.
        $this->assertLessThan(5000, $queries, 'Queries for 100 students and 30 classes: ' . $queries);
        $this->assertSame(100, $DB->count_records('course_modules_completion',
            ['coursemoduleid' => $meeting->cmid, 'completionstate' => COMPLETION_INCOMPLETE]));
        // A subsequent call must read current evidence rather than a static cache from this batch.
        $DB->set_field('googlemeet_events', 'syncstatus', 'pending', ['id' => $events[0]]);
        $before = $DB->perf_get_queries();
        $this->assertFalse(service::completion_state($meeting->id, $students[0]->id));
        $this->assertGreaterThan($before, $DB->perf_get_queries());
    }

    public function test_relinking_google_identity_does_not_reassign_historical_participant(): void {
        global $DB;
        [$meeting, $event, $original] = $this->fixture();
        service::sync($meeting->id, $this->api($event->eventdate));
        $participant = $DB->get_record('googlemeet_participant', [], '*', MUST_EXIST);
        $this->assertEquals($original->id, $participant->userid);
        $this->assertSame('auto', $participant->mappingstatus);
        $DB->delete_records('googlemeet_identity', ['userid' => $original->id]);
        $replacement = $this->getDataGenerator()->create_user();
        $DB->insert_record('googlemeet_identity', ['userid' => $replacement->id, 'issuerid' => 42,
            'googlesub' => 'student-sub', 'timecreated' => time()]);
        $DB->set_field('googlemeet_events', 'lastsync', 0, ['id' => $event->id]);
        service::sync($meeting->id, $this->api($event->eventdate));
        $stored = $DB->get_record('googlemeet_participant', ['id' => $participant->id], '*', MUST_EXIST);
        $this->assertEquals($original->id, $stored->userid);
        $this->assertEquals($participant->mappedat, $stored->mappedat);
        $this->assertSame('auto', $stored->mappingstatus);
        $originalreport = service::event_report($event->id, $original->id);
        $this->assertSame('attended', $originalreport['rows'][0]['status']);
        $this->assertSame(3600, $originalreport['rows'][0]['seconds']);
        $replacementreport = service::event_report($event->id, $replacement->id);
        $this->assertSame([], $replacementreport['rows']);
        $this->assertSame([], $replacementreport['participants']);
    }

    public function test_privacy_tombstone_prevents_reimport(): void {
        global $DB;
        [$meeting, $event] = $this->fixture();
        service::sync($meeting->id, $this->api($event->eventdate));
        $participant = $DB->get_record('googlemeet_participant', [], '*', MUST_EXIST);
        $DB->delete_records('googlemeet_session', ['participantid' => $participant->id]);
        $participant->resource = 'deleted:' . hash('sha256', $participant->resource);
        $participant->mappingstatus = 'deleted';
        $participant->userid = 0;
        $participant->googlesub = '';
        $participant->displayname = '';
        $DB->update_record('googlemeet_participant', $participant);
        $DB->set_field('googlemeet_events', 'lastsync', 0, ['id' => $event->id]);
        service::sync($meeting->id, $this->api($event->eventdate));
        $this->assertSame(1, $DB->count_records('googlemeet_participant'));
        $this->assertSame(0, $DB->count_records('googlemeet_session'));
    }
}
