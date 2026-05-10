import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')
const repoRoot = path.resolve(frontendRoot, '../../..')

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8')
}

function readJson(relativePath) {
  return JSON.parse(read(relativePath))
}

function assertIncludes(source, needle, message) {
  assert.ok(source.includes(needle), message)
}

const legacyRoot = path.join(repoRoot, 'demo/call-app/image-planning')
const canonicalRoot = path.join(repoRoot, 'demo/call-app/planning-image')

assert.ok(!fs.existsSync(legacyRoot), 'legacy image-planning package must not be copied into integration')
assert.ok(fs.existsSync(canonicalRoot), 'canonical planning-image package must remain integrated')

const classification = read('documentation/call-app-planning-image-dirty-worktree-classification.md')
assertIncludes(
  classification,
  '/home/jochen/projects/king.site/worktrees/planning-image-call-app',
  'classification must name the dirty source worktree',
)
assertIncludes(classification, '`image-planning`', 'classification must identify the legacy source app key')
assertIncludes(classification, '`planning-image`', 'classification must identify the canonical integrated app key')
assertIncludes(
  classification,
  'After this commit is integrated, the source dirty worktree can be removed',
  'classification must record the source worktree cleanup decision',
)
assertIncludes(
  classification,
  'No still-relevant non-media value remains only in the dirty source worktree',
  'classification must state that unique non-media value has been preserved',
)

const readme = read('demo/call-app/README.md')
assertIncludes(readme, 'public/<app-key>.css', 'README must preserve generic package stylesheet naming')
assertIncludes(readme, 'public/<app-key>.js', 'README must preserve generic package runtime naming')
assertIncludes(readme, '`planning-image`', 'README must list the canonical planning-image package')

const manifest = readJson('demo/call-app/planning-image/call-app.manifest.json')
assert.equal(manifest.app_key, 'planning-image', 'canonical planning-image manifest key mismatch')
assert.equal(manifest.status, 'runtime_ready', 'canonical planning-image package must stay runtime-ready')
assert.equal(
  manifest.iframe?.receives_primary_session_token,
  false,
  'canonical planning-image iframe must not receive primary session tokens',
)
assert.ok(
  !manifest.iframe?.sandbox?.includes('allow-same-origin'),
  'canonical planning-image iframe sandbox must stay opaque',
)

const crdt = readJson('demo/call-app/planning-image/crdt.schema.json')
assert.equal(crdt.documents?.[0]?.kind, 'planning_image_document', 'canonical planning-image CRDT kind mismatch')
assert.ok(
  crdt.documents?.[0]?.operation_types?.includes('planning_image.viewport'),
  'canonical planning-image CRDT schema must preserve the viewport operation anchor',
)
assert.ok(
  !crdt.documents?.[0]?.operation_types?.includes('viewport.update'),
  'legacy viewport.update operation must not be reintroduced',
)

const runtime = read('demo/call-app/planning-image/public/planning-image.js')
for (const [needle, message] of [
  ['FileReader', 'runtime must preserve iframe-local file upload support'],
  ["canvas.addEventListener('wheel'", 'runtime must preserve wheel zoom support'],
  ["canvas.addEventListener('pointerdown'", 'runtime must preserve pointer pan support'],
  ['fitImage()', 'runtime must preserve fit-to-view support'],
  ['primary_session_token_received: false', 'runtime must reject primary session token delivery'],
  ['window.crypto.randomUUID', 'runtime must preserve UUID-backed image ids'],
  ['imageThumbList.replaceChildren()', 'runtime must preserve CRDT-backed thumbnail rendering'],
  ["canvas.toDataURL('image/png')", 'runtime must preserve PNG export support'],
]) {
  assertIncludes(runtime, needle, message)
}

const bundle = `${read('demo/call-app/planning-image/public/index.html')}\n${runtime}`
assert.ok(!bundle.includes('sessionToken'), 'planning-image bundle must not reference parent session tokens')
assert.ok(!bundle.includes('Authorization'), 'planning-image bundle must not reference authorization headers')

const viteConfig = read('demo/video-chat/frontend-vue/vite.config.js')
assertIncludes(viteConfig, 'walkFiles(callAppRoot)', 'Vite must continue publishing all Call App package files')
assertIncludes(
  viteConfig,
  'fileName: `call-app/${relativePath}`',
  'Vite emitted Call App asset path must stay package-relative',
)

const workspaceState = read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/callAppWorkspaceState.js')
assertIncludes(
  workspaceState,
  'function callAppOriginForAppKey(appKey)',
  'workspace state must keep non-whiteboard Call App origin derivation',
)
assert.match(
  workspaceState,
  /\['app', 'apps'\]\.includes\(parts\[0\]\)/,
  'workspace state must derive concrete app subdomains from generic app/apps hosts',
)
assert.doesNotMatch(
  workspaceState,
  /\['app', 'apps', 'whiteboard'\]/,
  'workspace state must not hard-code the origin derivation to whiteboard only',
)

console.log('[call-app-planning-image-dirty-worktree-classification-contract] PASS')
