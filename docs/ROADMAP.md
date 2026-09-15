# Delivery roadmap

## Phase 0 — architecture baseline

Status: initial implementation.

Deliverables:

- Installable `mod_tupmeet` module.
- Initial scheduling form.
- Master-account abstraction in schema.
- Architecture and Codex rules.

## Phase 1 — master account + OAuth2

Priority: critical.

Deliverables:

- Admin page to register/select the Moodle OAuth2 issuer.
- Connect/reconnect/verify master Google account.
- Mark one enabled account as default.
- Preserve previous accounts for historical activities.
- Service class for authenticated system OAuth client.

Implementation: `0.2.0-alpha`, plugin version `2026091500`. The owner approved Phase 1 as the base for Phase 2. See [Phase 1](PHASE1.md) for its historical evidence and administrator checklist.

## Phase 2 — create meetings

Priority: critical for Saturday sessions.

Deliverables:

- Calendar API event creation.
- Weekly RRULE generation.
- Google Meet link generated automatically.
- Persist Calendar event id, Meet URI/code, and account id.
- Safe update of schedule.

Implementation: `0.3.0-alpha`, plugin version `2026091501`. Calendar conferenceData generates the Meet link in this phase, as explicitly authorized. See [Phase 2](PHASE2.md). Local implementation awaits code review and authorization before push.

## Phase 3 — Meet configuration

Status: not started; requires explicit approval after Phase 2 review.

Priority: high.

Deliverables:

- Meet REST API integration.
- Automatic-recording preference where supported.
- Transcript preference where supported.
- Clear capability/error messages if Workspace policy prevents an option.

## Phase 4 — recordings

Priority: critical.

Deliverables:

- Discover conference records and recordings through Meet REST API.
- Store stable Google resource ids and playback/export URI.
- Never depend on `Meet Recordings` or `Google Meet` folder names.

## Phase 5 — automation and publication

- Scheduled sync task.
- Teacher review/publication workflow.
- Student recording list.
- Sync status/logging.

## Phase 6 — legacy migration

- Read existing `mod_googlemeet` activities.
- Analyze legacy links/metadata.
- Non-destructive migration preview.
- Import validated activities and existing recording references.
