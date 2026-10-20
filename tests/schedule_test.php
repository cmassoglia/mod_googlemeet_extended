<?php
// This file is part of Moodle - http://moodle.org/
// Moodle is distributed under the GNU GPL v3 or later.
namespace mod_googlemeet;
defined('MOODLE_INTERNAL') || die();

/** Schedule updates must preserve already captured attendance. @coversNothing */
final class schedule_test extends \advanced_testcase {
    /** An edited timetable keeps the historical occurrence and changes future occurrences only. */
    public function test_update_preserves_started_occurrence_and_roster(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $meeting = $this->getDataGenerator()->create_module('googlemeet', ['course' => $course->id]);
        $meeting->coursemodule = $meeting->cmid;
        $old = $DB->get_record('googlemeet_events', ['googlemeetid' => $meeting->id], '*', MUST_EXIST);
        $old->eventdate = time() - 7200;
        $old->snapshotat = time() - 7100;
        $DB->update_record('googlemeet_events', $old);
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('googlemeet_roster', ['eventid' => $old->id, 'userid' => $user->id]);
        $future = (object)['googlemeetid' => $meeting->id, 'eventdate' => time() + DAYSECS,
            'duration' => 1800, 'timemodified' => time()];
        googlemeet_set_events($meeting, [$future]);
        $this->assertEquals($old, $DB->get_record('googlemeet_events', ['id' => $old->id]));
        $this->assertTrue($DB->record_exists('googlemeet_roster', ['eventid' => $old->id, 'userid' => $user->id]));
        $futureid = $DB->get_field('googlemeet_events', 'id', ['eventdate' => $future->eventdate]);
        $future->duration = 2700;
        googlemeet_set_events($meeting, [$future]);
        $this->assertEquals($futureid, $DB->get_field('googlemeet_events', 'id', ['eventdate' => $future->eventdate]));
        $this->assertEquals(2700, $DB->get_field('googlemeet_events', 'duration', ['id' => $futureid]));
    }

    /** Updating legacy activity settings cannot impersonate a different Google organiser. */
    public function test_protected_binding_and_activation(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
        $previous = (object)['attendancefrom' => 0, 'attendanceenabled' => 0, 'organizerid' => 12,
            'attendanceissuerid' => 3, 'organizersub' => 'verified', 'authrequired' => 0,
            'url' => 'https://meet.google.com/abc-defg-hij'];
        $record = (object)['attendanceenabled' => 1, 'organizerid' => 99, 'organizersub' => 'forged'];
        googlemeet_prepare_attendance_settings($record, $previous);
        $this->assertEquals(12, $record->organizerid);
        $this->assertEquals('verified', $record->organizersub);
        $this->assertGreaterThan(0, $record->attendancefrom);
        $this->assertEquals(75, $record->attendancethreshold);
        $record->url = 'https://meet.google.com/xyz-abcd-efg';
        googlemeet_prepare_attendance_settings($record, $previous);
        $this->assertEquals(0, $record->organizerid);
        $this->assertEquals(1, $record->authrequired);
    }
}
