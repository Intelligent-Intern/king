import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

function fail(message) {
  throw new Error(`[gossip-backpressure-contract] FAIL: ${message}`)
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`)
}

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')

function read(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8')
}

async function main() {
  const publisherBackpressureController = read('src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.ts')
  const rtcTransport = read('src/lib/gossipmesh/rtcDataChannelTransport.ts')

  requireContains(rtcTransport, 'maxBufferedBytes?: number', 'RTC Gossip transport must accept a DataChannel bufferedAmount budget')
  requireContains(rtcTransport, 'GOSSIP_DATACHANNEL_LOW_WATER_RATIO = 0.5', 'RTC Gossip transport must resume/flush at 50 percent low water')
  requireContains(rtcTransport, 'GOSSIP_DATACHANNEL_DROP_QUEUE_RATIO = 0.25', 'RTC Gossip transport must surface 25 percent queue pressure')
  requireContains(rtcTransport, "GOSSIP_DATACHANNEL_STUCK_NOT_SENDING_REASON = 'gossip_datachannel_stuck_not_sending'", 'RTC Gossip transport must name stuck-not-sending pressure')
  requireContains(rtcTransport, 'channel.bufferedAmountLowThreshold', 'RTC Gossip transport must wire browser low-water callbacks')
  requireContains(rtcTransport, 'this.shouldQueueForBufferedAmount(entry, serialized)', 'RTC Gossip transport must queue instead of blindly sending into a full DataChannel')
  requireContains(rtcTransport, "this.enqueue(targetPeerId, serialized, 'gossip_datachannel_buffered_amount_pressure')", 'RTC Gossip bufferedAmount pressure must enter the bounded queue')
  requireContains(rtcTransport, "this.emitTelemetry('late_drops', 0, peerId, GOSSIP_DATACHANNEL_STUCK_NOT_SENDING_REASON, entry)", 'RTC Gossip flush stalls must emit stuck-not-sending telemetry without reconnect')
  requireContains(rtcTransport, "this.emitTelemetry('dropped', 1, peerId, pressureReason, entry)", 'RTC Gossip queue overflow must count drops')
  requireContains(rtcTransport, "this.emitTelemetry('late_drops', 1, peerId, pressureReason, entry)", 'RTC Gossip queue overflow must count late drops')
  assert.equal(rtcTransport.includes('SOCKET_RESTART'), false, 'RTC Gossip transport must not make socket restart decisions')

  requireContains(publisherBackpressureController, "kind === 'gossip_backpressure'", 'publisher controller must recognize planned Gossip backpressure')
  requireContains(publisherBackpressureController, "reason === 'gossip_datachannel_stuck_not_sending'", 'publisher controller must recognize Gossip stuck-not-sending pressure')
  requireContains(publisherBackpressureController, 'queue_depth: queueDepth', 'publisher decisions must expose Gossip queue depth')
  requireContains(publisherBackpressureController, 'max_queue_depth: maxQueueDepth', 'publisher decisions must expose Gossip max queue depth')
  requireContains(publisherBackpressureController, 'dropped_count: droppedCount', 'publisher decisions must expose Gossip drop count')

  const gossipDecisionBranch = publisherBackpressureController.match(/if \(gossipBackpressure\) \{[\s\S]*?\n  \} else if \(kind === 'pre_encode_buffer'\)/)?.[0] || ''
  assert.ok(gossipDecisionBranch, 'publisher controller must keep a dedicated Gossip backpressure branch')
  requireContains(gossipDecisionBranch, 'gossipPressureRatio >= 0.25', '25 percent Gossip pressure must start cadence/drop response')
  requireContains(gossipDecisionBranch, 'PUBLISHER_BACKPRESSURE_ACTIONS.CADENCE_THROTTLE', '25 percent Gossip pressure must cadence-throttle')
  requireContains(gossipDecisionBranch, 'PUBLISHER_BACKPRESSURE_ACTIONS.DROP_FRAME', '25 percent Gossip pressure must drop late frames')
  requireContains(gossipDecisionBranch, 'gossipPressureRatio >= 0.5', '50 percent Gossip pressure must make profile pressure visible')
  requireContains(gossipDecisionBranch, 'PUBLISHER_BACKPRESSURE_ACTIONS.PROFILE_DOWNSHIFT', '50 percent Gossip pressure must allow profile downshift')
  requireContains(gossipDecisionBranch, 'gossipPressureRatio >= 1', 'full Gossip pressure must pause encode')
  requireContains(gossipDecisionBranch, "reason === 'gossip_datachannel_stuck_not_sending'", 'stuck-not-sending must be handled as planned Gossip backpressure')
  requireContains(gossipDecisionBranch, 'PUBLISHER_BACKPRESSURE_ACTIONS.PAUSE_ENCODE', 'full Gossip pressure must pause encoding')
  requireContains(gossipDecisionBranch, 'PUBLISHER_BACKPRESSURE_ACTIONS.REQUEST_KEYFRAME', 'full Gossip pressure must request a clean frame')
  assert.equal(
    gossipDecisionBranch.includes('SOCKET_RESTART'),
    false,
    'planned Gossip backpressure branch must not request SOCKET_RESTART',
  )

  process.stdout.write('[gossip-backpressure-contract] PASS\n')
}

main().catch((error) => {
  if (error instanceof Error) {
    fail(error.message)
  }
  fail('unknown failure')
})
