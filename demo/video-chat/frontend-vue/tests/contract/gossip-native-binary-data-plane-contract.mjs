import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const repoRoot = path.resolve(__dirname, '../../../../..')
const frontendRoot = path.join(repoRoot, 'demo/video-chat/frontend-vue')

function read(filePath) {
  return fs.readFileSync(filePath, 'utf8')
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(`[gossip-native-binary-data-plane-contract] ${message}`)
  }
}

const codec = read(path.join(frontendRoot, 'src/lib/gossipmesh/iibinCodec.ts'))
const transport = read(path.join(frontendRoot, 'src/lib/gossipmesh/rtcDataChannelTransport.ts'))
const wire = read(path.join(frontendRoot, 'src/lib/gossipmesh/wireContract.ts'))
const planning = read(path.join(repoRoot, 'GOSSIP_PLANNING.md'))
const current = read(path.join(repoRoot, 'GOSSIP_CURRENT_BUILD.md'))
const packageJson = read(path.join(frontendRoot, 'package.json'))

for (const filePath of [
  'packages/iibin/dist/index.js',
  'extension/src/iibin/iibin.c',
  'extension/src/object_store/object_store.c',
  'extension/src/server/websocket.c',
  'extension/src/client/websocket.c',
  'infra/scripts/lsquic-bootstrap.lock',
]) {
  assert(fs.existsSync(path.join(repoRoot, filePath)), `required native King surface missing: ${filePath}`)
}

assert(
  /from '..\/..\/..\/..\/..\/..\/packages\/iibin\/dist\/index\.js'/.test(codec),
  'gossip data codec must use the repo IIBIN package rather than a JSON-only encoder',
)
assert(
  /new IIBINEncoder\(\)\.encode/.test(codec) && /new IIBINDecoder\(data\)\.decode\(\)/.test(codec),
  'gossip codec must encode and decode with IIBIN',
)
assert(
  /king-video-chat-gossipmesh-iibin-media-envelope/.test(codec)
    && /king-object-store-gossipmesh-control-plane/.test(codec),
  'IIBIN envelope must identify the media envelope and King object_store control-plane contract',
)
assert(
  /king_lsquic_http3/.test(codec) && /king_websocket_binary/.test(codec),
  'gossip native transport stack must include LSQUIC/HTTP3 and King binary WebSocket paths',
)
assert(
  /codec\?:\s*GossipDataPlaneCodec/.test(transport)
    && /this\.codec = options\.codec \|\| GOSSIP_IIBIN_CODEC/.test(transport),
  'RTC transport must accept an injectable codec and default to IIBIN',
)
assert(
  /const serialized = this\.codec\.encode\(msg\)/.test(transport)
    && /this\.onDataMessage\(this\.codec\.decode\(event\.data\), peerId\)/.test(transport),
  'RTC transport must send and receive binary codec frames',
)
assert(
  !/JSON\.stringify\(msg\)/.test(transport) && !/JSON\.parse\(event\.data\)/.test(transport),
  'gossip RTC data transport must not regress to JSON text frames',
)
assert(
  /event\.data instanceof ArrayBuffer/.test(transport),
  'gossip RTC data transport must require binary ArrayBuffer frames',
)
assert(
  /GOSSIP_DATA_CODEC_IIBIN/.test(wire)
    && /GOSSIP_DATA_ENVELOPE_CONTRACT/.test(wire)
    && /GOSSIP_CONTROL_OBJECT_STORE_CONTRACT/.test(wire)
    && /GOSSIP_NATIVE_TRANSPORT_PRIORITY/.test(wire),
  'wire contract must expose codec, envelope, object_store, and native transport contracts',
)
assert(
  /king_lsquic_http3/.test(wire) && /king_websocket_binary/.test(wire),
  'topology hints must be able to describe King LSQUIC and binary WebSocket transports',
)
assert(
  /IIBIN/.test(current) && /object_store/.test(current) && /LSQUIC/.test(planning),
  'root gossip docs must record the binary/native transport requirement before outbound publication',
)
assert(
  packageJson.includes('gossip-native-binary-data-plane-contract.mjs'),
  'gossip contract suite must include the native binary data-plane contract',
)

console.log('[gossip-native-binary-data-plane-contract] PASS')
