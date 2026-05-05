import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')
const controllerPath = path.join(frontendRoot, 'src/lib/gossipmesh/gossipController.ts')
const rtcTransportPath = path.join(frontendRoot, 'src/lib/gossipmesh/rtcDataChannelTransport.ts')
const source = fs.readFileSync(controllerPath, 'utf8')
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
  /this\.dataTransport\.sendData\(neighborId,\s*\{\s*\.\.\.msg,\s*ttl\s*\},\s*fromPeerId\)/.test(forward),
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
  /ordered:\s*false/.test(rtcTransport) && /maxRetransmits:\s*0/.test(rtcTransport),
  'data-lane RTC channel should be unreliable/unordered for late-droppable media frames',
)

console.log('[gossip-controller-decentralized-routing-contract] PASS')
