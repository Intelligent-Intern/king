import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(new URL('../..', import.meta.url).pathname)
const repoRoot = path.resolve(root, '../../..')
const dataLane = fs.readFileSync(path.join(root, 'src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts'), 'utf8')
const recoveryState = fs.readFileSync(path.join(root, 'src/domain/realtime/workspace/callWorkspace/gossipRecoveryState.ts'), 'utf8')
const runtimeConfig = fs.readFileSync(path.join(root, 'src/domain/realtime/workspace/callWorkspace/runtimeConfig.ts'), 'utf8')
const controller = fs.readFileSync(path.join(root, 'src/lib/gossipmesh/gossipController.ts'), 'utf8')
const backendRecovery = fs.readFileSync(path.join(root, '../backend-king-php/domain/realtime/realtime_gossipmesh_recovery.php'), 'utf8')
const websocketRecovery = fs.readFileSync(path.join(root, '../backend-king-php/http/module_realtime_gossipmesh_recovery.php'), 'utf8')
const websocketCommands = fs.readFileSync(path.join(root, '../backend-king-php/http/module_realtime_websocket_commands.php'), 'utf8')
const runtimeContract = fs.readFileSync(path.join(root, '../backend-king-php/tests/realtime-gossipmesh-runtime-contract.php'), 'utf8')
const sprint = fs.readFileSync(path.join(repoRoot, 'SPRINT.md'), 'utf8')
const packageJson = fs.readFileSync(path.join(root, 'package.json'), 'utf8')

function assert(condition, message) {
  if (!condition) {
    throw new Error(`[gossip-native-recovery-contract] ${message}`)
  }
}

assert(
  /createGossipRecoveryState/.test(dataLane)
    && /rememberPublishedFrame\(msg\)/.test(dataLane)
    && /recoveryRequestForReceivedFrame\(frame\)/.test(dataLane),
  'workspace data lane must cache publisher frames and derive receiver-side recovery requests',
)
assert(
  /type:\s*'gossip\/recovery\/request'/.test(dataLane)
    && /lane:\s*'ops'/.test(dataLane)
    && /missing_from_sequence/.test(dataLane)
    && /prefer_keyframe/.test(dataLane),
  'workspace data lane must send sanitized recovery requests over the server ops lane',
)
assert(
  /call\/gossip-recovery/.test(runtimeConfig)
    && /handleGossipRecoveryOpsMessage/.test(dataLane)
    && /cachedFramesForRequest/.test(dataLane)
    && /publishGossipRecoveryFrame/.test(dataLane),
  'clients must handle server-routed recovery ops and serve cached frames over bounded Gossip links',
)
assert(
  /requestKeyframe/.test(dataLane)
    && /keyframe_requests/.test(controller)
    && /missing_frame_requests/.test(controller)
    && /retransmits_served/.test(controller),
  'Gossip telemetry must expose per-publisher keyframe, missing-frame, and served-retransmit counters',
)
assert(
  /GOSSIP_RECOVERY_FRAME_CACHE_LIMIT = 64/.test(recoveryState)
    && /GOSSIP_RECOVERY_RETRANSMIT_LIMIT = 8/.test(recoveryState)
    && /GOSSIP_RECOVERY_REQUEST_COOLDOWN_MS = 1000/.test(recoveryState),
  'recovery cache must be bounded and request coalescing must prevent recovery storms',
)
assert(
  /videochat_gossipmesh_decode_recovery_request/.test(backendRecovery)
    && /videochat_gossipmesh_recovery_forbidden_fields/.test(backendRecovery)
    && /data_base64/.test(backendRecovery)
    && /protected_frame/.test(backendRecovery),
  'backend recovery decoder must reject media-bearing recovery ops payloads',
)
assert(
  /videochat_realtime_handle_gossipmesh_recovery_request_command/.test(websocketCommands)
    && /VIDEOCHAT_GOSSIPMESH_CALL_RECOVERY_TYPE/.test(websocketRecovery)
    && /call\/media-quality-pressure/.test(websocketRecovery)
    && /videochat_presence_room_key\(\$roomId, \$tenantId\)/.test(websocketRecovery),
  'backend must route recovery as ops control to publisher connections and trigger publisher keyframe generation',
)
assert(
  !/videochat_presence_send_frame\([\s\S]{0,700}(data_base64|protected_frame|encoded_frame)/.test(websocketRecovery),
  'server recovery handler must not become a normal media fanout path',
)
assert(
  /websocket gossip recovery should emit recovery ops, keyframe request, and ack/.test(runtimeContract)
    && /websocket recovery must not distribute unsafe token/.test(runtimeContract),
  'backend runtime contract must prove recovery routing and no media fanout',
)
assert(
  /- \[x\] GSP-07 Gossip-native recovery/.test(sprint)
    && /gossip-native-recovery-contract\.mjs/.test(sprint)
    && /no media fanout/.test(sprint),
  'SPRINT must record completed GSP-07 proof after this implementation is verified',
)
assert(
  packageJson.includes('gossip-native-recovery-contract.mjs'),
  'gossip contract suite must include the native recovery contract',
)

console.log('[gossip-native-recovery-contract] PASS')
