import fs from 'node:fs'
import path from 'node:path'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')
const repoRoot = path.resolve(frontendRoot, '../../..')
const callAppRoot = path.join(repoRoot, 'demo/call-apps')
const singularCallAppRoot = path.join(repoRoot, 'demo/call-app')
const whiteboardRoot = path.join(callAppRoot, 'whiteboard')
const planningImageRoot = path.join(callAppRoot, 'planning-image')
const textDocumentRoot = path.join(callAppRoot, 'text-document')
const presentationRoot = path.join(callAppRoot, 'presentation')
const spreadsheetRoot = path.join(callAppRoot, 'spreadsheet')
const callDiagnosticsRoot = path.join(callAppRoot, 'call-diagnostics')

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

function trackedFiles(relativePath) {
  const output = execFileSync('git', ['ls-files', '--cached', '--others', '--exclude-standard', relativePath], {
    cwd: repoRoot,
    encoding: 'utf8',
  })

  return output
    .split('\n')
    .filter(Boolean)
    .filter((file) => fs.existsSync(path.join(repoRoot, file)))
}

function assertNoFiles(relativePaths, message) {
  assert(relativePaths.length === 0, `${message}: ${relativePaths.join(', ')}`)
}

function rootMarkdownFiles() {
  return fs.readdirSync(repoRoot, { withFileTypes: true })
    .filter((entry) => entry.isFile() && entry.name.toLowerCase().endsWith('.md'))
    .map((entry) => entry.name)
    .sort()
}

assert(fs.existsSync(callAppRoot), 'demo/call-apps root must exist')
assert(fs.existsSync(whiteboardRoot), 'demo/call-apps/whiteboard package must exist')
assert(fs.existsSync(planningImageRoot), 'demo/call-apps/planning-image package must exist')
assert(fs.existsSync(textDocumentRoot), 'demo/call-apps/text-document package must exist')
assert(fs.existsSync(presentationRoot), 'demo/call-apps/presentation package must exist')
assert(fs.existsSync(spreadsheetRoot), 'demo/call-apps/spreadsheet package must exist')
assert(fs.existsSync(callDiagnosticsRoot), 'demo/call-apps/call-diagnostics package must exist')
assert(!fs.existsSync(singularCallAppRoot), 'demo/call-app must not exist; canonical source root is plural demo/call-apps')

const appKeys = [
  'whiteboard',
  'planning-image',
  'text-document',
  'presentation',
  'spreadsheet',
  'call-diagnostics',
]
const appPackageRequiredFiles = [
  'call-app.manifest.json',
  'mcp.descriptor.json',
  'crdt.schema.json',
  'health.descriptor.json',
  'public/index.html',
]
const appPackageRuntimeFiles = appKeys.flatMap((appKey) => [
  `public/${appKey}.css`,
  `public/${appKey}.js`,
])
const allowedCallAppPackageRoots = appKeys.map((appKey) => `demo/call-apps/${appKey}/`)
const trackedCallAppFiles = trackedFiles('demo/call-apps')
const misplacedCallAppFiles = trackedCallAppFiles.filter((file) => (
  file !== 'demo/call-apps/README.md'
  && !allowedCallAppPackageRoots.some((allowedRoot) => file.startsWith(allowedRoot))
))
assertNoFiles(
  misplacedCallAppFiles,
  'tracked Call App package files must live under demo/call-apps/<app-key>/',
)
const trackedSingularCallAppFiles = trackedFiles('demo/call-app')
  .filter((file) => fs.existsSync(path.join(repoRoot, file)))
assertNoFiles(
  trackedSingularCallAppFiles,
  'tracked Call App package files must not use demo/call-app; canonical source root is demo/call-apps',
)

const trackedDistCallAppFiles = trackedFiles('demo/video-chat/frontend-vue/dist/call-app')
assertNoFiles(
  trackedDistCallAppFiles,
  'frontend dist/call-app must not contain tracked Call App package mirrors',
)

const allowedRootMarkdownFiles = new Set(['README.md', 'BACKLOG.md', 'EPIC.md', 'SPRINT.md'])
const extraRootMarkdownFiles = rootMarkdownFiles().filter((file) => !allowedRootMarkdownFiles.has(file))
assertNoFiles(
  extraRootMarkdownFiles,
  'root Markdown must stay limited to README.md, BACKLOG.md, and SPRINT.md',
)

const packageDirectoryNames = fs.readdirSync(callAppRoot, { withFileTypes: true })
  .filter((entry) => entry.isDirectory())
  .map((entry) => entry.name)
  .sort()
const expectedPackageDirectoryNames = [...appKeys].sort()
assert(
  JSON.stringify(packageDirectoryNames) === JSON.stringify(expectedPackageDirectoryNames),
  `demo/call-apps package directories must match app keys: ${packageDirectoryNames.join(', ')}`,
)

for (const appKey of appKeys) {
  const packageRoot = path.join(callAppRoot, appKey)
  const packageFiles = [
    ...appPackageRequiredFiles,
    `public/${appKey}.css`,
    `public/${appKey}.js`,
  ]

  for (const requiredFile of packageFiles) {
    assert(fs.existsSync(path.join(packageRoot, requiredFile)), `${appKey} package must include ${requiredFile}`)
  }

  const packageManifest = readJson(`demo/call-apps/${appKey}/call-app.manifest.json`)
  assert(packageManifest.schema_version === 'king.call_app.manifest.v1', `${appKey} manifest schema version mismatch`)
  assert(packageManifest.app_key === appKey, `${appKey} manifest app_key must match its package directory`)
  assert(packageManifest.status === 'runtime_ready', `${appKey} package must advertise runtime_ready status`)
  assert(packageManifest.semantic_dns?.service_type === 'call_app', `${appKey} manifest must declare Semantic-DNS call_app service type`)
  assert(packageManifest.semantic_dns?.mother_node_registration?.required === true, `${appKey} manifest must require mother-node registration`)
  assert(packageManifest.marketplace?.order_scope === 'organization', `${appKey} manifest marketplace order scope must be organization`)
  assert(packageManifest.marketplace?.requires_installation === true, `${appKey} manifest must require organization installation before call use`)
  assert(
    ['allowed_by_default', 'blocked_by_default'].includes(packageManifest.default_participant_access),
    `${appKey} manifest default participant access must be explicit`,
  )
  assert(packageManifest.iframe?.entrypoint === 'public/index.html', `${appKey} iframe entrypoint must be package-local`)
  assert(packageManifest.iframe?.receives_primary_session_token === false, `${appKey} iframe must not receive the primary session token`)
  assert(packageManifest.iframe?.bridge_protocol === 'king.call_app.iframe.v1', `${appKey} iframe bridge protocol mismatch`)
  assertArrayIncludes(packageManifest.iframe?.sandbox, 'allow-scripts', `${appKey} iframe sandbox must allow scripts for the app runtime`)
  assert(!packageManifest.iframe?.sandbox?.includes('allow-same-origin'), `${appKey} iframe sandbox must not allow same-origin by default`)

  const packageMcpDescriptor = readJson(`demo/call-apps/${appKey}/mcp.descriptor.json`)
  assert(packageMcpDescriptor.schema_version === 'king.call_app.mcp_descriptor.v1', `${appKey} MCP descriptor schema mismatch`)
  assert(packageMcpDescriptor.service_name === `call_app.${appKey}.mcp`, `${appKey} MCP service name mismatch`)

  const packageCrdtSchema = readJson(`demo/call-apps/${appKey}/crdt.schema.json`)
  assert(packageCrdtSchema.schema_version === 'king.call_app.crdt_schema.v1', `${appKey} CRDT schema version mismatch`)
  assert(packageCrdtSchema.protocol === 'king.call_app.crdt.v1', `${appKey} CRDT protocol mismatch`)

  const packageHealth = readJson(`demo/call-apps/${appKey}/health.descriptor.json`)
  assert(packageHealth.schema_version === 'king.call_app.health_descriptor.v1', `${appKey} health descriptor schema mismatch`)
  const packageHealthPaths = packageHealth.checks.map((check) => check.path)
  const healthCheckedFiles = packageFiles.filter((file) => file !== 'health.descriptor.json')
  for (const healthPath of healthCheckedFiles) {
    assertArrayIncludes(packageHealthPaths, healthPath, `${appKey} health descriptor missing check for ${healthPath}`)
  }

  const packageIframe = read(`demo/call-apps/${appKey}/public/index.html`)
  const packageRuntime = read(`demo/call-apps/${appKey}/public/${appKey}.js`)
  const packageBundle = `${packageIframe}\n${packageRuntime}`
  assert(packageIframe.includes('king.call_app.iframe.v1'), `${appKey} iframe entrypoint must declare bridge protocol`)
  assert(packageIframe.includes(`${appKey}.css`), `${appKey} iframe entrypoint must load its package stylesheet`)
  assert(packageIframe.includes(`${appKey}.js`), `${appKey} iframe entrypoint must load its package runtime`)
  assert(packageRuntime.includes("message.type === 'call_app.launch'"), `${appKey} iframe runtime must wait for launch message`)
  assert(packageRuntime.includes("'call_app.ready'"), `${appKey} iframe runtime must emit ready message after launch`)
  assert(packageRuntime.includes('primary_session_token_received: false'), `${appKey} iframe runtime must not accept a primary session token`)
  assert(!packageBundle.includes('sessionToken'), `${appKey} iframe bundle must not reference parent session tokens`)
  assert(!packageBundle.includes('Authorization'), `${appKey} iframe bundle must not reference authorization headers`)
}

const appPackageSourceBasenames = new Set([
  ...appPackageRequiredFiles.filter((file) => !file.includes('/')).map((file) => path.posix.basename(file)),
  ...appPackageRuntimeFiles.map((file) => path.posix.basename(file)),
])
const appPackageSourcePathSuffixes = new Set([
  ...appPackageRequiredFiles,
  ...appPackageRuntimeFiles,
])
const allowedHostBridgeSourcePrefixes = [
  'demo/video-chat/frontend-vue/src/domain/realtime/callApps/',
  'demo/video-chat/frontend-vue/src/stores/callAppsCatalogStore.js',
]
const allowedBackendPathPrefixes = [
  'demo/video-chat/backend-king-php/domain/call_apps/',
  'demo/video-chat/backend-king-php/http/module_call_apps.php',
  'demo/video-chat/backend-king-php/support/call_app_',
]
const trackedBackendFiles = trackedFiles('demo/video-chat/backend-king-php')
const trackedFrontendSourceFiles = trackedFiles('demo/video-chat/frontend-vue/src')
const frontendSourcePackageMirrors = trackedFrontendSourceFiles.filter((file) => {
  const basename = path.posix.basename(file)
  const sourceSuffix = file.replace('demo/video-chat/frontend-vue/src/', '')
  return appPackageSourceBasenames.has(basename) || appPackageSourcePathSuffixes.has(sourceSuffix)
})
assertNoFiles(
  frontendSourcePackageMirrors,
  'frontend src must not contain Call App package source files as a second source of truth',
)
assert(
  allowedHostBridgeSourcePrefixes.every((allowedPath) => trackedFrontendSourceFiles.some((file) => file.startsWith(allowedPath))),
  'frontend host/bridge Call App paths must remain explicitly allowed',
)
assert(
  allowedBackendPathPrefixes.every((allowedPath) => trackedBackendFiles.some((file) => file.startsWith(allowedPath))),
  'backend Call App paths must remain explicitly allowed outside the app package source scan',
)

const readme = read('demo/call-apps/README.md')
assert(readme.includes('canonical repository source root is plural `demo/call-apps/`'), 'README must document the plural package source root decision')
assert(readme.includes('`demo/call-app/` is not a Call App source root'), 'README must reject demo/call-app as a parallel source root')
assert(readme.includes('Runtime/public Call App URLs remain `/call-app/<app-key>/...`'), 'README must preserve the runtime /call-app URL contract separately from the source root')
assert(readme.includes('demo/call-apps/<app-key>/'), 'README must document the package root convention')
assert(readme.includes('repository-root special-purpose Markdown'), 'README must document the root Markdown boundary')
for (const requiredFile of [
  'call-app.manifest.json',
  'mcp.descriptor.json',
  'crdt.schema.json',
  'health.descriptor.json',
  'public/index.html',
  'public/<app-key>.css',
  'public/<app-key>.js',
]) {
  assert(readme.includes(requiredFile), `README must document ${requiredFile}`)
}
for (const requiredFile of [
  'call-app.manifest.json',
  'mcp.descriptor.json',
  'crdt.schema.json',
  'health.descriptor.json',
  'public/index.html',
  'public/whiteboard.css',
  'public/whiteboard.js',
]) {
  assert(fs.existsSync(path.join(whiteboardRoot, requiredFile)), `whiteboard package must include ${requiredFile}`)
}
for (const requiredFile of [
  'call-app.manifest.json',
  'mcp.descriptor.json',
  'crdt.schema.json',
  'health.descriptor.json',
  'public/index.html',
  'public/planning-image.css',
  'public/planning-image.js',
]) {
  assert(fs.existsSync(path.join(planningImageRoot, requiredFile)), `planning-image package must include ${requiredFile}`)
}
assert(readme.includes('planning-image'), 'README must list the planning-image package')
for (const requiredFile of [
  'call-app.manifest.json',
  'mcp.descriptor.json',
  'crdt.schema.json',
  'health.descriptor.json',
  'public/index.html',
  'public/text-document.css',
  'public/text-document.js',
]) {
  assert(fs.existsSync(path.join(textDocumentRoot, requiredFile)), `text-document package must include ${requiredFile}`)
}
assert(readme.includes('text-document'), 'README must list the text-document package')
for (const requiredFile of [
  'call-app.manifest.json',
  'mcp.descriptor.json',
  'crdt.schema.json',
  'health.descriptor.json',
  'public/index.html',
  'public/presentation.css',
  'public/presentation.js',
]) {
  assert(fs.existsSync(path.join(presentationRoot, requiredFile)), `presentation package must include ${requiredFile}`)
}
assert(readme.includes('presentation'), 'README must list the presentation package')
for (const requiredFile of [
  'call-app.manifest.json',
  'mcp.descriptor.json',
  'crdt.schema.json',
  'health.descriptor.json',
  'public/index.html',
  'public/spreadsheet.css',
  'public/spreadsheet.js',
]) {
  assert(fs.existsSync(path.join(spreadsheetRoot, requiredFile)), `spreadsheet package must include ${requiredFile}`)
}
assert(readme.includes('spreadsheet'), 'README must list the spreadsheet package')
for (const requiredFile of [
  'call-app.manifest.json',
  'mcp.descriptor.json',
  'crdt.schema.json',
  'health.descriptor.json',
  'public/index.html',
  'public/call-diagnostics.css',
  'public/call-diagnostics.js',
]) {
  assert(fs.existsSync(path.join(callDiagnosticsRoot, requiredFile)), `call-diagnostics package must include ${requiredFile}`)
}
assert(readme.includes('call-diagnostics'), 'README must list the call-diagnostics package')

const manifest = readJson('demo/call-apps/whiteboard/call-app.manifest.json')
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

const mcpDescriptor = readJson('demo/call-apps/whiteboard/mcp.descriptor.json')
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

const crdtSchema = readJson('demo/call-apps/whiteboard/crdt.schema.json')
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
  'text.update',
  'sticky_note.add',
  'sticky_note.update',
]) {
  assertArrayIncludes(operationTypes, operationType, `CRDT schema missing operation type ${operationType}`)
}
for (const presenceType of ['cursor.move', 'selection.update', 'tool.preview']) {
  assert(!operationTypes.includes(presenceType), `${presenceType} must not be listed as a persisted document operation`)
  assertArrayIncludes(crdtSchema.presence?.types, presenceType, `CRDT schema missing non-persistent presence type ${presenceType}`)
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

const health = readJson('demo/call-apps/whiteboard/health.descriptor.json')
assert(health.schema_version === 'king.call_app.health_descriptor.v1', 'health descriptor schema mismatch')
const healthPaths = health.checks.map((check) => check.path)
for (const healthPath of [
  'call-app.manifest.json',
  'mcp.descriptor.json',
  'crdt.schema.json',
  'public/index.html',
  'public/whiteboard.css',
  'public/whiteboard.js',
]) {
  assertArrayIncludes(healthPaths, healthPath, `health descriptor missing check for ${healthPath}`)
}

const iframe = read('demo/call-apps/whiteboard/public/index.html')
const iframeRuntime = read('demo/call-apps/whiteboard/public/whiteboard.js')
const iframeBundle = `${iframe}\n${iframeRuntime}`
assert(iframe.includes('king.call_app.iframe.v1'), 'iframe entrypoint must declare bridge protocol')
assert(iframe.includes('whiteboard.css'), 'iframe entrypoint must load the extracted stylesheet')
assert(iframe.includes('whiteboard.js'), 'iframe entrypoint must load the extracted runtime')
assert(iframeRuntime.includes("message.type === 'call_app.launch'"), 'iframe runtime must wait for launch message')
assert(iframeRuntime.includes("'call_app.ready'"), 'iframe runtime must emit ready message after launch')
assert(iframeRuntime.includes('primary_session_token_received: false'), 'iframe runtime must not accept a primary session token')
assert(!iframeBundle.includes('sessionToken'), 'iframe bundle must not reference parent session tokens')
assert(!iframeBundle.includes('Authorization'), 'iframe bundle must not reference authorization headers')

const planningDocs = `${read('SPRINT.md')}\n${read('BACKLOG.md')}`
assert(planningDocs.includes('Root planning Markdown remains limited to `README.md`, `BACKLOG.md`,'), 'planning docs must keep root markdown constrained')
assert(planningDocs.includes('Keep Call App package roots canonical at `demo/call-apps/<app-key>/`.'), 'planning docs must retain the canonical Call App package root contract')
assert(planningDocs.includes('Keep `demo/video-chat/frontend-vue/src/domain/realtime/callApps` as'), 'planning docs must retain the host/source boundary')
assert(planningDocs.includes('Treat `demo/video-chat/frontend-vue/dist/call-app` as build output only.'), 'planning docs must retain the build-output boundary')
const packageJson = read('demo/video-chat/frontend-vue/package.json')
assert(packageJson.includes('call-app-package-layout-contract.mjs'), 'package scripts must include package layout contract')
assert(packageJson.includes('test:contract:call-apps:sqlite'), 'package scripts must expose the SQLite-backed Call App backend proof')
const sqliteRuntimeProof = read('demo/video-chat/backend-king-php/tests/call-app-sqlite-runtime-proof.sh')
assert(sqliteRuntimeProof.includes('CALL_APP_SQLITE_PHP_IMAGE'), 'SQLite runtime proof must allow the PHP container image to be pinned')
for (const contract of [
  'call-app-marketplace-entitlement-contract.sh',
  'call-app-availability-contract.sh',
  'call-app-session-lifecycle-contract.sh',
]) {
  assert(sqliteRuntimeProof.includes(contract), `SQLite runtime proof must run ${contract}`)
}
const viteConfig = read('demo/video-chat/frontend-vue/vite.config.js')
assert(viteConfig.includes('callAppStaticPlugin()'), 'frontend build must install the Call App static publishing plugin')
assert(viteConfig.includes('fileName: `call-app/${relativePath}`'), 'frontend build must emit Call App package files into dist/call-app')

console.log('[call-app-package-layout-contract] PASS')
