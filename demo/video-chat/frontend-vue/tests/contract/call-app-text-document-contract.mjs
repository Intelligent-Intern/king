import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');
const appRoot = path.join(repoRoot, 'demo/call-app/text-document');

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

assert.ok(fs.existsSync(appRoot), 'text-document package must exist');

for (const requiredFile of [
  'call-app.manifest.json',
  'mcp.descriptor.json',
  'crdt.schema.json',
  'health.descriptor.json',
  'public/index.html',
  'public/text-document.css',
  'public/text-document.js',
]) {
  assert.ok(fs.existsSync(path.join(appRoot, requiredFile)), `text-document package missing ${requiredFile}`);
}

const manifest = readJson('demo/call-app/text-document/call-app.manifest.json');
assert.equal(manifest.schema_version, 'king.call_app.manifest.v1', 'manifest schema version mismatch');
assert.equal(manifest.app_key, 'text-document', 'manifest app key mismatch');
assert.equal(manifest.status, 'runtime_ready', 'text-document must advertise runtime readiness');
assert.equal(manifest.category, 'collaboration', 'text-document category must use the marketplace collaboration enum');
assert.equal(manifest.default_participant_access, 'allowed_by_default', 'text-document must default to shared call editing');
assert.equal(manifest.iframe?.receives_primary_session_token, false, 'iframe must not receive primary session tokens');
assert.equal(manifest.iframe?.bridge_protocol, 'king.call_app.iframe.v1', 'iframe bridge protocol mismatch');
assert.ok(manifest.iframe?.sandbox?.includes('allow-scripts'), 'iframe sandbox must allow scripts');
assert.ok(manifest.iframe?.sandbox?.includes('allow-downloads'), 'iframe sandbox must allow client-side document downloads');
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
assert.deepEqual(manifest.exports.map((entry) => entry.format), ['odt', 'pdf'], 'text-document must advertise ODT and PDF exports');

const mcp = readJson('demo/call-app/text-document/mcp.descriptor.json');
assert.equal(mcp.schema_version, 'king.call_app.mcp_descriptor.v1', 'MCP descriptor schema mismatch');
assert.equal(mcp.app_key, 'text-document', 'MCP app key mismatch');
assert.equal(mcp.service_name, 'call_app.text-document.mcp', 'MCP service name mismatch');
assert.equal(mcp.marketplace_listing?.default_participant_access, 'allowed_by_default', 'MCP listing must expose shared text-document default access');
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

const crdt = readJson('demo/call-app/text-document/crdt.schema.json');
assert.equal(crdt.schema_version, 'king.call_app.crdt_schema.v1', 'CRDT schema mismatch');
assert.equal(crdt.app_key, 'text-document', 'CRDT app key mismatch');
assert.equal(crdt.protocol, 'king.call_app.crdt.v1', 'CRDT protocol mismatch');
assert.equal(crdt.documents?.[0]?.kind, 'text_document', 'CRDT document kind mismatch');
for (const blockType of ['heading1', 'heading2', 'paragraph', 'bullet', 'numbered', 'note']) {
  assert.ok(crdt.documents[0].block_types.includes(blockType), `CRDT schema missing block type ${blockType}`);
}
for (const operationType of [
  'text_document.block.upsert',
  'text_document.block.delete',
  'text_document.format.update',
  'text_document.note.upsert',
]) {
  assert.ok(crdt.documents[0].operation_types.includes(operationType), `CRDT schema missing ${operationType}`);
}
assert.equal(crdt.presence?.persisted, false, 'text-document presence must not be persisted');
assert.deepEqual(crdt.exports, ['odt', 'pdf'], 'CRDT exports must match manifest');

const health = readJson('demo/call-app/text-document/health.descriptor.json');
const healthPaths = health.checks.map((check) => check.path);
for (const healthPath of [
  'call-app.manifest.json',
  'mcp.descriptor.json',
  'crdt.schema.json',
  'public/index.html',
  'public/text-document.css',
  'public/text-document.js',
]) {
  assert.ok(healthPaths.includes(healthPath), `health descriptor missing ${healthPath}`);
}

const html = read('demo/call-app/text-document/public/index.html');
const css = read('demo/call-app/text-document/public/text-document.css');
const runtime = read('demo/call-app/text-document/public/text-document.js');
const bundle = `${html}\n${css}\n${runtime}`;

assert.ok(lineCount(html) < 120, 'text-document entrypoint must stay thin');
assert.ok(lineCount(css) < 800, 'text-document stylesheet must stay below 800 lines');
assert.ok(lineCount(runtime) < 800, 'text-document runtime must stay below 800 lines');

includes(html, 'meta name="king-call-app-key" content="text-document"', 'HTML must declare app key');
includes(html, 'king.call_app.iframe.v1', 'HTML must declare bridge protocol');
includes(html, 'text-document.css', 'HTML must load extracted stylesheet');
includes(html, 'text-document.js', 'HTML must load extracted runtime');
for (const control of ['heading1', 'heading2', 'paragraph', 'bullet', 'numbered', 'note', 'exportOdt', 'exportPdf']) {
  includes(html, control, `HTML must expose ${control}`);
}

includes(runtime, "message.type === 'call_app.launch'", 'runtime must wait for launch messages');
includes(runtime, "'call_app.ready'", 'runtime must emit ready after launch');
includes(runtime, 'primary_session_token_received: false', 'runtime must explicitly reject primary token delivery');
includes(runtime, "'call_app.crdt.bootstrap.request'", 'runtime must request CRDT bootstrap through iframe bridge');
includes(runtime, "'call_app.crdt.ops.request'", 'runtime must request CRDT replay through iframe bridge');
includes(runtime, "'call_app.crdt.op.append'", 'runtime must append CRDT ops through iframe bridge');
includes(runtime, 'window.setInterval(requestOps, 1800)', 'runtime must continuously replay CRDT ops');

assert.match(runtime, /function canRead\(\)[\s\S]*capabilities\.has\('call_apps\.crdt\.read'\)/, 'read gate must use launch capabilities');
assert.match(runtime, /function canWrite\(\)[\s\S]*permissionActions\.has\('write'\)/, 'write gate must use backend grant actions');
assert.match(runtime, /function canDelete\(\)[\s\S]*permissionActions\.has\('delete'\)/, 'delete gate must use backend grant actions');
assert.match(runtime, /function canExport\(\)[\s\S]*call_apps\.export\.download[\s\S]*call_apps\.export\.request/, 'export gate must use launch export capabilities');
assert.match(runtime, /payloadType\.endsWith\('\.delete'\) \? !canDelete\(\) : !canWrite\(\)/, 'delete payloads must use delete permission gate');

for (const operationType of [
  'text_document.block.upsert',
  'text_document.block.delete',
  'text_document.format.update',
  'text_document.note.upsert',
]) {
  includes(runtime, operationType, `runtime must emit or apply ${operationType}`);
}
for (const feature of [
  'contentEditable',
  'selectionOffsets',
  'toggleRuns',
  'zipStored',
  'application/vnd.oasis.opendocument.text',
  'application/pdf',
  'downloadBlob',
]) {
  includes(runtime, feature, `runtime missing ${feature}`);
}

assert.doesNotMatch(bundle, /sessionToken|Authorization|localStorage|XMLHttpRequest|fetch\(/, 'iframe bundle must not access parent auth material or direct APIs');

console.log('[call-app-text-document-contract] PASS');
