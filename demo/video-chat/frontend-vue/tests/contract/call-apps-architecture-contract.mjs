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

const planningDocs = `${readRepo('SPRINT.md')}\n${readRepo('BACKLOG.md')}`
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
const packageLayoutReadme = readRepo('demo/call-apps/README.md')

assertIncludes(planningDocs, 'Root planning Markdown remains limited to `README.md`, `BACKLOG.md`,', 'planning sources must keep root markdown constrained')
assertIncludes(planningDocs, 'Keep Call App package roots canonical at `demo/call-apps/<app-key>/`.', 'planning sources must preserve the canonical Call App package root')
assertIncludes(planningDocs, 'Keep `demo/video-chat/frontend-vue/src/domain/realtime/callApps` as', 'planning sources must preserve the Call Apps host/source boundary')
assertIncludes(planningDocs, 'Treat `demo/video-chat/frontend-vue/dist/call-app` as build output only.', 'planning sources must preserve the Call Apps build-output boundary')
assertIncludes(planningDocs, 'Do not grow `CallWorkspaceView.vue`', 'planning sources must preserve the CallWorkspace extraction boundary')
assertIncludes(packageLayoutReadme, 'canonical repository source root is plural `demo/call-apps/`', 'package layout docs must keep demo/call-apps as the canonical source root')
assertIncludes(packageLayoutReadme, '`demo/call-app/` is not a Call App source root', 'package layout docs must reject demo/call-app as a parallel package root')
assertIncludes(packageLayoutReadme, 'Runtime/public Call App URLs remain `/call-app/<app-key>/...`', 'package layout docs must keep runtime /call-app URLs separate from the source root decision')
assert(!fs.existsSync(path.join(repoRoot, 'demo/call-app')), 'demo/call-app must not exist while demo/call-apps is canonical')

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
