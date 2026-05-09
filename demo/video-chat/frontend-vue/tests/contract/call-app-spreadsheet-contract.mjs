import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');
const appRoot = path.join(repoRoot, 'demo/call-app/spreadsheet');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function readJson(relativePath) {
  return JSON.parse(read(relativePath));
}

function assertIncludes(source, needle, message) {
  assert.ok(source.includes(needle), message);
}

function assertArrayIncludes(array, value, message) {
  assert.ok(Array.isArray(array), `${message}: expected array`);
  assert.ok(array.includes(value), message);
}

assert.ok(fs.existsSync(appRoot), 'spreadsheet package must exist');
for (const requiredFile of [
  'call-app.manifest.json',
  'mcp.descriptor.json',
  'crdt.schema.json',
  'health.descriptor.json',
  'public/index.html',
  'public/spreadsheet.css',
  'public/spreadsheet.js',
]) {
  assert.ok(fs.existsSync(path.join(appRoot, requiredFile)), `spreadsheet package missing ${requiredFile}`);
}

const manifest = readJson('demo/call-app/spreadsheet/call-app.manifest.json');
assert.equal(manifest.schema_version, 'king.call_app.manifest.v1', 'manifest schema version mismatch');
assert.equal(manifest.app_key, 'spreadsheet', 'manifest app_key mismatch');
assert.equal(manifest.status, 'runtime_ready', 'spreadsheet must advertise runtime readiness');
assert.equal(manifest.category, 'collaboration', 'spreadsheet category must fit the existing Call App catalog');
assert.equal(manifest.default_participant_access, 'blocked_by_default', 'spreadsheet must use explicit participant grants');
assert.equal(manifest.iframe?.bridge_protocol, 'king.call_app.iframe.v1', 'spreadsheet bridge protocol mismatch');
assert.equal(manifest.iframe?.receives_primary_session_token, false, 'spreadsheet iframe must not receive primary session tokens');
assert.ok(manifest.iframe?.sandbox?.includes('allow-scripts'), 'spreadsheet sandbox must allow scripts');
assert.ok(!manifest.iframe?.sandbox?.includes('allow-same-origin'), 'spreadsheet sandbox must keep opaque iframe origin');
for (const permission of [
  'call_apps.crdt.read',
  'call_apps.crdt.append',
  'call_apps.crdt.replay',
  'call_apps.presence.publish',
  'call_apps.export.request',
  'call_apps.export.download',
]) {
  assertArrayIncludes(manifest.permissions, permission, `manifest missing ${permission}`);
}
assert.deepEqual(
  manifest.exports.map((entry) => entry.format),
  ['spreadsheetml', 'csv'],
  'spreadsheet must advertise SpreadsheetML and CSV exports',
);
assert.ok(!JSON.stringify(manifest).includes('kingrt.com'), 'spreadsheet package must not add a dedicated app domain');

const mcp = readJson('demo/call-app/spreadsheet/mcp.descriptor.json');
assert.equal(mcp.schema_version, 'king.call_app.mcp_descriptor.v1', 'MCP schema mismatch');
assert.equal(mcp.app_key, 'spreadsheet', 'MCP app_key mismatch');
assert.equal(mcp.service_name, 'call_app.spreadsheet.mcp', 'MCP service name mismatch');
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
assert.deepEqual(mcp.export_formats.map((entry) => entry.format), ['spreadsheetml', 'csv'], 'MCP export formats mismatch');

const crdt = readJson('demo/call-app/spreadsheet/crdt.schema.json');
assert.equal(crdt.schema_version, 'king.call_app.crdt_schema.v1', 'CRDT schema mismatch');
assert.equal(crdt.protocol, 'king.call_app.crdt.v1', 'CRDT protocol mismatch');
assert.equal(crdt.documents?.[0]?.kind, 'spreadsheet_workbook', 'spreadsheet document kind mismatch');
for (const operationType of [
  'sheet.add',
  'sheet.rename',
  'sheet.delete',
  'cell.set',
  'cell.delete',
  'cell.format',
  'range.format',
  'range.delete',
]) {
  assertArrayIncludes(crdt.documents[0].operation_types, operationType, `CRDT schema missing ${operationType}`);
}
for (const field of [
  'app_id',
  'app_version',
  'call_id',
  'app_session_id',
  'document_id',
  'schema_version',
  'actor_id',
  'operation_id',
  'logical_clock',
  'causal_dependencies',
  'payload_type',
  'payload',
  'server_admission_stamp',
]) {
  assertArrayIncludes(crdt.envelope?.required_fields, field, `CRDT envelope missing ${field}`);
}
assert.equal(crdt.presence?.persisted, false, 'spreadsheet presence must not be persisted');
assertArrayIncludes(crdt.presence?.types, 'selection.update', 'spreadsheet must advertise selection presence');
assert.deepEqual(crdt.exports, ['spreadsheetml', 'csv'], 'CRDT exports must match manifest');

const health = readJson('demo/call-app/spreadsheet/health.descriptor.json');
const healthPaths = health.checks.map((check) => check.path);
for (const healthPath of [
  'call-app.manifest.json',
  'mcp.descriptor.json',
  'crdt.schema.json',
  'public/index.html',
  'public/spreadsheet.css',
  'public/spreadsheet.js',
]) {
  assertArrayIncludes(healthPaths, healthPath, `health descriptor missing ${healthPath}`);
}

const html = read('demo/call-app/spreadsheet/public/index.html');
const css = read('demo/call-app/spreadsheet/public/spreadsheet.css');
const runtime = read('demo/call-app/spreadsheet/public/spreadsheet.js');
const bundle = `${html}\n${css}\n${runtime}`;

assertIncludes(html, 'meta name="king-call-app-key" content="spreadsheet"', 'HTML must declare spreadsheet app key');
assertIncludes(html, 'meta name="king-call-app-bridge" content="king.call_app.iframe.v1"', 'HTML must declare bridge protocol');
assertIncludes(html, '<table id="grid"', 'HTML must expose the spreadsheet grid');
assertIncludes(html, '<input id="formulaInput"', 'HTML must expose formula/cell input');
assertIncludes(html, 'spreadsheet.css', 'HTML must load extracted stylesheet');
assertIncludes(html, 'spreadsheet.js', 'HTML must load extracted runtime');
assert.ok(html.split('\n').length < 90, 'spreadsheet iframe entrypoint must stay thin');

assert.match(runtime, /message\.type === 'call_app\.launch'[\s\S]*'call_app\.ready'/, 'runtime must launch through the iframe bridge');
assert.match(runtime, /primary_session_token_received:\s*false/, 'runtime must explicitly reject primary token delivery');
assert.match(runtime, /function canRead\(\)[\s\S]*call_apps\.crdt\.read[\s\S]*hasAction\('read'\)/, 'runtime must gate reads through backend launch permissions');
assert.match(runtime, /function canWrite\(\)[\s\S]*call_apps\.crdt\.append[\s\S]*hasAction\('write'\)/, 'runtime must gate writes through backend launch permissions');
assert.match(runtime, /function canDelete\(\)[\s\S]*hasAction\('delete'\)/, 'runtime must gate deletes through backend launch permissions');
assert.match(runtime, /function canExport\(\)[\s\S]*call_apps\.export\.download[\s\S]*call_apps\.export\.request/, 'runtime must gate exports through backend export capabilities');
assert.match(runtime, /call_app\.crdt\.bootstrap\.request[\s\S]*call_app\.crdt\.ops\.request[\s\S]*call_app\.crdt\.op\.append/, 'runtime must bootstrap, replay, and append CRDT ops');
assert.match(runtime, /setInterval\(requestOps, 1800\)/, 'runtime must keep replaying CRDT ops without manual refresh');
for (const operationType of crdt.documents[0].operation_types) {
  assertIncludes(runtime, operationType, `runtime must handle or emit ${operationType}`);
}
for (const formula of ['SUM', 'AVERAGE', 'MIN', 'MAX']) {
  assertIncludes(runtime, `name === '${formula}'`, `runtime must support ${formula} formulas`);
}
assert.match(runtime, /parseAdd[\s\S]*parseMultiply[\s\S]*parsePower[\s\S]*parseUnary/, 'runtime must parse simple arithmetic formulas without eval');
assert.doesNotMatch(runtime, /eval\s*\(|new Function|Function\s*\(/, 'formula evaluator must not use dynamic code execution');
for (const exportNeedle of ['text/csv', 'application/vnd.ms-excel', 'Excel.Sheet', 'Worksheet ss:Name']) {
  assertIncludes(runtime, exportNeedle, `runtime must export ${exportNeedle}`);
}
assert.match(runtime, /call_app\.presence\.publish[\s\S]*selection\.update[\s\S]*call_app\.presence\.update/, 'runtime must publish and render shared selection presence');
assert.doesNotMatch(bundle, /sessionToken|Authorization|localStorage|primary_session_token_received:\s*true/, 'spreadsheet bundle must not access parent auth material');
assert.doesNotMatch(bundle, /certbot|\.kingrt\.com/, 'spreadsheet package must remain path-hosted without DNS or certbot assumptions');

for (const relativePath of [
  'demo/call-app/spreadsheet/public/spreadsheet.js',
  'demo/call-app/spreadsheet/public/spreadsheet.css',
  'demo/call-app/spreadsheet/public/index.html',
]) {
  assert.ok(read(relativePath).split('\n').length <= 800, `${relativePath} must stay below the 800-line source target`);
}

console.log('[call-app-spreadsheet-contract] PASS');
