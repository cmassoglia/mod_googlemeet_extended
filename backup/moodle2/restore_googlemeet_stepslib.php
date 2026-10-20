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
 * All the steps to restore mod_googlemeet are defined here.
 *
 * @package     mod_googlemeet
 * @subpackage  backup-moodle2
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Defines the structure step to restore one mod_googlemeet activity.
 */
class restore_googlemeet_activity_structure_step extends restore_activity_structure_step {

    /**
     * Defines the structure to be restored.
     *
     * @return restore_path_element[].
     */
    protected function define_structure() {
        $paths = array();
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('googlemeet', '/activity/googlemeet');

        $paths[] = new restore_path_element('googlemeet_event',
            '/activity/googlemeet/events/event');

        $paths[] = new restore_path_element('googlemeet_recording',
            '/activity/googlemeet/recordings/recording');

        if ($userinfo) {
            foreach (['conference', 'participant', 'session', 'eventconf', 'roster', 'audit'] as $table) {
                $paths[] = new restore_path_element('googlemeet_' . $table,
                    '/activity/googlemeet/' . $table . 's/' . $table);
            }
        }
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Process a googlemeet restore.
     *
     * @param object $data The data in object form
     * @return void
     */
    protected function process_googlemeet($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();

        // Any changes to the list of dates that needs to be rolled should be same during course restore and course reset.
        // See MDL-9367.
        if (!$this->get_setting_value('userinfo') && $data->eventdate > time()) {
            $data->eventdate = $this->apply_date_offset($data->eventdate);
            $data->eventenddate = $this->apply_date_offset($data->eventenddate);
        }
        // OAuth bindings and automatic import must always be explicitly reconnected.
        $data->attendanceenabled = 0;
        $data->organizerid = 0;
        $data->organizersub = '';
        $data->attendanceissuerid = 0;
        $data->authrequired = 1;

        // Insert the googlemeet record.
        $newitemid = $DB->insert_record('googlemeet', $data);
        // Immediately after inserting "activity" record, call this.
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Process a event restore.
     *
     * @param object $data The data in object form
     * @return void
     */
    protected function process_googlemeet_event($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->googlemeetid = $this->get_new_parentid('googlemeet');
        if (!$this->get_setting_value('userinfo') && $data->eventdate > time()) {
            $data->eventdate = $this->apply_date_offset($data->eventdate);
        }

        $newitemid = $DB->insert_record('googlemeet_events', $data);
        $this->set_mapping('googlemeet_event', $oldid, $newitemid);
    }

    /**
     * Process a recording restore.
     *
     * @param object $data The data in object form
     * @return void
     */
    protected function process_googlemeet_recording($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->googlemeetid = $this->get_new_parentid('googlemeet');

        $newitemid = $DB->insert_record('googlemeet_recordings', $data);
        $this->set_mapping('googlemeet_recording', $oldid, $newitemid);
    }

    /** Restore an imported record, retaining its original timestamps. */
    protected function restore_attendance_record(string $table, $data): void {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        unset($data->id);
        if (in_array($table, ['conference', 'audit'])) {
            $data->googlemeetid = $this->get_new_parentid('googlemeet');
        }
        foreach (['eventid' => 'event', 'conferenceid' => 'conference', 'participantid' => 'participant'] as $field => $parent) {
            if (!empty($data->$field)) {
                $data->$field = $this->get_mappingid('googlemeet_' . $parent, $data->$field, 0);
            }
        }
        foreach (['userid', 'mappedby', 'subjectid', 'previoususerid'] as $field) {
            if (isset($data->$field)) {
                $data->$field = $this->get_mappingid('user', $data->$field, 0);
            }
        }
        // A user missing from the destination must never retain a source-site Moodle ID.
        if ($table === 'roster' && empty($data->userid)) {
            return;
        }
        $newid = $DB->insert_record('googlemeet_' . $table, $data);
        $this->set_mapping('googlemeet_' . $table, $oldid, $newid);
    }

    protected function process_googlemeet_conference($data) {
        $this->restore_attendance_record('conference', $data);
    }

    protected function process_googlemeet_participant($data) {
        $this->restore_attendance_record('participant', $data);
    }

    protected function process_googlemeet_session($data) {
        $this->restore_attendance_record('session', $data);
    }

    protected function process_googlemeet_eventconf($data) {
        $this->restore_attendance_record('eventconf', $data);
    }

    protected function process_googlemeet_roster($data) {
        $this->restore_attendance_record('roster', $data);
    }

    protected function process_googlemeet_audit($data) {
        $this->restore_attendance_record('audit', $data);
    }

    /**
     * Defines post-execution actions.
     */
    protected function after_execute() {
        $this->add_related_files('mod_googlemeet', 'intro', null);
    }
}
