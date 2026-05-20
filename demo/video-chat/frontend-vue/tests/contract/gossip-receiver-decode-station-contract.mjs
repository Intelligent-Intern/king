import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');

function read(relativePath) {
  return fs.readFileSync(path.join(frontendRoot, relativePath), 'utf8');
}

function functionBlock(source, signature) {
  const start = source.indexOf(signature);
  assert.notEqual(start, -1, `${signature} must exist`);
  const nextFunction = source.indexOf('\n  function ', start + signature.length);
  const nextAsyncFunction = source.indexOf('\n  async function ', start + signature.length);
  const nextIndexes = [nextFunction, nextAsyncFunction].filter((value) => value > start);
  const end = nextIndexes.length > 0 ? Math.min(...nextIndexes) : source.length;
  return source.slice(start, end);
}

function assertBefore(source, before, after, message) {
  const beforeIndex = source.indexOf(before);
  const afterIndex = source.indexOf(after);
  assert.notEqual(beforeIndex, -1, `${message}: missing ${before}`);
  assert.notEqual(afterIndex, -1, `${message}: missing ${after}`);
  assert.ok(beforeIndex < afterIndex, message);
}

const packageJson = JSON.parse(read('package.json'));
const frameDecode = read('src/domain/realtime/sfu/frameDecode.ts');
const gossipDataLane = read('src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts');
const gossipMediaFrameEnvelope = read('src/domain/realtime/workspace/callWorkspace/gossipMediaFrameEnvelope.ts');

assert.ok(
  String(packageJson.scripts?.['test:contract:gossip'] || '').includes('gossip-receiver-decode-station-contract.mjs'),
  'active Gossip contract gate must include the receiver decode station contract',
);

const objectFieldBody = functionBlock(frameDecode, 'function objectField(source, camelCaseName, snakeCaseName = camelCaseName)');
assert.ok(
  objectFieldBody.includes('source?.[camelCaseName] || source?.[snakeCaseName]'),
  'Gossip metadata aliases must be read from camelCase and snake_case object fields',
);

const gossipDeliveredFrameBody = functionBlock(frameDecode, 'function isGossipDeliveredFrame(frame)');
for (const transportPath of [
  'gossip_primary_direct',
  'gossip_direct',
  'gossip_rtc_datachannel',
  'gossip_server_fanout',
]) {
  assert.ok(gossipDeliveredFrameBody.includes(transportPath), `Gossip decode bypass must recognize ${transportPath}`);
}
assert.match(
  gossipDeliveredFrameBody,
  /frame\?\.transportPath[\s\S]*frame\?\.transport_path[\s\S]*frame\?\.gossipRuntimePath[\s\S]*frame\?\.gossip_runtime_path/,
  'Gossip decode bypass must read both direct transport and Gossip runtime aliases',
);
assert.match(
  gossipDeliveredFrameBody,
  /const metadata = frame\?\.metadata[\s\S]*const codecRuntime = frame\?\.codecRuntime[\s\S]*frame\?\.codec_runtime/,
  'Gossip decode bypass must inspect nested metadata and codec runtime aliases',
);
assert.match(
  gossipDeliveredFrameBody,
  /objectField\(metadata, 'transportPath', 'transport_path'\)[\s\S]*objectField\(metadata, 'runtimePath', 'runtime_path'\)[\s\S]*objectField\(codecRuntime, 'runtimePath', 'runtime_path'\)/,
  'Gossip decode bypass must accept transport metadata values before SFU gates run',
);

assert.match(
  gossipMediaFrameEnvelope,
  /function sfuFrameFromGossipMessage\(msg,\s*delivery\)[\s\S]*transportPath:\s*runtimePath/,
  'Gossip media frames must preserve runtime path as the renderer transport path',
);
assert.match(
  gossipDataLane,
  /function routeGossipMediaFrameToRenderer\(frame,\s*directGossipPrimary\)[\s\S]*transportPath:\s*'gossip_primary_direct'/,
  'direct Gossip primary frames must reach the decode station with a Gossip transport path',
);
assert.match(
  gossipDataLane,
  /handleSFUEncodedFrame\(\{[\s\S]*transportPath:\s*'gossip_server_fanout'/,
  'server-fanout Gossip frames must reach the decode station with a Gossip transport path',
);

const cacheEpochBody = functionBlock(frameDecode, 'function shouldDropRemoteSfuFrameForCacheEpoch(peer, publisherId, frame)');
assertBefore(
  cacheEpochBody,
  'if (isGossipDeliveredFrame(frame)) return false;',
  'ensureRemoteSfuTrackCacheState(peer);',
  'Gossip-delivered frames must bypass SFU cache epoch state before cache lookup',
);

const continuityBody = functionBlock(frameDecode, 'function shouldDropRemoteSfuFrameForContinuity(publisherId, peer, frame)');
assertBefore(
  continuityBody,
  'if (isGossipDeliveredFrame(frame)) return false;',
  'const trackKey = remoteJitterTrackKey(frame);',
  'Gossip-delivered frames must bypass SFU continuity before carrier/jitter keys',
);

const decodeBody = functionBlock(frameDecode, 'async function decodeSfuFrameForPeer(publisherId, peer, frame, options = {})');
assertBefore(
  decodeBody,
  'const gossipDeliveredFrame = isGossipDeliveredFrame(frame);',
  'maybeBufferRemoteFrameForJitter(publisherId, peer, frame)',
  'Decode station must classify Gossip delivery before the jitter-buffer gate',
);
assert.match(
  decodeBody,
  /if \(!gossipDeliveredFrame && !options\.fromJitterBuffer && maybeBufferRemoteFrameForJitter\(publisherId, peer, frame\)\)/,
  'Gossip-delivered frames must bypass receiver jitter buffering',
);
assert.equal(
  (decodeBody.match(/if \(!gossipDeliveredFrame\) \{\s*drainRemoteJitterBuffer\(publisherId, peer, frame\);\s*\}/g) || []).length,
  2,
  'successful Gossip decode paths must not drain SFU jitter buffers',
);

assert.ok(
  frameDecode.includes('suppressRemoteFrameDropDiagnostics = false'),
  'receiver drop diagnostic suppression default must be preserved',
);
assert.ok(
  functionBlock(frameDecode, 'function logDroppedRemoteSfuFrame(peer, publisherId, frame, reason, extraPayload = {}, immediate = false)').includes('if (suppressRemoteFrameDropDiagnostics) return;'),
  'remote frame drop diagnostics must remain suppressible',
);
assert.match(
  decodeBody,
  /if \(peer\.needsKeyframe && frameMetadata\.type !== 'keyframe'\) \{[\s\S]*if \(suppressRemoteFrameDropDiagnostics\) return;/,
  'delta-before-keyframe diagnostics must remain suppressible',
);

assert.ok(
  frameDecode.includes('const resolvedPublisherId = normalizeSfuPublisherId(peerLookup?.publisherId || publisherId);'),
  'publisher-id alias resolution must be preserved',
);
assert.ok(
  frameDecode.includes('frame = { ...frame, publisherId: resolvedPublisherId, publisherIdAlias: publisherId };'),
  'alias frames must continue decoding under the canonical publisher key',
);
assert.ok(
  frameDecode.includes('void decodeSfuFrameForPeer(resolvedPublisherId, peer, frame);'),
  'decode must continue using the resolved publisher id',
);

process.stdout.write('[gossip-receiver-decode-station-contract] PASS\n');
