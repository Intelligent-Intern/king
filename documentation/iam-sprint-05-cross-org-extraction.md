# IAM Sprint 05 Cross-Organization Extraction

Date: 2026-05-10

Scope: IAM5-08 extraction review for the cross-organization proof branches. This
lane did not port backend/runtime code, package scripts, shared CI wiring, or
planning checkboxes. Background, Gossip, SFU, MediaSecurity, and BTGF areas were
not touched.

## Source Branches

| Branch | Head | Useful cross-organization value | Decision |
| --- | --- | --- | --- |
| `local/iam-e2e-cross-org-remaining-proof-2` | `fd6c33be` | Integration bundle for cross-org denial, active-org switching, foreign personalized/anonymous joins, privacy no-leak checks, and stale invite membership rights. | Source evidence only. The branch is broad and replaces focused contracts and wiring, so it is not a safe wholesale import. |
| `codex/iam-e2e-cross-org-remaining-proof-2-test-only-20260509` | `6cd09066` | Test-only variant of the same remaining proof bundle, including a static `iam-cross-org-remaining-proof-contract.mjs`. | Source evidence only. Its static gate depends on package, CI, and sprint edits outside IAM5-08 scope. |
| `local/iam-e2e-cross-org-active-org-switch` | `e9b02a81` | Tightens active-organization switch coverage so stale or switched organization context cannot mint foreign membership or admin rights. | Covered by current maintained cross-org and stale-role contracts for the no-membership path; multi-tenant active-switch call denial remains documented follow-up. |
| `local/iam-e2e-cross-org-foreign-join-edges` | `bf396945` | Adds foreign personalized link, foreign anonymous link, owner/guest-list non-leak, multi-org account, and temporary-account cross-org edge proofs. | Partially covered by current fail-closed contracts. Call-scoped foreign join positives are not ported in this lane. |
| `local/iam-e2e-foreign-personalized-mismatch` | `17618082` | Adds wrong-host and correct-host foreign personalized-link journeys with verified current-session context and no foreign target data. | Current strong-mismatch privacy contracts preserve the denied no-leak behavior. Correct-host account-update journeys are outside IAM5-08 write scope. |
| `local/iam-e2e-privacy-foreign-data` | `d3dc4f52` | Adds browser/API privacy checks for invalid, wrong-user, and strong-mismatch foreign data responses. | Covered by existing link privacy, mismatch no-leak, and backend privacy contracts for denied paths. Audit minimization expansion is separate proof value. |
| `local/iam-e2e-membership-stale-invite-rights-proof-2` | `c62e3930` | Proves moved, downgraded, promoted, and removed organization members keep only explicit call-scoped invite rights while stale organization powers are re-read. | Current stale-role and membership-removal contracts cover revalidation and call-scoped admission boundaries. The exact stale invite matrix remains source evidence for a backend lane. |

## Extracted Contract

The durable IAM5-08 contract is:

- Organization membership, admin role, owner rights, guest-list rights, and
  active organization selection are evaluated in the target call tenant, never
  copied from another organization.
- A denied cross-org resolve returns a public forbidden envelope with
  `access_link: null` and `call: null`; denied call fetches return
  `calls_forbidden` without private call identifiers, titles, rooms, owner
  emails, or target-user data.
- Active-organization switch state is re-read from backend membership. A stale
  browser snapshot may name another tenant, but it must not mint membership,
  tenant-admin, platform-admin, moderation, or owner-management rights.
- Stale live sessions, local fallback sessions, forged role query/header/body
  hints, and stale decoded auth contexts are revalidated before call access and
  moderation decisions.
- Personalized-link mismatch and invalid-link privacy failures keep the current
  browser session authoritative, do not bind denied foreign sessions, and render
  localized safe errors from stable error codes rather than foreign payload data.
- A call-scoped invite may keep access to its specific call, but it must not
  restore organization-scoped tenant/admin powers for unrelated calls after
  organization movement, downgrade, or removal.

The current Sprint 05 base preserves those guardrails with maintained proofs:

- `demo/video-chat/frontend-vue/tests/contract/call-access-cross-org-contract.mjs`
  pins the seed matrix cross-org denial for `alpha_org_admin` against
  `beta_active`, verifies the beta active-org snapshot carries
  `membership_id: 0`, and asserts denied payloads omit private call data.
- `demo/video-chat/backend-king-php/tests/call-access-cross-org-contract.php`
  proves organization A context cannot fetch organization B invite-only calls,
  stale personalized links do not preserve foreign admin power, open-link guest
  sessions are tenant-scoped, and legacy admin fallback is least privilege.
- `demo/video-chat/frontend-vue/tests/contract/call-access-stale-role-org-switch-contract.mjs`
  and
  `demo/video-chat/backend-king-php/tests/call-access-stale-organization-role-contract.php`
  prove role downgrade, cached session fallback, stale client role hints, and
  stale decoded session context all fail closed.
- `demo/video-chat/frontend-vue/tests/contract/call-access-direct-join-rights-contract.mjs`
  keeps direct-join authorization limited to platform admin, target tenant
  admin, call owner, or guest-list participant, with the cross-org admin case
  denied.
- `demo/video-chat/backend-king-php/tests/call-access-privacy-contract.php`,
  `demo/video-chat/backend-king-php/tests/call-access-strong-mismatch-privacy-contract.php`,
  `demo/video-chat/frontend-vue/tests/contract/call-access-link-privacy-contract.mjs`,
  `demo/video-chat/frontend-vue/tests/contract/call-access-mismatch-no-leak-states-contract.mjs`,
  and
  `demo/video-chat/frontend-vue/tests/contract/call-access-strong-mismatch-privacy-contract.mjs`
  keep invalid and wrong-account link states from leaking foreign person, call,
  calendar, organization, or denied-session data.
- `demo/video-chat/backend-king-php/tests/call-access-membership-removal-contract.php`
  and
  `demo/video-chat/frontend-vue/tests/contract/call-access-removed-members-contract.mjs`
  prove call-scoped sessions and removed-member visibility do not become tenant
  or lobby-wide rights.

## Added Focused Proof

`demo/video-chat/frontend-vue/tests/contract/call-access-cross-org-extract-contract.mjs`
is an unwired static extraction contract for IAM5-08. It reads this document and
the current maintained contracts, then checks that the source branches are
recorded, current tenant-isolation/no-leak guardrails remain present, and the
source-only follow-up value is documented instead of silently claimed as ported.

## Non-ported Source Value

The source branches contain useful backend/browser scenarios that are not safe
to claim from this lane:

- positive foreign personalized-link joins where an organization A account
  enters an organization B call only through an explicit call-scoped invite;
- foreign anonymous links for a logged-in organization A account that bind the
  current account to the organization B call without creating a temporary guest
  or organization B membership;
- multi-tenant active-org switching where current organization B membership
  still does not grant organization B invite-only call access from organization
  A admin state;
- owner-rights and guest-list direct-join non-leak cases across organizations;
- temporary account and stale invite matrices proving moved, downgraded,
  promoted, and removed organization members keep only current call-scoped or
  current organization-scoped rights;
- browser Playwright journeys and package/CI wiring for the source static gates.

Those scenarios should be rebuilt in a focused backend/browser lane against the
current Sprint 05 contracts if they are selected for implementation. They should
not be imported by deleting or replacing the maintained cross-org and stale-role
contracts, and they must not weaken tenant isolation by treating an invite,
active organization switch, or stale browser role as organization membership.
