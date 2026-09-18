# TUP Meet (`mod_tupmeet`)

TUP Meet is an institutional Moodle activity module intended to create and manage Google Meet sessions from Moodle while maintaining institutional control of the organizer account and recording history.

## Phase 3.4 — Meet-first for new activities

Version `2026091801` (`0.6.0-alpha`) promotes the successful staging PoC to the normal activity workflow. See [Phase 3.4](docs/PHASE3_4.md) for the owner-reported real evidence, upgrade, independent states, conservative Space creation and pending integrated smoke.

- Every new activity creates one Meet REST Space, persists its canonical name/URI/code, and independently reconciles the teacher COHOST, automatic artifacts and a native Calendar event using that same Meet.
- Calendar invitations include the server-validated teacher and use `sendUpdates=all`. A subsequent GET must confirm the event, conference and attendee before Calendar becomes ready.
- One Space serves the whole recurring series. Name/schedule edits update Calendar; recording/transcription edits configure the same Space.
- Join remains available when Space is ready even if Calendar fails. Students do not see technical diagnostics.
- Space creation is not safely repeatable after an ambiguous response: timeout/transport failure requires manual review, without a second automatic POST. Explicit HTTP 429 uses backoff; normal creation has no fixed throttle.
- Historical activities retain `calendar` or `legacy` mode, owner and identifiers. They are not migrated and no longer automatically write COHOST membership on Calendar-created Spaces; existing manual assignments remain.
- Account administration remains under Site administration > Plugins > Activity modules > TUP Meet, using native Moodle OAuth2 and one verified enabled default for new activities.
- Credentials/tokens remain in Moodle OAuth2. Local deletion never deletes Google events, Spaces, Members or recordings.

The isolated [PoC](docs/PHASE3_3_POC.md) remains staging-only for comparison and must be removed or disabled before a production release. Its cache is never used by normal activities.

**Phase 4, recording/transcript retrieval, Drive API, MP4 downloads, publication and legacy migration are not implemented.** Publication remains a preference. Artifact creation depends on Workspace licensing/policies and a privileged participant joining; configuration readiness is not evidence that a recording exists.

Historical design/evidence: [Phase 1](docs/PHASE1.md), [Phase 2](docs/PHASE2.md), [Phase 2 smoke](docs/PHASE2_SMOKE.md), [Phase 3](docs/PHASE3.md), [Phase 3.2](docs/PHASE3_2.md), [Phase 3.2.1](docs/PHASE3_2_1.md). Current behavior is described by Phase 3.4. [Continuous integration](docs/CI.md) retains QUALITY and Moodle 4.5/5.0/5.1 PHPUnit jobs; local tests do not imply remote validation.

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
