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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * English strings for TUP Meet.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'TUP Meet';
$string['masteraccounts'] = 'Institutional master accounts';
$string['registeraccount'] = 'Register account';
$string['accountdisplayname'] = 'Account label';
$string['oauthissuer'] = 'Moodle OAuth2 issuer';
$string['googleemail'] = 'Verified institutional email';
$string['accountstate'] = 'Use for new activities';
$string['lastverification'] = 'Last successful verification';
$string['connectaccount'] = 'Connect / reconnect in Moodle';
$string['verifyaccount'] = 'Verify Google identity';
$string['setdefaultaccount'] = 'Set as default';
$string['disableaccount'] = 'Disable for new activities';
$string['enableaccount'] = 'Enable for new activities';
$string['accountenabled'] = 'Enabled';
$string['accountdisabledlabel'] = 'Disabled (historical)';
$string['defaultaccount'] = 'Default';
$string['notverified'] = 'Pending verification';
$string['manageissuers'] = 'Manage Moodle OAuth2 services';
$string['issuerunavailable'] = 'Unavailable issuer';
$string['noavailableissuers'] = 'No unused, configured Google issuers are available. Create a separate Google issuer in Moodle for each institutional account.';
$string['accountinstructions'] = 'Register a label and issuer, connect its system account in Moodle, then return here and verify. Check the displayed email before selecting the default. Reconnect an issuer only to its original Google account; use a separate issuer for a replacement account. Verification dates record the last success, not current authorization health. Disabling the default pauses creation of new activities until another default is selected.';
$string['accountregistered'] = 'Account registered. Connect it in Moodle, return here and verify its Google identity.';
$string['accountupdated'] = 'Account updated. Review its verified email and default state below.';
$string['invalidissuer'] = 'Select an enabled, configured Google issuer for internal services with authorization, token and OpenID userinfo endpoints.';
$string['connectionfailed'] = 'Could not authenticate the Moodle system account. Check its authorization, scopes and network connection, then reconnect if necessary. Plugin account state was preserved.';
$string['invalididentity'] = 'Google did not return a verified Workspace identity with subject, email and hosted domain, or the email is outside the issuer domains. Check OpenID userinfo and authorize the institutional account.';
$string['identitychanged'] = 'This issuer is connected to a different Google identity or email. The historical identity has not been changed. Reconnect the original account; register replacement accounts with a separate issuer.';
$string['accountnotverified'] = 'Verify the Google identity and review its email before enabling or selecting this account.';
$string['accountdisabled'] = 'Enable this verified account before selecting it as default.';
$string['accountbusy'] = 'Another account operation is in progress. Try again shortly.';
$string['invalidaccountname'] = 'Enter an account label of 1 to 255 characters.';
$string['issuerinuse'] = 'This issuer already belongs to a registered account. Use a separate issuer to preserve historical authorization.';
$string['accountinuse'] = 'Only unused, disabled, never-verified registrations can be removed. Verified institutional identities are retained.';
$string['nodefaultaccount'] = 'No single enabled, verified default account is available. Ask the site administrator to configure TUP Meet before creating new activities. Existing activities retain their owners.';
$string['invalidrequest'] = 'Invalid account operation. Use the administration page buttons.';
$string['accountoperationfailed'] = 'The operation could not be completed. Check the account and Moodle OAuth2 configuration.';
$string['phase1notice'] = 'Phase 1: institutional account administration is available. Calendar events, Meet links and recording automation are not implemented.';
$string['privacy:metadata:accounts'] = 'Historical institutional account metadata, independent of Moodle user accounts.';
$string['privacy:metadata:displayname'] = 'Administrator-provided institutional account label.';
$string['privacy:metadata:googleemail'] = 'Verified Google institutional email.';
$string['privacy:metadata:googlesub'] = 'Stable Google OpenID account identifier.';
$string['privacy:metadata:timeverified'] = 'Timestamp of the last successful identity verification.';
$string['privacy:metadata:oauth2'] = 'Moodle core owns the OAuth2 authorization and token lifecycle.';
$string['modulename'] = 'TUP Meet';
$string['modulenameplural'] = 'TUP Meet';
$string['pluginadministration'] = 'TUP Meet administration';
$string['tupmeet:addinstance'] = 'Add a TUP Meet activity';
$string['tupmeet:view'] = 'View a TUP Meet activity';
$string['tupmeet:manage'] = 'Manage a TUP Meet activity';
$string['meetingname'] = 'Meeting name';
$string['scheduling'] = 'Scheduling';
$string['startdatetime'] = 'Start date and time';
$string['enddatetime'] = 'End date and time';
$string['isrecurring'] = 'Recurring meeting';
$string['recurrenceinterval'] = 'Repeat every (weeks)';
$string['recurrencedays'] = 'Repeat on';
$string['recurrenceuntil'] = 'Repeat until';
$string['meetsettings'] = 'Google Meet';
$string['autorecord'] = 'Record automatically';
$string['autotranscript'] = 'Generate transcript automatically';
$string['publicationmode'] = 'Recording publication';
$string['publicationmanual'] = 'Manual';
$string['publicationautomatic'] = 'Automatic';
$string['errorendbeforestart'] = 'The end date/time must be after the start date/time.';
$string['errorrecurrenceinterval'] = 'The recurrence interval must be between 1 and 999 weeks.';
$string['errorrecurrenceday'] = 'Select at least one day for the recurring meeting.';
$string['errorrecurrenceuntil'] = 'The recurrence end date cannot be earlier than the meeting start.';
$string['timezone'] = 'Meeting timezone';
$string['nextsession'] = 'Next session';
$string['nosession'] = 'No upcoming sessions are scheduled.';
$string['joinmeet'] = 'Join Google Meet';
$string['preferencesonly'] = 'Recording, transcription and publication settings are saved preferences only. TUP Meet does not activate automatic recording, transcription or publication in this phase.';
$string['syncpending'] = 'The meeting is being prepared. The Google Meet link is not available yet. Reload this page shortly; Moodle will retry automatically.';
$string['syncerror'] = 'The meeting could not be synchronized. The Google Meet link is unavailable. A teacher can retry; the administrator may need to reconnect the institutional account.';
$string['synclegacy'] = 'This activity has not been scheduled in Calendar. A teacher must review and save its schedule.';
$string['retrysync'] = 'Retry synchronization';
$string['calendarfailed'] = 'Google Calendar synchronization failed. Check the institutional authorization and try again.';
$string['invalidschedule'] = 'The meeting schedule is invalid.';
$string['invalidrequest'] = 'The submission identifier is invalid. Reopen the activity form.';
$string['duplicatesubmission'] = 'This submission was already saved. Open the existing TUP Meet activity from the course.';
$string['errorstartweekday'] = 'The first meeting date must match one of the selected weekdays.';
$string['privacy:metadata:calendarname'] = 'The meeting title is sent to the institutional Google Calendar.';
$string['privacy:metadata:calendarintro'] = 'The activity description is sent to the institutional Google Calendar.';
$string['privacy:metadata:calendarschedule'] = 'Meeting dates, times, timezone and recurrence are sent to Google Calendar.';
$string['privacy:metadata:googlecalendar'] = 'Institutional meetings are stored in Google Calendar. Moodle user identities and enrolment lists are not sent.';
$string['meetingavailable'] = 'Ready';
$string['artifacton'] = 'Enabled';
$string['artifactoff'] = 'Disabled';
$string['meetconfigpending'] = 'Configuration pending';
$string['meetconfigerror'] = 'Could not configure';
$string['meetconfigunconfigured'] = 'Not yet applied';
$string['meetconfigfailed'] = 'Could not apply automatic recording/transcription settings. Check authorization, Workspace eligibility and administrative policies, then retry.';
$string['meetconfigerrornotice'] = 'The meeting is available, but automatic recording/transcription settings could not be applied. Moodle retries a limited number of times. After correcting authorization or Workspace settings, retry here.';
$string['meetconfigunconfigurednotice'] = 'The meeting is available. These saved preferences have not yet been applied to Google Meet. Review them and save the activity or apply them here after authorizing the new permission.';
$string['retryartifactconfig'] = 'Apply / retry automatic settings';
$string['artifactnotice'] = 'Automatic recording and transcription depend on Workspace licensing, policies and an authorized host joining. Configuration readiness does not confirm that an artifact exists. Recording visibility is controlled locally in Moodle; Drive access remains unchanged.';
$string['privacy:metadata:artifactconfig'] = 'Automatic recording and transcription preferences are sent to the institutional Google Meet space.';
$string['privacy:metadata:googlemeet'] = 'Google Meet receives artifact settings for the existing institutional meeting space. No recordings, transcripts, participant lists or attendance are retrieved.';

$string['cohostuserid'] = 'Teacher co-host';
$string['cohostuserid_help'] = 'Choose an active enrolled teacher with permission to manage course activities. The email comes from Moodle. The first saved selection cannot be replaced automatically, even after an uncertain remote result.';
$string['cohostinvalid'] = 'Select an active enrolled teacher with permission to manage activities and a valid Moodle email. A saved email change requires administrative review.';
$string['cohostlocked'] = 'The saved co-host cannot be replaced automatically. Previous Google privileges require explicit administrative review; this plugin does not remove members.';
$string['cohostnotice'] = 'When the teacher is configured as a co-host, Google Meet can start the configured automatic features when the teacher joins from the Web, subject to Google Workspace licensing and policies.';
$string['cohoststatus'] = 'Co-host status';
$string['cohostunconfigured'] = 'Not configured';
$string['cohostpending'] = 'Pending';
$string['cohostready'] = 'Configured';
$string['cohosterror'] = 'Could not configure';
$string['cohostfailed'] = 'The meeting remains available, but the teacher could not be confirmed as a co-host. Review their Moodle identity, OAuth authorization and Google Workspace permissions and policies.';
$string['retrycohost'] = 'Retry co-host configuration';
$string['privacy:metadata:cohost'] = 'Minimal teacher identity and status for the meeting co-host. Local erasure does not remove Google membership; remote privileges require separate explicit review.';
$string['privacy:metadata:cohostuserid'] = 'Selected Moodle teacher user ID; not sent to Google.';
$string['privacy:metadata:cohostemail'] = 'Server-resolved teacher email snapshot sent to Google Meet only to configure space membership as a co-host.';
$string['privacy:metadata:cohostmembername'] = 'Google Meet member resource identifying the confirmed co-host.';
$string['privacy:metadata:cohoststatus'] = 'Last membership configuration status.';
$string['privacy:metadata:cohostmodified'] = 'Time of confirmation for the current membership revision.';

$string['cohosterrorstage'] = 'Co-host failure stage';
$string['cohosthttpstatus'] = 'HTTP status';
$string['cohoststageidentity'] = 'Identity or authorization verification';
$string['cohoststagespace'] = 'Meeting space resolution';
$string['cohoststagelist'] = 'Member listing';
$string['cohoststagecreate'] = 'Member creation';
$string['cohoststagepatch'] = 'Member role update';
$string['cohoststageunknown'] = 'Unknown';
$string['privacy:metadata:cohosterrorstage'] = 'Allowlisted operation where co-host configuration failed; no provider details.';
$string['privacy:metadata:cohosthttpstatus'] = 'Normalized HTTP status of the co-host failure, or zero when unavailable.';

$string['cachedef_meet_first_poc'] = 'Temporary administrator Meet-first experiment';
$string['privacy:metadata:poccache'] = 'Staging-only PoC cache, keyed by administrator ID: course/teacher/account IDs, teacher email hash, schedule, resource IDs and safe stage/status. Expires after 24 hours; administrators can clear it manually. No tokens or raw responses.';
$string['privacy:metadata:pocattendee'] = 'The selected teacher email is sent to Google Calendar only as an attendee of the explicitly requested experimental fallback event.';
$string['poctitle'] = 'TUP Meet — Meet-first PoC (STAGING ONLY)';
$string['pocadminonly'] = 'This experiment is available only to authenticated site administrators.';
$string['pocfailed'] = 'The experimental operation could not be confirmed. Check the safe stage/status and prerequisites. No automatic retry is performed.';
$string['pocnorepeat'] = 'This operation has already been attempted. Inspect Google and retain the resource IDs before manual cleanup; do not repeat an uncertain creation.';
$string['pocwarning'] = 'EXPERIMENTAL / STAGING ONLY. Each button explicitly runs its named step using the current verified default owner. Normal TUP Meet activities are unchanged. Temporary results expire after 24 hours or a cache purge: record the safe IDs before leaving. NOT_CONFIRMED does not prove that Google created nothing.';
$string['pocstagingack'] = 'I confirm this is staging and authorize this isolated experiment with the selected teacher.';
$string['pocstart'] = 'Prepare experiment (local only)';
$string['poccourseid'] = 'Moodle course ID';
$string['pocloadteachers'] = 'Load eligible teachers (local only)';
$string['pocstage'] = 'Experimental stage';
$string['pocstatus'] = 'Result';
$string['pocownerid'] = 'TUP Meet owner account ID';
$string['pocuserid'] = 'Selected Moodle USER ID';
$string['poceventid'] = 'Requested Calendar event ID (creation may be uncertain)';
$string['poccleanup'] = 'Manual cleanup: retain the Space name, Meet link and both requested event IDs. Inspect the owner’s primary Calendar even after a timeout or FAIL; remove test events manually and send cancellations when appropriate. Review the test Space and cohost in Google Meet using supported administrator controls. Clearing these temporary results does not delete events, Spaces or members. The owner remains the verified default institutional account; no recording retrieval occurs.';
$string['pocfallbackack'] = 'I reviewed the native attempt and any event it may have created, checked for an unexpected Meet, and authorize a separate fallback event and an invitation to the selected teacher (sendUpdates=all).';
$string['pocclear'] = 'Clear local PoC results';
$string['pocclearack'] = 'I retained the resource IDs and completed or arranged manual Google cleanup. This only clears the local temporary experiment.';

$string['spacelabel'] = 'Google Meet';
$string['meetingunavailable'] = 'The Google Meet link is not available yet. Please contact the course teacher if it remains unavailable.';
$string['spacepending'] = 'Pending';
$string['spacecreating'] = 'Creation attempted';
$string['spaceready'] = 'Ready';
$string['spaceerror'] = 'Creation rejected';
$string['spaceuncertain'] = 'Uncertain: manual review required';
$string['spacefailed'] = 'The Space creation could not be confirmed.';
$string['spaceuncertainnotice'] = 'Google may have created this Space, but Moodle could not confirm the result. Automatic creation is stopped to prevent a second Meet. An administrator must review the outcome; do not recreate the activity as a retry.';
$string['retryspace'] = 'Retry rejected Space creation';
$string['calendarpending'] = 'Pending';
$string['calendarready'] = 'Synchronized';
$string['calendarerror'] = 'Error';
$string['calendarindependenterror'] = 'Calendar could not be synchronized. The existing Google Meet link remains available.';
$string['cohosthistorical'] = 'Meeting created with the historical Calendar model. Automatic cohost assignment is unavailable for this Space. Existing manual assignments are preserved.';
$string['privacy:metadata:calendarattendee'] = 'The verified course teacher email is sent to Google Calendar as an invited attendee of the Meet-first event.';

$string['recordingfailed'] = 'Recording metadata could not be reconciled.';
$string['taskdiscoverrecordings'] = 'Discover due Meet recordings';
$string['recordings'] = 'Recordings';
$string['recordingssync'] = 'Synchronize recordings';
$string['recordingsretry'] = 'Retry filename confirmation';
$string['recordingssyncidle'] = 'Discovery has not run yet.';
$string['recordingssyncpending'] = 'Discovery queued.';
$string['recordingssyncsyncing'] = 'Discovering recording metadata.';
$string['recordingssyncready'] = 'Recording metadata synchronized.';
$string['recordingssyncerror'] = 'Recording discovery needs attention.';
$string['recordingssyncerrorhelp'] = 'Verify the historical account authorization and API access, then synchronize again. Existing recordings are retained.';
$string['recordingslastsync'] = 'Last successful discovery';
$string['recordingshistorical'] = 'Historical Calendar meeting: recording discovery is read-only; automatic rename is disabled.';
$string['recordingsnone'] = 'No recordings registered yet. Processing, access or Google retention can delay or prevent discovery.';
$string['recordingssession'] = 'Session';
$string['recordingsstate'] = 'Status';
$string['recordingsfile'] = 'File';
$string['recordingsrename'] = 'Filename status';
$string['recordingsoriginal'] = 'Original observed name';
$string['recordingsopen'] = 'Open in Google Drive';
$string['recordingsstateSTARTED'] = 'In progress';
$string['recordingsstateENDED'] = 'Processing recording…';
$string['recordingsstateFILE_GENERATED'] = 'Available';
$string['recordingsrenameunavailable'] = 'Waiting for the MP4';
$string['recordingsrenamepending'] = 'Pending';
$string['recordingsrenameready'] = 'Confirmed';
$string['recordingsrenameerror'] = 'Could not confirm the filename; the recording remains available.';
$string['recordingsrenameskipped'] = 'Skipped: historical read-only meeting, missing rename capability, or late segment conflicts with frozen names. Review manually.';
$string['recordingsqueued'] = 'Recording metadata task queued.';
$string['recordingsnotqueued'] = 'No new task queued: work is pending, the request was too recent, or this item is not eligible.';
$string['recordingsbusy'] = 'Recording metadata is being processed. Try deleting the activity again shortly.';
$string['privacy:metadata:conferences'] = 'Shared institutional conference identities and actual session times, without participant lists.';
$string['privacy:metadata:conferencename'] = 'Permanent conference identity returned by Meet.';
$string['privacy:metadata:recordingname'] = 'Recording identity within its conference.';
$string['privacy:metadata:recordingtimes'] = 'Actual start/end and first/last observation times.';
$string['privacy:metadata:recordings'] = 'Shared recording metadata and frozen filenames; no video or transcript content is stored.';
$string['privacy:metadata:recordingdestination'] = 'Validated Drive file identity and playback link returned by Meet.';
$string['privacy:metadata:recordingfilename'] = 'Original, observed and desired file names derived from the activity title and actual session time.';
$string['privacy:metadata:recordingstate'] = 'Processing and rename status, attempt counts and numeric HTTP status; no response bodies.';
$string['privacy:metadata:recordingsmeet'] = 'The permanent Space identity is sent to Google Meet to discover conference and recording metadata using the historical account.';
$string['privacy:metadata:drive'] = 'Only a validated recording file ID and desired name are sent to Google Drive for metadata lookup and rename. No permissions, parents or media are modified or downloaded.';

$string['sessiondateformat'] = '%A, %d %B %Y';
$string['recordingdateformat'] = '%d %b %Y';
$string['sessionends'] = 'Ends:';
$string['sessionweekly'] = 'Weekly meeting';
$string['sessionweeks'] = 'Every {$a} weeks';
$string['classrecordings'] = 'Class recordings';
$string['classrecordingsnone'] = 'No class recordings are available yet.';
$string['technicaladministration'] = 'Technical administration';
$string['recordinghours'] = 'Time';
$string['recordingvideo'] = 'Video';
$string['recordingaction'] = 'Recording';
$string['recordingstudents'] = 'Students';
$string['recordingwatch'] = 'Watch recording';
$string['recordingpart'] = 'Part {$a}';
$string['recordinghide'] = 'Hide recording from students';
$string['recordingshow'] = 'Show recording to students';
$string['recordingvisible'] = 'Visible';
$string['recordinghidden'] = 'Hidden';
$string['opensnewtab'] = 'Opens in a new tab';
$string['visibilitysaved'] = 'Recording visibility saved.';
$string['visibilitybusy'] = 'The recording is being updated. Please try again in a moment.';
$string['eventrecordingvisibilitychanged'] = 'Recording visibility changed';
$string['visibilityhistory'] = 'Recording visibility actions';
$string['privacy:metadata:studentvisible'] = 'Local Moodle visibility for students; it does not change Drive permissions.';
$string['privacy:metadata:visibilityuserid'] = 'Moodle user who last changed local recording visibility.';
$string['privacy:metadata:visibilitymodified'] = 'Time of the last manual visibility change.';
$string['publicationmode_help'] = 'Automatic: new recordings appear to students when first detected. Teachers can hide each recording. Manual: new recordings remain hidden until a teacher shows them using the eye control. Changing this preference does not alter existing recordings or Drive permissions.';
