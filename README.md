# Google Meet™ Extended for Moodle

This maintained fork adds post-class attendance and statistics while preserving the `mod_googlemeet` component,
existing Calendar rooms, recordings and reminders. Upgrade existing installations in `mod/googlemeet`;
do not install it alongside another copy of the same component.

## Attendance

Enable attendance per activity, then use **Attendance → Connect organizer** to grant the organizer read-only Meet access.
Enable Google Meet API in the OAuth project's Google Cloud console. Keep the standard Moodle Google OAuth callback URL.
The issuer must expose OpenID Connect UserInfo with `sub`; organizer authorization uses
`openid email profile https://www.googleapis.com/auth/meetings.space.readonly`.
Students use **Link my Google account** with identity-only scopes (`openid email profile`), independently of their Moodle login.
Email addresses and display names are never used to automatically match learners.

Moodle cron must run normally. A five-minute dispatcher snapshots enrolled learners when a class starts and queues
imports as the connected organizer. No organizer browser session needs to stay open. Revoked/insufficient permissions
require reconnecting. Google expires conference records 30 days after they end; Moodle keeps successfully imported evidence.
An outage during a later refresh does not invalidate that evidence. A cron delayed past enrollment changes can only use
the historical enrollment timestamps Moodle still has; it cannot reconstruct an unrecorded history of role changes.

Defaults are 75% effective presence within the Moodle timetable, 80% of eligible classes to complete the activity,
and an informational late flag after ten minutes. Thresholds are editable per activity. Overlapping devices count once;
disconnect gaps do not count. Completion waits until the series ends and its pending records are reviewed.
Missing Google records or unresolved identities remain pending, rather than becoming confirmed absences.

Teachers can classify external participants, map pending attendees to roster learners and cancel classes with an audited reason.
Reports by class, learner and course include intervals, totals, percentages, connection sessions, concurrency and CSV export.
Learners can only access their own results. Imported timestamps cannot be edited. Started class windows and Google source
are protected: use another activity when changing the room or organizing Google account after a series starts.

Attendance is disabled on upgrade and starts prospectively when enabled; there is no automatic backfill before activation.
There is no age-based purge. Privacy requests, an explicitly selected course reset, or activity deletion remove the corresponding
personal records; minimal hashed suppression markers prevent Google from reimporting erased participant details.
User-data backups preserve attendance with Moodle user remapping; backups never contain OAuth credentials or global account links.
Restores require explicitly reconnecting and re-enabling synchronization.

The attendance importer does not change recording-sharing permissions. The inherited Drive recording synchronization
can make recordings readable by anyone with their link; review that separate existing behavior before using it.

## Validation

Run the Moodle PHPUnit suite `mod_googlemeet_testsuite` after initializing the isolated test database.
Tests use mocked Google responses: no production mock switch or credentials are included.
The compatibility workflow covers stable Moodle 4.5, 5.0, 5.1, 5.2 and 5.3 using supported PHP versions.
A real Google Workspace class remains a separate acceptance check; simulated tests do not establish live attendance capture.

The Google Meet™ for Moodle plugin allows the teacher, without having to leave Moodle, to create a Google Meet room and make available to the students the recordings of the room saved in Google Drive.

To create the Google Meet meeting room from Moodle, the Google Meet™ plugin for Moodle needs an active OAuth 2 service for Google.
To learn how to set up an OAuth 2 service for Google, click [here](https://github.com/ronefel/moodle-mod_googlemeet/wiki/How-to-create-Client-ID-and-Client-Secret).

<div>
<img src="https://ronefel.nimbusweb.me/box/attachment/8669013/93arpv0xye1v1fuw44bs/RFOdRT6UcpaK9F8a/screen1.png" alt="screen1.png" width="270" />
<img src="https://ronefel.nimbusweb.me/box/attachment/8669016/no8bamexlmcaw3cbk22g/a4Fo3nWopQTWd7PJ/screen2.png" alt="screen2.png" width="270" />
<img src="https://ronefel.nimbusweb.me/box/attachment/8669017/wlb0oosfs8pgpll1o18l/EHMyOvHzWaQMnWHL/screen3.png" alt="screen3.png" width="270" />
<img src="https://ronefel.nimbusweb.me/box/attachment/8669019/tf0y4bgv6rgvay0hc54b/MWncdrQf0Xx67Zas/screen4.png" alt="screen4.png" width="270" />
<img src="https://ronefel.nimbusweb.me/box/attachment/8669020/zoxcrmbwgty0qd989g8z/SQwhOZtDqpMCiPiY/screen5.png" alt="screen5.png" width="270" />
<img src="https://ronefel.nimbusweb.me/box/attachment/8703578/il2crnahoh0spr56owby/c2rpQQ0Eb5Z9co6F/screen6.png" alt="screen6.png" width="170" />
</div>

## Requirements
Moodle 4.5+

PHP 8.1+ for Moodle 4.5; use the PHP version required by each later Moodle release.

## Installation
1.  Copy this plugin to the `mod\googlemeet` folder on the server
2.  Login as administrator
3.  Go to Site Administrator > Notification
4.  Install the plugin

For Moodle 5.1+, install under the public document root's `mod/googlemeet` directory.

## Security

If you discover any security related issues, please email [ronefel@hotmail.com](mailto:ronefel@hotmail.com) instead of using the issue tracker.

## License ##

2020 Rone Santos <ronefel@hotmail.com>

The GNU GENERAL PUBLIC LICENSE. Please see [License File](LICENSE.md) for more information.

> ©2018 Google LLC All rights reserved.<br/>
> Google Meet and the Google Meet logo are registered trademarks of Google LLC.
