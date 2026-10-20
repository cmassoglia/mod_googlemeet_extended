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
 * Read-only Meet API transport.
 *
 * @package mod_googlemeet
 * @copyright 2026 MindFree
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_googlemeet\attendance;
defined('MOODLE_INTERNAL') || die();

/** Reads all pages using an already authorised core OAuth client. */
class meet_client {
    /** @var \curl Authorised transport. */
    protected $transport;

    /** Accept a core OAuth client (a curl subclass); transport doubles work in tests. */
    public function __construct($transport) {
        $this->transport = $transport;
    }

    /** Retrieve conferences overlapping the Moodle window, including those already running. */
    public function conferences(string $code, int $start, int $end): array {
        if (!preg_match('/^[a-z]{3}-[a-z]{4}-[a-z]{3}$/', $code)) {
            throw new \InvalidArgumentException('Invalid Meet code');
        }
        $base = 'space.meeting_code = "' . $code . '" AND start_time < "' . gmdate('Y-m-d\TH:i:s\Z', $end) . '"';
        $ended = $this->pages('conferenceRecords', 'conferenceRecords', [
            'filter' => $base . ' AND end_time > "' . gmdate('Y-m-d\TH:i:s\Z', $start) . '"']);
        $active = $this->pages('conferenceRecords', 'conferenceRecords', ['filter' => $base . ' AND end_time IS NULL']);
        $all = [];
        foreach (array_merge($ended, $active) as $record) {
            $all[$record->name] = $record;
        }
        return array_values($all);
    }

    /** Read all participants. */
    public function participants(string $conference): array {
        $this->validate_resource($conference, '/^conferenceRecords\/[A-Za-z0-9_-]+$/');
        return $this->pages($conference . '/participants', 'participants');
    }

    /** Read every reconnect and device session. */
    public function sessions(string $participant): array {
        $this->validate_resource($participant, '/^conferenceRecords\/[A-Za-z0-9_-]+\/participants\/[A-Za-z0-9_-]+$/');
        return $this->pages($participant . '/participantSessions', 'participantSessions');
    }

    /** Reject API resource names that could alter the endpoint. */
    private function validate_resource(string $resource, string $pattern): void {
        if (!preg_match($pattern, $resource)) {
            throw new \unexpectedvalueexception('Invalid Meet resource');
        }
    }

    /** Page through a collection. Never store tokens or upstream error payloads. */
    protected function pages(string $path, string $collection, array $params = []): array {
        $items = [];
        $seen = [];
        $params['pageSize'] = 100;
        do {
            $body = $this->transport->get('https://meet.googleapis.com/v2/' . $path, $params);
            $info = $this->transport->get_info();
            $status = (int)($info['http_code'] ?? 0);
            if (!empty($this->transport->errno) || $status < 200 || $status >= 300) {
                throw new \runtimeexception('Meet API request failed (HTTP ' . $status . ')', $status);
            }
            $data = json_decode($body);
            if (!is_object($data) || isset($data->error) ||
                    (isset($data->$collection) && !is_array($data->$collection))) {
                throw new \unexpectedvalueexception('Invalid Meet API response');
            }
            $items = array_merge($items, $data->$collection ?? []);
            $token = $data->nextPageToken ?? '';
            if ($token !== '' && isset($seen[$token])) {
                throw new \unexpectedvalueexception('Repeated Meet page token');
            }
            $seen[$token] = true;
            $params['pageToken'] = $token;
        } while ($token !== '');
        return $items;
    }
}
