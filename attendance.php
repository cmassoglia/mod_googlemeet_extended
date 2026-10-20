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
/** Attendance report and audited staff review. */
require(__DIR__ . '/../../config.php');

use mod_googlemeet\attendance\service;

$id = optional_param('id', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$eventid = optional_param('eventid', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);
if ($id) {
    $cm = get_coursemodule_from_id('googlemeet', $id, 0, false, MUST_EXIST);
    if ($courseid && $courseid !== (int)$cm->course) {
        throw new moodle_exception('invalidcoursemodule');
    }
    $courseid = $cm->course;
}
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
require_login($course);
$PAGE->set_context(context_course::instance($courseid));
$PAGE->set_url('/mod/googlemeet/attendance.php', array_filter(['id' => $id, 'courseid' => $courseid,
    'eventid' => $eventid, 'userid' => $userid]));
$PAGE->set_title(get_string('attendance', 'googlemeet'));
$PAGE->set_heading(format_string($course->fullname));
$activities = [];
foreach (get_fast_modinfo($course)->get_instances_of('googlemeet') as $module) {
    if (($id && $module->id != $id) || !$module->uservisible) {
        continue;
    }
    $context = context_module::instance($module->id);
    if (!has_capability('mod/googlemeet:viewattendance', $context) &&
            !has_capability('mod/googlemeet:viewownattendance', $context)) {
        continue;
    }
    $meet = $DB->get_record('googlemeet', ['id' => $module->instance], '*', MUST_EXIST);
    if (!empty($meet->attendanceenabled)) {
        $activities[$meet->id] = [$module, $context, $meet];
    }
}
if ($id && !$activities) {
    throw new moodle_exception('attendanceunavailable', 'googlemeet');
}
$action = optional_param('action', '', PARAM_ALPHA);
if ($action && data_submitted()) {
    require_sesskey();
    if (!$id || count($activities) !== 1) {
        throw new moodle_exception('invalidcoursemodule');
    }
    [$module, $context, $meet] = reset($activities);
    require_capability('mod/googlemeet:manageattendance', $context);
    $factory = \core\lock\lock_config::get_lock_factory('mod_googlemeet_attendance');
    $lock = $factory->get_lock('sync_' . $meet->id, 10);
    if (!$lock) {
        throw new moodle_exception('locktimeout');
    }
    try {
        if ($action === 'sync') {
            service::queue_sync((int)$meet->id);
        } else {
            $reason = trim(required_param('reason', PARAM_TEXT));
            if ($reason === '') {
                throw new moodle_exception('attendancereasonrequired', 'googlemeet');
            }
            $event = $DB->get_record('googlemeet_events', ['id' => $eventid, 'googlemeetid' => $meet->id], '*', MUST_EXIST);
            $transaction = $DB->start_delegated_transaction();
            $participantid = 0;
            if ($action === 'cancel') {
                $DB->set_field('googlemeet_events', 'cancelled', 1, ['id' => $event->id]);
            } else if ($action === 'map') {
                $participantid = required_param('participantid', PARAM_INT);
                $participant = $DB->get_record_sql('SELECT p.* FROM {googlemeet_participant} p
                    JOIN {googlemeet_conference} c ON c.id = p.conferenceid
                    JOIN {googlemeet_eventconf} ec ON ec.conferenceid = c.id
                    WHERE p.id = :id AND ec.eventid = :event AND c.googlemeetid = :meet',
                    ['id' => $participantid, 'event' => $event->id, 'meet' => $meet->id], MUST_EXIST);
                if ($participant->mappingstatus === 'deleted') {
                    throw new moodle_exception('invalidparameter');
                }
                $mappeduser = required_param('mappeduser', PARAM_INT);
                if ($mappeduser && !$DB->record_exists_sql('SELECT r.id FROM {googlemeet_roster} r
                        JOIN {googlemeet_eventconf} ec ON ec.eventid = r.eventid
                        WHERE ec.conferenceid = :conference AND r.userid = :userid',
                        ['conference' => $participant->conferenceid, 'userid' => $mappeduser])) {
                    throw new moodle_exception('attendanceinvalidstudent', 'googlemeet');
                }
                $DB->update_record('googlemeet_participant', (object)['id' => $participantid, 'userid' => $mappeduser,
                    'mappingstatus' => $mappeduser ? 'manual' : 'external', 'mappedby' => $USER->id,
                    'mappedat' => time(), 'mappingreason' => $reason]);
            } else {
                throw new moodle_exception('invalidparameter');
            }
            $DB->insert_record('googlemeet_audit', (object)['googlemeetid' => $meet->id, 'eventid' => $event->id,
                'participantid' => $participantid, 'userid' => $USER->id, 'action' => $action,
                'subjectid' => $action === 'map' ? $mappeduser : 0,
                'previoususerid' => $action === 'map' ? $participant->userid : 0,
                'reason' => $reason,
                'timecreated' => time()]);
            $transaction->allow_commit();
            service::recalculate((int)$meet->id);
        }
    } finally {
        $lock->release();
    }
    redirect($PAGE->url);
}
$sections = [];
$studenttotals = [];
$csv = [];
$eventfound = !$eventid;
foreach ($activities as [$module, $context, $meet]) {
    $staff = has_capability('mod/googlemeet:viewattendance', $context);
    $manage = has_capability('mod/googlemeet:manageattendance', $context);
    if (!$staff && $userid && $userid !== (int)$USER->id) {
        throw new required_capability_exception($context, 'mod/googlemeet:viewattendance', 'nopermissions', '');
    }
    $filteruser = $staff ? ($userid ?: null) : (int)$USER->id;
    $events = $DB->get_records('googlemeet_events', ['googlemeetid' => $meet->id], 'eventdate ASC');
    $section = ['identitylinked' => $DB->record_exists('googlemeet_identity', ['userid' => $USER->id,
        'issuerid' => $meet->attendanceissuerid]),
        'name' => format_string($meet->name), 'events' => [], 'manage' => $manage,
        'authrequired' => !empty($meet->authrequired), 'id' => $module->id, 'sesskey' => sesskey(),
        'identityurl' => (new moodle_url('/mod/googlemeet/attendance_identity.php', ['id' => $module->id]))->out(false),
        'organizerurl' => (new moodle_url('/mod/googlemeet/attendance_identity.php', ['id' => $module->id, 'organizer' => 1]))->out(false)];
    foreach ($events as $event) {
        if (($eventid && $eventid !== (int)$event->id) || $event->eventdate < $meet->attendancefrom) {
            continue;
        }
        $eventfound = true;
        $report = service::event_report((int)$event->id, $filteruser);
        $item = ['eventurl' => (new moodle_url('/mod/googlemeet/attendance.php',
            array_filter(['id' => $module->id, 'eventid' => $event->id, 'userid' => $filteruser])))->out(false),
            'date' => userdate($event->eventdate), 'eventid' => $event->id, 'id' => $module->id,
            'sesskey' => sesskey(), 'manage' => $manage, 'cancelled' => !empty($event->cancelled),
            'syncstatus' => get_string('attendancestate', 'googlemeet',
                get_string('attendancesyncstate' . $event->syncstatus, 'googlemeet')),
            'lastsync' => $event->lastsync ? userdate($event->lastsync) : get_string('never', 'googlemeet'),
            'syncerror' => $event->syncerror ? get_string('attendancesyncfailed', 'googlemeet') : '',
            'rows' => [], 'participants' => []];
        foreach ($report['rows'] as $row) {
            $row = (array)$row;
            // Enforce own-record restriction even if the service contract changes.
            if ($filteruser && (int)$row['userid'] !== $filteruser) {
                continue;
            }
            $status = (string)$row['status'];
            $label = get_string_manager()->string_exists('attendancestatus' . $status, 'googlemeet') ?
                get_string('attendancestatus' . $status, 'googlemeet') : $status;
            $intervals = $DB->get_records_sql('SELECT s.* FROM {googlemeet_session} s
                JOIN {googlemeet_participant} p ON p.id = s.participantid
                JOIN {googlemeet_eventconf} ec ON ec.conferenceid = p.conferenceid
                WHERE ec.eventid = :event AND p.userid = :userid ORDER BY s.timestart',
                ['event' => $event->id, 'userid' => $row['userid']]);
            $details = [];
            foreach ($intervals as $interval) {
                $details[] = ['start' => userdate($interval->timestart),
                    'end' => $interval->timeend ? userdate($interval->timeend) : get_string('attendancestatuspending', 'googlemeet')];
            }
            if (empty($event->cancelled)) {
                $key = $row['userid'];
                if (!isset($studenttotals[$key])) {
                    $studenttotals[$key] = ['name' => $row['name'], 'attended' => 0, 'absent' => 0, 'pending' => 0,
                        'classes' => 0, 'studenturl' => (new moodle_url('/mod/googlemeet/attendance.php',
                            ['courseid' => $courseid, 'userid' => $row['userid']]))->out(false)];
                }
                $studenttotals[$key]['classes']++;
                $studenttotals[$key][$status]++;
            }
            $item['rows'][] = ['first' => $row['first'] ? userdate($row['first']) : '–',
                'last' => $row['last'] ? userdate($row['last']) : '–',
                'sessions' => $row['sessions'], 'totalminutes' => round($row['total'] / 60, 1),
                'name' => $row['name'], 'minutes' => round($row['seconds'] / 60, 1),
                'percentage' => round($row['percentage'], 1), 'status' => $label,
                'late' => !empty($row['late']), 'early' => !empty($row['early']), 'intervals' => $details,
                'studenturl' => (new moodle_url('/mod/googlemeet/attendance.php',
                    ['courseid' => $courseid, 'userid' => $row['userid']]))->out(false)];
            $csv[] = [$meet->name, userdate($event->eventdate), $row['userid'], $row['name'],
                round($row['seconds'] / 60, 1), round($row['percentage'], 1), $label];
        }
        if ($staff) {
            $item['summary'] = [];
            foreach ($report['summary'] as $key => $value) {
                $item['summary'][] = ['label' => get_string('attendancesummary' . $key, 'googlemeet'), 'value' => $key === 'meanseconds' ? round($value / 60, 1) : round($value, 1)];
            }
            foreach ($report['participants'] as $participant) {
                $participant = (object)$participant;
                if ($participant->mappingstatus === 'deleted') {
                    continue;
                }
                $eligible = $DB->get_records_sql('SELECT DISTINCT u.id, u.firstname, u.lastname FROM {user} u
                    JOIN {googlemeet_roster} r ON r.userid = u.id
                    JOIN {googlemeet_eventconf} ec ON ec.eventid = r.eventid
                    WHERE ec.conferenceid = :conference ORDER BY u.lastname, u.firstname',
                    ['conference' => $participant->conferenceid]);
                $options = [];
                foreach ($eligible as $student) {
                    $options[] = ['id' => $student->id, 'name' => fullname($student),
                        'selected' => (int)$participant->userid === (int)$student->id];
                }
                $item['participants'][] = ['id' => $participant->id, 'name' => $participant->displayname,
                    'subject' => $participant->googlesub,
                    'mapping' => get_string('attendancemapping' . $participant->mappingstatus, 'googlemeet'),
                    'options' => $options, 'manage' => $manage, 'cmid' => $module->id,
                    'eventid' => $event->id, 'sesskey' => sesskey()];
            }
        }
        $section['events'][] = $item;
    }
    $sections[] = $section;
}
if (!$eventfound) {
    throw new moodle_exception('invalidparameter');
}
if ($download === 'csv') {
    require_once($CFG->libdir . '/csvlib.class.php');
    $export = new csv_export_writer();
    $export->set_filename('googlemeet-attendance');
    $export->add_data(array_map(fn($key) => get_string($key, 'googlemeet'),
        ['roomname', 'date', 'attendanceuserid', 'attendancestudent', 'attendanceminutes', 'attendancepercentage', 'attendancestatus']));
    foreach ($csv as $record) {
        $export->add_data(array_map(function($cell) {
            $cell = (string)$cell;
            return preg_match('/^[\s]*[=+@\-]/u', $cell) ? "'" . $cell : $cell;
        }, $record));
    }
    $export->download_file();
    exit;
}
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('attendance', 'googlemeet'));
echo $OUTPUT->notification(get_string('attendancependingnotice', 'googlemeet'), 'info');
echo html_writer::link(new moodle_url('/mod/googlemeet/attendance.php', ['courseid' => $courseid]),
    get_string('attendancecourse', 'googlemeet'), ['class' => 'btn btn-outline-primary mb-3 mr-2']);
$exporturl = new moodle_url($PAGE->url, ['download' => 'csv']);
echo html_writer::link($exporturl, get_string('attendanceexport', 'googlemeet'), ['class' => 'btn btn-secondary mb-3']);
echo $OUTPUT->render_from_template('mod_googlemeet/attendance', ['sections' => $sections, 'students' => array_values($studenttotals)]);
echo $OUTPUT->footer();
