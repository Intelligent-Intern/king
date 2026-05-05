import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(__dirname, '../..')
const harnessPath = path.join(root, 'public/gossip-harness.html')
const packagePath = path.join(root, 'package.json')
const html = fs.readFileSync(harnessPath, 'utf8')
const packageJson = fs.readFileSync(packagePath, 'utf8')

function assert(condition, message) {
  if (!condition) {
    throw new Error(`[gossip-harness-faults-contract] ${message}`)
  }
}

function requireContains(needle, message) {
  assert(html.includes(needle), message)
}

function requireRegex(pattern, message) {
  assert(pattern.test(html), message)
}

const faultModes = [
  'data_drop',
  'duplicate_frames',
  'slow_peer',
  'neighbor_failure',
  'ops_heartbeat_loss',
  'ops_carrier_loss',
]

requireContains('<title>GossipMesh Task 0 - 4-Peer Local Video Harness</title>', 'standalone harness title must identify the four-peer local test')
requireContains('Start 4-Peer Mesh', 'standalone harness must expose the four-peer mesh start action')
requireContains("const PEER_NAMES = ['Alice', 'Bob', 'Charlie', 'Diana'];", 'standalone harness must keep exactly four named local peers')
requireContains('const DEFAULT_FANOUT = 4;', 'local harness must default to fanout degree 4')
requireContains('const MAX_FANOUT = 5;', 'local harness must keep hard fanout cap 5')
requireContains('v-model.number="fanout"', 'local harness must expose an adjustable numeric fanout control')
requireContains(':max="MAX_FANOUT"', 'fanout control must be bounded by the hard cap')
requireRegex(
  /function effectiveFanout\(allPeerIds\)[\s\S]*Math\.min\(MAX_FANOUT,\s*Math\.max\(1,\s*requestedFanout\)\)[\s\S]*Math\.min\(boundedByHardCap,\s*Math\.max\(0,\s*allPeerIds\.length - 1\)\)/,
  'effective fanout must clamp to both hard cap and available peer degree',
)
requireRegex(
  /return rotated\.slice\(0,\s*effectiveFanout\(allPeerIds\)\)/,
  'neighbor selection must use adjustable effective fanout',
)
requireRegex(
  /neighbors\.slice\(0,\s*effectiveFanout\(peers\.value\.map\(\(peer\) => peer\.id\)\)\)/,
  'data forwarding must use adjustable effective fanout',
)
requireRegex(
  /fanout:\s*effectiveFanout\(nextPeers\.map\(\(peer\) => peer\.id\)\)/,
  'topology event must report the effective bounded fanout',
)
assert(
  packageJson.includes('node tests/contract/gossip-harness-faults-contract.mjs'),
  'npm run test:contract:gossip must include the standalone harness regression contract',
)

for (const mode of faultModes) {
  requireContains(`value="${mode}"`, `fault mode option is missing: ${mode}`)
  requireContains(`faultType.value === '${mode}'`, `fault mode has no executable branch: ${mode}`)
}

requireContains('const faultPeerId = ref(\'peer_2\')', 'fault target must default to a peer different from the default viewer')
requireContains('v-model="faultPeerId"', 'harness must expose a separate Fault peer selector')
requireContains('v-model="selectedPeerId"', 'harness must expose a separate View as selector')

requireRegex(
  /faultType\.value === 'neighbor_failure' && fromPeerId === faultPeerId\.value/,
  'neighbor_failure must impair the selected fault peer as a publisher/forwarder',
)
requireRegex(
  /faultType\.value === 'slow_peer' && targetPeerId === faultPeerId\.value/,
  'slow_peer must impair the selected fault peer as a receiver',
)
requireRegex(
  /faultType\.value === 'data_drop' && Math\.random\(\) < 0\.2/,
  'data_drop must actually drop a percentage of data-lane frames',
)
requireRegex(
  /faultType\.value === 'duplicate_frames'[\s\S]*deliverFrame\(targetPeerId, frame, fromPeerId, ttl - 1, true\)/,
  'duplicate_frames must inject a second delivery attempt',
)
requireRegex(
  /target\.seenWindow\.has\(frame\.frame_id\)[\s\S]*drop_duplicate/,
  'duplicate data frames must be detected and logged',
)

requireRegex(
  /peer\.id === faultPeerId\.value[\s\S]*ops_heartbeat_loss[\s\S]*ops_carrier_loss/,
  'ops heartbeat/carrier faults must target faultPeerId, not the current viewer',
)
requireRegex(
  /faultType\.value === 'ops_carrier_loss' && peer\.id === faultPeerId\.value[\s\S]*peer\.lastHeartbeatAt = now - LOST_AFTER_MS - 100/,
  'ops_carrier_loss must force carrier timeout for the fault peer',
)
requireRegex(
  /logEvent\(peer\.id, 'reconnect_requested', 'ops'/,
  'reconnect requests must be logged on the ops lane',
)
requireRegex(
  /reconnect_allowed: true[\s\S]*reconnect_reason: 'ops_carrier_timeout'/,
  'carrier-loss reconnect must include allow/reason fields',
)

requireRegex(
  /fromPeer\.carrierState === 'lost'[\s\S]*reason: 'publisher_carrier_lost'/,
  'a lost publisher must stop forwarding fresh data',
)
requireRegex(
  /target\.carrierState === 'lost'[\s\S]*reason: 'receiver_carrier_lost'/,
  'a lost receiver must stop accepting fresh data',
)
requireRegex(
  /const isStale = !isOwn && staleAgeMs > STALE_FRAME_AFTER_MS/,
  'remote panes must visibly become stale when fresh mesh data stops',
)

requireRegex(
  /publisher\.id === selectedPeerId \? 'own local publish' : 'received via gossip'/,
  'viewer perspective must distinguish own local video from received videos',
)
requireRegex(
  /for \(const peer of peers\.value\)[\s\S]*latestOwnFrames\[peer\.id\] = frame[\s\S]*deliverToNeighbors\(peer\.id, frame/,
  'all peers must publish their own video frames',
)

console.log('[gossip-harness-faults-contract] PASS')
