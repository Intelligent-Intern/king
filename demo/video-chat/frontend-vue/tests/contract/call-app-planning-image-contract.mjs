import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function readJson(relativePath) {
  return JSON.parse(read(relativePath));
}

function assertIncludes(source, needle, message) {
  assert.ok(source.includes(needle), message);
}

const appRoot = path.join(repoRoot, 'demo/call-app/planning-image');
assert.ok(fs.existsSync(appRoot), 'planning-image package must exist');

for (const requiredFile of [
  'call-app.manifest.json',
  'mcp.descriptor.json',
  'crdt.schema.json',
  'health.descriptor.json',
  'public/index.html',
  'public/planning-image.css',
  'public/planning-image.js',
]) {
  assert.ok(fs.existsSync(path.join(appRoot, requiredFile)), `planning-image package missing ${requiredFile}`);
}

const manifest = readJson('demo/call-app/planning-image/call-app.manifest.json');
assert.equal(manifest.schema_version, 'king.call_app.manifest.v1', 'manifest schema version mismatch');
assert.equal(manifest.app_key, 'planning-image', 'manifest app_key mismatch');
assert.equal(manifest.status, 'runtime_ready', 'planning-image must advertise runtime readiness');
assert.equal(manifest.category, 'collaboration', 'planning-image category must fit existing marketplace enum');
assert.equal(manifest.default_participant_access, 'allowed_by_default', 'planning-image must default to shared call access');
assert.equal(manifest.iframe?.receives_primary_session_token, false, 'planning-image iframe must not receive primary session tokens');
assert.equal(manifest.iframe?.bridge_protocol, 'king.call_app.iframe.v1', 'planning-image bridge protocol mismatch');
assert.ok(manifest.iframe?.sandbox?.includes('allow-scripts'), 'planning-image sandbox must allow scripts');
assert.ok(!manifest.iframe?.sandbox?.includes('allow-same-origin'), 'planning-image sandbox must keep opaque iframe origin');
assert.ok(manifest.permissions.includes('call_apps.crdt.read'), 'planning-image must request CRDT read permission');
assert.ok(manifest.permissions.includes('call_apps.crdt.append'), 'planning-image must request CRDT append permission');
assert.deepEqual(manifest.exports.map((entry) => entry.format), ['png'], 'planning-image must advertise PNG export');

const mcp = readJson('demo/call-app/planning-image/mcp.descriptor.json');
assert.equal(mcp.schema_version, 'king.call_app.mcp_descriptor.v1', 'MCP schema mismatch');
assert.equal(mcp.app_key, 'planning-image', 'MCP app_key mismatch');
assert.equal(mcp.service_name, 'call_app.planning-image.mcp', 'MCP service name mismatch');
assert.equal(mcp.marketplace_listing?.default_participant_access, 'allowed_by_default', 'MCP listing must expose shared planning default access');
assert.equal(mcp.launch_contract?.primary_session_token_allowed, false, 'MCP launch contract must reject primary tokens');
for (const method of [
  'call_app.describe',
  'call_app.capabilities',
  'call_app.crdt_schema',
  'call_app.launch_contract',
  'call_app.health',
  'call_app.export_formats',
  'call_app.marketplace_listing',
]) {
  assert.ok(mcp.methods.some((entry) => entry.name === method), `MCP descriptor missing ${method}`);
}

const crdt = readJson('demo/call-app/planning-image/crdt.schema.json');
assert.equal(crdt.schema_version, 'king.call_app.crdt_schema.v1', 'CRDT schema mismatch');
assert.equal(crdt.app_key, 'planning-image', 'CRDT app_key mismatch');
assert.equal(crdt.documents?.[0]?.kind, 'planning_image_document', 'planning-image document kind mismatch');
for (const operationType of ['planning_image.replace', 'planning_image.clear', 'planning_image.viewport']) {
  assert.ok(crdt.documents[0].operation_types.includes(operationType), `CRDT schema missing ${operationType}`);
}
assert.equal(crdt.presence?.persisted, false, 'planning-image presence must not be persisted');
assert.deepEqual(crdt.exports, ['png'], 'planning-image CRDT exports must match manifest');

const health = readJson('demo/call-app/planning-image/health.descriptor.json');
const healthPaths = health.checks.map((check) => check.path);
for (const healthPath of ['public/index.html', 'public/planning-image.css', 'public/planning-image.js']) {
  assert.ok(healthPaths.includes(healthPath), `health descriptor missing ${healthPath}`);
}

const html = read('demo/call-app/planning-image/public/index.html');
const css = read('demo/call-app/planning-image/public/planning-image.css');
const runtime = read('demo/call-app/planning-image/public/planning-image.js');
const bundle = `${html}\n${css}\n${runtime}`;

assertIncludes(html, 'meta name="king-call-app-key" content="planning-image"', 'HTML must declare planning-image app key');
assertIncludes(html, '<input id="imageInput" type="file"', 'HTML must expose top image upload control');
assertIncludes(html, '<canvas id="imageCanvas"', 'HTML must expose bottom image canvas');
assertIncludes(html, 'planning-image.css', 'HTML must load extracted stylesheet');
assertIncludes(html, 'planning-image.js', 'HTML must load extracted runtime');
assertIncludes(runtime, "message.type === 'call_app.launch'", 'runtime must wait for launch message');
assertIncludes(runtime, "'call_app.ready'", 'runtime must emit ready after launch');
assertIncludes(runtime, "'call_app.crdt.op.append'", 'runtime must persist image replacements through the Call App CRDT bridge');
assertIncludes(runtime, "'planning_image.replace'", 'runtime must implement shared image replacement operation');
assertIncludes(runtime, "message.type === 'call_app.crdt.ops.response'", 'runtime must consume remote CRDT op polling responses');
assertIncludes(runtime, 'applyImagePayload(envelope.payload || {}, true)', 'runtime must apply shared image replacements from replayed envelopes');
assertIncludes(runtime, 'FileReader', 'runtime must read uploaded images inside the iframe');
assertIncludes(runtime, "canvas.addEventListener('wheel'", 'runtime must support wheel zoom');
assertIncludes(runtime, "canvas.addEventListener('pointerdown'", 'runtime must support pointer pan');
assertIncludes(runtime, 'fitImage()', 'runtime must support fit-to-view');
assertIncludes(runtime, "canvas.toDataURL('image/png')", 'runtime must support PNG export of the current canvas view');
assertIncludes(runtime, 'primary_session_token_received: false', 'runtime must explicitly reject primary token delivery');
assert.ok(!bundle.includes('sessionToken'), 'planning-image bundle must not reference parent session tokens');
assert.ok(!bundle.includes('Authorization'), 'planning-image bundle must not reference authorization headers');

const workspaceState = read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/callAppWorkspaceState.js');
assert.match(
  workspaceState,
  /\['app', 'apps'\]\.includes\(parts\[0\]\)/,
  'Call App iframe URL generation must not require planning-image.kingrt.com when only whiteboard.kingrt.com is configured',
);
assert.doesNotMatch(
  workspaceState,
  /\['app', 'apps', 'whiteboard'\]/,
  'Call App iframe URL generation must keep concrete whiteboard host path-based for additional apps',
);

const sidebarPanel = read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/CallAppsSidebarPanel.vue');
assertIncludes(sidebarPanel, 'function appDefaultPolicy(app)', 'Call Apps sidebar must derive attach defaults from app metadata');
assertIncludes(sidebarPanel, 'defaultPolicy.value = attachDefaultPolicy(app)', 'Call Apps sidebar must preselect shared Planning Image access');

const mcpMetadata = read('demo/video-chat/backend-king-php/domain/call_apps/call_app_mcp_metadata.php');
assertIncludes(mcpMetadata, "'default_participant_access' => (string) ($manifest['default_participant_access'] ?? '')", 'MCP marketplace listing must echo manifest default participant access');

const marketplaceEntitlements = read('demo/video-chat/backend-king-php/domain/call_apps/call_app_marketplace_entitlements.php');
assertIncludes(marketplaceEntitlements, 'function videochat_call_app_catalog_default_participant_access', 'Marketplace install defaults must read package participant access metadata');
assertIncludes(marketplaceEntitlements, "videochat_call_app_catalog_default_participant_access($catalogEntry)", 'Marketplace installation must default to package participant access when payload omits a policy');

const callAppSessions = read('demo/video-chat/backend-king-php/domain/call_apps/call_app_sessions.php');
assertIncludes(callAppSessions, 'function videochat_call_app_update_default_participant_grants', 'Existing active sessions must be able to refresh default participant grants');
assertIncludes(callAppSessions, 'videochat_call_app_update_default_participant_grants($pdo, $tenantId', 'Re-attaching an existing Call App must update stale default grants');

const packageJson = read('demo/video-chat/frontend-vue/package.json');
assertIncludes(packageJson, 'call-app-planning-image-contract.mjs', 'package scripts must include planning-image contract');

console.log('[call-app-planning-image-contract] PASS');
