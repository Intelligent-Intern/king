import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')

const controller = fs.readFileSync(path.join(frontendRoot, 'src/lib/gossipmesh/gossipController.ts'), 'utf8')
const rtcTransport = fs.readFileSync(path.join(frontendRoot, 'src/lib/gossipmesh/rtcDataChannelTransport.ts'), 'utf8')
const workspaceGossip = fs.readFileSync(path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts'), 'utf8')

function assert(condition, message) {
  if (!condition) {
    throw new Error(`[gossip-telemetry-contract] ${message}`)
  }
}

function requireContains(source, needle, message) {
  assert(source.includes(needle), `${message}: missing "${needle}"`)
}

for (const counter of [
  'sent',
  'received',
  'forwarded',
  'dropped',
  'duplicates',
  'ttl_exhausted',
  'late_drops',
  'stale_generation_drops',
  'server_fanout_avoided',
  'peer_outbound_fanout',
  'rtc_datachannel_sends',
  'in_memory_harness_sends',
  'topology_repairs_requested',
]) {
  requireContains(controller, `${counter}:`, `controller stats must expose ${counter}`)
}

for (const eventType of [
  "'drop'",
  "'stale_generation_drop'",
  "'late_drop'",
  "'ttl_exhausted'",
  "'server_fanout_avoided'",
  "'peer_outbound_fanout'",
  "'rtc_datachannel_send'",
  "'in_memory_harness_send'",
]) {
  requireContains(controller, eventType, `controller event log must expose ${eventType}`)
}

requireContains(controller, "kind: 'in_memory_harness'", 'in-memory harness transport must carry a transport kind label')
requireContains(controller, "this.recordTelemetryCounter(fromPeerId, 'in_memory_harness_sends')", 'in-memory harness sends must increment telemetry')
requireContains(controller, 'const avoidedServerFanout = Math.max(0, this.peers.size - 1)', 'local publication must estimate avoided server fanout')
requireContains(controller, "this.recordTelemetryCounter(fromPeerId, 'server_fanout_avoided', avoidedServerFanout)", 'local publication must count server fanout avoidance')
requireContains(controller, "this.recordTelemetryCounter(fromPeerId, 'peer_outbound_fanout', fanoutCount)", 'forwarding must count peer outbound fanout')
requireContains(controller, "this.recordTelemetryCounter(fromPeerId, 'ttl_exhausted')", 'TTL exhaustion must be counted')
requireContains(controller, 'hopLatencyMs(msg, now)', 'receive/drop events must include hop latency when timestamps exist')
requireContains(controller, 'last_hop_sent_at_ms: forwardedAtMs', 'forwarding must stamp hop send time for downstream hop latency')
requireContains(controller, 'recordTransportTelemetry(peerId: string, counter: keyof GossipTelemetryCounters', 'controller must expose read-only transport telemetry intake')
requireContains(controller, 'createTelemetrySnapshot(peerId: string', 'controller must expose sanitized telemetry snapshots for ops-lane emission')
requireContains(controller, "kind: 'gossip_telemetry_snapshot'", 'telemetry snapshots must use a dedicated sanitized snapshot kind')
requireContains(controller, "rolloutStrategy || 'sfu_first_explicit'", 'telemetry snapshots must keep the rollout strategy explicitly SFU-first')
requireContains(controller, 'counters: { ...peer.telemetry }', 'telemetry snapshots must include counters without media payloads')

requireContains(rtcTransport, "readonly kind = 'rtc_datachannel' as const", 'RTC transport must carry a transport kind label')
requireContains(rtcTransport, 'onTelemetry?: (event: GossipTransportTelemetryEvent) => void', 'RTC transport must expose a telemetry callback')
requireContains(rtcTransport, "this.emitTelemetry('rtc_datachannel_sends', 1, targetPeerId)", 'open RTC sends must increment telemetry')
requireContains(rtcTransport, "this.emitTelemetry('late_drops', 1, peerId)", 'RTC queue overflow drops must count as late-droppable media drops')
requireContains(workspaceGossip, 'onTelemetry: (event) =>', 'workspace data lane must wire RTC telemetry back to the controller')
requireContains(workspaceGossip, 'controller.recordTransportTelemetry?.(peerId, counter', 'workspace data lane must record RTC transport telemetry without affecting routing')
requireContains(workspaceGossip, "controller?.recordTransportTelemetry?.(localPeerId(), 'topology_repairs_requested', 1)", 'workspace data lane must count topology repair requests for rollout gates')
requireContains(workspaceGossip, 'function emitGossipTelemetrySnapshot', 'workspace data lane must send sanitized gossip telemetry snapshots')
requireContains(workspaceGossip, "type: 'gossip/telemetry/snapshot'", 'workspace telemetry snapshots must go over the ops-lane websocket command type')
requireContains(workspaceGossip, "lane: 'ops'", 'workspace telemetry snapshots must declare the ops lane')
requireContains(workspaceGossip, "rolloutStrategy: 'sfu_first_explicit'", 'workspace telemetry snapshots must preserve explicit SFU-first rollout')
requireContains(workspaceGossip, 'if (!GOSSIP_DATA_LANE_CONFIG.enabled || !GOSSIP_DATA_LANE_CONFIG.publish || !GOSSIP_DATA_LANE_CONFIG.receive) return false;', 'telemetry snapshots must only emit in explicit active rollout mode')
assert(!/gossip\/telemetry\/snapshot[\s\S]{0,400}(protected_frame|data_base64|sdp|ice_candidate|raw_media_key)/.test(workspaceGossip), 'workspace telemetry snapshot send path must not attach media/signaling/secret fields')

console.log('[gossip-telemetry-contract] PASS')
