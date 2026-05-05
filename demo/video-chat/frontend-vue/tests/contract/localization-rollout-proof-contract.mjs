import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const frontendRoot = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(frontendRoot, '../../..');

async function source(relativePath) {
  return readFile(path.join(repoRoot, relativePath), 'utf8');
}

const rollout = await source('documentation/dev/video-chat/localization-rollout.md');
const migrations = await source('demo/video-chat/backend-king-php/support/localization.php');
const migrationRegistry = await source('demo/video-chat/backend-king-php/support/database_migrations.php');
const packageJson = await source('demo/video-chat/frontend-vue/package.json');
const deploySmoke = await source('demo/video-chat/scripts/deploy-smoke.sh');

for (const requiredText of [
  'npm run test:contract:localization',
  'npm run test:e2e:localization-smoke',
  'npm run build',
  'demo/video-chat/scripts/check-deploy-idempotency.sh',
  'demo/video-chat/scripts/deploy-smoke.sh',
  'pdo_sqlite',
  '0030_localization_foundation',
  '0031_translation_import_history',
  'supported_locales',
  'translation_resources',
  'translation_imports',
  'users.locale',
  'DEFAULT \'en\'',
  'de',
  'ar',
  'sgd',
  'user_id = 1',
  'CSV',
  '/api/auth/logout',
  '/api/admin/localization/imports/preview',
  'without committing imported rows',
  'temporary smoke session is revoked',
  'default `en`',
  '`ltr` direction',
  'additive',
  'code-first',
]) {
  assert.ok(rollout.includes(requiredText), `rollout note must mention ${requiredText}`);
}

assert.match(migrationRegistry, /0030_localization_foundation/, 'migration registry must include localization foundation');
assert.match(migrationRegistry, /0031_translation_import_history/, 'migration registry must include translation import history');
assert.match(migrations, /CREATE TABLE IF NOT EXISTS supported_locales/, 'supported locales migration must be additive');
assert.match(migrations, /CREATE TABLE IF NOT EXISTS translation_resources/, 'translation resources migration must be additive');
assert.match(migrations, /CREATE TABLE IF NOT EXISTS translation_imports/, 'translation imports migration must be additive');
assert.match(migrations, /ALTER TABLE users ADD COLUMN locale TEXT NOT NULL DEFAULT 'en'/, 'user locale column must default existing users to English');
assert.match(migrations, /UPDATE users\s+SET locale = 'en'/, 'migration must backfill invalid or empty user locales to English');
assert.match(migrations, /CREATE UNIQUE INDEX IF NOT EXISTS idx_translation_resources_scope_key/, 'translation resource uniqueness must be idempotent');
assert.match(packageJson, /test:e2e:localization-smoke/, 'package scripts must expose localization smoke proof');
assert.match(packageJson, /test:contract:localization/, 'package scripts must expose localization contract proof');
assert.match(deploySmoke, /ADMIN_SMOKE_SESSION_TOKEN/, 'deploy smoke must track temporary admin session for cleanup');
assert.match(deploySmoke, /trap cleanup_admin_session EXIT/, 'deploy smoke must cleanup admin session on early exit');
assert.match(deploySmoke, /\/api\/auth\/logout/, 'deploy smoke must revoke the temporary admin session');
assert.match(deploySmoke, /VIDEOCHAT_DEPLOY_SMOKE_EXPECT_USER_LOCALE:-en/, 'deploy smoke must default the authenticated locale smoke to English');
assert.match(deploySmoke, /\/api\/auth\/session/, 'deploy smoke must verify authenticated session payload');
assert.match(deploySmoke, /supported locale missing/, 'deploy smoke must verify seeded rollout locales');
assert.match(deploySmoke, /admin session payload localization mismatch/, 'deploy smoke must aggregate authenticated localization payload failures');
assert.match(deploySmoke, /\/api\/admin\/localization\/imports\/preview/, 'deploy smoke must exercise superadmin localization CSV preview');
assert.match(deploySmoke, /deploy-smoke-preview\.csv/, 'deploy smoke must use a named preview-only CSV payload');
assert.match(deploySmoke, /superadmin preview verified without commit/, 'deploy smoke must document that CSV proof is preview-only');
assert.doesNotMatch(deploySmoke, /\/api\/admin\/localization\/imports\/commit/, 'deploy smoke must not commit localization CSV rows');
assert.match(deploySmoke, /admin session cleanup failed/, 'deploy smoke must surface admin session cleanup failure');

console.log('[localization-rollout-proof-contract] PASS');
