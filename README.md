# TUP Meet (`mod_tupmeet`)

TUP Meet is an institutional Moodle activity module intended to create and manage Google Meet sessions from Moodle while maintaining institutional control of the organizer account and recording history.

## Phase 0 status

This branch is the installable architecture baseline. It currently provides:

- Moodle activity module skeleton (`mod_tupmeet`).
- Minimum Moodle version: 4.5 (`2024100700`).
- Scheduling form with start/end date and time.
- Weekly recurrence controls and recurrence end date.
- Automatic-recording and automatic-transcript preferences.
- Recording publication preference (teacher approval or automatic).
- Database fields reserved for Google Calendar/Meet identifiers.
- Master-account history table designed so the active account can change without breaking historical activities.

It **does not yet call Google APIs**. That begins in Phase 1.

## Master-account principle

The organizer account must never be hard-coded. Each activity retains the account identifier used when it was created. Changing the default master account affects only new meetings unless an explicit migration procedure is implemented later.

## Installation for a development Moodle

Copy the plugin directory as:

```text
<Moodle code root>/mod/tupmeet
```

For Moodle 5.1+, the Moodle codebase is normally under the `public` directory, so the effective location can be:

```text
<Moodle root>/public/mod/tupmeet
```

Then visit **Site administration > Notifications** and complete the plugin installation.

Do not install this alpha package directly in production before validating it in a clone/staging environment.
