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
        +--> meet service -----> Google Meet REST API
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

Stores master-account metadata and the Moodle OAuth2 issuer reference. Phase 1 will provide the administration interface and connection workflow.

No OAuth access/refresh tokens are stored in this table.

## Safety properties

- No Google resources are deleted when a Moodle activity is deleted in Phase 0.
- Changing the active account never rewrites historical activity ownership automatically.
- Synchronization will be designed to be idempotent.
- Recording publication and Google-file permissions are separate concerns.
