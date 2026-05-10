import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function readText(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function assertOrdered(source, needles, message) {
  let offset = -1;
  for (const needle of needles) {
    const next = source.indexOf(needle, offset + 1);
    assert(next > offset, `${message}: missing or out of order "${needle}"`);
    offset = next;
  }
}

const launchTokens = readText('demo/video-chat/backend-king-php/domain/call_apps/call_app_launch_tokens.php');
const sessionLifecycle = readText('demo/video-chat/backend-king-php/domain/call_apps/call_app_session_lifecycle.php');
const crdt = readText('demo/video-chat/backend-king-php/domain/call_apps/call_app_crdt.php');
const marketplaceEntitlementTest = readText('demo/video-chat/backend-king-php/tests/call-app-marketplace-entitlement-contract.php');
const extractionDoc = readText('documentation/iam7-08-call-app-entitlement-revocation.md');

assertOrdered(
  extractionDoc,
  [
    'local/iam-e2e-call-app-entitlement-revocation',
    'dd21579f',
    'The current integration base already contains a stronger version',
    'demo/video-chat/backend-king-php/domain/call_apps/call_app_launch_tokens.php',
    'demo/video-chat/backend-king-php/tests/call-app-marketplace-entitlement-contract.php',
    'demo/video-chat/frontend-vue/tests/contract/iam9-06-call-app-entitlement-revocation-contract.mjs',
  ],
  'IAM9-06 extraction note must identify the historical source and current proof files',
);

assert.match(
  extractionDoc,
  /without\s+depending on `SPRINT\.md`, `BACKLOG\.md`, or `READYNESS_TRACKER\.md` edits/,
  'IAM9-06 extraction note must keep planning files out of this proof scope',
);

assert.match(
  launchTokens,
  /function videochat_call_app_launch_session_availability[\s\S]*organization_call_app_installations[\s\S]*organization_call_app_entitlements[\s\S]*call_app_catalog_entries[\s\S]*installation_disabled[\s\S]*entitlement_not_active[\s\S]*entitlement_expired[\s\S]*app_unhealthy[\s\S]*token_stale_after_entitlement_change/s,
  'launch-token availability must re-check installation, entitlement, expiry, catalog health, and reconnect staleness',
);

assert.match(
  launchTokens,
  /function videochat_call_app_mint_launch_token[\s\S]*videochat_call_app_launch_session_availability\(\$pdo, \$record\)[\s\S]*\$availability\['reason'\] \?\? 'app_not_available'/s,
  'launch-token mint must fail closed through current organization installation and entitlement availability',
);

assert.match(
  launchTokens,
  /function videochat_call_app_validate_launch_token[\s\S]*\$issuedAt = \(string\) \(\$tokenRow\['issued_at'\] \?\? ''\)[\s\S]*videochat_call_app_launch_session_availability\(\$pdo, \$record, \$issuedAt\)[\s\S]*\$availability\['reason'\] \?\? 'app_not_available'/s,
  'launch-token validation must reject reconnect tokens after organization installation or entitlement changes',
);

assert.match(
  sessionLifecycle,
  /function videochat_call_app_session_installation_available[\s\S]*installations\.status = 'enabled'[\s\S]*entitlements\.status = 'active'[\s\S]*entitlements\.expires_at[\s\S]*catalog\.health_status = 'healthy'/s,
  'session lifecycle availability must require enabled installation, active entitlement, unexpired entitlement, and healthy catalog metadata',
);

assert.match(
  sessionLifecycle,
  /function videochat_call_app_update_session[\s\S]*\$status === 'active'[\s\S]*videochat_call_app_session_installation_available\(\$pdo, \$tenantId, \$record\)[\s\S]*app_not_available/s,
  'stale session activation must be blocked after organization entitlement revocation',
);

assert.match(
  crdt,
  /function videochat_call_app_crdt_session_for_actor[\s\S]*videochat_call_app_session_installation_available\(\$pdo, \$tenantId, \$record\)[\s\S]*app_not_available/s,
  'cached CRDT bootstrap, replay, and append paths must close after organization entitlement revocation',
);

assert.match(
  marketplaceEntitlementTest,
  /revoked organization entitlement must remove Whiteboard from call availability[\s\S]*revoked organization entitlement must block Call App session start[\s\S]*revoked organization entitlement must block stale session activation[\s\S]*revoked organization entitlement must block launch for existing sessions[\s\S]*revoked organization entitlement must invalidate existing launch tokens[\s\S]*revoked organization entitlement must block cached CRDT bootstrap[\s\S]*revoked organization entitlement must block cached CRDT append/s,
  'marketplace entitlement proof must cover availability, session, launch-token, and CRDT revocation surfaces',
);

assert.match(
  marketplaceEntitlementTest,
  /app_not_available'[\s\S]*post-revoke session start must fail because the app is unavailable[\s\S]*app_not_available'[\s\S]*post-revoke session activation must fail because the app is unavailable[\s\S]*entitlement_not_active'[\s\S]*post-revoke launch must fail because the entitlement is not active[\s\S]*entitlement_not_active'[\s\S]*post-revoke launch-token validation must fail because the entitlement is not active[\s\S]*app_not_available'[\s\S]*post-revoke CRDT bootstrap must fail because the app is unavailable[\s\S]*app_not_available'[\s\S]*post-revoke CRDT append must fail because the app is unavailable/s,
  'marketplace entitlement proof must preserve exact fail-closed reasons for each revocation surface',
);

console.log('[iam9-06-call-app-entitlement-revocation-contract] PASS');
