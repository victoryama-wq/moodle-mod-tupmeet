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

Implementation and hardening: `0.3.1-alpha`, plugin version `2026091502`, approved as the Phase 3 base. Calendar conferenceData generates the Meet link. See [Phase 2](PHASE2.md) and the [reported smoke and localization hardening](PHASE2_SMOKE.md).

## Phase 3 — Meet configuration

Status: approved on `codex/fase-3-meet-auto-artifacts`; remote CI passed for `096d6bb22679fdb63c63681388f711fd9ba17f2f`. Real Google smoke remains separate. Version `2026091700 / 0.4.0-alpha`. See [Phase 3](PHASE3.md).

Priority: high.

Deliverables:

- Meet REST API integration.
- Automatic-recording preference where supported.
- Transcript preference where supported.
- Clear capability/error messages if Workspace policy prevents an option.

## Phase 3.2 — automatic teacher cohost

Implemented locally on `codex/fase-3-2-cohost`, version `2026091701 / 0.4.1-alpha`. One eligible Moodle teacher, official members API, independent status/retries, immutable initial selection and privacy handling. Preserves Phase 3 recording/transcription. Pending review, authorized remote validation and Calendar-space smoke. See [Phase 3.2](PHASE3_2.md).

## Phase 4 — recordings

Validated base: **2026091900 / 0.7.0-alpha**, branch **codex/fase-4-recordings**.
Meet metadata, bounded polling, immutable filenames, metadata-only Drive rename
and teacher/admin catalog. Student publication, downloads, permission/folder changes
and transcripts remain excluded. Restricted scope requires reconnection; remote CI
passed for eb238103722d24dfdc178bbaaad1e73d3ce1a51c. The owner also confirmed
automatic discovery/rename with cron and intact folders. See [Phase 4](PHASE4.md)
for its original implementation report and [Phase 5](PHASE5.md) for the later smoke evidence.

Priority: critical.

Deliverables:

- Discover conference records and recordings through Meet REST API.
- Store stable Google resource ids and playback/export URI.
- Never depend on `Meet Recordings` or `Google Meet` folder names.

## Phase 5 — automation and publication

Local implementation: **2026091901 / 0.8.0-alpha**, branch **codex/fase-5-ui-publication**.
Academic session summary and responsive recording table, initial automatic/manual
visibility, individual eye controls, last-actor privacy and local audit events.
Technical diagnostics are manager-only. Phase 4 synchronization/rename and all
Google scopes/permissions remain unchanged. The owner subsequently approved the
Phase 5.1 remote audit and visual correction in staging. See [Phase 5](PHASE5.md)
for historical implementation details and [Phase 6A](PHASE6A.md) for the next boundary.

## Phase 6A — operational hardening and diagnostics

Local version **2026092101 / 0.8.2-alpha**. Local-only site dashboard, task diagnostics, permission audit, bounded recovery/load tests, PoC removal and structural publication default alignment. Phase 5.1 visual smoke was approved by the owner; Phase 6A remote CI, upgrade and staging smoke were subsequently approved by the owner. See [Phase 6A](PHASE6A.md).

## Phase 6B — one-time historical recording migration by CSV

Local implementation **2026092200 / 0.8.3-alpha** on codex/fase-6b-legacy-csv.
Separate CSV references, strict exact matching to Meet-first activities,
preview/confirmation, idempotent transactional import, unified academic catalog,
local eye controls and privacy attribution. CSV normalization happens externally:
no old-name parser, Google requests, Drive changes or mod_googlemeet dependency.
Remote CI and staging smoke remain later authorized stages. See [Phase 6B](PHASE6B.md).
