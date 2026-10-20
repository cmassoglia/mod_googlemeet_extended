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
 * Calculator tests.
 *
 * @package mod_googlemeet
 * @copyright 2026 MindFree
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_googlemeet;
use mod_googlemeet\attendance\calculator;
defined('MOODLE_INTERNAL') || die();

/** @covers \mod_googlemeet\attendance\calculator */
final class attendance_calculator_test extends \basic_testcase {
    public function test_union_clips_devices_and_preserves_outside_time(): void {
        $sessions = [['timestart' => 50, 'timeend' => 150], ['timestart' => 120, 'timeend' => 220],
            ['timestart' => 230, 'timeend' => 300]];
        $row = calculator::calculate($sessions, 100, 250, 10);
        $this->assertSame(140, $row['seconds']);
        $this->assertSame(240, $row['total']);
        $this->assertSame(100, $row['first']);
        $this->assertSame(250, $row['last']);
        $this->assertSame(3, $row['sessions']);
        $this->assertFalse($row['late']);
        $this->assertFalse($row['early']);
    }

    public function test_reconnections_and_exact_grace_boundary(): void {
        $row = calculator::calculate([['timestart' => 700, 'timeend' => 1000],
            ['timestart' => 1100, 'timeend' => 1600]], 100, 2000);
        $this->assertSame(800, $row['seconds']);
        $this->assertFalse($row['late']);
        $this->assertTrue($row['early']);
        $row = calculator::calculate([['timestart' => 701, 'timeend' => 1000]], 100, 2000);
        $this->assertTrue($row['late']);
    }

    public function test_concurrency_counts_people_once_and_handles_touching_intervals(): void {
        $stats = calculator::concurrency([
            [['timestart' => 100, 'timeend' => 200], ['timestart' => 100, 'timeend' => 200]],
            [['timestart' => 200, 'timeend' => 300]],
        ], 100, 300);
        $this->assertSame(1, $stats['peak']);
        $this->assertEquals(1, $stats['average']);
    }

    public function test_open_and_invalid_intervals_are_not_invented(): void {
        $row = calculator::calculate([['timestart' => 100, 'timeend' => 0],
            ['timestart' => 200, 'timeend' => 100]], 100, 300);
        $this->assertSame(0, $row['seconds']);
        $this->assertNull($row['first']);
        $this->assertSame([], calculator::intervals([]));
    }
}
