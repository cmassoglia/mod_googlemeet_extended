<?php
// This file is part of Moodle - http://moodle.org/
// Moodle is free software under the GNU GPL v3 or later.

namespace mod_googlemeet;

use mod_googlemeet\completion\custom_completion;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->libdir . '/completionlib.php');

/** Moodle 4.5 custom completion contract.
 * @covers \mod_googlemeet\completion\custom_completion
 */
final class completion_test extends \advanced_testcase {
    public function test_rule_uses_real_attendance_state_and_remains_incomplete_without_evidence(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->setAdminUser();
        $module = $this->getDataGenerator()->create_module('googlemeet', ['course' => $course->id,
            'attendanceenabled' => 1, 'completionattendance' => 80, 'completion' => COMPLETION_TRACKING_AUTOMATIC]);
        $cm = get_fast_modinfo($course, $user->id)->get_cm($module->cmid);
        $completion = new custom_completion($cm, $user->id);
        $this->assertSame(['completionattendance'], $completion::get_defined_custom_rules());
        $this->assertSame(COMPLETION_INCOMPLETE, $completion->get_state('completionattendance'));
        $this->assertSame(['completionview', 'completionattendance'], $completion->get_sort_order());
        $this->assertArrayHasKey('completionattendance', $completion->get_custom_rule_descriptions());
    }

    public function test_undefined_rule_is_rejected_before_backend_access(): void {
        $this->resetAfterTest();
        $cm = $this->getMockBuilder(\cm_info::class)->disableOriginalConstructor()->getMock();
        $this->expectException(\coding_exception::class);
        (new custom_completion($cm, 1))->get_state('undefined');
    }

    public function test_disabled_rule_is_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $module = $this->getDataGenerator()->create_module('googlemeet', ['course' => $course->id,
            'completionattendance' => 0]);
        $cm = get_fast_modinfo($course)->get_cm($module->cmid);
        $this->expectException(\moodle_exception::class);
        (new custom_completion($cm, 1))->get_state('completionattendance');
    }
}
