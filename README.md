# TUP Meet (`mod_tupmeet`)

TUP Meet is an institutional Moodle activity module intended to create and manage Google Meet sessions from Moodle while maintaining institutional control of the organizer account and recording history.

## Phase 3.3 experimental / staging-only status

Version `2026091800` (`0.5.0-alpha-poc`) adds an isolated, site-administrator-only [Meet-first experiment](docs/PHASE3_3_POC.md). Every external step requires an explicit protected POST. It does not replace the normal Calendar-first activity workflow. Native Calendar association of a Meet-created Space and real cohost/recording behavior remain pending staging smoke.

## Preserved Phase 3.2.1 activity workflow

This branch preserves institutional account administration and Calendar/Meet scheduling, and configures automatic artifacts:

- Site administration > Plugins > Activity modules > TUP Meet.
- Registration using an existing Google OAuth2 issuer, native Moodle connection/reconnection, and live OpenID identity verification.
- Explicit selection of one enabled default, with immutable historical activity ownership.
- Separate account/OAuth services; no OAuth credentials in plugin tables.
- Calendar primary-event creation, whole-series edits, weekly RRULE and automatic Google Meet conference requests.
- Explicit Moodle timezone, stable event identity, post-commit synchronization and durable task retries.
- Visible pending/error states and a validated Google Meet join button.
- Meet REST configuration of automatic recording/transcription on the existing Calendar-created Space.
- Permanent Space identity, independent configuration status and bounded durable retries; failures retain the join link.
- One server-validated Moodle teacher configured as COHOST, with independent states and bounded retries.
- Readonly scope for Calendar-space member queries and safe cohost stage/HTTP diagnostics for activity managers.
- Unchanged normal schema, with fresh-install and Phase 0/1/2/3/3.2 upgrade paths; Phase 3.3 adds no tables or fields.

The original activity baseline provides:

- Moodle activity module skeleton (`mod_tupmeet`).
- Minimum Moodle version: 4.5 (`2024100700`).
- Scheduling form with start/end date and time.
- Weekly recurrence controls and recurrence end date.
- Automatic-recording and automatic-transcript preferences.
- Recording publication preference (teacher approval or automatic).
- Database fields reserved for Google Calendar/Meet identifiers.
- Master-account history table designed so the active account can change without breaking historical activities.

Google calls use Moodle OAuth2, OpenID userinfo, Calendar API and Meet REST settings and members. **Recording/transcript retrieval, publication, Drive integration, attendance, Smart Notes and legacy migration are not implemented.** Publication remains a preference. Google starts automatic artifact generation only when someone with the necessary privileges joins, subject to Workspace licensing/policies; configured does not mean a recording already exists.

See [Phase 3](docs/PHASE3.md) for architecture, independent states, the new OAuth scope, manual reauthorization and the pending real smoke procedure. Historical activities require an explicit save/retry to apply their preferences after upgrade. [Phase 2](docs/PHASE2.md), [its smoke and hardening report](docs/PHASE2_SMOKE.md) and [Phase 1](docs/PHASE1.md) retain the earlier evidence. The [Phase 3.2 implementation report](docs/PHASE3_2.md) retains the original cohost design and its historical validation notes. Later phases require separate approval.

See [Phase 3.2.1](docs/PHASE3_2_1.md) for the reported cohost smoke failure, readonly reauthorization, safe diagnostics and the next Calendar-created Space smoke. This hardening is local only; successful members.create on those Spaces remains unconfirmed.

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
