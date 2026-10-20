<?php
// This file is part of Moodle - http://moodle.org/
// Moodle is distributed under the GNU GPL v3 or later.

defined('MOODLE_INTERNAL') || die();

/** Test instances for Google Meet without Google credentials. */
class mod_googlemeet_generator extends testing_module_generator {
    /** Create a scheduled room backed only by Moodle data. */
    public function create_instance($record = null, ?array $options = null) {
        $record = (array)$record + [
            'name' => 'Test Google Meet', 'originalname' => 'Test Google Meet',
            'url' => 'https://meet.google.com/abc-defg-hij', 'creatoremail' => '',
            'eventdate' => usergetmidnight(time()) + DAYSECS,
            'starthour' => 10, 'startminute' => 0, 'endhour' => 11, 'endminute' => 0,
            'addmultiply' => 0, 'period' => 1, 'notify' => 0, 'minutesbefore' => 10,
        ];
        $record['eventenddate'] = $record['eventenddate'] ?? $record['eventdate'];
        return parent::create_instance($record, (array)$options);
    }
}
