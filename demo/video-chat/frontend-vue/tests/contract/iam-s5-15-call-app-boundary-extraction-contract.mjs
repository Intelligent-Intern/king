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

function escapedPattern(value) {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function requireText(source, text, message) {
  assert.match(source, new RegExp(escapedPattern(text)), message);
}

const evidence = readText('documentation/iam-sprint-05-call-app-boundary-extraction.md');
const packageJson = JSON.parse(readText('demo/video-chat/frontend-vue/package.json'));
const callAppRevocation = readText('demo/video-chat/frontend-vue/tests/contract/call-access-callapp-revocation-contract.mjs');
const permissionRevocation = readText('demo/video-chat/frontend-vue/tests/contract/call-app-permission-revocation-contract.mjs');
const iframeLaunch = readText('demo/video-chat/frontend-vue/tests/contract/call-app-iframe-launch-contract.mjs');
const marketplaceJourney = readText('demo/video-chat/frontend-vue/tests/contract/call-app-marketplace-to-call-journey-contract.mjs');
const whiteboardInstall = readText('demo/video-chat/frontend-vue/tests/contract/call-app-whiteboard-install-browser-proof-contract.mjs');
const launchTokens = readText('demo/video-chat/backend-king-php/domain/call_apps/call_app_launch_tokens.php');
const sessions = readText('demo/video-chat/backend-king-php/domain/call_apps/call_app_sessions.php');
const crdt = readText('demo/video-chat/backend-king-php/domain/call_apps/call_app_crdt.php');
const lifecycleTest = readText('demo/video-chat/backend-king-php/tests/call-app-session-lifecycle-contract.php');
const marketplaceEntitlementTest = readText('demo/video-chat/backend-king-php/tests/call-app-marketplace-entitlement-contract.php');
const whiteboardBrowserProof = readText('demo/video-chat/frontend-vue/tests/e2e/call-app-whiteboard-install-sidebar.spec.js');

for (const required of [
  'local/iam-e2e-call-app-entitlement-revocation',
  'dd21579f5ce62985febbd7629b67aa0063409eb8',
  'local/iam-e2e-call-app-launch-token-reconnect',
  'a833db6f94883f04fa36cc91ec704bd051e705a0',
  'local/iam-e2e-whiteboard-org-install-final',
  '4367d573f2074f27720d91fd4ef60ad03a06a7f1',
  'local/iam-e2e-disabled-user-session-revocation',
  '5a3fb5c8af08fb08ab3310df8fcce4c19156740b',
  'videochat_call_app_launch_session_availability',
  'token_stale_after_entitlement_change',
  'prod-whiteboard-org-install-proof.sh',
  'No product code, package scripts, shared CI wiring, Call App UI feature files,',
]) {
  requireText(evidence, required, `evidence must include ${required}`);
}

assert.match(
  evidence,
  /does not weaken the desired contract to "revoked_at and expires_at only"/,
  'evidence must preserve the stronger launch-token entitlement/staleness contract as follow-up',
);

const callAppsGate = String(packageJson.scripts?.['test:contract:call-apps'] || '');
for (const contractPath of [
  'call-app-permission-revocation-contract.mjs',
  'call-app-iframe-launch-contract.mjs',
  'call-app-marketplace-to-call-journey-contract.mjs',
  'call-app-whiteboard-install-browser-proof-contract.mjs',
]) {
  assert.match(callAppsGate, new RegExp(escapedPattern(contractPath)), `Call Apps gate must keep ${contractPath}`);
}

const iamGate = String(packageJson.scripts?.['test:contract:iam-call-access'] || '');
assert.match(
  iamGate,
  /call-access-callapp-revocation-contract\.mjs/,
  'IAM call-access gate must keep the Call App revocation boundary contract',
);

assert.match(
  callAppRevocation,
  /launch token validation must re-check IAM call admission[\s\S]*CRDT bootstrap, replay, append, and snapshot must resolve actor admission[\s\S]*whiteboard runtime must stop polling and editing after backend revocation/s,
  'current Call App revocation contract must re-check call admission and stop private iframe state after revocation',
);

assert.match(
  permissionRevocation,
  /denying a user grant must retire that user subject active launch tokens[\s\S]*grant audit payloads must include revocation and reconnect metadata[\s\S]*CRDT bootstrap and replay must reject revoked participants[\s\S]*whiteboard runtime must consume runtime grant denial and disable polling\/editing after revocation/s,
  'permission revocation contract must preserve token retirement, reconnect metadata, CRDT denial, and iframe denial handling',
);

assert.match(
  permissionRevocation,
  /presence relay authorization must evaluate read\/write permission actions[\s\S]*iframe CRDT bridge must require write for presence publish and read for incoming presence delivery/s,
  'permission revocation contract must preserve permission-action presence boundaries',
);

assert.match(
  iframeLaunch,
  /backend must mint persistent hashed short-lived Call App launch tokens[\s\S]*backend must validate launch tokens by hash, revocation, and expiry[\s\S]*launch-token validation must be a public token-scoped endpoint[\s\S]*parent launch code must not expose primary auth material to the iframe/s,
  'iframe launch contract must preserve hashed launch tokens, public validation, and no-primary-token iframe boundary',
);

assert.match(
  marketplaceJourney,
  /backend journey must order and install before listing call availability[\s\S]*backend journey must prove owner-only attach and participant launch admission[\s\S]*marketplace-to-call app journey must not leak primary auth material/s,
  'marketplace journey contract must preserve org install to call availability and launch admission',
);

assert.match(
  whiteboardInstall,
  /browser proof must start from a user-visible Whiteboard organization install action[\s\S]*browser proof must place an order before installing Whiteboard[\s\S]*browser proof must verify installed Whiteboard appears through call app availability before attach[\s\S]*browser proof must not rely on manual database edits or backend storage shortcuts/s,
  'whiteboard install browser contract must preserve organization order/install, availability, attach, and no manual DB shortcuts',
);

assert.match(
  launchTokens,
  /function videochat_call_app_mint_launch_token[\s\S]*videochat_call_app_grant_subject_in_call[\s\S]*participant_not_in_call/s,
  'current mint path must re-check call admission before issuing a launch token',
);
assert.match(
  launchTokens,
  /function videochat_call_app_validate_launch_token[\s\S]*revoked_at[\s\S]*expires_at[\s\S]*session_status[\s\S]*videochat_call_app_grant_subject_in_call[\s\S]*participant_not_in_call/s,
  'current validation path must reject revoked/expired/inactive tokens and re-check call admission',
);
assert.match(
  sessions,
  /function videochat_call_app_retire_launch_tokens_for_grant[\s\S]*UPDATE call_app_launch_tokens[\s\S]*revoked_at/s,
  'current grant mutation path must retire active launch tokens',
);
assert.match(
  sessions,
  /retired_launch_tokens[\s\S]*reconnect_policy[\s\S]*active_launch_tokens_revoked_on_grant_restriction/s,
  'current grant mutation path must report reconnect policy and retired token counts',
);
assert.match(
  crdt,
  /function videochat_call_app_crdt_requires_allowed_grant[\s\S]*participant_grant_denied/s,
  'current CRDT path must have a single explicit allowed-grant denial gate',
);

assert.match(
  lifecycleTest,
  /denying a participant must revoke their active launch token[\s\S]*revoked participant launch token must fail reconnect validation[\s\S]*denied participant launch must not allow CRDT read[\s\S]*denied participant must not bootstrap private CRDT state[\s\S]*denied participant must not replay private CRDT state/s,
  'backend lifecycle proof must cover token revocation, reconnect denial, and private-state denial',
);
assert.match(
  lifecycleTest,
  /guest grant should inherit default allow[\s\S]*guest grant state must apply across reconnect lookups/s,
  'backend lifecycle proof must cover guest grant reconnect lookup',
);

assert.match(
  marketplaceEntitlementTest,
  /catalog whiteboard must start not installed for organization[\s\S]*regular user should not order Call Apps for organization[\s\S]*client tenant override must fail[\s\S]*installation without entitlement should fail[\s\S]*entitlement should be active[\s\S]*installation should be enabled[\s\S]*post-install Whiteboard must appear in call availability/s,
  'marketplace entitlement proof must preserve org-scoped entitlement and installation boundaries',
);
assert.match(
  whiteboardBrowserProof,
  /POST \/api\/marketplace\/call-apps\/whiteboard\/orders[\s\S]*POST \/api\/marketplace\/call-apps\/whiteboard\/installations[\s\S]*GET \/api\/calls\/\$\{CALL_ID\}\/call-apps\/available[\s\S]*POST \/api\/calls\/\$\{CALL_ID\}\/call-app-sessions/s,
  'whiteboard browser proof must exercise order, install, availability, and attach',
);
assert.match(
  whiteboardBrowserProof,
  /\/api\/call-app-sessions\/session-whiteboard-install-proof\/participant-grants[\s\S]*retired_launch_tokens/s,
  'whiteboard browser proof must exercise grant token-retirement payloads',
);

if (!/videochat_call_app_launch_session_availability/.test(launchTokens)) {
  assert.match(
    evidence,
    /Current base does not yet contain\s+`videochat_call_app_launch_session_availability`/,
    'evidence must label launch-token entitlement/staleness revalidation as source-only when current backend lacks it',
  );
}

assert.match(
  evidence,
  /Production proof command is source-only[\s\S]*deploy script and package wiring edits are outside scope/,
  'evidence must preserve production Whiteboard org-install proof as follow-up without editing deploy/package surfaces',
);

process.stdout.write('[iam-s5-15-call-app-boundary-extraction-contract] PASS\n');
