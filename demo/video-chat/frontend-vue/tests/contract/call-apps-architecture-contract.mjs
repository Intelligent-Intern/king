import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')
const repoRoot = path.resolve(frontendRoot, '../../..')

function readRepo(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8')
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(`[call-apps-architecture-contract] ${message}`)
  }
}

function assertIncludes(source, value, message) {
  assert(source.includes(value), message)
}

const sprint = readRepo('SPRINT.md')
const semanticDnsHeader = readRepo('extension/include/semantic_dns/semantic_dns.h')
const mcpHeader = readRepo('extension/include/mcp/mcp.h')
const marketplaceDomain = readRepo('demo/video-chat/backend-king-php/domain/marketplace/call_app_marketplace.php')
const marketplaceModule = readRepo('demo/video-chat/backend-king-php/http/module_marketplace.php')
const roomSnapshot = readRepo('demo/video-chat/backend-king-php/domain/realtime/realtime_room_snapshot.php')
const websocketCommands = readRepo('demo/video-chat/backend-king-php/http/module_realtime_websocket_commands.php')
const permissionGrants = readRepo('demo/video-chat/backend-king-php/domain/tenancy/permission_grants.php')
const marketplaceDescriptor = readRepo('demo/video-chat/frontend-vue/src/modules/marketplace/descriptor.js')
const callsDescriptor = readRepo('demo/video-chat/frontend-vue/src/modules/calls/descriptor.js')
const packageJson = readRepo('demo/video-chat/frontend-vue/package.json')

assertIncludes(sprint, '## Sprint: Call Apps Marketplace And CRDT Collaboration Surface', 'SPRINT.md must contain the Call Apps sprint')
assertIncludes(sprint, 'CAP-01 Architecture Inventory, 2026-05-07', 'SPRINT.md must contain the CAP-01 source inventory')
assertIncludes(sprint, '- [x] CAP-01 Architecture inventory and contracts', 'CAP-01 must be closed only after inventory and contract proof exist')
assertIncludes(sprint, 'demo/call-app/<app-key>/', 'Call Apps must live under demo/call-app/<app-key>/')
assertIncludes(sprint, 'demo/call-app/whiteboard/', 'whiteboard must be the first concrete Call App package path')
assertIncludes(sprint, 'CallWorkspaceView.vue` must not', 'Call App implementation must not grow CallWorkspaceView.vue')

for (const capability of [
  'call_apps.discover',
  'call_apps.marketplace.order',
  'call_apps.marketplace.install',
  'call_apps.marketplace.disable',
  'call_apps.call.attach',
  'call_apps.call.remove',
  'call_apps.call.view',
  'call_apps.permissions.manage',
  'call_apps.permissions.use',
  'call_apps.permissions.revoke',
  'call_apps.launch',
  'call_apps.launch.validate',
  'call_apps.crdt.read',
  'call_apps.crdt.append',
  'call_apps.crdt.replay',
  'call_apps.presence.publish',
  'call_apps.export.request',
  'call_apps.export.download',
]) {
  assertIncludes(sprint, `  - \`${capability}\``, `missing planned Call App capability ${capability}`)
}

for (const route of [
  'GET /api/admin/marketplace/apps',
  'POST /api/admin/marketplace/apps',
  'GET /api/admin/marketplace/apps/{app_id}',
  'PATCH /api/admin/marketplace/apps/{app_id}',
  'DELETE /api/admin/marketplace/apps/{app_id}',
  'GET /api/marketplace/call-apps',
  'GET /api/marketplace/call-apps/{app_key}',
  'POST /api/marketplace/call-apps/{app_key}/orders',
  'POST /api/marketplace/call-apps/{app_key}/installations',
  'PATCH /api/marketplace/call-apps/{app_key}/installations/{installation_id}',
  'GET /api/calls/{call_id}/call-apps/available',
  'GET /api/calls/{call_id}/call-app-sessions',
  'POST /api/calls/{call_id}/call-app-sessions',
  'PATCH /api/call-app-sessions/{session_id}',
  'DELETE /api/call-app-sessions/{session_id}',
  'GET /api/call-app-sessions/{session_id}/participant-grants',
  'PATCH /api/call-app-sessions/{session_id}/participant-grants',
  'POST /api/call-app-sessions/{session_id}/launch-token',
  'POST /api/call-app-sessions/{session_id}/launch-token/validate',
  'GET /api/call-app-sessions/{session_id}/crdt/bootstrap',
  'GET /api/call-app-sessions/{session_id}/crdt/ops',
  'POST /api/call-app-sessions/{session_id}/crdt/ops',
  'POST /api/call-app-sessions/{session_id}/crdt/snapshots',
  'POST /api/call-app-sessions/{session_id}/exports',
  'GET /api/call-app-exports/{job_id}',
  'GET /api/call-app-exports/{job_id}/download',
]) {
  assertIncludes(sprint, `  - \`${route}\``, `missing planned Call App route boundary ${route}`)
}

for (const mcpMethod of [
  'call_app.describe',
  'call_app.capabilities',
  'call_app.crdt_schema',
  'call_app.launch_contract',
  'call_app.health',
  'call_app.export_formats',
  'call_app.marketplace_listing',
]) {
  assertIncludes(sprint, `  - \`${mcpMethod}\``, `missing planned MCP metadata method ${mcpMethod}`)
}

assertIncludes(semanticDnsHeader, 'KING_SERVICE_TYPE_MCP_AGENT', 'Semantic DNS must already expose MCP agent service type')
assertIncludes(semanticDnsHeader, 'KING_SERVICE_TYPE_MOTHER_NODE', 'Semantic DNS must already expose mother-node service type')
assertIncludes(semanticDnsHeader, 'PHP_FUNCTION(king_semantic_dns_register_service)', 'Semantic DNS registration function must exist')
assertIncludes(semanticDnsHeader, 'PHP_FUNCTION(king_semantic_dns_discover_service)', 'Semantic DNS discovery function must exist')
assertIncludes(semanticDnsHeader, 'PHP_FUNCTION(king_semantic_dns_register_mother_node)', 'Semantic DNS mother-node registration function must exist')

assertIncludes(mcpHeader, 'king_mcp_request', 'MCP request primitive must exist for metadata discovery')
assertIncludes(mcpHeader, 'king_mcp_transfer_store', 'MCP upload primitive must exist for future app package metadata/assets')
assertIncludes(mcpHeader, 'king_mcp_transfer_find', 'MCP download primitive must exist for future app package metadata/assets')

assertIncludes(marketplaceDomain, 'function videochat_admin_list_call_apps', 'legacy admin marketplace list function must be inventoried')
assertIncludes(marketplaceDomain, 'function videochat_admin_create_call_app', 'legacy admin marketplace create function must be inventoried')
assertIncludes(marketplaceModule, "if ($path === '/api/admin/marketplace/apps')", 'legacy admin marketplace route boundary must exist')
assertIncludes(marketplaceModule, "preg_match('#^/api/admin/marketplace/apps/(\\d+)$#'", 'legacy admin marketplace item route boundary must exist')

assertIncludes(roomSnapshot, "'type' => 'room/snapshot'", 'room snapshot owner must exist for later active app session state')
assertIncludes(websocketCommands, 'videochat_realtime_handle_secondary_websocket_command', 'websocket command router must exist for later Call App events')
assertIncludes(permissionGrants, 'videochat_tenancy_user_has_resource_permission', 'tenant resource grant evaluator must exist for Call App permissions')
assertIncludes(marketplaceDescriptor, "module_key: 'marketplace'", 'frontend marketplace module descriptor must exist')
assertIncludes(callsDescriptor, "module_key: 'calls'", 'frontend calls module descriptor must exist')
assertIncludes(packageJson, 'call-apps-architecture-contract.mjs', 'frontend package scripts must include the Call Apps architecture contract')

console.log('[call-apps-architecture-contract] PASS')
