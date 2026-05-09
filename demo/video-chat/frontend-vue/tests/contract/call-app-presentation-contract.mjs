import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');
const appRoot = path.join(repoRoot, 'demo/call-app/presentation');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function readJson(relativePath) {
  return JSON.parse(read(relativePath));
}

function assertIncludes(source, needle, message) {
  assert.ok(source.includes(needle), message);
}

function assertNotIncludes(source, needle, message) {
  assert.ok(!source.includes(needle), message);
}

assert.ok(fs.existsSync(appRoot), 'presentation package must exist');

for (const requiredFile of [
  'call-app.manifest.json',
  'mcp.descriptor.json',
  'crdt.schema.json',
  'health.descriptor.json',
  'public/index.html',
  'public/presentation.css',
  'public/presentation.js',
]) {
  assert.ok(fs.existsSync(path.join(appRoot, requiredFile)), `presentation package missing ${requiredFile}`);
}

const manifest = readJson('demo/call-app/presentation/call-app.manifest.json');
assert.equal(manifest.schema_version, 'king.call_app.manifest.v1', 'manifest schema version mismatch');
assert.equal(manifest.app_key, 'presentation', 'manifest app_key mismatch');
assert.equal(manifest.status, 'runtime_ready', 'presentation package must advertise runtime readiness');
assert.equal(manifest.category, 'collaboration', 'presentation category must fit existing marketplace enum');
assert.equal(manifest.default_participant_access, 'allowed_by_default', 'presentation must default to shared call editing');
assert.equal(manifest.iframe?.entrypoint, 'public/index.html', 'presentation iframe entrypoint mismatch');
assert.equal(manifest.iframe?.bridge_protocol, 'king.call_app.iframe.v1', 'presentation bridge protocol mismatch');
assert.equal(manifest.iframe?.receives_primary_session_token, false, 'presentation iframe must not receive primary session tokens');
assert.ok(manifest.iframe?.sandbox?.includes('allow-scripts'), 'presentation sandbox must allow app scripts');
assert.ok(manifest.iframe?.sandbox?.includes('allow-downloads'), 'presentation sandbox must allow PPTX download');
assert.ok(!manifest.iframe?.sandbox?.includes('allow-same-origin'), 'presentation iframe must keep an opaque sandbox origin');
for (const permission of [
  'call_apps.crdt.read',
  'call_apps.crdt.append',
  'call_apps.crdt.replay',
  'call_apps.permissions.manage',
  'call_apps.permissions.revoke',
  'call_apps.export.request',
  'call_apps.export.download',
]) {
  assert.ok(manifest.permissions.includes(permission), `manifest missing ${permission}`);
}
assert.deepEqual(
  manifest.exports.map((entry) => entry.format),
  ['pptx'],
  'presentation must export PowerPoint-compatible PPTX, not legacy PPT',
);
assert.equal(
  manifest.exports[0].mime_type,
  'application/vnd.openxmlformats-officedocument.presentationml.presentation',
  'PPTX MIME type mismatch',
);

const mcp = readJson('demo/call-app/presentation/mcp.descriptor.json');
assert.equal(mcp.schema_version, 'king.call_app.mcp_descriptor.v1', 'MCP schema mismatch');
assert.equal(mcp.app_key, 'presentation', 'MCP app_key mismatch');
assert.equal(mcp.service_name, 'call_app.presentation.mcp', 'MCP service name mismatch');
assert.equal(mcp.marketplace_listing?.default_participant_access, 'allowed_by_default', 'MCP listing must expose shared presentation default access');
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

const crdt = readJson('demo/call-app/presentation/crdt.schema.json');
assert.equal(crdt.schema_version, 'king.call_app.crdt_schema.v1', 'CRDT schema mismatch');
assert.equal(crdt.app_key, 'presentation', 'CRDT app_key mismatch');
assert.equal(crdt.documents?.[0]?.kind, 'presentation_document', 'presentation document kind mismatch');
for (const operationType of [
  'presentation.slide.add',
  'presentation.slide.update',
  'presentation.slide.delete',
  'presentation.text.update',
  'presentation.shape.add',
  'presentation.shape.update',
  'presentation.shape.delete',
  'presentation.image_placeholder.add',
  'presentation.image_placeholder.update',
  'presentation.image_placeholder.delete',
  'presentation.playback.update',
]) {
  assert.ok(crdt.documents[0].operation_types.includes(operationType), `CRDT schema missing ${operationType}`);
}
assert.equal(crdt.presence?.persisted, false, 'presentation presence must not be persisted');
assert.deepEqual(crdt.exports, ['pptx'], 'presentation CRDT exports must match manifest');

const health = readJson('demo/call-app/presentation/health.descriptor.json');
const healthPaths = health.checks.map((check) => check.path);
for (const healthPath of ['public/index.html', 'public/presentation.css', 'public/presentation.js']) {
  assert.ok(healthPaths.includes(healthPath), `health descriptor missing ${healthPath}`);
}

const html = read('demo/call-app/presentation/public/index.html');
const css = read('demo/call-app/presentation/public/presentation.css');
const runtime = read('demo/call-app/presentation/public/presentation.js');
const bundle = `${html}\n${css}\n${runtime}`;

assertIncludes(html, 'meta name="king-call-app-key" content="presentation"', 'HTML must declare presentation app key');
assertIncludes(html, 'id="thumbnailList"', 'HTML must expose slide thumbnails');
assertIncludes(html, 'id="slideTitle"', 'HTML must expose title editing');
assertIncludes(html, 'id="slideBody"', 'HTML must expose body editing');
assertIncludes(html, 'id="presentToggle"', 'HTML must expose presenter playback control');
assertIncludes(html, 'id="exportPptx"', 'HTML must expose PPTX export control');
assertIncludes(html, 'presentation.css', 'HTML must load extracted stylesheet');
assertIncludes(html, 'presentation.js', 'HTML must load extracted runtime');

assertIncludes(runtime, "message.type === 'call_app.launch'", 'runtime must wait for launch message');
assertIncludes(runtime, "'call_app.ready'", 'runtime must emit ready after launch');
assertIncludes(runtime, 'primary_session_token_received: false', 'runtime must explicitly reject primary token delivery');
assertIncludes(runtime, "'call_app.crdt.bootstrap.request'", 'runtime must request CRDT bootstrap through iframe bridge');
assertIncludes(runtime, "'call_app.crdt.ops.request'", 'runtime must replay CRDT ops through iframe bridge');
assertIncludes(runtime, "'call_app.crdt.op.append'", 'runtime must append CRDT mutations through iframe bridge');
assertIncludes(runtime, "window.setInterval(requestOps, 2000)", 'runtime must continuously replay collaborative updates');
assertIncludes(runtime, "presentation.text.update", 'runtime must co-edit slide title/body text');
assertIncludes(runtime, "presentation.shape.add", 'runtime must add shared simple shapes');
assertIncludes(runtime, "presentation.image_placeholder.add", 'runtime must add shared image placeholders');
assertIncludes(runtime, "presentation.playback.update", 'runtime must synchronize presenter playback state');
assertIncludes(runtime, 'function canRead()', 'runtime must gate read access');
assertIncludes(runtime, 'function canWrite()', 'runtime must gate write access');
assertIncludes(runtime, 'function canDelete()', 'runtime must gate delete access');
assertIncludes(runtime, 'function canExport()', 'runtime must gate export access');
assertIncludes(runtime, "capabilities.has('call_apps.export.download')", 'export gate must use backend launch capabilities');
assertIncludes(runtime, 'dom.exportPptx.disabled = !canExport()', 'PPTX export button must be permission gated');
assertIncludes(runtime, "link.download = 'kingrt-presentation.pptx'", 'runtime must download a clearly labeled PPTX');
assertIncludes(runtime, 'pptxMime', 'runtime must use the PPTX MIME type');
assertIncludes(runtime, "'[Content_Types].xml'", 'runtime must generate an OOXML package');
assertIncludes(runtime, 'zipStore(files)', 'runtime must package PPTX as a ZIP container');
assertNotIncludes(bundle, 'sessionToken', 'presentation bundle must not reference parent session tokens');
assertNotIncludes(bundle, 'Authorization', 'presentation bundle must not reference authorization headers');
assertNotIncludes(bundle, 'localStorage', 'presentation bundle must not persist auth-adjacent browser state');
assertNotIncludes(bundle, 'presentation.kingrt.com', 'presentation app must not assume a dedicated presentation domain');

for (const relativePath of [
  'demo/call-app/presentation/public/index.html',
  'demo/call-app/presentation/public/presentation.css',
  'demo/call-app/presentation/public/presentation.js',
  'demo/video-chat/frontend-vue/tests/contract/call-app-presentation-contract.mjs',
]) {
  const lineCount = read(relativePath).split('\n').length;
  assert.ok(lineCount < 800, `${relativePath} must stay below the 800-line source target`);
}

const workspaceState = read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/callAppWorkspaceState.js');
assert.match(
  workspaceState,
  /const path = `\/call-app\/\$\{encodeURIComponent\(appKey\)\}\/\$\{entrypoint\}`/,
  'Call App iframe URL generation must keep presentation under the existing path-hosted model',
);

console.log('[call-app-presentation-contract] PASS');
