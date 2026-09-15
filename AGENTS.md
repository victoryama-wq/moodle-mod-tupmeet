# AGENTS.md — TUP Meet

## Project identity

- Moodle component: `mod_tupmeet`
- Product name: TUP Meet
- Minimum Moodle version: 4.5 LTS
- Design targets: Moodle 4.5, 5.0, 5.1; newer supported branches must be validated before being declared supported.
- License: GPL v3 or later.

## Non-negotiable architecture rules

1. Never modify Moodle core.
2. Never modify `mod_googlemeet` as part of this project.
3. Never hard-code a Google organizer email, OAuth client id, secret, token, Drive folder name, or meeting owner.
4. Treat the Google master account as a replaceable entity with historical identity.
5. Every TUP Meet activity must retain the account id that owned it when created.
6. Changing the default master account must not silently reassign historical meetings or recordings.
7. Never delete Google Calendar events, Meet spaces, or Drive recordings as a side effect of deleting a Moodle activity unless a future explicit, separately confirmed administrative workflow is designed.
8. Never change Google Drive sharing permissions automatically merely to make a recording visible.
9. Use official Google Calendar API and Google Meet REST API; do not depend on Drive folder names to discover recordings.
10. All synchronization operations must be idempotent.
11. Use Moodle APIs, XMLDB, capabilities, scheduled tasks, OAuth2 facilities, privacy API, output/rendering APIs, and coding style where applicable.
12. Do not store OAuth access or refresh tokens in custom plaintext database fields.
13. External API failures must not corrupt Moodle activity state.
14. Do not push, merge, deploy, or change production unless explicitly authorized.
15. Add upgrade steps for every persistent schema change after the first installable version.

## Development sequence

- Phase 0: installable activity skeleton, form, schema, architecture documentation.
- Phase 1: master-account administration and Moodle OAuth2 integration.
- Phase 2: Google Calendar event creation, recurrence and Meet conferenceData generation.
- Phase 3: Meet REST configuration, automatic recording and transcription where supported.
- Phase 4: conference/recording synchronization via Meet REST API.
- Phase 5: recording review/publication UI and scheduled synchronization.
- Phase 6: migration assistant for legacy `mod_googlemeet` activities.
- Phase 7: compatibility and regression suite.

## Required checks before a phase is considered complete

- PHP syntax passes.
- Moodle coding standards are reviewed.
- Database changes are upgrade-safe.
- No secrets or credentials are committed.
- No external deletion is performed implicitly.
- Behavior is tested at least on Moodle 4.5 and the current target 5.x environment.
