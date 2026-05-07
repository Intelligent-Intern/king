import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')
const repoRoot = path.resolve(frontendRoot, '../../..')
const callAppRoot = path.join(repoRoot, 'demo/call-app')
const whiteboardRoot = path.join(callAppRoot, 'whiteboard')

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8')
}

function readJson(relativePath) {
  return JSON.parse(read(relativePath))
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(`[call-app-package-layout-contract] ${message}`)
  }
}

function assertArrayIncludes(array, value, message) {
  assert(Array.isArray(array), `${message}: expected array`)
  assert(array.includes(value), message)
}

assert(fs.existsSync(callAppRoot), 'demo/call-app root must exist')
assert(fs.existsSync(whiteboardRoot), 'demo/call-app/whiteboard package must exist')

const readme = read('demo/call-app/README.md')
assert(readme.includes('demo/call-app/<app-key>/'), 'README must document the package root convention')
for (const requiredFile of [
  'call-app.manifest.json',
  'mcp.descriptor.json',
  'crdt.schema.json',
  'health.descriptor.json',
  'public/index.html',
]) {
  assert(readme.includes(requiredFile), `README must document ${requiredFile}`)
  assert(fs.existsSync(path.join(whiteboardRoot, requiredFile)), `whiteboard package must include ${requiredFile}`)
}

const manifest = readJson('demo/call-app/whiteboard/call-app.manifest.json')
assert(manifest.schema_version === 'king.call_app.manifest.v1', 'manifest schema version mismatch')
assert(manifest.app_key === 'whiteboard', 'manifest app_key mismatch')
assert(manifest.version === '0.1.0', 'manifest version mismatch')
assert(manifest.status === 'runtime_ready', 'whiteboard package must advertise the CAP-13 runtime implementation')
assert(manifest.category === 'whiteboard', 'manifest category mismatch')
assert(manifest.semantic_dns?.service_type === 'call_app', 'manifest must declare Semantic-DNS call_app service type')
assert(manifest.semantic_dns?.mother_node_registration?.required === true, 'manifest must require mother-node registration')
assert(manifest.marketplace?.order_scope === 'organization', 'manifest marketplace order scope must be organization')
assert(manifest.marketplace?.requires_installation === true, 'manifest must require organization installation before call use')
assert(manifest.default_participant_access === 'blocked_by_default', 'whiteboard must default to blocked participant access')
assert(manifest.iframe?.receives_primary_session_token === false, 'iframe must not receive the primary session token')
assert(manifest.iframe?.bridge_protocol === 'king.call_app.iframe.v1', 'iframe bridge protocol mismatch')
assertArrayIncludes(manifest.iframe?.sandbox, 'allow-scripts', 'iframe sandbox must allow scripts for the app runtime')
assert(!manifest.iframe?.sandbox?.includes('allow-same-origin'), 'CAP-02 iframe sandbox must not allow same-origin by default')
for (const permission of [
  'call_apps.discover',
  'call_apps.marketplace.order',
  'call_apps.marketplace.install',
  'call_apps.call.attach',
  'call_apps.permissions.manage',
  'call_apps.permissions.use',
  'call_apps.launch',
  'call_apps.crdt.read',
  'call_apps.crdt.append',
  'call_apps.crdt.replay',
  'call_apps.presence.publish',
  'call_apps.export.request',
  'call_apps.export.download',
]) {
  assertArrayIncludes(manifest.permissions, permission, `manifest missing permission ${permission}`)
}
assertArrayIncludes(manifest.exports?.map((entry) => entry.format), 'png', 'whiteboard must advertise PNG export')
assertArrayIncludes(manifest.exports?.map((entry) => entry.format), 'pdf', 'whiteboard must advertise PDF export')

const mcpDescriptor = readJson('demo/call-app/whiteboard/mcp.descriptor.json')
assert(mcpDescriptor.schema_version === 'king.call_app.mcp_descriptor.v1', 'MCP descriptor schema mismatch')
assert(mcpDescriptor.service_name === 'call_app.whiteboard.mcp', 'MCP service name mismatch')
const mcpMethodNames = mcpDescriptor.methods.map((method) => method.name)
for (const method of [
  'call_app.describe',
  'call_app.capabilities',
  'call_app.crdt_schema',
  'call_app.launch_contract',
  'call_app.health',
  'call_app.export_formats',
  'call_app.marketplace_listing',
]) {
  assertArrayIncludes(mcpMethodNames, method, `MCP descriptor missing method ${method}`)
}
assert(mcpDescriptor.launch_contract?.primary_session_token_allowed === false, 'MCP launch contract must reject primary session tokens')

const crdtSchema = readJson('demo/call-app/whiteboard/crdt.schema.json')
assert(crdtSchema.schema_version === 'king.call_app.crdt_schema.v1', 'CRDT schema version mismatch')
assert(crdtSchema.protocol === 'king.call_app.crdt.v1', 'CRDT protocol mismatch')
assert(crdtSchema.documents?.[0]?.kind === 'whiteboard_document', 'CRDT schema must define whiteboard_document')
const operationTypes = crdtSchema.documents[0].operation_types
for (const operationType of [
  'stroke.add',
  'shape.add',
  'shape.update',
  'shape.delete',
  'text.add',
  'sticky_note.add',
  'selection.update',
  'cursor.move',
]) {
  assertArrayIncludes(operationTypes, operationType, `CRDT schema missing operation type ${operationType}`)
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
  assertArrayIncludes(crdtSchema.envelope?.required_fields, field, `CRDT envelope missing ${field}`)
}
assert(crdtSchema.envelope?.idempotency?.duplicate_policy === 'ignore_after_first_admission', 'CRDT duplicate policy mismatch')
assert(crdtSchema.presence?.persisted === false, 'presence must not be persisted as document ops')

const health = readJson('demo/call-app/whiteboard/health.descriptor.json')
assert(health.schema_version === 'king.call_app.health_descriptor.v1', 'health descriptor schema mismatch')
const healthPaths = health.checks.map((check) => check.path)
for (const healthPath of [
  'call-app.manifest.json',
  'mcp.descriptor.json',
  'crdt.schema.json',
  'public/index.html',
]) {
  assertArrayIncludes(healthPaths, healthPath, `health descriptor missing check for ${healthPath}`)
}

const iframe = read('demo/call-app/whiteboard/public/index.html')
assert(iframe.includes('king.call_app.iframe.v1'), 'iframe entrypoint must declare bridge protocol')
assert(iframe.includes("message.type === 'call_app.launch'"), 'iframe entrypoint must wait for launch message')
assert(iframe.includes("'call_app.ready'"), 'iframe entrypoint must emit ready message after launch')
assert(iframe.includes('primary_session_token_received: false'), 'iframe entrypoint must not accept a primary session token')
assert(!iframe.includes('sessionToken'), 'iframe entrypoint must not reference parent session tokens')
assert(!iframe.includes('Authorization'), 'iframe entrypoint must not reference authorization headers')

const sprint = read('SPRINT.md')
assert(sprint.includes('- [x] CAP-02 `demo/call-app` package layout'), 'SPRINT.md must mark CAP-02 complete')
const packageJson = read('demo/video-chat/frontend-vue/package.json')
assert(packageJson.includes('call-app-package-layout-contract.mjs'), 'package scripts must include package layout contract')

console.log('[call-app-package-layout-contract] PASS')
