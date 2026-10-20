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
 * Attendance persistence and reporting.
 *
 * @package mod_googlemeet
 * @copyright 2026 MindFree
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_googlemeet\attendance;
defined('MOODLE_INTERNAL') || die();

/** Coordinates immutable rosters, idempotent Google imports and completion. */
class service {
    /** @var array Per-user final results, scoped strictly to one recalculate call. */
    private static $completioncache = [];

    /** Queue a deduplicated task with the organiser's user context. */
    public static function queue_sync(int $googlemeetid): bool {
        global $DB;
        $meeting = $DB->get_record('googlemeet', ['id' => $googlemeetid], '*', MUST_EXIST);
        if (empty($meeting->attendanceenabled) || empty($meeting->organizerid) || !empty($meeting->authrequired)) {
            return false;
        }
        $task = new \mod_googlemeet\task\attendance_sync();
        $task->set_component('mod_googlemeet');
        $task->set_userid($meeting->organizerid);
        $task->set_custom_data(['googlemeetid' => $googlemeetid]);
        return (bool)\core\task\manager::queue_adhoc_task($task, true);
    }

    /** Snapshot active students once, when an eligible class starts. */
    public static function capture_rosters(?int $googlemeetid = null): void {
        global $DB;
        $params = ['now' => time()];
        $where = 'm.attendanceenabled = 1 AND e.cancelled = 0 AND e.snapshotat = 0
            AND e.eventdate >= m.attendancefrom AND e.eventdate <= :now';
        if ($googlemeetid !== null) {
            $where .= ' AND m.id = :meeting';
            $params['meeting'] = $googlemeetid;
        }
        $events = $DB->get_records_sql("SELECT e.* FROM {googlemeet_events} e
            JOIN {googlemeet} m ON m.id = e.googlemeetid WHERE $where", $params);
        foreach ($events as $event) {
            $factory = \core\lock\lock_config::get_lock_factory('mod_googlemeet_attendance');
            $synclock = $factory->get_lock('sync_' . $event->googlemeetid, 0);
            if (!$synclock) {
                continue;
            }
            $lock = $factory->get_lock('roster_' . $event->id, 0);
            if (!$lock) {
                $synclock->release();
                continue;
            }
            try {
                $current = $DB->get_record('googlemeet', ['id' => $event->googlemeetid]);
                $event = $DB->get_record('googlemeet_events', ['id' => $event->id]);
                if (!$current || !$current->attendanceenabled || !$event || $event->cancelled || $event->snapshotat ||
                        $event->eventdate < $current->attendancefrom) {
                    continue;
                }
                $cm = get_coursemodule_from_instance('googlemeet', $event->googlemeetid, 0, false, MUST_EXIST);
                $context = \context_module::instance($cm->id);
                $students = get_enrolled_users($context, 'moodle/course:isincompletionreports', 0, 'u.*', null, 0, 0, true);
                // A delayed cron must not add enrolments created after the class started.
                // Current enrolment/role state is the available evidence; Moodle does not retain role history here.
                $eligible = $DB->get_records_sql('SELECT DISTINCT ue.userid AS id
                    FROM {user_enrolments} ue JOIN {enrol} en ON en.id = ue.enrolid
                    WHERE en.courseid = :course AND en.status = :enabled AND ue.status = :active
                        AND ue.timecreated <= :created AND (ue.timestart = 0 OR ue.timestart <= :started)
                        AND (ue.timeend = 0 OR ue.timeend > :ended)',
                    ['course' => $cm->course, 'enabled' => ENROL_INSTANCE_ENABLED, 'active' => ENROL_USER_ACTIVE,
                        'created' => $event->eventdate, 'started' => $event->eventdate, 'ended' => $event->eventdate]);
                $transaction = $DB->start_delegated_transaction();
                foreach ($students as $student) {
                    if (!isset($eligible[$student->id])) {
                        continue;
                    }
                    if (!$DB->record_exists('googlemeet_roster', ['eventid' => $event->id, 'userid' => $student->id])) {
                        $DB->insert_record('googlemeet_roster', ['eventid' => $event->id, 'userid' => $student->id]);
                    }
                }
                $DB->set_field('googlemeet_events', 'snapshotat', time(), ['id' => $event->id]);
                $transaction->allow_commit();
            } finally {
                $lock->release();
                $synclock->release();
            }
        }
    }

    /** Import all eligible recent classes, using only the organiser's authorised client. */
    public static function sync(int $googlemeetid, ?meet_client $client = null): void {
        global $DB;
        $meeting = $DB->get_record('googlemeet', ['id' => $googlemeetid], '*', MUST_EXIST);
        if (empty($meeting->attendanceenabled)) {
            return;
        }
        self::capture_rosters($googlemeetid);
        $factory = \core\lock\lock_config::get_lock_factory('mod_googlemeet_attendance');
        $lock = $factory->get_lock('sync_' . $googlemeetid, 0);
        if (!$lock) {
            return;
        }
        try {
            $meeting = $DB->get_record('googlemeet', ['id' => $googlemeetid]);
            if (!$meeting || !$meeting->attendanceenabled) {
                return;
            }
            try {
                $client = $client ?? new meet_client(authorization::organizer_client($meeting));
            } catch (\Throwable $e) {
                if (self::authorization_error($e)) {
                    $DB->set_field('googlemeet', 'authrequired', 1, ['id' => $googlemeetid]);
                }
                throw $e;
            }
            $code = basename(parse_url($meeting->url, PHP_URL_PATH) ?? '');
            $events = $DB->get_records_select('googlemeet_events',
                'googlemeetid = :meeting AND cancelled = 0 AND eventdate >= :activation AND eventdate <= :now',
                ['meeting' => $googlemeetid, 'activation' => $meeting->attendancefrom, 'now' => time()], 'eventdate');
            foreach ($events as $event) {
                // Google expires conference records after 30 days. Keep local evidence indefinitely.
                if ($event->eventdate + $event->duration < time() - 30 * DAYSECS) {
                    continue;
                }
                $interval = time() - ($event->eventdate + $event->duration) < DAYSECS ? HOURSECS : DAYSECS;
                if ($event->syncstatus === 'resolved' && $event->lastsync > time() - $interval) {
                    continue;
                }
                try {
                    $records = $client->conferences($code, $event->eventdate, $event->eventdate + $event->duration);
                    $data = [];
                    foreach ($records as $conference) {
                        $participants = [];
                        foreach ($client->participants($conference->name) as $participant) {
                            $participants[] = [$participant, $client->sessions($participant->name)];
                        }
                        $data[] = [$conference, $participants];
                    }
                    self::store($meeting, $event, $data);
                } catch (\Throwable $e) {
                    $auth = self::authorization_error($e);
                    $DB->update_record('googlemeet_events', (object)['id' => $event->id,
                        'syncstatus' => $event->syncstatus === 'resolved' ? 'resolved' : ($auth ? 'authrequired' : 'error'),
                        'lastsync' => time(),
                        'syncerror' => $auth ? 'authorization_required' : 'synchronization_failed']);
                    if ($auth) {
                        $DB->set_field('googlemeet', 'authrequired', 1, ['id' => $meeting->id]);
                        return;
                    }
                    throw $e;
                }
            }
            self::recalculate($googlemeetid);
        } finally {
            $lock->release();
        }
    }

    /** Write only after all pages succeeded; never delete earlier evidence. */
    private static function store(\stdClass $meeting, \stdClass $event, array $data): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $resolved = !empty($data) && time() >= $event->eventdate + $event->duration;
        foreach ($data as [$conference, $participants]) {
            if (!$participants) {
                $resolved = false;
            }
            $conf = (object)['googlemeetid' => $meeting->id, 'resource' => $conference->name,
                'space' => $conference->space ?? '', 'timestart' => self::timestamp($conference->startTime),
                'timeend' => self::timestamp($conference->endTime ?? '')];
            $conf->id = self::upsert('googlemeet_conference', $conf, ['googlemeetid', 'resource']);
            if (!$conf->timeend) {
                $resolved = false;
            }
            if (!$DB->record_exists('googlemeet_eventconf', ['eventid' => $event->id, 'conferenceid' => $conf->id])) {
                $DB->insert_record('googlemeet_eventconf', ['eventid' => $event->id, 'conferenceid' => $conf->id]);
            }
            foreach ($participants as [$participant, $sessions]) {
                // Privacy tombstones suppress reimport, including session details.
                if ($DB->record_exists('googlemeet_participant', ['conferenceid' => $conf->id,
                        'resource' => 'deleted:' . hash('sha256', $participant->name)])) {
                    continue;
                }
                $old = $DB->get_record('googlemeet_participant', ['conferenceid' => $conf->id, 'resource' => $participant->name]);
                $sub = preg_replace('~^users/~', '', $participant->signedinUser->user ?? '');
                $identity = $sub !== '' ? $DB->get_record('googlemeet_identity', [
                    'googlesub' => $sub, 'issuerid' => $meeting->attendanceissuerid]) : false;
                $person = (object)['conferenceid' => $conf->id, 'resource' => $participant->name,
                    'googlesub' => $sub, 'displayname' => $participant->signedinUser->displayName ??
                        $participant->anonymousUser->displayName ?? $participant->phoneUser->displayName ?? '',
                    'userid' => $identity ? $identity->userid : 0, 'mappingstatus' => $identity ? 'auto' : 'pending',
                    'mappedby' => 0, 'mappedat' => $identity ? time() : 0];
                if ($sub !== '' && hash_equals((string)$meeting->organizersub, $sub)) {
                    $person->userid = $meeting->organizerid;
                    $person->mappingstatus = 'auto';
                    $person->mappedat = time();
                }
                // An established match belongs to the historical participant, not to today's global identity owner.
                if ($old && (in_array($old->mappingstatus, ['manual', 'external', 'deleted'], true) ||
                        ($old->mappingstatus === 'auto' && $old->userid > 0))) {
                    foreach (['userid', 'mappingstatus', 'mappedby', 'mappedat'] as $field) {
                        $person->$field = $old->$field;
                    }
                }
                $person->id = self::upsert('googlemeet_participant', $person, ['conferenceid', 'resource']);
                if (!$sessions) {
                    $resolved = false;
                }
                foreach ($sessions as $session) {
                    $record = (object)['participantid' => $person->id, 'resource' => $session->name,
                        'timestart' => self::timestamp($session->startTime), 'timeend' => self::timestamp($session->endTime ?? '')];
                    self::upsert('googlemeet_session', $record, ['participantid', 'resource']);
                    if (!$record->timeend) {
                        $resolved = false;
                    }
                }
            }
        }
        // Empty responses do not invalidate previously complete, durable evidence.
        $status = $resolved ? 'resolved' : ($data || $event->syncstatus !== 'resolved' ? 'pending' : 'resolved');
        $DB->update_record('googlemeet_events', (object)['id' => $event->id, 'syncstatus' => $status,
            'lastsync' => time(), 'syncerror' => null]);
        $transaction->allow_commit();
    }

    /** Only authentication/permission failures disconnect the organiser. */
    private static function authorization_error(\Throwable $error): bool {
        return in_array((int)$error->getCode(), [401, 403], true) ||
            ($error instanceof \moodle_exception &&
                in_array($error->errorcode, ['attendanceauthrequired', 'invalidissuerid'], true));
    }

    /** Convert API RFC3339 timestamps, rejecting malformed dates. */
    private static function timestamp(string $value): int {
        if ($value === '') {
            return 0;
        }
        $time = strtotime($value);
        if ($time === false) {
            throw new \unexpectedvalueexception('Invalid Meet timestamp');
        }
        return $time;
    }

    /** Insert or update by the schema's stable resource key. */
    private static function upsert(string $table, \stdClass $record, array $keys): int {
        global $DB;
        $conditions = [];
        foreach ($keys as $key) {
            $conditions[$key] = $record->$key;
        }
        $id = $DB->get_field($table, 'id', $conditions);
        if ($id) {
            $record->id = $id;
            $DB->update_record($table, $record);
            return (int)$id;
        }
        return (int)$DB->insert_record($table, $record);
    }

    /**
     * Report one class. The caller must enforce report capabilities/user visibility.
     * Rows contain userid/name, seconds/total/percentage, first/last/sessions, late/early/status.
     */
    public static function event_report(int $eventid, ?int $userid = null): array {
        global $DB;
        $event = $DB->get_record('googlemeet_events', ['id' => $eventid], '*', MUST_EXIST);
        $meeting = $DB->get_record('googlemeet', ['id' => $event->googlemeetid], '*', MUST_EXIST);
        $participants = $DB->get_records_sql('SELECT p.* FROM {googlemeet_participant} p
            JOIN {googlemeet_eventconf} ec ON ec.conferenceid = p.conferenceid WHERE ec.eventid = :event', ['event' => $eventid]);
        $sessions = $DB->get_records_sql('SELECT s.* FROM {googlemeet_session} s
            JOIN {googlemeet_participant} p ON p.id = s.participantid
            JOIN {googlemeet_eventconf} ec ON ec.conferenceid = p.conferenceid
            WHERE ec.eventid = :event', ['event' => $eventid]);
        $byparticipant = [];
        foreach ($sessions as $session) {
            $byparticipant[$session->participantid][] = $session;
        }
        $people = [];
        $pending = false;
        foreach ($participants as $participant) {
            $participant->sessionsdata = $byparticipant[$participant->id] ?? [];
            $pending = $pending || $participant->mappingstatus === 'pending';
            $key = $participant->userid ? 'u' . $participant->userid :
                ($participant->googlesub !== '' ? 'g' . $participant->googlesub : 'p' . $participant->id);
            $people[$key] = array_merge($people[$key] ?? [], $participant->sessionsdata);
        }
        $roster = $DB->get_records_sql('SELECT u.* FROM {user} u
            JOIN {googlemeet_roster} r ON r.userid = u.id WHERE r.eventid = :event', ['event' => $eventid]);
        $identities = $DB->get_records_sql('SELECT i.userid AS id FROM {googlemeet_identity} i
            JOIN {googlemeet_roster} r ON r.userid = i.userid
            WHERE r.eventid = :event AND i.issuerid = :issuer',
            ['event' => $eventid, 'issuer' => $meeting->attendanceissuerid]);
        $rows = [];
        $summary = ['attended' => 0, 'absent' => 0, 'pending' => 0];
        foreach ($roster as $user) {
            if ($userid !== null && $user->id != $userid) {
                continue;
            }
            $sessions = $people['u' . $user->id] ?? [];
            $row = calculator::calculate($sessions, $event->eventdate, $event->eventdate + $event->duration, $meeting->lategrace);
            $identity = isset($identities[$user->id]);
            $ready = $event->syncstatus === 'resolved' && $event->snapshotat && !$event->cancelled &&
                $event->eventdate + $event->duration <= time();
            $status = 'pending';
            if ($ready && $row['seconds'] * 100 >= $event->duration * $meeting->attendancethreshold) {
                $status = 'attended';
            } else if ($ready && !$pending && ($identity || $sessions)) {
                $status = 'absent';
            }
            $row += ['userid' => (int)$user->id, 'name' => fullname($user), 'status' => $status];
            $rows[] = $row;
            $summary[$status]++;
        }
        $summary['meanseconds'] = $rows ? array_sum(array_column($rows, 'seconds')) / count($rows) : 0;
        $summary += calculator::concurrency($people, $event->eventdate, $event->eventdate + $event->duration);
        if ($userid !== null) {
            $participants = array_filter($participants, static function($p) use ($userid) { return $p->userid == $userid; });
            // Learner reports do not expose other people's concurrency or cohort counts.
            $summary = array_intersect_key($summary, array_flip(['attended', 'absent', 'pending', 'meanseconds']));
        }
        return ['event' => $event, 'rows' => $rows, 'participants' => array_values($participants), 'summary' => $summary];
    }

    /** Final completion is available only after the whole eligible series is resolved. */
    public static function completion_state(int $googlemeetid, int $userid): bool {
        global $DB;
        if (array_key_exists($googlemeetid, self::$completioncache)) {
            return self::$completioncache[$googlemeetid][$userid] ?? false;
        }
        $meeting = $DB->get_record('googlemeet', ['id' => $googlemeetid], '*', MUST_EXIST);
        return self::completion_results($meeting)[$userid] ?? false;
    }

    /** Evaluate the complete series once for all roster users. */
    private static function completion_results(\stdClass $meeting): array {
        global $DB;
        if (!$meeting->attendanceenabled || !$meeting->completionattendance) {
            return [];
        }
        $events = $DB->get_records_select('googlemeet_events',
            'googlemeetid = :meeting AND cancelled = 0 AND eventdate >= :activation',
            ['meeting' => $meeting->id, 'activation' => $meeting->attendancefrom]);
        $totals = [];
        $attended = [];
        foreach ($events as $event) {
            if ($event->eventdate + $event->duration > time() || !$event->snapshotat || $event->syncstatus !== 'resolved') {
                return [];
            }
            $report = self::event_report($event->id);
            foreach ($report['participants'] as $participant) {
                if ($participant->mappingstatus === 'pending') {
                    return [];
                }
            }
            foreach ($report['rows'] as $row) {
                if ($row['status'] === 'pending') {
                    return [];
                }
                $userid = $row['userid'];
                $totals[$userid] = ($totals[$userid] ?? 0) + 1;
                $attended[$userid] = ($attended[$userid] ?? 0) + ($row['status'] === 'attended' ? 1 : 0);
            }
        }
        $results = [];
        foreach ($totals as $userid => $total) {
            $results[$userid] = 100 * $attended[$userid] >= $meeting->completionattendance * $total;
        }
        return $results;
    }

    /** Notify Moodle completion after sync or an authorised correction. */
    public static function recalculate(int $googlemeetid): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        $meeting = $DB->get_record('googlemeet', ['id' => $googlemeetid], '*', MUST_EXIST);
        // Linking an identity can resolve durable evidence after Google has expired its copy.
        $pending = $DB->get_records_sql('SELECT p.* FROM {googlemeet_participant} p
            JOIN {googlemeet_conference} c ON c.id = p.conferenceid
            WHERE c.googlemeetid = :meeting AND p.mappingstatus = :pending',
            ['meeting' => $googlemeetid, 'pending' => 'pending']);
        foreach ($pending as $participant) {
            if ($participant->googlesub === '') {
                continue;
            }
            $identity = $DB->get_record('googlemeet_identity', ['googlesub' => $participant->googlesub,
                'issuerid' => $meeting->attendanceissuerid]);
            if ($identity) {
                // Preserve a concurrent manual decision or privacy tombstone.
                $DB->execute('UPDATE {googlemeet_participant} SET userid = :userid,
                    mappingstatus = :automatic, mappedat = :now WHERE id = :id AND mappingstatus = :pending',
                    ['userid' => $identity->userid, 'automatic' => 'auto', 'now' => time(),
                        'id' => $participant->id, 'pending' => 'pending']);
            }
        }
        if (!$meeting->attendanceenabled || !$meeting->completionattendance) {
            return;
        }
        $cm = get_coursemodule_from_instance('googlemeet', $googlemeetid, $meeting->course, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $meeting->course], '*', MUST_EXIST);
        $completion = new \completion_info($course);
        if (!$completion->is_enabled($cm)) {
            return;
        }
        $users = $DB->get_records_sql('SELECT DISTINCT r.userid AS id FROM {googlemeet_roster} r
            JOIN {googlemeet_events} e ON e.id = r.eventid WHERE e.googlemeetid = :meeting', ['meeting' => $googlemeetid]);
        // Moodle calls completion_state separately per user; share this series evaluation only for this batch.
        self::$completioncache[$googlemeetid] = self::completion_results($meeting);
        try {
            foreach ($users as $user) {
                $completion->update_state($cm, COMPLETION_UNKNOWN, $user->id);
            }
        } finally {
            unset(self::$completioncache[$googlemeetid]);
        }
    }
}
