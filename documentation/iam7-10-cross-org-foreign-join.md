# IAM7-10 Cross-Org Foreign Join Proof

Status: extracted focused current value from
`local/iam-e2e-cross-org-foreign-join-edges`; the historical branch remains
parked because it contains broad stale Sprint, Background, SFU, Gossip,
Call-App, and unrelated IAM churn.

Accepted current value:
- same-organization admin call access/admin rights are proven before stale
  membership changes;
- organization-admin rights are re-read and do not survive disabled
  organization membership;
- organization A admin/user context cannot fetch or administer organization B
  invite-only calls;
- active-tenant switching cannot mint organization B membership;
- stale personalized organization B links do not grant organization A users
  call access;
- foreign verified context on a personalized organization B link fails with a
  conflict, does not persist a call-access session, and returns no private
  organization B call or target-user data;
- organization B open links remain call-scoped and do not grant access to other
  organization B invite-only calls.

Proof:
- `demo/video-chat/backend-king-php/tests/call-access-cross-org-contract.php`
  now carries the runtime assertions.
- `demo/video-chat/frontend-vue/tests/contract/call-access-cross-org-contract.mjs`
  pins the focused backend proof so the IAM contract gate keeps the edge cases
  wired.
