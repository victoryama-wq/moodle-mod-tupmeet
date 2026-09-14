# Codex prompt — Phase 1: master account and OAuth2

Work only in the `mod_tupmeet` repository.

Read `AGENTS.md`, `README.md`, `docs/ARCHITECTURE.md`, and `docs/ROADMAP.md` before changing code.

## Objective

Implement Phase 1: administration and authentication of the replaceable institutional Google master account, without yet creating Google Calendar events or Google Meet spaces.

## Functional requirements

1. Add an administration page under Site administration > Plugins > Activity modules > TUP Meet.
2. Allow an administrator to register a master account using an existing Moodle OAuth2 issuer configured for Google.
3. Store only master-account metadata in `tupmeet_accounts`:
   - display name
   - institutional Google email
   - Moodle OAuth2 issuer id
   - enabled state
   - default state
   - connection status
   - timestamps
4. Never store Google access tokens, refresh tokens, client secrets, or OAuth client ids in custom plaintext plugin fields.
5. Use Moodle core OAuth2 APIs for authentication/token lifecycle.
6. Provide these administrator actions:
   - add/register master account
   - verify connection
   - set as default
   - disable for new meetings
   - reconnect when authorization expires
7. There may be multiple historical account records, but exactly one enabled account may be the default for new meetings.
8. Disabling or replacing the default account must not rewrite `accountid` on existing `tupmeet` activities.
9. Do not delete historical master account rows if any activity references them.
10. Do not call Google Calendar or Google Meet APIs in this phase except the minimum OAuth/user-info verification needed to confirm which Google account was authorized.

## Architecture requirements

Create service classes rather than putting OAuth logic in page scripts. Suggested namespace:

```text
classes/local/account/
  account_manager.php
  oauth_client_factory.php
```

Use Moodle forms for administrator input and Moodle capabilities for administrator-only changes.

## Schema/upgrade requirements

- Treat the current plugin version as already installed.
- Any schema changes must be implemented using XMLDB upgrade steps in `db/upgrade.php` and bump `$plugin->version`.
- Do not edit `db/install.xml` as the only migration mechanism if an installed Phase 0 site would need the change. Keep install.xml synchronized for fresh installs, but add the upgrade path.

## Safety requirements

- No Moodle core changes.
- No changes to legacy `mod_googlemeet`.
- No production deployment.
- No push/merge unless explicitly requested.
- No secrets in repository, fixtures, logs, screenshots, or test data.
- Validate the authenticated Google email and show it to the administrator before setting an account as default.
- On API/OAuth error, preserve prior account/default state.

## Tests

Add automated tests where practical for:

- setting the first enabled account as default;
- switching the default account without altering activity ownership;
- preventing deletion/unsafe removal of an account referenced by activities;
- enforcing only one default account;
- rejecting invalid issuer ids.

## Completion output

When finished, report:

1. Files changed.
2. Database changes and plugin version bump.
3. Administrator workflow to connect the account.
4. Tests executed and results.
5. Anything that still requires manual Google Cloud/Moodle OAuth configuration.
6. Explicit confirmation that Calendar/Meet creation has not yet been enabled.
