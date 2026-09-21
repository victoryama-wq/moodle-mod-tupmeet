# TUP Meet (`mod_tupmeet`)

TUP Meet is an institutional Moodle activity module intended to create and manage Google Meet sessions from Moodle while maintaining institutional control of the organizer account and recording history.

## Phase 6A — operational hardening and local diagnostics

Version **2026092101 / 0.8.2-alpha** adds a site-config-only system dashboard using stored local evidence, removes the experimental PoC and aligns the structural publication default with automatic. Existing activity preferences and the academic/Google flows are preserved. See [Phase 6A](docs/PHASE6A.md) for security, task thresholds, validation and the pending staging smoke.

## Preserved Phase 5 — academic experience and local publication

Version **2026091901 / 0.8.0-alpha** presents the next session and a responsive class-recording table. New activities default to automatic publication: newly discovered recordings are initially visible. Teachers can show/hide each recording using Moodle's eye control; subsequent discovery or preference edits preserve individual decisions. Technical diagnostics remain in a separate manager-only panel. See [Phase 5](docs/PHASE5.md) for the upgrade policy, privacy, access boundary and staging checklist.

Phase 4's discovery, polling and metadata-only rename are preserved; its real automatic-discovery/rename smoke was confirmed by the owner. Existing Drive/OAuth setup and scopes remain unchanged. Moodle controls link visibility, not Drive permissions: another institutional reader with a shared link may still access the recording directly. There is no media download, permission/folder change or transcript retrieval.

## Preserved Phase 3.4 — Meet-first for new activities

Version `2026091802` (`0.6.1-alpha`) retains the Meet-first workflow and expresses recurrence `UNTIL` at the start time on the final local date. See [Phase 3.4.1](docs/PHASE3_4_1.md) for this limited hardening and the approved Meet-first smoke, and [Phase 3.4](docs/PHASE3_4.md) for the workflow, upgrade and independent states.

- Every new activity creates one Meet REST Space, persists its canonical name/URI/code, and independently reconciles the teacher COHOST, automatic artifacts and a native Calendar event using that same Meet.
- Calendar invitations include the server-validated teacher and use `sendUpdates=all`. A subsequent GET must confirm the event, conference and attendee before Calendar becomes ready.
- One Space serves the whole recurring series. Name/schedule edits update Calendar; recording/transcription edits configure the same Space.
- Join remains available when Space is ready even if Calendar fails. Students do not see technical diagnostics.
- Space creation is not safely repeatable after an ambiguous response: timeout/transport failure requires manual review, without a second automatic POST. Explicit HTTP 429 uses backoff; normal creation has no fixed throttle.
- Historical activities retain `calendar` or `legacy` mode, owner and identifiers. They are not migrated and no longer automatically write COHOST membership on Calendar-created Spaces; existing manual assignments remain.
- Account administration remains under Site administration > Plugins > Activity modules > TUP Meet, using native Moodle OAuth2 and one verified enabled default for new activities.
- Credentials/tokens remain in Moodle OAuth2. Local deletion never deletes Google events, Spaces, Members or recordings.

The experimental PoC was removed in Phase 6A. Its [historical evidence](docs/PHASE3_3_POC.md) remains; replace the plugin directory and purge Moodle caches when upgrading so removed PHP files cannot remain accessible.

Phase 4 provides recording discovery and Drive filename updates. Phase 5 adds local academic visibility independently of rename success. Artifact creation depends on Workspace licensing/policies and a privileged participant joining; configuration readiness is not evidence that a recording exists.

Historical design/evidence: [Phase 1](docs/PHASE1.md), [Phase 2](docs/PHASE2.md), [Phase 2 smoke](docs/PHASE2_SMOKE.md), [Phase 3](docs/PHASE3.md), [Phase 3.2](docs/PHASE3_2.md), [Phase 3.2.1](docs/PHASE3_2_1.md), [Phase 4](docs/PHASE4.md). Current behavior combines Phase 3.4, Phase 4 and Phase 5. [Continuous integration](docs/CI.md) retains QUALITY and Moodle 4.5/5.0/5.1 PHPUnit jobs; local tests do not imply remote validation.

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
