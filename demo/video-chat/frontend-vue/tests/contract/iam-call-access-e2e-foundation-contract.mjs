import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const videoChatRoot = path.resolve(frontendRoot, '..');
const repoRoot = path.resolve(videoChatRoot, '../..');

function readText(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function readJson(relativePath) {
  return JSON.parse(readText(relativePath));
}

const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const matrix = readJson('demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json');
const e2eSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js');
const backendContract = readText('demo/video-chat/backend-king-php/tests/call-access-membership-removal-contract.php');
const smoke = readText('demo/video-chat/scripts/smoke.sh');
const auth = readText('demo/video-chat/backend-king-php/support/auth.php');
const authCache = readText('demo/video-chat/backend-king-php/support/auth_session_cache.php');
const tenantContext = readText('demo/video-chat/backend-king-php/support/tenant_context.php');
const callAccessPublic = readText('demo/video-chat/backend-king-php/domain/calls/call_access_public.php');

const scripts = packageJson.scripts || {};

assert.match(
  String(scripts['test:e2e:call-access'] || ''),
  /playwright test tests\/e2e\/call-access-join\.spec\.js/,
  'package script must expose the focused Call Access Playwright spec',
);
assert.match(
  String(scripts['test:contract:iam-call-access'] || ''),
  /iam-call-access-e2e-foundation-contract\.mjs/,
  'package script must expose the IAM Call Access contract gate',
);
assert.match(
  String(scripts['test:contract:iam-call-access'] || ''),
  /\.\.\/backend-king-php\/tests\/call-access-membership-removal-contract\.sh/,
  'IAM Call Access contract gate must include the backend membership-removal proof',
);
assert.match(
  String(scripts['test:e2e:matrix'] || ''),
  /tests\/e2e\/call-access-join\.spec\.js/,
  'compose E2E matrix must execute the Call Access join spec',
);

const uiParityPaths = new Set(matrix.commands?.['frontend:e2e:ui-parity']?.paths || []);
const matrixPaths = new Set(matrix.commands?.['frontend:e2e:matrix']?.paths || []);
const requiredSpecs = new Set(matrix.release_gate?.required_ui_parity_specs || []);
assert.ok(
  uiParityPaths.has('frontend-vue/tests/e2e/call-access-join.spec.js'),
  'UI parity matrix must list the Call Access join spec',
);
assert.ok(
  matrixPaths.has('frontend-vue/tests/e2e/call-access-join.spec.js'),
  'compose E2E matrix must list the Call Access join spec',
);
assert.ok(
  requiredSpecs.has('frontend-vue/tests/e2e/call-access-join.spec.js'),
  'release gate must pin the Call Access join spec as required coverage',
);

assert.match(e2eSpec, /\/api\/call-access\/\$\{accessId\}\/join/, 'E2E spec must observe the public join resolution request');
assert.match(e2eSpec, /\/api\/call-access\/\$\{accessId\}\/session/, 'E2E spec must observe the public call-access session request');
assert.match(e2eSpec, /tenant_admin[\s\S]*false/, 'E2E spec must assert the session does not gain tenant-admin rights');
assert.match(
  backendContract,
  /videochat_tenant_user_is_member\(\$pdo, \$invitedUserId, \$tenantId\)[\s\S]*membership removal/s,
  'backend contract must prove losing tenant membership remains effective',
);
assert.match(
  backendContract,
  /videochat_resolve_call_access_public\(\$pdo, \$accessId\)[\s\S]*remain resolvable/s,
  'backend contract must prove explicit call-scoped links remain resolvable',
);
assert.match(
  backendContract,
  /tenant_admin[\s\S]*false/,
  'backend contract must prove call-scoped fallback does not restore tenant admin rights',
);
assert.match(
  smoke,
  /call-access-membership-removal-contract\.sh/,
  'smoke gate must include the backend call-access membership-removal contract',
);
assert.match(
  auth + authCache,
  /videochat_tenant_context_for_call_access_session/,
  'auth paths must fall back to call-scoped tenant context for access sessions',
);
assert.match(
  tenantContext,
  /membership_id,[\s\S]*0 AS membership_id,[\s\S]*'member' AS membership_role/s,
  'call-scoped tenant fallback must be least-privilege and must not invent membership ids',
);
assert.match(
  callAccessPublic,
  /videochat_fetch_active_user_for_call_access\([\s\S]*false[\s\S]*\);/,
  'public call-access resolution must allow explicit invitation lookup without active tenant membership',
);

process.stdout.write('[iam-call-access-e2e-foundation-contract] PASS\n');
