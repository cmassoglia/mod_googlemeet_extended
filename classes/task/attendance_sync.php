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
 * Run attendance import as organiser.
 *
 * @package mod_googlemeet
 * @copyright 2026 MindFree
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_googlemeet\task;
defined('MOODLE_INTERNAL') || die();

/** Adhoc importer; Moodle applies exponential retry delays to transient failures. */
class attendance_sync extends \core\task\adhoc_task {
    /** Import under the user context selected by the dispatcher. */
    public function execute() {
        global $DB, $USER;
        $data = $this->get_custom_data();
        $meeting = $DB->get_record('googlemeet', ['id' => $data->googlemeetid]);
        if (!$meeting || !$meeting->attendanceenabled || $meeting->authrequired) {
            return;
        }
        if ((int)$USER->id !== (int)$meeting->organizerid || (int)$this->get_userid() !== (int)$meeting->organizerid) {
            $DB->set_field('googlemeet', 'authrequired', 1, ['id' => $meeting->id]);
            return;
        }
        try {
            \mod_googlemeet\attendance\service::sync($meeting->id);
        } catch (\Throwable $e) {
            if ($this->get_fail_delay() >= 480) {
                mtrace('Google Meet attendance: retry limit reached for activity ' . $meeting->id);
                return;
            }
            throw $e;
        }
    }
}
