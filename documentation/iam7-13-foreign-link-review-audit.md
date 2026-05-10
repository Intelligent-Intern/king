# IAM7-13 Foreign Link Review Audit

`local/iam-e2e-foreign-link-review-audit` was inspected as historical source
evidence, but it is not safe to merge wholesale. Its branch tip also carries
broad stale call-app, realtime, IAM, media, and cleanup churn that is outside
this sprint ticket.

The focused current proof extracted here keeps only the IAM7-13 value:
foreign/duplicate personalized-link review flags and related mismatch audit
events are scoped to the resolved call tenant and call id, not a stale or
foreign link tenant. Review flags deduplicate per access fingerprint and
foreign subject. Persisted review/audit rows keep actor, target, call, status,
timestamps, and fingerprints for reviewer understanding while omitting raw
access ids, session ids, tokens, cookies, SDP, ICE, host names, emails, call
titles, and person names.

Verification targets:
- `call-access-foreign-link-review-audit-contract.php`
- `call-access-foreign-link-review-audit-contract.sh`
- `call-access-foreign-link-review-audit-contract.mjs`
