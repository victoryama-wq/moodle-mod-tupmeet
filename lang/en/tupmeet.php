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
$string['publicationmanual'] = 'Teacher approval required';
$string['publicationautomatic'] = 'Publish automatically';
$string['errorendbeforestart'] = 'The end date/time must be after the start date/time.';
$string['errorrecurrenceinterval'] = 'The recurrence interval must be between 1 and 999 weeks.';
$string['errorrecurrenceday'] = 'Select at least one day for the recurring meeting.';
$string['errorrecurrenceuntil'] = 'The recurrence end date cannot be earlier than the meeting start.';
$string['timezone'] = 'Meeting timezone';
$string['nextsession'] = 'Next session';
$string['nosession'] = 'No upcoming session';
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
