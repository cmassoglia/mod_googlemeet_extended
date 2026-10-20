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
 * Attendance interval calculations.
 *
 * @package mod_googlemeet
 * @copyright 2026 MindFree
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_googlemeet\attendance;
defined('MOODLE_INTERNAL') || die();

/** Pure calculations; intervals use half-open Unix second boundaries. */
class calculator {
    /** Merge overlapping connections, including multiple devices. */
    public static function intervals(array $sessions, int $start = 0, int $end = PHP_INT_MAX): array {
        $intervals = [];
        foreach ($sessions as $session) {
            $session = (object)$session;
            // An open session is incomplete evidence, not an inferred departure.
            if (empty($session->timeend)) {
                continue;
            }
            $from = max($start, (int)$session->timestart);
            $until = min($end, (int)$session->timeend);
            if ($until > $from) {
                $intervals[] = [$from, $until];
            }
        }
        usort($intervals, static function($a, $b) { return $a[0] <=> $b[0]; });
        $merged = [];
        foreach ($intervals as $interval) {
            $last = count($merged) - 1;
            if ($last >= 0 && $merged[$last][1] >= $interval[0]) {
                $merged[$last][1] = max($merged[$last][1], $interval[1]);
            } else {
                $merged[] = $interval;
            }
        }
        return $merged;
    }

    /** Calculate a person's effective and total duration. */
    public static function calculate(array $sessions, int $start, int $end, int $grace = 600): array {
        $intervals = self::intervals($sessions, $start, $end);
        $sum = static function($items) {
            return array_sum(array_map(static function($i) { return $i[1] - $i[0]; }, $items));
        };
        $seconds = $sum($intervals);
        $first = $intervals ? $intervals[0][0] : null;
        $last = $intervals ? $intervals[count($intervals) - 1][1] : null;
        return ['seconds' => $seconds, 'total' => $sum(self::intervals($sessions)),
            'percentage' => $end > $start ? 100 * $seconds / ($end - $start) : 0,
            'first' => $first, 'last' => $last, 'sessions' => count($sessions),
            'late' => $first !== null && $first > $start + $grace,
            'early' => $last !== null && $last < $end];
    }

    /** Peak and average concurrent people, after uniting every person's sessions. */
    public static function concurrency(array $people, int $start, int $end): array {
        $changes = [];
        $personseconds = 0;
        foreach ($people as $sessions) {
            foreach (self::intervals($sessions, $start, $end) as [$from, $until]) {
                $changes[$from] = ($changes[$from] ?? 0) + 1;
                $changes[$until] = ($changes[$until] ?? 0) - 1;
                $personseconds += $until - $from;
            }
        }
        ksort($changes, SORT_NUMERIC);
        $current = $peak = 0;
        foreach ($changes as $change) {
            $current += $change;
            $peak = max($peak, $current);
        }
        return ['peak' => $peak, 'average' => $end > $start ? $personseconds / ($end - $start) : 0];
    }
}
