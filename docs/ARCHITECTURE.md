# TUP Meet architecture

## Goal

Provide an institutional Google Meet activity for Moodle that preserves the simple teacher workflow of the legacy plugin while replacing folder-based recording discovery with supported Google APIs.

## Core ownership model

TUP Meet starts with one active **master institutional Google account**.

The account is configurable and replaceable. A historical account remains represented in `tupmeet_accounts` even after it is no longer the default. Each activity has an `accountid` field so its organizer identity is stable over time.

Example:

```text
Term 2026-3
  Activity A -> master account #1
  Activity B -> master account #1

Change default master account

Term 2027-1
  Activity C -> master account #2
  Activity D -> master account #2
```

Activities A and B continue to resolve against account #1. They are not silently migrated to account #2.

## External services

### Google Calendar API

Responsibilities:

- Create the calendar event.
- Configure start/end date and time.
- Configure recurrence rules.
- Request Google Meet conference data.
- Update the event when the Moodle activity schedule is changed.

### Google Meet REST API

Responsibilities:

- Resolve the Meet space/conference.
- Configure supported meeting artifacts such as automatic recording when available for the Workspace edition/account.
- Retrieve conference records.
- Retrieve recordings and their Drive destination/export URI.
- Later: participant/session information and transcripts.

### Google Drive

Drive is the physical destination for recordings but **folder names must not be used as the primary synchronization mechanism**.

## Moodle layers

```text
mod_tupmeet
  UI / mod_form
        |
        v
  local meeting manager
        |
        +--> account service --> Moodle OAuth2
        +--> calendar service --> Google Calendar API
        +--> meet service --> Google Meet REST API settings (Phase 3)
        |
        v
  Moodle persistence
        +--> tupmeet
        +--> tupmeet_accounts
        +--> future conferences / recordings / sync log tables
```

## Phase 0 schema

### `tupmeet`

Stores activity schedule, recurrence, recording preferences, Google identifiers, and the historical `accountid`.

### `tupmeet_accounts`

Stores master-account metadata and the Moodle OAuth2 issuer reference. Phase 1 implements administration and verification through the native Moodle system account.

No OAuth access/refresh tokens are stored in this table.

## Phase 1 account/OAuth boundary

- `classes/local/account/account_manager.php`: registration, verification metadata, default/enabled transitions, safe removal of never-verified unused registrations, and permanent ownership of new local activities.
- `classes/local/account/oauth_client_factory.php`: configured Google issuer validation, native connection URL, live OpenID userinfo validation, and identity-checked Moodle system clients for later phases.
- `accounts.php` and `classes/form/account_form.php`: site administrator presentation/input only. All mutations require `moodle/site:config`; page mutations require POST and a valid Moodle sesskey. OAuth confirmation, state, callback and token storage remain in Moodle core.
- `settings.php`: the module administration entry under activity modules.

Moodle has one system account per issuer. A replacement institutional account therefore uses a separate issuer; an issuer already registered in TUP Meet cannot be registered again. Old issuers must remain configured for future historical access. Their credentials remain in core OAuth2 storage, never in plugin tables.

Verification calls the native system client and its raw OpenID userinfo endpoint, independent of editable Moodle user-field mappings. It requires a subject (`sub`), a valid verified email, a Workspace hosted domain (`hd`), and compliance with any issuer allowed-domain restriction. The configured issuer must use the Google service type and the standard OpenID userinfo endpoint. Personal consumer Google accounts are not institutional Workspace accounts.

`googlesub` is immutable after the first successful verification. The original email is retained and compared on reconnection. A different subject, a reassigned email address, or an email rename is rejected for explicit administrator review. It never overwrites the historical identity or defaults. `for_account()` repeats identity verification before returning a native client; it permits disabled historical accounts, but never silently falls back to the default account.

`connectionstatus = verified` and `timeverified` record the last successful verification, not continuous authorization health. A failed verification leaves previous metadata/default/enabled state untouched and displays a generic localized failure without provider response bodies. No background verification task is added in Phase 1.

## Default and ownership invariants

Account mutations use Moodle's lock API (`mod_tupmeet/accounts`) and delegated database transactions. OAuth network verification occurs before the plugin transaction so token lifecycle writes remain owned by core. Only one account can be default through the service. The first account also requires explicit default selection after the administrator sees its email.

Disabling the default clears its default flag. Zero defaults is the explicit unconfigured/paused state; it blocks new activity creation. Re-enabling an account does not select it automatically. Selecting another default changes account rows only, never activity ownership.

The activity add callback assigns the selected account server-side. It performs no Google operation. Activity edits ignore supplied `accountid`. Accounts with verified identities are always retained, including when currently unused, so an activity created within an outer Moodle transaction cannot race with account removal. The internal removal method only allows disabled, never-verified registrations with no activity references. No deletion UI is exposed.

## Phase 1 upgrade and privacy

Version `2026091500` adds `googlesub` (nullable char 255, NULL initially) and `timeverified` (integer, zero initially). `db/install.xml` and `db/upgrade.php` cover fresh and existing installations. Upgrade clears default flags and marks prior account metadata pending verification; it keeps every account row, email, issuer and activity `accountid`, including legacy zero values. It does not guess historical owners.

The privacy provider declares institutional identity metadata and the core OAuth2 subsystem. These shared institutional records have no Moodle user-ID relationship, so user-specific export/deletion does not reassign or erase institutional history. Account retention/erasure policy needs a separate institutional process; no automatic destructive cleanup is introduced.

## Safety properties

- No Google resources are deleted when a Moodle activity is deleted.
- Changing the active account never rewrites historical activity ownership automatically.
- Calendar synchronization uses a precommitted event ID, correlation marker and durable retries.
- Recording publication and Google-file permissions are separate concerns.

## Phase 2 scheduling boundary

- `local/meeting/schedule`: validation, explicit Moodle timezone, RFC3339, weekly RRULE and next-session arithmetic.
- `local/meeting/meeting_manager`: allowed form fields, immutable account, stable submission/event IDs, desired-state revision, persistence and task queue.
- `local/google/calendar_service`: identity-checked native OAuth client; GET, INSERT and PATCH in the historical owner's primary calendar; safe errors and conferenceData.
- `db/events.php` / `observer`: non-internal Moodle observers dispatched after commit attempt synchronization immediately.
- `task/sync_meeting`: durable retry with native task backoff. Saved atomically with desired state, before any Google call.
- `view.php` / POST-only `retry.php`: status, join link and teacher-controlled retry. No HTTP logic in pages or lib.php.

The callbacks persist desired state and queue work within Moodle's transaction. The non-internal observer runs after commit, so successful conference generation normally provides the link on the first view. Interrupted requests and asynchronous conference generation continue through cron.

Each form retains a random creationkey protected by a unique index. The event ID is assigned before HTTP; syncversion identifies the committed revision. GET by stable ID recovers timeouts and remote-success/local-write failures. The private tupmeet marker must match before adoption or update. Per-activity Moodle locks serialize remote reconciliation; conditional writes prevent an old response from marking a newer edit ready.

Disabled historical registrations continue to supply Calendar scopes. The official callback returns the owned-events scope only for registered Google issuers. No Google resource deletion is implemented.

Phase 2 adds timezone, nullable unique creationkey, syncversion and syncstatus to tupmeet. No new tables or token columns. Upgrade preserves timestamps, ownership and verified defaults. Legacy schedules use the site timezone and need review/save before creating Google events. Privacy metadata declares title, description and schedule transfer to Google Calendar. Full details: [Phase 2](PHASE2.md).

## Phase 3 independent Meet configuration

`local/google/meet_service` resolves the Calendar code once, validates the permanent Space name and configures only two artifact leaf fields through native OAuth GET/PATCH. `local/meeting/meet_config_manager` persists the name before PATCH and owns a separate revision, status, confirmation timestamp and five-attempt budget. No recording/transcript retrieval or Drive calls exist in this phase.

`task/sync_meet_config` is separate from Calendar work. Calendar readiness and the next Meet task commit atomically; network calls remain after commit. Both workers share the per-activity lock, and revision-conditional writes prevent stale responses from confirming newer edits. Artifact-only saves keep Calendar ready and its IDs unchanged. Teacher-only diagnostics cannot remove the student join link.

Version `2026091700` adds four Meet configuration fields without reassigning identities or activating historical preferences during upgrade. Explicit save/retry adopts the new behavior. The additional `meetings.space.settings` scope is limited to registered Google issuers, including historical accounts. Details and limitations: [Phase 3](PHASE3.md).

## Phase 3.2 independent teacher cohost

`cohost_identity` resolves an active enrolled teacher with `moodle/course:manageactivities` and a valid Moodle email. The first saved user/email selection is immutable, including uncertain remote outcomes. `member_service` uses the historical owner's native OAuth client and official Meet v2 members list/create/patch; it never deletes members or creates spaces. `cohost_manager` owns a third independent state/revision and five-attempt budget, sharing the activity lock with Calendar/artifacts. The cohost task is queued atomically with Calendar readiness and can resolve a missing Space even if artifact settings fail.

Version `2026091701` adds eight fields without activating historical selections. Privacy exports/erases local teacher data and invalidates workers, while retaining a nonpersonal selection-lock marker to prevent accidental accumulation of Google privileges. Remote revocation needs a separate explicit process. Scope `meetings.space.created` is limited to registered issuers. Its behavior on Calendar-created spaces must be verified in staging; no alternative creation architecture is implemented. See [Phase 3.2](PHASE3_2.md).

## Phase 3.4 current provisioning boundary

[Phase 3.4](PHASE3_4.md) supersedes the above creation/cohost prerequisites for new activities. `provisionmode` is assigned once: existing legacy/calendar rows keep their historical behavior; all normal new records use Meet-first. No HTTP or origin inference occurs during upgrade.

`space_service` owns one non-idempotent POST; `space_manager` checkpoints its attempt under the shared meeting lock before sending, confirms canonical identity and atomically queues three independent workers. `provisioning` centralizes mode/readiness and revision conditions. Calendar, artifacts and COHOST do not gate one another after Space readiness. The normal path never imports PoC code or accesses its cache.

There is no documented idempotency key for spaces.create. The approved exception to the original blanket idempotency invariant is conservative at-most-one attempted creation until a definitive rejection: timeouts/ambiguous results and interrupted creating workers become uncertain and require manual review. Only complete HTTP 429 responses automatically retry with backoff; normal creation has no fixed pacing. This cannot guarantee recovery of an unknown remote Space after losing its response.

Meet-first Calendar uses native conference data, the validated teacher attendee and sendUpdates=all, and confirms the event via GET. Historical Calendar-created Spaces never receive automatic COHOST writes. Canonical Space and URI survive every child error. No destructive Google operations, ownership migration or Phase 4 are introduced.

## Phase 4 recording metadata boundary

Permanent Space -> Meet conferenceRecords -> recordings -> local normalized metadata -> Drive file ID. Independent discovery and rename revisions use historical owners, per-activity locks and bounded adhoc retries. Scheduled dispatch selects at most 25 due activities every five minutes; HTTP stays outside transactions. No Calendar/folder search, media, permission changes or external deletion. The manager-only catalog reads local data. Restricted drive.metadata is requested only for registered Google issuers. Details, schema and pending smoke: [Phase 4](PHASE4.md).
