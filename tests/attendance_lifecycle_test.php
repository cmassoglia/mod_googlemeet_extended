<?php
// This file is part of Moodle - http://moodle.org/
// Moodle is free software under the GNU GPL v3 or later.

namespace mod_googlemeet;

use mod_googlemeet\attendance\lifecycle;
use mod_googlemeet\privacy\provider;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->libdir . '/phpunit/classes/restore_date_testcase.php');
require_once($CFG->dirroot . '/course/lib.php');

/** Exercises lifecycle/privacy against the real test database, without Google requests.
 * @covers \mod_googlemeet\attendance\lifecycle
 * @covers \mod_googlemeet\privacy\provider
 * @covers \backup_googlemeet_activity_structure_step
 * @covers \restore_googlemeet_activity_structure_step
 */
final class attendance_lifecycle_test extends \restore_date_testcase {
    /** Create imported evidence directly, avoiding the legacy Calendar client. */
    private function fixture(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['startdate' => $this->startdate]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $id = $DB->insert_record('googlemeet', (object)['course' => $course->id, 'name' => 'Attendance',
            'originalname' => 'Attendance', 'url' => 'https://meet.google.com/abc-defg-hij',
            'intro' => '', 'attendanceenabled' => 1, 'organizerid' => $user->id, 'organizersub' => 'subject',
            'attendanceissuerid' => 1, 'authrequired' => 0, 'eventdate' => 100000, 'eventenddate' => 103600]);
        $cmid = add_course_module((object)['course' => $course->id, 'module' => $DB->get_field('modules', 'id',
            ['name' => 'googlemeet']), 'instance' => $id, 'visible' => 1]);
        course_add_cm_to_section($course, $cmid, 0);
        $context = \context_module::instance($cmid);
        $eventid = $DB->insert_record('googlemeet_events', (object)['googlemeetid' => $id, 'eventdate' => 100000,
            'duration' => 3600, 'snapshotat' => 99999, 'syncstatus' => 'resolved', 'lastsync' => 104000]);
        $conferenceid = $DB->insert_record('googlemeet_conference', (object)['googlemeetid' => $id,
            'resource' => 'conferenceRecords/test', 'space' => 'spaces/test', 'timestart' => 100000, 'timeend' => 103600]);
        $participantid = $DB->insert_record('googlemeet_participant', (object)['conferenceid' => $conferenceid,
            'resource' => 'conferenceRecords/test/participants/person', 'googlesub' => 'subject',
            'displayname' => 'Person', 'userid' => $user->id, 'mappingstatus' => 'manual',
            'mappedby' => $user->id, 'mappedat' => 104001]);
        $DB->insert_record('googlemeet_session', (object)['participantid' => $participantid,
            'resource' => 'sessions/one', 'timestart' => 100000, 'timeend' => 103600]);
        $DB->insert_record('googlemeet_eventconf', (object)['eventid' => $eventid, 'conferenceid' => $conferenceid]);
        $DB->insert_record('googlemeet_roster', (object)['eventid' => $eventid, 'userid' => $user->id]);
        $DB->insert_record('googlemeet_audit', (object)['googlemeetid' => $id, 'eventid' => $eventid,
            'participantid' => $participantid, 'userid' => $user->id, 'subjectid' => $user->id,
            'previoususerid' => $user->id, 'action' => 'mapped', 'timecreated' => 104002]);
        $DB->insert_record('googlemeet_identity', (object)['userid' => $user->id, 'issuerid' => 1,
            'googlesub' => 'subject', 'email' => $user->email, 'timecreated' => 99999]);
        $DB->insert_record('googlemeet_notify_done', (object)['eventid' => $eventid, 'userid' => $user->id,
            'timesent' => 99999]);
        return compact('course', 'user', 'id', 'context', 'eventid', 'participantid', 'conferenceid');
    }

    public function test_reset_removes_history_and_disables_binding_but_keeps_global_identity(): void {
        global $DB;
        $f = $this->fixture();
        lifecycle::reset_activity($f['id']);
        foreach (['conference', 'participant', 'session', 'eventconf', 'roster', 'audit'] as $table) {
            $this->assertFalse($DB->record_exists('googlemeet_' . $table, []));
        }
        $this->assertTrue($DB->record_exists('googlemeet_identity', ['userid' => $f['user']->id]));
        $event = $DB->get_record('googlemeet_events', ['id' => $f['eventid']]);
        $this->assertEquals(100000, $event->eventdate);
        $this->assertEquals(0, $event->snapshotat);
        $this->assertEquals('pending', $event->syncstatus);
        $meeting = $DB->get_record('googlemeet', ['id' => $f['id']]);
        $this->assertEquals(0, $meeting->attendanceenabled);
        $this->assertEquals(0, $meeting->organizerid);
        $this->assertEquals('', $meeting->organizersub);
        $this->assertEquals(1, $meeting->authrequired);
    }

    public function test_delete_is_scoped_and_idempotent(): void {
        global $DB;
        $f = $this->fixture();
        $other = $DB->insert_record('googlemeet_conference', (object)['googlemeetid' => $f['id'] + 100,
            'resource' => 'other', 'space' => 'other', 'timestart' => 1]);
        lifecycle::delete_activity($f['id']);
        lifecycle::delete_activity($f['id']);
        $this->assertTrue($DB->record_exists('googlemeet_conference', ['id' => $other]));
        $this->assertTrue($DB->record_exists('googlemeet_events', ['id' => $f['eventid']]));
        $this->assertTrue($DB->record_exists('googlemeet_identity', ['userid' => $f['user']->id]));
        $this->assertFalse($DB->record_exists('googlemeet_session', []));
    }

    public function test_privacy_discovers_contexts_and_erases_with_suppression_marker(): void {
        global $DB;
        $f = $this->fixture();
        $userid = $f['user']->id;
        $contexts = array_map('intval', provider::get_contexts_for_userid($userid)->get_contextids());
        $this->assertContains((int)$f['context']->id, $contexts);
        $this->assertContains((int)\context_system::instance()->id, $contexts);
        $users = new userlist($f['context'], 'mod_googlemeet');
        provider::get_users_in_context($users);
        $this->assertContains((int)$userid, array_map('intval', $users->get_userids()));
        provider::delete_data_for_user(new approved_contextlist($f['user'], 'mod_googlemeet', $contexts));
        $p = $DB->get_record('googlemeet_participant', ['id' => $f['participantid']]);
        $this->assertEquals('deleted', $p->mappingstatus);
        $this->assertEquals('deleted:' . hash('sha256', 'conferenceRecords/test/participants/person'), $p->resource);
        $this->assertEquals('', $p->googlesub);
        $this->assertEquals('', $p->displayname);
        $this->assertEquals(0, $p->userid);
        foreach (['session', 'roster', 'identity', 'notify_done'] as $table) {
            $this->assertFalse($DB->record_exists('googlemeet_' . $table, []));
        }
        $audit = $DB->get_record('googlemeet_audit', ['googlemeetid' => $f['id']], '*', MUST_EXIST);
        $this->assertEquals(0, $audit->userid);
        $this->assertEquals(0, $audit->subjectid);
        $this->assertEquals(0, $audit->previoususerid);
        $this->assertEquals(0, $audit->participantid);
        $this->assertNull($audit->reason);
    }

    public function test_privacy_bulk_delete_retains_unrelated_participant(): void {
        global $DB;
        $f = $this->fixture();
        $other = $DB->insert_record('googlemeet_participant', (object)['conferenceid' => $f['conferenceid'],
            'resource' => 'unrelated', 'googlesub' => 'someone-else', 'displayname' => 'Other']);
        provider::delete_data_for_users(new approved_userlist($f['context'], 'mod_googlemeet', [$f['user']->id]));
        $this->assertEquals('someone-else', $DB->get_field('googlemeet_participant', 'googlesub', ['id' => $other]));
        $this->assertTrue($DB->record_exists('googlemeet_identity', ['userid' => $f['user']->id]));
    }

    public function test_relinked_google_identity_cannot_export_or_erase_another_users_historical_sessions(): void {
        global $DB;
        $f = $this->fixture();
        $newowner = $this->getDataGenerator()->create_user();
        $DB->delete_records('googlemeet_identity', ['userid' => $f['user']->id]);
        $DB->insert_record('googlemeet_identity', (object)['userid' => $newowner->id, 'issuerid' => 1,
            'googlesub' => 'subject', 'email' => $newowner->email, 'timecreated' => 104010]);
        $DB->set_field('googlemeet_participant', 'mappingstatus', 'auto', ['id' => $f['participantid']]);
        $contexts = array_map('intval', provider::get_contexts_for_userid($newowner->id)->get_contextids());
        $this->assertNotContains((int)$f['context']->id, $contexts);
        $users = new userlist($f['context'], 'mod_googlemeet');
        provider::get_users_in_context($users);
        $this->assertNotContains((int)$newowner->id, array_map('intval', $users->get_userids()));

        // Even an explicitly supplied module context must not expose the historical owner's sessions.
        $approved = new approved_contextlist($newowner, 'mod_googlemeet',
            [$f['context']->id, \context_system::instance()->id]);
        provider::export_user_data($approved);
        $exported = \core_privacy\local\request\writer::with_context($f['context'])->get_data(['Attendance']);
        $this->assertSame([], $exported->participants);
        $this->assertSame([], $exported->audit);

        // Acting as a mapper gives access to one's correction, not to the other person's sessions.
        $DB->set_field('googlemeet_participant', 'mappedby', $newowner->id, ['id' => $f['participantid']]);
        provider::export_user_data($approved);
        $exported = \core_privacy\local\request\writer::with_context($f['context'])->get_data(['Attendance']);
        $this->assertSame([], $exported->participants);
        $this->assertCount(1, $exported->mappingactions);
        provider::delete_data_for_user($approved);
        $participant = $DB->get_record('googlemeet_participant', ['id' => $f['participantid']], '*', MUST_EXIST);
        $this->assertEquals($f['user']->id, $participant->userid);
        $this->assertEquals('auto', $participant->mappingstatus);
        $this->assertEquals(0, $participant->mappedby);
        $this->assertEquals('subject', $participant->googlesub);
        $this->assertTrue($DB->record_exists('googlemeet_session', ['participantid' => $participant->id]));
        $this->assertFalse($DB->record_exists('googlemeet_identity', ['userid' => $newowner->id]));
    }

    public function test_privacy_remapped_previous_user_is_discovered_exported_and_erased_without_affecting_current_user(): void {
        global $DB;
        $f = $this->fixture();
        $previous = $this->getDataGenerator()->create_user();
        $DB->set_field('googlemeet_participant', 'mappingreason', 'Correction from previous account',
            ['id' => $f['participantid']]);
        $auditid = $DB->insert_record('googlemeet_audit', (object)['googlemeetid' => $f['id'],
            'eventid' => $f['eventid'], 'participantid' => $f['participantid'], 'userid' => $f['user']->id,
            'subjectid' => $f['user']->id, 'previoususerid' => $previous->id,
            'action' => 'map', 'reason' => 'Correction from previous account', 'timecreated' => 104003]);
        $contexts = array_map('intval', provider::get_contexts_for_userid($previous->id)->get_contextids());
        $this->assertContains((int)$f['context']->id, $contexts);
        $users = new userlist($f['context'], 'mod_googlemeet');
        provider::get_users_in_context($users);
        $this->assertContains((int)$previous->id, array_map('intval', $users->get_userids()));
        $approved = new approved_contextlist($previous, 'mod_googlemeet', $contexts);
        provider::export_user_data($approved);
        $exported = \core_privacy\local\request\writer::with_context($f['context'])->get_data(['Attendance']);
        $this->assertCount(1, $exported->audit);
        $this->assertEquals($auditid, $exported->audit[0]->id);
        $this->assertEquals($previous->id, $exported->audit[0]->previoususerid);
        provider::delete_data_for_user($approved);
        $audit = $DB->get_record('googlemeet_audit', ['id' => $auditid], '*', MUST_EXIST);
        $this->assertEquals($f['user']->id, $audit->userid);
        $this->assertEquals($f['user']->id, $audit->subjectid);
        $this->assertEquals(0, $audit->previoususerid);
        $this->assertNull($audit->reason);
        $participant = $DB->get_record('googlemeet_participant', ['id' => $f['participantid']], '*', MUST_EXIST);
        $this->assertEquals($f['user']->id, $participant->userid);
        $this->assertEquals('manual', $participant->mappingstatus);
        $this->assertNull($participant->mappingreason);
        $this->assertTrue($DB->record_exists('googlemeet_session', ['participantid' => $participant->id]));
        $this->assertNotContains((int)$f['context']->id,
            array_map('intval', provider::get_contexts_for_userid($previous->id)->get_contextids()));
    }

    public function test_privacy_export_contains_identity_sessions_and_original_notifications(): void {
        $f = $this->fixture();
        $system = \context_system::instance();
        provider::export_user_data(new approved_contextlist($f['user'], 'mod_googlemeet',
            [$system->id, $f['context']->id]));
        $identity = \core_privacy\local\request\writer::with_context($system)->get_data(['Identity']);
        $this->assertEquals('subject', $identity->googlesub);
        $data = \core_privacy\local\request\writer::with_context($f['context'])->get_data(['Attendance']);
        $this->assertCount(1, $data->participants);
        $this->assertEquals(100000, $data->participants[0]->sessions[0]->timestart);
        $this->assertEquals(100000, $data->participants[0]->classes[0]->eventdate);
        $this->assertCount(1, $data->audit);
        $notification = \core_privacy\local\request\writer::with_context($f['context'])->get_data([]);
        $this->assertTrue(property_exists($notification, 'timesent'));
    }

    public function test_privacy_all_users_keeps_suppression_marker_and_global_identity(): void {
        global $DB;
        $f = $this->fixture();
        provider::delete_data_for_all_users_in_context($f['context']);
        $participant = $DB->get_record('googlemeet_participant', ['id' => $f['participantid']]);
        $this->assertEquals('deleted', $participant->mappingstatus);
        $this->assertFalse($DB->record_exists('googlemeet_session', []));
        $this->assertFalse($DB->record_exists('googlemeet_audit', []));
        $this->assertFalse($DB->record_exists('googlemeet_roster', []));
        $this->assertEquals(0, $DB->get_field('googlemeet', 'attendanceenabled', ['id' => $f['id']]));
        $this->assertTrue($DB->record_exists('googlemeet_identity', ['userid' => $f['user']->id]));
    }

    public function test_backup_restore_history_remaps_ids_preserves_timestamps_and_disables_sync(): void {
        global $DB;
        $f = $this->fixture();
        $identitycount = $DB->count_records('googlemeet_identity');
        $courseid = $this->backup_and_restore($f['course']);
        $meeting = $DB->get_record('googlemeet', ['course' => $courseid], '*', MUST_EXIST);
        $this->assertEquals(0, $meeting->attendanceenabled);
        $this->assertEquals(0, $meeting->organizerid);
        $this->assertEquals('', $meeting->organizersub);
        $this->assertEquals(1, $meeting->authrequired);
        $event = $DB->get_record('googlemeet_events', ['googlemeetid' => $meeting->id], '*', MUST_EXIST);
        $this->assertEquals(100000, $event->eventdate);
        $this->assertEquals(99999, $event->snapshotat);
        $this->assertEquals(104000, $event->lastsync);
        $this->assertEquals('resolved', $event->syncstatus);
        $conference = $DB->get_record('googlemeet_conference', ['googlemeetid' => $meeting->id], '*', MUST_EXIST);
        $participant = $DB->get_record('googlemeet_participant', ['conferenceid' => $conference->id], '*', MUST_EXIST);
        $this->assertNotEquals($f['participantid'], $participant->id);
        $this->assertEquals($f['user']->id, $participant->userid);
        $this->assertEquals($f['user']->id, $participant->mappedby);
        $session = $DB->get_record('googlemeet_session', ['participantid' => $participant->id], '*', MUST_EXIST);
        $this->assertEquals(100000, $session->timestart);
        $this->assertTrue($DB->record_exists('googlemeet_eventconf', ['eventid' => $event->id, 'conferenceid' => $conference->id]));
        $this->assertTrue($DB->record_exists('googlemeet_roster', ['eventid' => $event->id, 'userid' => $f['user']->id]));
        $audit = $DB->get_record('googlemeet_audit', ['googlemeetid' => $meeting->id], '*', MUST_EXIST);
        $this->assertEquals($participant->id, $audit->participantid);
        $this->assertEquals($event->id, $audit->eventid);
        $this->assertEquals($f['user']->id, $audit->subjectid);
        $this->assertEquals($f['user']->id, $audit->previoususerid);
        $this->assertEquals($identitycount, $DB->count_records('googlemeet_identity'));
    }
    public function test_backup_without_userinfo_has_schedule_and_no_attendance_history(): void {
        global $DB, $CFG, $USER;
        $f = $this->fixture();
        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $bc = new \backup_controller(\backup::TYPE_1COURSE, $f['course']->id, \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO, \backup::MODE_GENERAL, $USER->id);
        $bc->get_plan()->get_setting('users')->set_value(false);
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $directory = 'googlemeet-no-userinfo';
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'),
            $CFG->dataroot . '/temp/backup/' . $directory);
        $bc->destroy();
        $courseid = \restore_dbops::create_new_course('Restored', 'restored-googlemeet', $f['course']->category);
        $rc = new \restore_controller($directory, $courseid, \backup::INTERACTIVE_NO, \backup::MODE_GENERAL,
            $USER->id, \backup::TARGET_NEW_COURSE);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();
        $meeting = $DB->get_record('googlemeet', ['course' => $courseid], '*', MUST_EXIST);
        $this->assertEquals(0, $meeting->attendanceenabled);
        $this->assertEquals(0, $meeting->organizerid);
        $event = $DB->get_record('googlemeet_events', ['googlemeetid' => $meeting->id], '*', MUST_EXIST);
        $this->assertEquals(100000, $event->eventdate);
        $this->assertEquals(3600, $event->duration);
        $this->assertEquals(0, $event->snapshotat);
        $this->assertEquals('pending', $event->syncstatus);
        $this->assertFalse($DB->record_exists('googlemeet_conference', ['googlemeetid' => $meeting->id]));
        $this->assertFalse($DB->record_exists('googlemeet_roster', ['eventid' => $event->id]));
        $this->assertFalse($DB->record_exists('googlemeet_audit', ['googlemeetid' => $meeting->id]));
    }

}
