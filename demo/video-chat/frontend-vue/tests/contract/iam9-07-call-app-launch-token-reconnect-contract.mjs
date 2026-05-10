import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(root, '../../..');

async function read(relativePath) {
  return readFile(path.join(repoRoot, relativePath), 'utf8');
}

const [
  launchTokens,
  launchRetirement,
  sessions,
  migrations,
  moduleCallApps,
  lifecycleContract,
] = await Promise.all([
  read('demo/video-chat/backend-king-php/domain/call_apps/call_app_launch_tokens.php'),
  read('demo/video-chat/backend-king-php/domain/call_apps/call_app_launch_token_retirement.php'),
  read('demo/video-chat/backend-king-php/domain/call_apps/call_app_sessions.php'),
  read('demo/video-chat/backend-king-php/support/call_app_session_migrations.php'),
  read('demo/video-chat/backend-king-php/http/module_call_apps.php'),
  read('demo/video-chat/backend-king-php/tests/call-app-session-lifecycle-contract.php'),
]);

assert.match(
  migrations,
  /CREATE TABLE IF NOT EXISTS call_app_launch_tokens[\s\S]*token_hash TEXT NOT NULL[\s\S]*expires_at TEXT NOT NULL[\s\S]*revoked_at TEXT[\s\S]*function videochat_call_app_launch_token_session_binding_migration_statements[\s\S]*source_session_id/,
  'launch tokens must be persisted by hash with expiry, revocation, and optional source-session binding',
);

assert.match(
  launchTokens,
  /function videochat_call_app_mint_launch_token[\s\S]*videochat_call_app_launch_session_availability\(\$pdo, \$record\)[\s\S]*videochat_call_app_grant_subject_in_call\([\s\S]*videochat_call_app_launch_source_session_active\([\s\S]*source_session_id[\s\S]*INSERT INTO call_app_launch_tokens/s,
  'minting must re-check availability, participant scope, and active primary session before binding a launch token',
);

assert.match(
  launchTokens,
  /function videochat_call_app_validate_launch_token[\s\S]*tokens\.token_hash = :token_hash[\s\S]*token_revoked[\s\S]*token_expired[\s\S]*session_not_active/s,
  'reconnect validation must resolve by token hash and reject revoked, expired, or inactive-session tokens',
);

assert.match(
  launchTokens,
  /function videochat_call_app_validate_launch_token[\s\S]*videochat_call_app_launch_source_session_active\([\s\S]*videochat_call_app_retire_launch_token_row\([\s\S]*auth_revoked/s,
  'reconnect validation must re-check the bound primary session and retire launch tokens when auth is revoked',
);

assert.match(
  launchTokens,
  /function videochat_call_app_validate_launch_token[\s\S]*token_stale_after_session_reactivation[\s\S]*videochat_call_app_launch_session_availability\(\$pdo, \$record, \$issuedAt\)[\s\S]*internal_admin_required[\s\S]*participant_not_in_call[\s\S]*token_stale_after_grant_change/s,
  'reconnect validation must fail closed after session reactivation, entitlement changes, internal-role loss, call removal, or grant changes',
);

assert.match(
  launchTokens,
  /function videochat_call_app_launch_session_availability[\s\S]*installation_status[\s\S]*entitlement_status[\s\S]*entitlement_expires_at[\s\S]*catalog_health_status[\s\S]*token_stale_after_entitlement_change/s,
  'availability checks must include installation state, entitlement state/expiry, catalog health, and post-issue entitlement updates',
);

assert.match(
  launchRetirement,
  /function videochat_call_app_retire_launch_tokens_for_grant[\s\S]*UPDATE call_app_launch_tokens[\s\S]*issued_to_user_id[\s\S]*revoked_at/s,
  'grant restriction must retire active launch tokens for that participant subject',
);

assert.match(
  sessions,
  /retired_launch_tokens[\s\S]*reconnect_policy[\s\S]*active_launch_tokens_revoked_on_grant_restriction[\s\S]*current_grant_rechecked_on_reconnect/s,
  'grant audit payloads must keep reconnect policy and retired-token metadata visible',
);

assert.match(
  moduleCallApps,
  /launch-token\/validate[\s\S]*videochat_call_app_validate_launch_token[\s\S]*call_app_launch_token_failed[\s\S]*stage' => 'validate'/s,
  'the public token-scoped validate endpoint must emit launch-token diagnostics without needing a primary session',
);

assert.match(
  moduleCallApps,
  /launch-token[\s\S]*videochat_get_call_for_user[\s\S]*videochat_call_app_mint_launch_token\([\s\S]*apiAuthContext\['session'\][\s\S]*stage' => 'mint'/s,
  'the authenticated mint endpoint must use current call access and pass the primary session binding into token issuance',
);

assert.match(
  lifecycleContract,
  /revoked participant launch token must fail reconnect validation[\s\S]*cross-call launch token replay must fail closed[\s\S]*expired launch token must fail reconnect validation[\s\S]*launch token validation must fail after entitlement revocation/s,
  'backend lifecycle proof must cover revoked, cross-call, expired, and entitlement-revoked reconnect attempts',
);

assert.match(
  lifecycleContract,
  /status-only launch token must not gain CRDT rights after read-only reconnect[\s\S]*pre-inactivation launch token must not revive after session reactivation[\s\S]*token_stale_after_session_reactivation/s,
  'backend lifecycle proof must cover permission broadening and session-reactivation stale-token reconnects',
);

console.log('[iam9-07-call-app-launch-token-reconnect-contract] PASS');
