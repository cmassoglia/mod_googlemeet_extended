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
 * Backup steps for mod_googlemeet are defined here.
 *
 * @package     mod_googlemeet
 * @subpackage  backup-moodle2
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Define the complete structure for backup, with file and id annotations.
 */
class backup_googlemeet_activity_structure_step extends backup_activity_structure_step {

    /**
     * Defines the structure of the resulting xml file.
     *
     * @return backup_nested_element The structure wrapped by the common 'activity' element.
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        // Replace with the attributes and final elements that the element will handle.
        $googlemeet = new backup_nested_element('googlemeet', ['id'], [
            'name',
            'originalname',
            'url',
            'intro',
            'introformat',
            'lastsync',
            'eventdate',
            'starthour',
            'startminute',
            'endhour',
            'endminute',
            'addmultiply',
            'days',
            'period',
            'eventenddate',
            'notify',
            'minutesbefore',
            'timemodified',
            'attendanceenabled', 'attendancefrom', 'attendancethreshold', 'completionattendance', 'lategrace', 'authrequired'
        ]);

        $events = new backup_nested_element('events');
        $event = new backup_nested_element('event', ['id'], [
            'eventdate',
            'duration', 'cancelled', 'timemodified',
            ...($userinfo ? ['snapshotat', 'syncstatus', 'lastsync', 'syncerror'] : [])
        ]);

        $recordings = new backup_nested_element('recordings');
        $recording = new backup_nested_element('recording', ['id'], [
            'recordingid',
            'name',
            'createdtime',
            'duration',
            'webviewlink',
            'visible',
            'timemodified'
        ]);

        // Build the tree in the order needed for restore.
        $googlemeet->add_child($events);
        $events->add_child($event);

        $googlemeet->add_child($recordings);
        $recordings->add_child($recording);

        // Define the source tables for the elements.
        $googlemeet->set_source_table('googlemeet', ['id' => backup::VAR_ACTIVITYID]);

        $event->set_source_table('googlemeet_events', ['googlemeetid' => backup::VAR_PARENTID]);

        if ($userinfo) {
            $recording->set_source_table('googlemeet_recordings', ['googlemeetid' => backup::VAR_PARENTID]);
        }

        // Flat containers are ordered so restore can remap every parent before its children.
        if ($userinfo) {
            $definitions = [
                'conference' => ['resource', 'space', 'timestart', 'timeend'],
                'participant' => ['conferenceid', 'resource', 'googlesub', 'displayname', 'userid',
                    'mappingstatus', 'mappedby', 'mappedat', 'mappingreason'],
                'session' => ['participantid', 'resource', 'timestart', 'timeend'],
                'eventconf' => ['eventid', 'conferenceid'],
                'roster' => ['eventid', 'userid'],
                'audit' => ['eventid', 'participantid', 'userid', 'subjectid', 'previoususerid', 'action', 'reason', 'timecreated'],
            ];
            foreach ($definitions as $table => $fields) {
                $container = new backup_nested_element($table . 's');
                $element = new backup_nested_element($table, ['id'], $fields);
                $googlemeet->add_child($container);
                $container->add_child($element);
                if (in_array($table, ['conference', 'audit'])) {
                    $element->set_source_table('googlemeet_' . $table, ['googlemeetid' => backup::VAR_PARENTID]);
                } else if ($table === 'participant') {
                    $element->set_source_sql("SELECT p.* FROM {googlemeet_participant} p
                        JOIN {googlemeet_conference} c ON c.id = p.conferenceid WHERE c.googlemeetid = ?",
                        [backup::VAR_ACTIVITYID]);
                } else if ($table === 'session') {
                    $element->set_source_sql("SELECT s.* FROM {googlemeet_session} s
                        JOIN {googlemeet_participant} p ON p.id = s.participantid
                        JOIN {googlemeet_conference} c ON c.id = p.conferenceid WHERE c.googlemeetid = ?",
                        [backup::VAR_ACTIVITYID]);
                } else {
                    $element->set_source_sql("SELECT t.* FROM {googlemeet_" . $table . "} t
                        JOIN {googlemeet_events} e ON e.id = t.eventid WHERE e.googlemeetid = ?",
                        [backup::VAR_ACTIVITYID]);
                }
                if (in_array('userid', $fields)) {
                    $element->annotate_ids('user', 'userid');
                }
                foreach (['mappedby', 'subjectid', 'previoususerid'] as $userfield) {
                    if (in_array($userfield, $fields)) {
                        $element->annotate_ids('user', $userfield);
                    }
                }
            }
        }

        // Define id annotations.

        // Define file annotations.
        $googlemeet->annotate_files('mod_googlemeet', 'intro', null); // This file area hasn't itemid.

        return $this->prepare_activity_structure($googlemeet);
    }
}
