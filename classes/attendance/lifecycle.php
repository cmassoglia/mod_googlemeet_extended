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
namespace mod_googlemeet\attendance;

/** Removal of activity-scoped imported attendance; identities belong to users globally. */
class lifecycle {
    /** Acquire the same activity lock as the importer before a destructive mutation. */
    public static function lock_activity(int $id): \core\lock\lock {
        $factory = \core\lock\lock_config::get_lock_factory('mod_googlemeet_attendance');
        $lock = $factory->get_lock('sync_' . $id, 10);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }
        return $lock;
    }

    public static function delete_activity(int $id): void {
        global $DB;
        $lock = self::lock_activity($id);
        try {
            $transaction = $DB->start_delegated_transaction();
            // Prevent already queued imports from recreating evidence after this lock is released.
            $DB->set_field('googlemeet', 'attendanceenabled', 0, ['id' => $id]);
            self::delete_history($id);
            $transaction->allow_commit();
        } finally {
            $lock->release();
        }
    }

    /** Delete children before parents; caller owns the activity lock and transaction. */
    private static function delete_history(int $id): void {
        global $DB;
        $params = ['activityid' => $id];
        $conferences = "SELECT id FROM {googlemeet_conference} WHERE googlemeetid = :activityid";
        $participants = "SELECT id FROM {googlemeet_participant} WHERE conferenceid IN ($conferences)";
        $events = "SELECT id FROM {googlemeet_events} WHERE googlemeetid = :activityid";
        $DB->delete_records('googlemeet_audit', ['googlemeetid' => $id]);
        $DB->delete_records_select('googlemeet_roster', "eventid IN ($events)", $params);
        $DB->delete_records_select('googlemeet_eventconf', "eventid IN ($events)", $params);
        $DB->delete_records_select('googlemeet_session', "participantid IN ($participants)", $params);
        $DB->delete_records_select('googlemeet_participant', "conferenceid IN ($conferences)", $params);
        $DB->delete_records('googlemeet_conference', ['googlemeetid' => $id]);
    }

    public static function reset_activity(int $id): void {
        global $DB;
        $lock = self::lock_activity($id);
        try {
            $transaction = $DB->start_delegated_transaction();
            $DB->update_record('googlemeet', (object)[
                'id' => $id, 'attendanceenabled' => 0, 'organizerid' => 0, 'organizersub' => '',
                'attendanceissuerid' => 0, 'authrequired' => 1,
            ]);
            self::delete_history($id);
            foreach ($DB->get_records('googlemeet_events', ['googlemeetid' => $id]) as $event) {
                $DB->update_record('googlemeet_events', (object)[
                    'id' => $event->id, 'snapshotat' => 0, 'syncstatus' => 'pending', 'lastsync' => 0, 'syncerror' => null,
                ]);
            }
            $transaction->allow_commit();
        } finally {
            $lock->release();
        }
    }
}
