import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');
const appRoot = path.join(repoRoot, 'demo/call-app/call-diagnostics');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function readJson(relativePath) {
  return JSON.parse(read(relativePath));
}

function includes(source, needle, message) {
  assert.ok(source.includes(needle), message);
}

function lineCount(source) {
  return source.split(/\r?\n/).length;
}

assert.ok(fs.existsSync(appRoot), 'call-diagnostics package must exist');

for (const requiredFile of [
  'call-app.manifest.json',
  'mcp.descriptor.json',
  'crdt.schema.json',
  'health.descriptor.json',
  'public/index.html',
  'public/call-diagnostics.css',
  'public/call-diagnostics.js',
]) {
  assert.ok(fs.existsSync(path.join(appRoot, requiredFile)), `call-diagnostics package missing ${requiredFile}`);
}

const manifest = readJson('demo/call-app/call-diagnostics/call-app.manifest.json');
assert.equal(manifest.schema_version, 'king.call_app.manifest.v1', 'manifest schema version mismatch');
assert.equal(manifest.app_key, 'call-diagnostics', 'manifest app key mismatch');
assert.equal(manifest.status, 'runtime_ready', 'call-diagnostics must advertise runtime readiness');
assert.equal(manifest.category, 'utility', 'call-diagnostics category must use the existing utility marketplace enum');
assert.equal(manifest.default_participant_access, 'allowed_by_default', 'diagnostic tail must be shared by default inside the call');
assert.equal(manifest.iframe?.receives_primary_session_token, false, 'iframe must not receive primary session tokens');
assert.equal(manifest.iframe?.bridge_protocol, 'king.call_app.iframe.v1', 'iframe bridge protocol mismatch');
assert.ok(manifest.iframe?.sandbox?.includes('allow-scripts'), 'iframe sandbox must allow scripts');
assert.ok(manifest.iframe?.sandbox?.includes('allow-downloads'), 'iframe sandbox must allow JSON download');
assert.ok(!manifest.iframe?.sandbox?.includes('allow-same-origin'), 'iframe sandbox must keep an opaque origin');
for (const permission of [
  'call_apps.crdt.read',
  'call_apps.crdt.append',
  'call_apps.crdt.replay',
  'call_apps.permissions.manage',
  'call_apps.export.request',
  'call_apps.export.download',
]) {
  assert.ok(manifest.permissions.includes(permission), `manifest missing ${permission}`);
}
assert.deepEqual(manifest.exports.map((entry) => entry.format), ['json'], 'call-diagnostics must advertise JSON export');

const mcp = readJson('demo/call-app/call-diagnostics/mcp.descriptor.json');
assert.equal(mcp.schema_version, 'king.call_app.mcp_descriptor.v1', 'MCP schema mismatch');
assert.equal(mcp.app_key, 'call-diagnostics', 'MCP app key mismatch');
assert.equal(mcp.service_name, 'call_app.call-diagnostics.mcp', 'MCP service name mismatch');
assert.equal(mcp.marketplace_listing?.default_participant_access, 'allowed_by_default', 'MCP listing must expose shared diagnostic default access');
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

const crdt = readJson('demo/call-app/call-diagnostics/crdt.schema.json');
assert.equal(crdt.schema_version, 'king.call_app.crdt_schema.v1', 'CRDT schema mismatch');
assert.equal(crdt.app_key, 'call-diagnostics', 'CRDT app key mismatch');
assert.equal(crdt.protocol, 'king.call_app.crdt.v1', 'CRDT protocol mismatch');
assert.equal(crdt.documents?.[0]?.kind, 'call_diagnostics_log', 'CRDT document kind mismatch');
for (const operationType of [
  'diagnostic.log.append',
  'diagnostic.log.clear',
  'diagnostic.stage.update',
]) {
  assert.ok(crdt.documents[0].operation_types.includes(operationType), `CRDT schema missing ${operationType}`);
}
assert.equal(crdt.presence?.persisted, false, 'call-diagnostics presence must not be persisted');
assert.deepEqual(crdt.exports, ['json'], 'CRDT exports must match manifest');

const health = readJson('demo/call-app/call-diagnostics/health.descriptor.json');
const healthPaths = health.checks.map((check) => check.path);
for (const healthPath of [
  'call-app.manifest.json',
  'mcp.descriptor.json',
  'crdt.schema.json',
  'public/index.html',
  'public/call-diagnostics.css',
  'public/call-diagnostics.js',
]) {
  assert.ok(healthPaths.includes(healthPath), `health descriptor missing ${healthPath}`);
}

const html = read('demo/call-app/call-diagnostics/public/index.html');
const css = read('demo/call-app/call-diagnostics/public/call-diagnostics.css');
const runtime = read('demo/call-app/call-diagnostics/public/call-diagnostics.js');
const bundle = `${html}\n${css}\n${runtime}`;

assert.ok(lineCount(html) < 140, 'call-diagnostics entrypoint must stay thin');
assert.ok(lineCount(css) < 800, 'call-diagnostics stylesheet must stay below 800 lines');
assert.ok(lineCount(runtime) < 800, 'call-diagnostics runtime must stay below 800 lines');
includes(html, 'meta name="king-call-app-key" content="call-diagnostics"', 'HTML must declare app key');
includes(html, 'king.call_app.iframe.v1', 'HTML must declare bridge protocol');
for (const label of ['WebSocket', 'ICE host', 'STUN', 'TURN', 'SFU', 'Call App']) {
  includes(html, label, `HTML must expose station ${label}`);
}
includes(runtime, "message.type === 'call_app.launch'", 'runtime must wait for launch messages');
includes(runtime, 'primary_session_token_received: false', 'runtime must explicitly reject primary token delivery');
includes(runtime, "'call_app.ready'", 'runtime must emit ready after launch');
includes(runtime, 'message.launch_context', 'runtime must read backend launch context grants');
includes(runtime, 'launchContext.permission_actions', 'runtime must use launch permission actions for write gates');
includes(runtime, 'call_app.diagnostics.tail.event', 'runtime must consume parent diagnostic tail events');
includes(runtime, "'call_app.crdt.op.append'", 'runtime must persist diagnostic entries through CRDT');
includes(runtime, "'diagnostic.log.append'", 'runtime must append diagnostic log operations');
includes(runtime, "window.setInterval(requestOps, POLL_MS)", 'runtime must continuously replay CRDT ops');
includes(runtime, 'classifyStage(entry)', 'runtime must map diagnostics onto connection stages');
includes(runtime, 'typ relay', 'runtime must classify TURN relay candidates');
includes(runtime, 'typ srflx', 'runtime must classify STUN srflx candidates');
includes(runtime, 'persistLog(entry)', 'runtime must store live diagnostics unless marked non-persistent');
includes(runtime, 'pausedLogs', 'runtime pause must buffer incoming tail entries');
includes(runtime, 'flushPausedLogs()', 'runtime pause must flush buffered entries on resume');
includes(runtime, 'filterMatchesEntry(entry, term)', 'runtime filters must apply through a focused predicate');
includes(runtime, 'JSON.stringify(summarizePayload(payload), null, 2)', 'runtime details must render jq-style pretty JSON');
assert.doesNotMatch(bundle, /sessionToken|Authorization|localStorage|XMLHttpRequest|fetch\(/, 'iframe bundle must not access parent auth material or direct APIs');

const tailBridge = read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/callAppDiagnosticTailBridge.js');
includes(tailBridge, "CALL_DIAGNOSTICS_APP_KEY = 'call-diagnostics'", 'host tail bridge must gate the diagnostics app key');
includes(tailBridge, "CALL_APP_DIAGNOSTIC_TAIL_MESSAGE_TYPE = 'call_app.diagnostics.tail.event'", 'host tail bridge must use a dedicated message type');
includes(tailBridge, "CLIENT_DIAGNOSTIC_WINDOW_EVENT = 'king:client-diagnostic'", 'host tail bridge must subscribe to client diagnostics');
includes(tailBridge, "CALL_APP_DIAGNOSTIC_WINDOW_EVENT = 'king:call-app-diagnostic'", 'host tail bridge must subscribe to Call App diagnostics');
includes(tailBridge, 'redactDiagnosticPayload', 'host tail bridge must redact diagnostic payloads before iframe delivery');
includes(tailBridge, 'postToIframe(frameWindow, session, CALL_APP_DIAGNOSTIC_TAIL_MESSAGE_TYPE', 'host tail bridge must use the existing iframe post bridge');
includes(tailBridge, "entry.event_type.startsWith('call_app_crdt_')", 'host tail bridge must avoid persisting its own CRDT feedback loop');

const workspaceHost = read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/CallAppWorkspaceHost.vue');
includes(workspaceHost, 'createCallAppDiagnosticTailBridge', 'workspace host must install the diagnostic tail bridge');
includes(workspaceHost, 'postToIframe: callAppCrdtBridge.postToIframe', 'diagnostic tail bridge must share the existing parent->iframe sender');

const clientDiagnostics = read('demo/video-chat/frontend-vue/src/support/clientDiagnostics.ts');
includes(clientDiagnostics, "CLIENT_DIAGNOSTIC_WINDOW_EVENT = 'king:client-diagnostic'", 'client diagnostics must expose the live tail window event');
includes(clientDiagnostics, 'export function dispatchClientDiagnosticWindowEvent(entry', 'client diagnostics must expose a sanitized live tail dispatcher');
includes(clientDiagnostics, 'redactClientDiagnosticWindowPayload', 'client diagnostics live event must redact sensitive payload fields');
const workspaceDiagnostics = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/clientDiagnostics.ts');
includes(workspaceDiagnostics, 'dispatchClientDiagnosticWindowEvent({', 'call workspace diagnostics must tap live events before persistence filtering');
includes(workspaceDiagnostics, 'event_type: eventType', 'call workspace live tap must preserve event type before info-level filtering');

const packageJson = read('demo/video-chat/frontend-vue/package.json');
includes(packageJson, 'call-app-call-diagnostics-contract.mjs', 'package scripts must include call-diagnostics contract');
const readme = read('demo/call-app/README.md');
includes(readme, 'call-diagnostics', 'README must list the call-diagnostics package');
const sprint = read('SPRINT.md');
includes(sprint, 'OCA-09 Call Diagnostics live tail Call App', 'SPRINT must track the diagnostic tail sprint ticket');

console.log('[call-app-call-diagnostics-contract] PASS');
