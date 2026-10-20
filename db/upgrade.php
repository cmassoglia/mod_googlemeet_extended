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
 * Plugin upgrade steps are defined here.
 *
 * @package     mod_googlemeet
 * @category    upgrade
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute mod_googlemeet upgrade from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_googlemeet_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2023042200) {

        // Define field eventid to be added to googlemeet.
        $table = new xmldb_table('googlemeet');
        $field = new xmldb_field('eventid', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'timemodified');

        // Conditionally launch add field eventid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Googlemeet savepoint reached.
        upgrade_mod_savepoint(true, 2023042200, 'googlemeet');
    }

    if ($oldversion < 2026102000) {
        // Reuse the installation definitions so fresh installs and upgrades stay identical.
        $xml = new xmldb_file(__DIR__ . '/install.xml');
        if (!$xml->loadXMLStructure()) {
            throw new upgrade_exception('mod_googlemeet', 2026102000, 'Invalid attendance schema');
        }
        $structure = $xml->getStructure();
        foreach (['googlemeet', 'googlemeet_events'] as $name) {
            $table = $structure->getTable($name);
            foreach ($table->getFields() as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
        }
        foreach (['googlemeet_conference', 'googlemeet_eventconf', 'googlemeet_participant',
                'googlemeet_session', 'googlemeet_roster', 'googlemeet_identity', 'googlemeet_audit'] as $name) {
            $table = $structure->getTable($name);
            if (!$dbman->table_exists($table)) {
                $dbman->create_table($table);
            }
        }
        upgrade_mod_savepoint(true, 2026102000, 'googlemeet');
    }

    if ($oldversion < 2026102001) {
        $table = new xmldb_table('googlemeet_audit');
        foreach (['subjectid', 'previoususerid'] as $name) {
            $field = new xmldb_field($name, XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        // Convert development-build audit payloads to queryable, privacy-aware subjects.
        $records = $DB->get_recordset('googlemeet_audit', ['action' => 'map']);
        foreach ($records as $record) {
            $payload = json_decode($record->reason ?? '', true);
            if (is_array($payload) && isset($payload['reason']) &&
                    array_key_exists('userid', $payload) && array_key_exists('previoususerid', $payload)) {
                $DB->update_record('googlemeet_audit', (object)['id' => $record->id,
                    'subjectid' => (int)$payload['userid'], 'previoususerid' => (int)$payload['previoususerid'],
                    'reason' => (string)$payload['reason']]);
            }
        }
        $records->close();
        upgrade_mod_savepoint(true, 2026102001, 'googlemeet');
    }

    return true;
}
