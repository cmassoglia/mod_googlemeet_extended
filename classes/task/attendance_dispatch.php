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
 * Dispatch attendance imports.
 *
 * @package mod_googlemeet
 * @copyright 2026 MindFree
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_googlemeet\task;
defined('MOODLE_INTERNAL') || die();

/** Five-minute dispatcher; does not make Google requests as the cron user. */
class attendance_dispatch extends \core\task\scheduled_task {
    /** Task name. */
    public function get_name() {
        return get_string('attendancedispatch', 'mod_googlemeet');
    }

    /** Capture roster and queue imports as their organisers. */
    public function execute() {
        global $DB;
        \mod_googlemeet\attendance\service::capture_rosters();
        $meetings = $DB->get_records('googlemeet', ['attendanceenabled' => 1, 'authrequired' => 0]);
        foreach ($meetings as $meeting) {
            try {
                \mod_googlemeet\attendance\service::queue_sync($meeting->id);
            } catch (\moodle_exception $e) {
                // An inactive/deleted organiser requires explicit reauthorisation.
                $DB->set_field('googlemeet', 'authrequired', 1, ['id' => $meeting->id]);
                mtrace('Google Meet attendance: organiser unavailable for activity ' . $meeting->id);
            }
        }
    }
}
