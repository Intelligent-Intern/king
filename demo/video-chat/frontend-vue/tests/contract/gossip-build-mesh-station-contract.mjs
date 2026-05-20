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

function requireMatch(source, pattern, message) {
  assert.match(source, pattern, `[gossip-build-mesh-station-contract] ${message}`)
}

const backendGossipMesh = read('demo/video-chat/backend-king-php/domain/realtime/realtime_gossipmesh.php')
const backendRoomState = read('demo/video-chat/backend-king-php/domain/realtime/realtime_gossipmesh_room_state.php')
const frameDecode = read('demo/video-chat/frontend-vue/src/domain/realtime/sfu/frameDecode.ts')
const gossipController = read('demo/video-chat/frontend-vue/src/lib/gossipmesh/gossipController.ts')
const rtcTransport = read('demo/video-chat/frontend-vue/src/lib/gossipmesh/rtcDataChannelTransport.ts')
const harness = read('demo/video-chat/frontend-vue/tests/e2e/helpers/nativeAudioTransferHarness.js')
const liveProof = read('demo/video-chat/frontend-vue/tests/e2e/live-call-video-proof.spec.js')
const packageJson = read('demo/video-chat/frontend-vue/package.json')
const packageScripts = JSON.parse(packageJson).scripts ?? {}
const gossipGate = String(packageScripts['test:contract:gossip'] || '')
const uiStatusContract = String(packageScripts['test:contract:call-workspace-ui-status'] || '')

requireMatch(
  backendGossipMesh,
  /foreach \(\$normalizedMembers as \$member\) \{\s*\n\s*\$topology\[\$member\['id'\]\] = \[\];\s*\n\s*\}[\s\S]*\$addSymmetricEdge = static function \(string \$leftId, string \$rightId\)[\s\S]*count\(\$topology\[\$leftId\]\) >= \$targetNeighbors[\s\S]*count\(\$topology\[\$rightId\]\) >= \$targetNeighbors[\s\S]*in_array\(\$rightId, \$topology\[\$leftId\], true\)[\s\S]*\$topology\[\$leftId\]\[\] = \$rightId;[\s\S]*\$topology\[\$rightId\]\[\] = \$leftId;/,
  'server topology must use symmetric build-mesh ring neighbor assignment with degree and duplicate guards',
)

requireMatch(
  backendGossipMesh,
  /for \(\$offset = 1; \$offset <= intdiv\(\$targetNeighbors, 2\); \$offset\+\+\) \{[\s\S]*\$addSymmetricEdge\(\$member\['id'\], \$normalizedMembers\[\(\$index \+ \$offset\) % \$memberCount\]\['id'\]\);[\s\S]*\$targetNeighbors % 2 === 1 && \$memberCount % 2 === 0[\s\S]*\$oppositeOffset = intdiv\(\$memberCount, 2\)[\s\S]*\$addSymmetricEdge\(\$member\['id'\], \$normalizedMembers\[\(\$index \+ \$oppositeOffset\) % \$memberCount\]\['id'\]\);/,
  'server topology must fill ring offsets and the even-member opposite edge before clients bind dedicated gossip neighbors',
)

requireMatch(
  backendGossipMesh,
  /\$admittedPeers = \[\];[\s\S]*'admitted_peers' => \$admittedPeers,[\s\S]*'neighbors' => \$neighbors/,
  'topology hints must include admitted_peers before clients bind dedicated gossip neighbors',
)

requireMatch(
  backendRoomState,
  /'seed' => \(string\) \(\$options\['seed'\] \?\? 'room_lifecycle'\)/,
  'room-state topology seed must stay stable across snapshot/churn reasons',
)

requireMatch(
  frameDecode,
  /function isGossipDeliveredFrame\(frame\)[\s\S]*gossip_rtc_datachannel[\s\S]*gossip_primary_direct[\s\S]*gossip_server_fanout/,
  'receiver must classify all active gossip delivery paths before SFU gates run',
)

requireMatch(
  frameDecode,
  /function shouldDropRemoteSfuFrameForCacheEpoch\(peer, publisherId, frame\) \{[\s\S]*if \(isGossipDeliveredFrame\(frame\)\) return false;/,
  'gossip frames must bypass SFU cache-epoch rejection',
)

requireMatch(
  frameDecode,
  /function shouldDropRemoteSfuFrameForContinuity\(publisherId, peer, frame\) \{[\s\S]*if \(isGossipDeliveredFrame\(frame\)\) return false;/,
  'gossip frames must bypass SFU continuity rejection',
)

requireMatch(
  frameDecode,
  /const gossipDeliveredFrame = isGossipDeliveredFrame\(frame\)[\s\S]*if \(!gossipDeliveredFrame && !options\.fromJitterBuffer && maybeBufferRemoteFrameForJitter\(publisherId, peer, frame\)\)/,
  'gossip frames must not wait in the SFU jitter buffer before decode',
)

requireMatch(
  gossipController,
  /seen_ttl_window: Map<string, number>[\s\S]*frame_history: Map<string, GossipTrackFrameHistory>[\s\S]*hasSeenWithEqualOrBetterTtl\(peer, frameId, incomingTtl\)/,
  'GossipController must keep Alex build-mesh TTL-aware duplicate suppression and per-track frame history',
)

assert.ok(
  !/heartbeatTimers|startHeartbeat|heartbeat_timeout|reconnect_requested|rtc_datachannel_lost/.test(gossipController),
  '[gossip-build-mesh-station-contract] GossipController must not run autonomous heartbeat health or reconnect decisions',
)

requireMatch(
  rtcTransport,
  /channel\.binaryType = 'arraybuffer'[\s\S]*void this\.handleIncomingMessage\(peerId, event\.data\)/,
  'dedicated Gossip RTCDataChannel receive path must request ArrayBuffer delivery and centralize binary decoding',
)

requireMatch(
  rtcTransport,
  /private async handleIncomingMessage\(peerId: string, data: unknown\): Promise<void>[\s\S]*data instanceof ArrayBuffer[\s\S]*typeof Blob !== 'undefined' && data instanceof Blob[\s\S]*await data\.arrayBuffer\(\)[\s\S]*this\.onDataMessage\(this\.codec\.decode\(bytes\), peerId\)/,
  'dedicated Gossip RTCDataChannel receive path must decode ArrayBuffer and Blob payloads instead of silently dropping browser-delivered media',
)

requireMatch(
  rtcTransport,
  /gossip_datachannel_unsupported_payload[\s\S]*gossip_datachannel_decode_failed/,
  'dedicated Gossip RTCDataChannel receive failures must become transport diagnostics, not invisible video loss',
)

requireMatch(
  harness,
  /deterministicVideoPattern = false[\s\S]*const drawDeterministicPattern = \(\) => \{[\s\S]*canvas\.captureStream\(settings\.frameRate\)/,
  'Playwright media shim must provide deterministic video frames for live proof',
)

requireMatch(
  harness,
  /export async function remoteVideoPixelSnapshot\(page\)[\s\S]*patternScore[\s\S]*export async function waitForDeterministicRemoteVideo\(page,/,
  'test harness must expose remote pixel probes that fail when video is not rendered',
)

requireMatch(
  liveProof,
  /DEFAULT_DURATION_MS = 30 \* 60 \* 1000[\s\S]*waitForDeterministicRemoteVideo\(admin\.page[\s\S]*expect\(maxPatternScore[\s\S]*admin page must not reload[\s\S]*websocket must not close/,
  'live proof must run long enough to prove remote pixels without reload or websocket churn',
)

requireMatch(
  liveProof,
  /const guestName = envValue\('KINGRT_LIVE_PROOF_GUEST_NAME'\) \|\| 'KingRT Live Proof Sender';[\s\S]*const guestNameInput = joinDialog\.locator\('[^']*display name[\s\S]*await expect\(guestNameInput\)\.toBeVisible\([\s\S]*await guestNameInput\.fill\(guestName\);[\s\S]*await expect\(guestNameInput\)\.toHaveValue\(guestName[\s\S]*getByRole\('button', \{ name: \/\^Join call\$\/i \}\)/,
  '[gossip-build-mesh-station-contract] live proof must fill the guest name required by public join links before trying admission',
)

assert.ok(
  gossipGate.includes('gossip-build-mesh-station-contract.mjs'),
  '[gossip-build-mesh-station-contract] gossip contract suite must include the build-mesh station proof',
)

assert.ok(
  uiStatusContract.includes('call-workspace-ui-status-contract.mjs'),
  '[gossip-build-mesh-station-contract] call workspace UI status contract script must run the UI status contract',
)

assert.ok(
  gossipGate.includes('npm run test:contract:call-workspace-ui-status'),
  '[gossip-build-mesh-station-contract] gossip contract suite must include the call workspace UI status gate',
)

console.log('[gossip-build-mesh-station-contract] PASS')
