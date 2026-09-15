# TUP Meet (`mod_tupmeet`)

TUP Meet is an institutional Moodle activity module intended to create and manage Google Meet sessions from Moodle while maintaining institutional control of the organizer account and recording history.

## Phase 1 status

This branch implements institutional master-account administration on top of the Phase 0 activity baseline:

- Site administration > Plugins > Activity modules > TUP Meet.
- Registration using an existing Google OAuth2 issuer, native Moodle connection/reconnection, and live OpenID identity verification.
- Explicit selection of one enabled default, with immutable historical activity ownership.
- Separate account/OAuth services; no OAuth credentials in plugin tables.
- Upgrade from `2026091400` to `2026091500` (`0.2.0-alpha`).

The original activity baseline provides:

- Moodle activity module skeleton (`mod_tupmeet`).
- Minimum Moodle version: 4.5 (`2024100700`).
- Scheduling form with start/end date and time.
- Weekly recurrence controls and recurrence end date.
- Automatic-recording and automatic-transcript preferences.
- Recording publication preference (teacher approval or automatic).
- Database fields reserved for Google Calendar/Meet identifiers.
- Master-account history table designed so the active account can change without breaking historical activities.

Google calls are limited to Moodle OAuth2 authentication and OpenID userinfo verification. **No Calendar events, Meet links, recording automation, Drive integration, or legacy migration are implemented.**

See [Phase 1 operations and validation](docs/PHASE1.md) for setup, exact administrator workflow, tests and limitations. Phase 2 requires a separate review and approval.

## Master-account principle

The organizer account must never be hard-coded. Each activity retains the account identifier used when it was created. Changing the default master account affects only new activities. Moodle stores one system account per issuer, so each replacement institutional account needs a separate issuer. Reconnection must authorize the original Google identity. The plugin checks both its stable OpenID subject and verified email.

Registering/verifying an account does not automatically select it as default: the administrator must first inspect the email. With no enabled verified default, new activity creation is blocked. Existing Phase 0 activities with `accountid = 0` remain unassigned; this phase does not infer or migrate their ownership.

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
