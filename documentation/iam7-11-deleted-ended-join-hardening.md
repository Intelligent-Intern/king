# IAM7-11 Deleted And Ended Join Hardening

Status: extracted on 2026-05-10 in `agent/iam7-11-deleted-ended-join-hardening`.

Historical branch assessed:
- `local/iam-e2e-deleted-ended-join-hardening`

The historical branch carries broad stale IAM work, frontend harness changes,
guest-list management surfaces, owner-absence work, and unrelated contract
churn. It is not safe to merge wholesale. The current extraction keeps only the
deleted/ended/disabled terminal-join proof and the runtime gaps needed by that
proof.

Current value extracted:
- terminal calls (`ended`, `cancelled`, deleted call rows) cannot be joined via
  public call-access links;
- terminal calls cannot issue fresh call-access sessions, and session issuers are
  not invoked on terminal denial;
- stale call-access session bindings fail after terminal call transition;
- disabled/deleted users invalidate stale session bindings with no payload leak;
- cancelled/stale invitations fail closed through public links and direct
  guest-list checks;
- realtime reconnect/admission no longer trusts cached owner/moderator or
  allowed participant state when the current DB call scope is unavailable or
  terminal;
- valid active/scheduled calls remain joinable for allowed participants.

Security shape:
- terminal public routes return only safe error envelopes;
- no raw access id, call title, participant email, generated session id, or guest
  display name is emitted by terminal-denial routes;
- Realtime admission uses the current King DB call role context for call-scoped
  rooms instead of cached connection role state.
