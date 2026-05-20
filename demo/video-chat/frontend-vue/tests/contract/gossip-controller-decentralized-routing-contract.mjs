import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')
const controllerPath = path.join(frontendRoot, 'src/lib/gossipmesh/gossipController.ts')
const routingPath = path.join(frontendRoot, 'src/lib/gossipmesh/routing.ts')
const rtcTransportPath = path.join(frontendRoot, 'src/lib/gossipmesh/rtcDataChannelTransport.ts')
const source = fs.readFileSync(controllerPath, 'utf8')
const routing = fs.readFileSync(routingPath, 'utf8')
const rtcTransport = fs.readFileSync(rtcTransportPath, 'utf8')

function assert(condition, message) {
  if (!condition) {
    throw new Error(`[gossip-controller-decentralized-routing-contract] ${message}`)
  }
}

function methodBody(methodName) {
  const signature = new RegExp(`\\n\\s*${methodName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\(`)
  const match = signature.exec(source)
  const start = match ? match.index : -1
  assert(start >= 0, `${methodName} method is missing`)
  const bodyStart = source.indexOf('{', start)
  assert(bodyStart >= 0, `${methodName} body is missing`)

  let depth = 0
  for (let index = bodyStart; index < source.length; index += 1) {
    const char = source[index]
    if (char === '{') depth += 1
    if (char === '}') {
      depth -= 1
      if (depth === 0) {
        return source.slice(bodyStart + 1, index)
      }
    }
  }
  throw new Error(`[gossip-controller-decentralized-routing-contract] ${methodName} body is unterminated`)
}

const publishFrame = methodBody('publishFrame')
const forward = methodBody('private forward')
const handleData = methodBody('handleData')

assert(
  /export const MIN_EXPANDER_FANOUT = 3/.test(routing) && /export const DEFAULT_FANOUT = 4/.test(routing),
  'production gossip routing must use min degree 3 and default degree 4',
)
assert(
  /export const MAX_FANOUT = 5/.test(routing),
  'production gossip routing must retain hard fanout cap 5',
)
assert(
  /Math\.min\(MAX_FANOUT,\s*Math\.max\(MIN_EXPANDER_FANOUT,\s*Math\.floor\(Number\(fanout\) \|\| DEFAULT_FANOUT\)\)\)/.test(routing),
  'neighbor selection must clamp fanout to the expander minimum and hard cap',
)
assert(
  /import \{ DEFAULT_FANOUT, computeTtl, selectNeighbors as selectDeterministicNeighbors \} from '\.\/routing'/.test(source),
  'controller must use the shared production fanout default from routing.ts',
)

assert(
  !/for\s*\(\s*const\s+\[[^\]]+\]\s+of\s+this\.peers\.entries\(\)\s*\)/.test(publishFrame),
  'publishFrame must not iterate every peer; it should only seed the publisher neighbor set',
)
assert(
  /this\.forward\(fromPeerId,\s*outbound,\s*frameId\)/.test(publishFrame),
  'publishFrame must seed neighbor forwarding through forward()',
)
assert(
  /setDataTransport\(transport:\s*GossipDataTransport\)/.test(source),
  'controller must expose an injectable data transport for RTCDataChannel wiring',
)
assert(
  /const forwardedMsg = this\.withRelayForwardMetadata\(msg,\s*fromPeerId,\s*forwardedAtMs,\s*ttl\)[\s\S]*this\.dataTransport\.sendData\(neighborId,\s*\{\s*\.\.\.forwardedMsg,\s*ttl,\s*last_hop_sent_at_ms:\s*forwardedAtMs\s*\},\s*fromPeerId\)/.test(forward),
  'forward() must send only to selected neighbors through the data transport',
)
assert(
  /previousHopPeerId/.test(forward) && /peer\.neighbor_set\.filter\(\(n\)\s*=>\s*n\s*!==\s*previousHopPeerId\)/.test(forward),
  'forward() must avoid immediately bouncing a frame back to the previous hop',
)
assert(
  /this\.forward\(receivingPeerId,\s*msg,\s*frameId,\s*fromPeerId\)/.test(handleData),
  'receiving peers must forward from their own neighbor set after duplicate suppression',
)
assert(
  /selectDeterministicNeighbors\(allPeers,\s*this\.callId,\s*this\.roomId,\s*peerId,\s*this\.fanout\)/.test(source),
  'topology refresh must use deterministic server-style neighbor assignment',
)
assert(
  /class GossipRtcDataChannelTransport implements GossipDataTransport/.test(rtcTransport),
  'gossip mesh must provide a concrete RTCDataChannel data-lane transport',
)
assert(
  /pc\.createDataChannel\(this\.label/.test(rtcTransport) && /pc\.addEventListener\('datachannel'/.test(rtcTransport),
  'RTC transport must support both initiator-created and remotely-created data channels',
)
assert(
  /ordered:\s*true/.test(rtcTransport)
    && /maxRetransmits:\s*0/.test(rtcTransport)
    && !/ordered:\s*false/.test(rtcTransport),
  'data-lane RTC channel must be ordered for video-gossip while preserving bounded late-drop behavior',
)
assert(
  /channel\.binaryType = 'arraybuffer'/.test(rtcTransport)
    && /private async handleIncomingMessage\(peerId: string, data: unknown\): Promise<void>/.test(rtcTransport)
    && /data instanceof ArrayBuffer/.test(rtcTransport)
    && /typeof Blob !== 'undefined' && data instanceof Blob/.test(rtcTransport)
    && /await data\.arrayBuffer\(\)/.test(rtcTransport),
  'data-lane RTC receive path must request ArrayBuffer delivery and decode ArrayBuffer plus Blob payloads',
)
assert(
  /private isCurrentChannel\(peerId: string, channel: RTCDataChannel\): boolean[\s\S]*this\.channels\.get\(peerId\)\?\.channel === channel/.test(rtcTransport)
    && /channel\.addEventListener\('open', \(\) => \{[\s\S]*if \(!this\.isCurrentChannel\(peerId, channel\)\) return[\s\S]*this\.flush\(peerId\)/.test(rtcTransport)
    && /channel\.addEventListener\('close', \(\) => \{[\s\S]*if \(!this\.isCurrentChannel\(peerId, channel\)\) return[\s\S]*'close'/.test(rtcTransport)
    && /channel\.addEventListener\('error', \(\) => \{[\s\S]*if \(!this\.isCurrentChannel\(peerId, channel\)\) return[\s\S]*'error'/.test(rtcTransport)
    && /channel\.addEventListener\('message', \(event\) => \{[\s\S]*if \(!this\.isCurrentChannel\(peerId, channel\)\) return[\s\S]*void this\.handleIncomingMessage\(peerId, event\.data\)/.test(rtcTransport)
    && /channel\.addEventListener\('bufferedamountlow', \(\) => \{[\s\S]*if \(!this\.isCurrentChannel\(peerId, channel\)\) return[\s\S]*this\.flush\(peerId\)/.test(rtcTransport),
  'data-lane RTC event handlers must ignore stale channel events after replacement or close',
)
assert(
  /await data\.arrayBuffer\(\)[\s\S]*if \(!this\.isCurrentChannel\(peerId, channel\)\) return[\s\S]*this\.onDataMessage\(this\.codec\.decode\(bytes\), peerId\)/.test(rtcTransport)
    && /gossip_datachannel_blob_arraybuffer_failed/.test(rtcTransport)
    && /gossip_datachannel_unsupported_payload/.test(rtcTransport)
    && /gossip_datachannel_decode_failed/.test(rtcTransport),
  'async Blob receive must re-check channel freshness and surface receive failures as telemetry',
)
assert(
  /channel\.bufferedAmountLowThreshold/.test(rtcTransport)
    && /this\.shouldQueueForBufferedAmount\(entry, serialized\)/.test(rtcTransport)
    && /gossip_datachannel_buffered_amount_pressure/.test(rtcTransport),
  'ordered data-channel patch must retain queue and bufferedAmount backpressure protections',
)

console.log('[gossip-controller-decentralized-routing-contract] PASS')
