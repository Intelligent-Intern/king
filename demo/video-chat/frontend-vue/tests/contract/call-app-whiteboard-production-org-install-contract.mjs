import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(root, '../../..');

async function read(relativePath) {
  return readFile(path.join(repoRoot, relativePath), 'utf8');
}

const [proofScript, packageJsonSource, sprint] = await Promise.all([
  read('demo/video-chat/scripts/prod-whiteboard-org-install-proof.sh'),
  read('demo/video-chat/frontend-vue/package.json'),
  read('SPRINT.md'),
]);

const packageJson = JSON.parse(packageJsonSource);

assert.match(
  proofScript,
  /DEPLOY_DOMAIN="\$\{VIDEOCHAT_DEPLOY_DOMAIN:-\$\{VIDEOCHAT_V1_PUBLIC_HOST:-kingrt\.com\}\}"/,
  'production proof must default to the kingrt.com service root',
);

assert.match(
  proofScript,
  /app\.kingrt\.com[\s\S]*api\.kingrt\.com[\s\S]*whiteboard\.kingrt\.com/,
  'production proof must pin the kingrt.com app, API, and Whiteboard hosts',
);

assert.match(
  proofScript,
  /api\/marketplace\/call-apps\?query=whiteboard[\s\S]*assert_marketplace_catalog/,
  'production proof must verify Whiteboard is visible and healthy in Marketplace',
);

assert.match(
  proofScript,
  /api\/marketplace\/call-apps\/whiteboard\/orders[\s\S]*api\/marketplace\/call-apps\/whiteboard\/installations/s,
  'production proof must order and install Whiteboard through marketplace endpoints',
);

assert.match(
  proofScript,
  /result\.entitlement\.status[\s\S]*entitlement_status[\s\S]*active/,
  'production proof must verify the marketplace order leaves an active entitlement',
);

assert.match(
  proofScript,
  /default_app_policy"\s*=>\s*"allowed_by_default"[\s\S]*config"\s*=>\s*\["proof"\s*=>\s*"bgf-10-bgf-11"\]/,
  'production proof must persist an explicit organization default policy without manual database edits',
);

assert.match(
  proofScript,
  /api\/calls"[\s\S]*PROOF_CALL_ID[\s\S]*api\/calls\/\$\{PROOF_CALL_ID\}\/call-apps\/available\?query=whiteboard/,
  'production proof must create a real call and verify Call Apps tab availability for that organization',
);

assert.match(
  proofScript,
  /api\/calls\/\$\{PROOF_CALL_ID\}\/call-app-sessions[\s\S]*assert_call_app_session/,
  'production proof must attach Whiteboard to the call after availability',
);

assert.match(
  proofScript,
  /https:\/\/\$\{DEPLOY_CALL_APP_DOMAIN\}\/public\/index\.html/,
  'production proof must verify the Whiteboard iframe host is reachable',
);

assert.match(
  proofScript,
  /cleanup\(\)[\s\S]*DELETE "https:\/\/\$\{DEPLOY_API_DOMAIN\}\/api\/calls\/\$\{PROOF_CALL_ID\}"/,
  'production proof must clean up its temporary call without touching marketplace entitlements',
);

assert.doesNotMatch(
  proofScript,
  /sqlite|PDO|INSERT\s+INTO|UPDATE\s+.*organization_call_app|manual database/i,
  'production proof must not depend on direct database edits',
);

assert.ok(
  packageJson.scripts['test:contract:call-apps'].includes('call-app-whiteboard-production-org-install-contract.mjs'),
  'Call Apps contract suite must include the production Whiteboard org-install proof contract',
);

assert.match(
  sprint,
  /BGF-10 Whiteboard Marketplace Production Proof[\s\S]*prod-whiteboard-org-install-proof\.sh[\s\S]*BGF-11/s,
  'SPRINT.md BGF-10 must reference the production Whiteboard org-install proof command',
);

assert.match(
  sprint,
  /BGF-11 sicherstellen[\s\S]*prod-whiteboard-org-install-proof\.sh/s,
  'SPRINT.md BGF-11 must reference the production Whiteboard org-install proof command',
);

console.log('[call-app-whiteboard-production-org-install-contract] PASS');
