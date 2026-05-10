# IAM7-14 Identity Mismatch Review

Sprint 07 extracts the focused value from
`local/iam-e2e-identity-mismatch-review-flow` without merging the stale branch.

Runtime scope:
- Personalized call-access session issuance remains fail-closed when the
  verified join context no longer matches the authenticated bearer session.
- The stable public error surface stays `409 call_access_conflict` with
  `auth=session_context_changed`; wrong-account host verification remains
  `403 call_access_forbidden` or `429 call_access_rate_limited`.
- The backend now records an `identity_mismatch_review` flag and redacted audit
  events for session-context mismatches before returning the safe screen.
- Host-name verification attempts are fingerprinted, audited, rate-limited, and
  never store the raw host name.

Privacy contract:
- No raw access id, session id, bearer token, host name, account email, or
  foreign account data is written into review payloads or mismatch audit
  payloads.
- Audit rows use fingerprints for access/session correlation and explicit
  `*_logged=false` markers for raw identifiers.

Historical branch assessment:
- `local/iam-e2e-identity-mismatch-review-flow` contains useful identity
  mismatch ideas, but it also carries broad stale IAM, Background, SFU, Gossip,
  frontend, and documentation churn. It is not safe to merge wholesale.
- The current extraction keeps only the backend review/audit/rate-limit proof
  needed by IAM7-14 and leaves broader UI warning-modal/account-update flows
  parked for a separate focused ticket.
