# TUP Meet (`mod_tupmeet`)

TUP Meet is an institutional Moodle activity module intended to create and manage Google Meet sessions from Moodle while maintaining institutional control of the organizer account and recording history.

## Phase 2 status

This branch preserves Phase 1 institutional account administration and adds Calendar/Meet scheduling:

- Site administration > Plugins > Activity modules > TUP Meet.
- Registration using an existing Google OAuth2 issuer, native Moodle connection/reconnection, and live OpenID identity verification.
- Explicit selection of one enabled default, with immutable historical activity ownership.
- Separate account/OAuth services; no OAuth credentials in plugin tables.
- Calendar primary-event creation, whole-series edits, weekly RRULE and automatic Google Meet conference requests.
- Explicit Moodle timezone, stable event identity, post-commit synchronization and durable task retries.
- Visible pending/error states and a validated Google Meet join button.
- Version `2026091502` (`0.3.1-alpha`), with fresh-install and Phase 0/1 upgrade paths; this patch adds Mexican Spanish without schema changes.

The original activity baseline provides:

- Moodle activity module skeleton (`mod_tupmeet`).
- Minimum Moodle version: 4.5 (`2024100700`).
- Scheduling form with start/end date and time.
- Weekly recurrence controls and recurrence end date.
- Automatic-recording and automatic-transcript preferences.
- Recording publication preference (teacher approval or automatic).
- Database fields reserved for Google Calendar/Meet identifiers.
- Master-account history table designed so the active account can change without breaking historical activities.

Google calls use Moodle OAuth2, OpenID userinfo and Calendar API. **Meet REST recording/transcription configuration, recording publication, Drive integration and legacy migration are not implemented.** Recording/transcription checkboxes remain preferences.

See [Phase 2 operations and validation](docs/PHASE2.md) for setup, scopes, the exact workflow, consistency strategy and limitations. [Phase 1](docs/PHASE1.md) remains the historical account/OAuth report. The [Phase 2 smoke and hardening report](docs/PHASE2_SMOKE.md) records the successful owner-reported Moodle 4.5 / Workspace smoke, explicit `es_mx` support and the inclusive local recurrence boundary. Phase 3 requires separate approval.

See [Continuous integration](docs/CI.md) for the GitHub Actions checks, Moodle 4.5/5.0/5.1 matrix, local commands and pending remote verification.

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
