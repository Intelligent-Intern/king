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
assert.equal(manifest.visibility?.internal_only, true, 'call-diagnostics must be marked internal-only');
assert.equal(manifest.visibility?.public_marketplace, false, 'call-diagnostics must not be public marketplace visible');
assert.ok(manifest.visibility?.admin_roles?.includes('admin'), 'call-diagnostics must be admin visible');
assert.equal(manifest.marketplace?.public_listing, false, 'call-diagnostics marketplace listing must stay private');
assert.equal(manifest.marketplace?.internal_only, true, 'call-diagnostics marketplace metadata must stay internal');
assert.equal(manifest.default_participant_access, 'blocked_by_default', 'diagnostic tail must not be shared by default inside the call');
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
assert.equal(mcp.visibility?.internal_only, true, 'MCP descriptor must mark diagnostics internal-only');
assert.equal(mcp.visibility?.public_marketplace, false, 'MCP descriptor must not expose diagnostics publicly');
assert.equal(mcp.marketplace_listing?.default_participant_access, 'blocked_by_default', 'MCP listing must expose blocked diagnostic default access');
assert.equal(mcp.marketplace_listing?.public_listing, false, 'MCP listing must be private');
assert.equal(mcp.marketplace_listing?.internal_only, true, 'MCP listing must be internal');
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
for (const label of ['Live Tail', 'Instances', 'Calls', 'Telemetry', 'Raw']) {
  includes(html, `>${label}</button>`, `HTML must expose ${label} diagnostics tab`);
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
includes(runtime, 'setPaused(true)', 'runtime pause control must use a focused paused-state transition');
includes(runtime, 'filterMatchesEntry(entry, term)', 'runtime filters must apply through a focused predicate');
includes(runtime, 'visibleTelemetryEntries()', 'runtime filters must apply to telemetry snapshots');
includes(runtime, 'JSON.stringify(summarizePayload(payload), null, 2)', 'runtime details must render jq-style pretty JSON');
includes(runtime, "'call_app.diagnostics.telemetry.snapshot'", 'runtime must handle telemetry snapshot messages');
includes(runtime, "'call_app.diagnostics.stage.update'", 'runtime must handle stage update messages');
includes(runtime, 'renderRawView(rows, telemetryRows)', 'runtime must expose redacted raw JSON for the active filter');
includes(runtime, "REDACTED = '[redacted]'", 'runtime output must visibly redact sensitive fields');
assert.doesNotMatch(bundle, /sessionToken|Authorization|localStorage|XMLHttpRequest|fetch\(/, 'iframe bundle must not access parent auth material or direct APIs');

const tailBridge = read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/callAppDiagnosticTailBridge.js');
includes(tailBridge, "CALL_DIAGNOSTICS_APP_KEY = 'call-diagnostics'", 'host tail bridge must gate the diagnostics app key');
includes(tailBridge, "CALL_APP_DIAGNOSTIC_TAIL_MESSAGE_TYPE = 'call_app.diagnostics.tail.event'", 'host tail bridge must use a dedicated message type');
includes(tailBridge, "CALL_APP_DIAGNOSTIC_TELEMETRY_SNAPSHOT_TYPE = 'call_app.diagnostics.telemetry.snapshot'", 'host tail bridge must route telemetry snapshots');
includes(tailBridge, "CALL_APP_DIAGNOSTIC_STAGE_UPDATE_TYPE = 'call_app.diagnostics.stage.update'", 'host tail bridge must route stage updates');
includes(tailBridge, "CLIENT_DIAGNOSTIC_WINDOW_EVENT = 'king:client-diagnostic'", 'host tail bridge must subscribe to client diagnostics');
includes(tailBridge, "CALL_APP_DIAGNOSTIC_WINDOW_EVENT = 'king:call-app-diagnostic'", 'host tail bridge must subscribe to Call App diagnostics');
includes(tailBridge, 'redactDiagnosticPayload', 'host tail bridge must redact diagnostic payloads before iframe delivery');
includes(tailBridge, 'diagnosticMessageType(raw)', 'host tail bridge must preserve first-class diagnostic message types');
includes(tailBridge, 'apiRequest(endpoint)', 'host tail bridge must fetch telemetry through the parent API client');
includes(tailBridge, "call-apps/call-diagnostics/telemetry-snapshot", 'host tail bridge must use the call-scoped diagnostics telemetry endpoint');
includes(tailBridge, 'postToIframe(frameWindow, session, messageType', 'host tail bridge must use the existing iframe post bridge');
includes(tailBridge, "entry.event_type.startsWith('call_app_crdt_')", 'host tail bridge must avoid persisting its own CRDT feedback loop');

const workspaceHost = read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/CallAppWorkspaceHost.vue');
includes(workspaceHost, 'createCallAppDiagnosticTailBridge', 'workspace host must install the diagnostic tail bridge');
includes(workspaceHost, 'postToIframe: callAppCrdtBridge.postToIframe', 'diagnostic tail bridge must share the existing parent->iframe sender');
includes(workspaceHost, 'apiRequest: props.apiRequest', 'diagnostic tail bridge must share the parent API client');

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

const backendDiagnostics = read('demo/video-chat/backend-king-php/domain/call_apps/call_app_diagnostics.php');
includes(backendDiagnostics, 'videochat_call_diagnostics_handle_telemetry_snapshot_route', 'backend must expose a dedicated call-scoped telemetry route handler');
includes(backendDiagnostics, '/api/calls/([A-Za-z0-9._-]{1,200})/call-apps/call-diagnostics/telemetry-snapshot', 'backend telemetry route must be scoped to the call');
includes(backendDiagnostics, 'videochat_call_app_actor_can_use_internal_admin_apps', 'backend telemetry route must require admin/system-admin context');
includes(backendDiagnostics, 'videochat_call_diagnostics_redact_value', 'backend telemetry payloads must be redacted before delivery');
const backendModule = read('demo/video-chat/backend-king-php/http/module_call_apps.php');
includes(backendModule, 'videochat_call_diagnostics_handle_telemetry_snapshot_route', 'call-app module must delegate diagnostics telemetry before generic session routes');
includes(backendModule, 'videochat_call_app_internal_only_error_response', 'call-app module must enforce internal-only diagnostics access on session routes');
assert.doesNotMatch(backendModule, /\/api\/admin\/call-diagnostics\/telemetry/, 'diagnostics telemetry must not use a broad public admin endpoint');
const availability = read('demo/video-chat/backend-king-php/domain/call_apps/call_app_availability.php');
includes(availability, 'include_internal', 'availability query must support admin-only internal app visibility');
includes(availability, "catalog.app_key <> :internal_app_key", 'availability query must hide diagnostics from normal users');
const sprint = read('SPRINT.md');
includes(sprint, 'IAM Remaining Proof And Branch Cleanup 07', 'SPRINT must remain focused on the active IAM sprint');
const backlog = read('BACKLOG.md');
includes(backlog, 'Video Call Stabilization and Internal Diagnostics are paused as active', 'BACKLOG must record paused diagnostics stabilization work');
includes(backlog, 'Call App diagnostics/telemetry improvements remain available as future', 'BACKLOG must retain diagnostics telemetry follow-up work');
includes(backlog, 'Call Diagnostics', 'BACKLOG must retain Call Diagnostics package history');
includes(backlog, 'package work are represented by local commits on the active integration', 'BACKLOG must retain Call Diagnostics package integration history');

console.log('[call-app-call-diagnostics-contract] PASS');
