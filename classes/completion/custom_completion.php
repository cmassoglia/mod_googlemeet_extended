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
namespace mod_googlemeet\completion;

use core_completion\activity_custom_completion;
use mod_googlemeet\attendance\service;

/** Attendance completion rule, evaluated against the imported series. */
class custom_completion extends activity_custom_completion {
    public function get_state(string $rule): int {
        $this->validate_rule($rule);
        return service::completion_state((int)$this->cm->instance, $this->userid)
            ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    public static function get_defined_custom_rules(): array {
        return ['completionattendance'];
    }

    public function get_custom_rule_descriptions(): array {
        $customdata = (array)$this->cm->customdata;
        $percentage = $customdata['customcompletionrules']['completionattendance'] ?? 0;
        return ['completionattendance' => get_string('completionattendance_desc', 'googlemeet', $percentage)];
    }

    public function get_sort_order(): array {
        return ['completionview', 'completionattendance'];
    }
}
