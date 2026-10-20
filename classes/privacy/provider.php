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
 * Privacy Subsystem implementation for mod_googlemeet.
 *
 * @package     mod_googlemeet
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_googlemeet\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_googlemeet\attendance\lifecycle;

/** Privacy operations for notifications, imported attendance and explicitly linked identities. */
class provider implements \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('googlemeet_notify_done', [
            'eventid' => 'privacy:metadata:googlemeet_notify_done:eventid',
            'userid' => 'privacy:metadata:googlemeet_notify_done:userid',
            'timesent' => 'privacy:metadata:googlemeet_notify_done:timesent',
        ], 'privacy:metadata:googlemeet_notify_done');
        $tables = [
            'googlemeet' => ['organizerid', 'organizersub', 'creatoremail'],
            'googlemeet_events' => ['snapshotat', 'lastsync', 'syncerror'],
            'googlemeet_conference' => ['resource', 'space', 'timestart', 'timeend'],
            'googlemeet_participant' => ['resource', 'googlesub', 'displayname', 'userid', 'mappedby', 'mappedat', 'mappingreason'],
            'googlemeet_session' => ['resource', 'timestart', 'timeend'],
            'googlemeet_roster' => ['eventid', 'userid'],
            'googlemeet_identity' => ['userid', 'issuerid', 'googlesub', 'email', 'timecreated'],
            'googlemeet_audit' => ['eventid', 'participantid', 'userid', 'subjectid', 'previoususerid', 'action', 'reason', 'timecreated'],
        ];
        foreach ($tables as $table => $fields) {
            $collection->add_database_table($table,
                array_fill_keys($fields, 'privacy:metadata:attendance:identifier'), 'privacy:metadata:attendance');
        }
        $collection->add_external_location_link('google', [
            'organizersub' => 'privacy:metadata:attendance:identifier',
            'email' => 'privacy:metadata:attendance:identifier',
            'resource' => 'privacy:metadata:attendance:identifier',
        ], 'privacy:metadata:google');
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contexts = new contextlist();
        if ($DB->record_exists('googlemeet_identity', ['userid' => $userid])) {
            $contexts->add_system_context();
        }
        $sql = "SELECT DISTINCT ctx.id FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :level
                  JOIN {modules} m ON m.id = cm.module AND m.name = :module
                  JOIN {googlemeet} g ON g.id = cm.instance
                 WHERE g.organizerid = :organizer
                    OR EXISTS (SELECT 1 FROM {googlemeet_events} e JOIN {googlemeet_notify_done} n ON n.eventid = e.id
                                WHERE e.googlemeetid = g.id AND n.userid = :notified)
                    OR EXISTS (SELECT 1 FROM {googlemeet_events} e JOIN {googlemeet_roster} r ON r.eventid = e.id
                                WHERE e.googlemeetid = g.id AND r.userid = :enrolled)
                    OR EXISTS (SELECT 1 FROM {googlemeet_audit} a WHERE a.googlemeetid = g.id
                                 AND (a.userid = :actor OR a.subjectid = :subject OR a.previoususerid = :previous))
                    OR EXISTS (SELECT 1 FROM {googlemeet_conference} c
                                 JOIN {googlemeet_participant} p ON p.conferenceid = c.id
                                WHERE c.googlemeetid = g.id AND (p.userid = :participant OR p.mappedby = :mapper
                                   OR (p.userid = 0 AND p.mappingstatus = 'pending' AND p.googlesub IN
                                       (SELECT i.googlesub FROM {googlemeet_identity} i WHERE i.userid = :identity))))";
        $contexts->add_from_sql($sql, ['level' => CONTEXT_MODULE, 'module' => 'googlemeet', 'organizer' => $userid,
            'notified' => $userid, 'enrolled' => $userid, 'actor' => $userid, 'participant' => $userid,
            'mapper' => $userid, 'identity' => $userid, 'subject' => $userid, 'previous' => $userid]);
        return $contexts;
    }

    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context instanceof \context_system) {
            $userlist->add_from_sql('userid', 'SELECT userid FROM {googlemeet_identity}', []);
            return;
        }
        $id = self::activity_id($context);
        if (!$id) {
            return;
        }
        $queries = [
            'SELECT organizerid AS userid FROM {googlemeet} WHERE id = :id AND organizerid > 0',
            'SELECT n.userid FROM {googlemeet_notify_done} n JOIN {googlemeet_events} e ON e.id = n.eventid
                WHERE e.googlemeetid = :id',
            'SELECT r.userid FROM {googlemeet_roster} r JOIN {googlemeet_events} e ON e.id = r.eventid
                WHERE e.googlemeetid = :id',
            'SELECT userid FROM {googlemeet_audit} WHERE googlemeetid = :id AND userid > 0',
            'SELECT subjectid AS userid FROM {googlemeet_audit} WHERE googlemeetid = :id AND subjectid > 0',
            'SELECT previoususerid AS userid FROM {googlemeet_audit} WHERE googlemeetid = :id AND previoususerid > 0',
            'SELECT p.userid FROM {googlemeet_participant} p JOIN {googlemeet_conference} c ON c.id = p.conferenceid
                WHERE c.googlemeetid = :id AND p.userid > 0',
            'SELECT p.mappedby AS userid FROM {googlemeet_participant} p JOIN {googlemeet_conference} c ON c.id = p.conferenceid
                WHERE c.googlemeetid = :id AND p.mappedby > 0',
            'SELECT i.userid FROM {googlemeet_identity} i JOIN {googlemeet_participant} p ON p.googlesub = i.googlesub
                JOIN {googlemeet_conference} c ON c.id = p.conferenceid
                WHERE c.googlemeetid = :id AND p.userid = 0 AND p.mappingstatus = \'pending\'',
        ];
        foreach ($queries as $sql) {
            $userlist->add_from_sql('userid', $sql, ['id' => $id]);
        }
    }

    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = (int)$contextlist->get_user()->id;
        $identity = $DB->get_record('googlemeet_identity', ['userid' => $userid]);
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                if ($identity) {
                    writer::with_context($context)->export_data(['Identity'], $identity);
                }
                continue;
            }
            $id = self::activity_id($context);
            if (!$id) {
                continue;
            }
            $data = (object)[];
            $data->notifications = array_values($DB->get_records_sql("SELECT n.* FROM {googlemeet_notify_done} n
                JOIN {googlemeet_events} e ON e.id = n.eventid WHERE e.googlemeetid = :id AND n.userid = :userid",
                ['id' => $id, 'userid' => $userid]));
            $data->roster = array_values($DB->get_records_sql("SELECT r.*, e.eventdate, e.duration FROM {googlemeet_roster} r
                JOIN {googlemeet_events} e ON e.id = r.eventid WHERE e.googlemeetid = :id AND r.userid = :userid",
                ['id' => $id, 'userid' => $userid]));
            $data->participants = [];
            $data->mappingactions = [];
            foreach (self::participants($id) as $participant) {
                if ((int)$participant->userid === $userid || ($identity && (int)$participant->userid === 0 &&
                        $participant->mappingstatus === 'pending' && $participant->googlesub === $identity->googlesub)) {
                    $participant->conference = $DB->get_record('googlemeet_conference', ['id' => $participant->conferenceid]);
                    $participant->classes = array_values($DB->get_records_sql("SELECT e.* FROM {googlemeet_events} e
                        JOIN {googlemeet_eventconf} ec ON ec.eventid = e.id WHERE ec.conferenceid = :id",
                        ['id' => $participant->conferenceid]));
                    $participant->sessions = array_values($DB->get_records('googlemeet_session', ['participantid' => $participant->id]));
                    $data->participants[] = $participant;
                } else if ((int)$participant->mappedby === $userid) {
                    $data->mappingactions[] = (object)['participantid' => $participant->id,
                        'mappedat' => $participant->mappedat, 'mappingreason' => $participant->mappingreason];
                }
            }
            $data->audit = array_values($DB->get_records_select('googlemeet_audit',
                'googlemeetid = :id AND (userid = :actor OR subjectid = :subject OR previoususerid = :previous)',
                ['id' => $id, 'actor' => $userid, 'subject' => $userid, 'previous' => $userid]));
            $activity = $DB->get_record('googlemeet', ['id' => $id]);
            if ((int)$activity->organizerid === $userid) {
                $data->organizer = (object)['organizersub' => $activity->organizersub, 'creatoremail' => $activity->creatoremail];
            }
            // Preserve the existing notification export path for consumers of earlier versions.
            foreach ($data->notifications as $notification) {
                $notificationdata = (object)array_merge((array)helper::get_context_data($context, $contextlist->get_user()),
                    ['timesent' => transform::datetime($notification->timesent)]);
                writer::with_context($context)->export_data([], $notificationdata);
            }
            writer::with_context($context)->export_data(['Attendance'], $data);
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context instanceof \context_system) {
            $DB->delete_records('googlemeet_identity');
            return;
        }
        $id = self::activity_id($context);
        if (!$id) {
            return;
        }
        $lock = lifecycle::lock_activity($id);
        try {
            $transaction = $DB->start_delegated_transaction();
            // Keep only hashed tombstones, so the next import cannot recreate erased people.
            foreach (self::participants($id) as $participant) {
                self::erase_participant($participant);
            }
            $params = ['id' => $id];
            foreach (['googlemeet_notify_done', 'googlemeet_roster'] as $table) {
                $DB->delete_records_select($table, 'eventid IN (SELECT id FROM {googlemeet_events} WHERE googlemeetid = :id)', $params);
            }
            $DB->delete_records('googlemeet_audit', ['googlemeetid' => $id]);
            self::clear_organizer($id);
            $transaction->allow_commit();
        } finally {
            $lock->release();
        }
    }

    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        $userid = (int)$contextlist->get_user()->id;
        $identity = $DB->get_record('googlemeet_identity', ['userid' => $userid]);
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                $DB->delete_records('googlemeet_identity', ['userid' => $userid]);
            } else if ($id = self::activity_id($context)) {
                self::erase_user($id, $userid, $identity ? $identity->googlesub : '');
            }
        }
    }

    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        foreach ($userlist->get_userids() as $userid) {
            if ($userlist->get_context() instanceof \context_system) {
                $DB->delete_records('googlemeet_identity', ['userid' => $userid]);
            } else if ($id = self::activity_id($userlist->get_context())) {
                $sub = $DB->get_field('googlemeet_identity', 'googlesub', ['userid' => $userid]);
                self::erase_user($id, (int)$userid, $sub ?: '');
            }
        }
    }

    /** Resolve only this module's contexts; foreign module contexts must be ignored. */
    private static function activity_id(\context $context): int {
        if (!$context instanceof \context_module) {
            return 0;
        }
        $cm = get_coursemodule_from_id('googlemeet', $context->instanceid);
        return $cm ? (int)$cm->instance : 0;
    }

    private static function participants(int $id): array {
        global $DB;
        return $DB->get_records_sql("SELECT p.* FROM {googlemeet_participant} p
            JOIN {googlemeet_conference} c ON c.id = p.conferenceid WHERE c.googlemeetid = :id", ['id' => $id]);
    }

    private static function erase_participant(\stdClass $participant): void {
        global $DB;
        $DB->delete_records('googlemeet_session', ['participantid' => $participant->id]);
        if ($participant->mappingstatus !== 'deleted') {
            $participant->resource = 'deleted:' . hash('sha256', $participant->resource);
        }
        $participant->googlesub = '';
        $participant->displayname = '';
        $participant->userid = 0;
        $participant->mappedby = 0;
        $participant->mappedat = 0;
        $participant->mappingreason = null;
        $participant->mappingstatus = 'deleted';
        $DB->update_record('googlemeet_participant', $participant);
        // Retain the action and its other subjects, without linking to this erased participant.
        foreach ($DB->get_records('googlemeet_audit', ['participantid' => $participant->id]) as $audit) {
            $DB->update_record('googlemeet_audit', (object)['id' => $audit->id, 'participantid' => 0, 'reason' => null]);
        }
    }

    private static function erase_user(int $id, int $userid, string $sub): void {
        global $DB;
        $lock = lifecycle::lock_activity($id);
        try {
            $transaction = $DB->start_delegated_transaction();
            foreach (self::participants($id) as $participant) {
                if ((int)$participant->userid === $userid || ($sub !== '' && (int)$participant->userid === 0 &&
                        $participant->mappingstatus === 'pending' && $participant->googlesub === $sub)) {
                    self::erase_participant($participant);
                } else if ((int)$participant->mappedby === $userid) {
                    $DB->update_record('googlemeet_participant', (object)['id' => $participant->id,
                        'mappedby' => 0, 'mappingreason' => null, 'mappedat' => 0]);
                }
            }
            foreach (['googlemeet_notify_done', 'googlemeet_roster'] as $table) {
                $DB->delete_records_select($table, 'userid = :userid AND eventid IN
                    (SELECT id FROM {googlemeet_events} WHERE googlemeetid = :id)', ['userid' => $userid, 'id' => $id]);
            }
            $audits = $DB->get_records_select('googlemeet_audit',
                'googlemeetid = :id AND (userid = :actor OR subjectid = :subject OR previoususerid = :previous)',
                ['id' => $id, 'actor' => $userid, 'subject' => $userid, 'previous' => $userid]);
            foreach ($audits as $audit) {
                foreach (['userid', 'subjectid', 'previoususerid'] as $field) {
                    if ((int)$audit->$field === $userid) {
                        $audit->$field = 0;
                    }
                }
                // The participant can retain a copy of the same correction's free text.
                if ($audit->participantid) {
                    $DB->set_field('googlemeet_participant', 'mappingreason', null, ['id' => $audit->participantid]);
                }
                // Free text can identify any actor or subject of the correction.
                $audit->reason = null;
                $DB->update_record('googlemeet_audit', $audit);
            }
            if ($DB->record_exists('googlemeet', ['id' => $id, 'organizerid' => $userid])) {
                self::clear_organizer($id);
            }
            $transaction->allow_commit();
        } finally {
            $lock->release();
        }
    }

    private static function clear_organizer(int $id): void {
        global $DB;
        $DB->update_record('googlemeet', (object)['id' => $id, 'organizerid' => 0, 'organizersub' => '',
            'attendanceissuerid' => 0, 'attendanceenabled' => 0, 'authrequired' => 1, 'creatoremail' => '']);
    }
}
