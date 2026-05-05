# Video Chat Localization Rollout

This note pins the deploy checklist for the video-chat localization sprint. It
does not record a production deploy by itself.

## Preflight

- Run `npm run test:contract:localization` from
  `demo/video-chat/frontend-vue`.
- Run `npm run test:e2e:localization-smoke` from
  `demo/video-chat/frontend-vue` before production deploy when a browser-backed
  local stack is available.
- Run `npm run build` from `demo/video-chat/frontend-vue`.
- Run `demo/video-chat/scripts/check-deploy-idempotency.sh` before deploy.
- Run `bash -n demo/video-chat/scripts/deploy-smoke.sh` before deploy.

If local PHP does not include `pdo_sqlite`, backend SQLite contracts may skip
locally. They must run in a SQLite-enabled backend test runtime before treating
the migration proof as complete.

## Migration Scope

Localization schema changes are additive:

- `0030_localization_foundation` creates `supported_locales` and
  `translation_resources`, seeds the website-source locale list, adds
  `users.locale DEFAULT 'en'`, backfills existing users to `en`, and adds
  lookup indexes.
- `0031_translation_import_history` creates `translation_imports` and related
  import-history indexes.

The deploy applies these migrations through the normal King PHP SQLite
bootstrap. The schema contract must prove `supported_locales`,
`translation_resources`, English default backfill, tenant/global translation
override behavior, and RTL locale metadata.

## Production Smoke

After deploy:

- Run `demo/video-chat/scripts/deploy-smoke.sh`.
  Its admin operations smoke logs out through `/api/auth/logout`, so the
  temporary smoke session is revoked after the protected checks.
  The smoke also verifies the authenticated session payload, default `en`
  locale, `ltr` direction, and seeded `en`, `de`, `ar`, and `sgd` locales.
  It then runs a primary-superadmin localization CSV preview against
  `/api/admin/localization/imports/preview` without committing imported rows.
- Log in as an existing user and confirm the default locale is English.
- Switch a test user to `de`, reload, and confirm Settings and navigation stay
  localized.
- Switch a test user to `ar`, reload, and confirm `dir="rtl"` shell, sidebar,
  settings modal, and admin layout direction.
- Switch a test user to `sgd` as the additional RTL website-source locale and
  repeat the RTL layout smoke.
- As primary superadmin `user_id = 1`, upload a translation CSV through
  Administration -> Localization, run preview, confirm row-level validation,
  then commit only after a clean preview.

## Rollback

Because the migrations are additive, the safe rollback is code-first:

- Redeploy the previous frontend/backend build if localization UI or runtime
  behavior regresses.
- Keep `supported_locales`, `translation_resources`, `translation_imports`, and
  `users.locale` in place during rollback; old code ignores them.
- Keep existing users on `en` by default; do not mutate passwords, sessions, or
  tenant memberships.
- If a bad CSV import caused visible copy issues, remove the affected
  `translation_resources` rows or upload a corrected CSV. Keep
  `translation_imports` as the audit trail.
- Drop localization tables or the `users.locale` column only after an explicit
  database backup and a separate maintenance decision. That is not part of the
  normal rollback.
