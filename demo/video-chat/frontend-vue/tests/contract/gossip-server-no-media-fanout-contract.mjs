import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(new URL('../..', import.meta.url).pathname)
const repoRoot = path.resolve(root, '../../..')
const websocketCommands = fs.readFileSync(path.join(root, '../backend-king-php/http/module_realtime_websocket_commands.php'), 'utf8')
const mediaFanoutGuard = fs.readFileSync(path.join(root, '../backend-king-php/http/module_realtime_media_fanout_guard.php'), 'utf8')
const sfuGateway = fs.readFileSync(path.join(root, '../backend-king-php/domain/realtime/realtime_sfu_gateway.php'), 'utf8')
const sfuSubscriberBudget = fs.readFileSync(path.join(root, '../backend-king-php/domain/realtime/realtime_sfu_subscriber_budget.php'), 'utf8')
const recoveryHandler = fs.readFileSync(path.join(root, '../backend-king-php/http/module_realtime_gossipmesh_recovery.php'), 'utf8')
const runtimeContract = fs.readFileSync(path.join(root, '../backend-king-php/tests/realtime-gossipmesh-runtime-contract.php'), 'utf8')
const sprint = fs.readFileSync(path.join(repoRoot, 'SPRINT.md'), 'utf8')
const packageJson = fs.readFileSync(path.join(root, 'package.json'), 'utf8')

function assert(condition, message) {
  if (!condition) {
    throw new Error(`[gossip-server-no-media-fanout-contract] ${message}`)
  }
}

const guardIndex = websocketCommands.indexOf('videochat_realtime_guard_no_normal_media_fanout')
const chatDecodeIndex = websocketCommands.indexOf('videochat_chat_decode_client_frame')
assert(
  guardIndex >= 0 && chatDecodeIndex > guardIndex,
  'normal realtime websocket must run the media fanout guard before chat/signaling command dispatch',
)
assert(
  mediaFanoutGuard.includes('VIDEOCHAT_REALTIME_MEDIA_FANOUT_GUARD_CODE')
    && mediaFanoutGuard.includes("'sfu/frame'")
    && mediaFanoutGuard.includes("'sfu/frame-chunk'")
    && mediaFanoutGuard.includes("'gossip/media-frame'")
    && mediaFanoutGuard.includes("'protected_frame'")
    && mediaFanoutGuard.includes("'data_base64'"),
  'media fanout guard must reject SFU/Gossip media commands and media-bearing payload fields',
)
assert(
  /videochat_presence_send_frame\(\s*\$websocket/.test(mediaFanoutGuard)
    && !/foreach\s*\(\s*\$presenceState\['rooms'\]/.test(mediaFanoutGuard)
    && !/roomConnections/.test(mediaFanoutGuard),
  'media fanout guard must only answer the offending websocket and must not broadcast to room sockets',
)
assert(
  sfuSubscriberBudget.includes("'sfu_send_path' => 'direct_fanout'")
    && sfuGateway.includes("'sfu_send_path' => 'live_relay_publish'")
    && sfuGateway.includes("'sfu_send_path' => 'sqlite_frame_buffer_insert'")
    && sfuGateway.includes('videochat_sfu_direct_fanout_frame('),
  'SFU fallback/relay/recording paths must stay explicit and separate from normal Realtime websocket dispatch',
)
assert(
  recoveryHandler.includes('VIDEOCHAT_GOSSIPMESH_CALL_RECOVERY_TYPE')
    && recoveryHandler.includes('call/media-quality-pressure')
    && !/protected_frame|data_base64|encoded_frame/.test(recoveryHandler),
  'Gossip recovery server path must remain control-plane only and must not carry media frames',
)
assert(
  runtimeContract.includes('normal realtime websocket must classify sfu/frame as forbidden media fanout')
    && runtimeContract.includes('normal media fanout guard must only answer the offending websocket')
    && runtimeContract.includes('control-plane recovery ops must not be blocked'),
  'backend runtime contract must prove the no-normal-media-fanout guard behavior',
)
assert(
  /- \[x\] GSP-08 Server no-normal-media-fanout guard/.test(sprint)
    && /normal_media_fanout_forbidden/.test(sprint)
    && /gossip-server-no-media-fanout-contract\.mjs/.test(sprint),
  'SPRINT must record completed GSP-08 proof after this guard is verified',
)
assert(
  packageJson.includes('gossip-server-no-media-fanout-contract.mjs'),
  'gossip contract suite must include the server no-normal-media-fanout contract',
)

console.log('[gossip-server-no-media-fanout-contract] PASS')
