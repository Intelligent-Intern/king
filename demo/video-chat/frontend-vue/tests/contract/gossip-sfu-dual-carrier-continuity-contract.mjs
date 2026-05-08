import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');

function read(relativePath) {
  return fs.readFileSync(path.join(frontendRoot, relativePath), 'utf8');
}

const remoteJitterBuffer = read('src/domain/realtime/sfu/remoteJitterBuffer.ts');
const frameDecode = read('src/domain/realtime/sfu/frameDecode.ts');
const mediaSecurityRuntime = read('src/domain/realtime/workspace/callWorkspace/mediaSecurityRuntime.ts');
const mediaSecurityErrors = read('src/domain/realtime/workspace/callWorkspace/mediaSecurityErrors.ts');

assert.match(
  remoteJitterBuffer,
  /function normalizeRemoteFrameContinuityCarrier\(frame\)[\s\S]*transportPath === 'gossip_rtc_datachannel' \? 'gossip' : ''/,
  'remote receiver jitter keys must scope Gossip continuity without renaming the existing SFU sequence domain',
);

assert.match(
  remoteJitterBuffer,
  /const baseKey = videoLayer !== '' \? `\$\{trackId\}:\$\{videoLayer\}` : trackId;[\s\S]*return carrier !== '' \? `\$\{baseKey\}:\$\{carrier\}` : baseKey;/,
  'remote jitter track key must suffix Gossip frames so they cannot be made stale by SFU mirror sequence numbers',
);

assert.match(
  frameDecode,
  /function shouldDropRemoteSfuFrameForContinuity\(publisherId, peer, frame\)[\s\S]*const trackKey = remoteJitterTrackKey\(frame\);/,
  'remote continuity state must use the carrier-scoped jitter key',
);

assert.match(
  frameDecode,
  /function renderDecodedSfuFrame\(peer, decoded, frame = null\)[\s\S]*const trackKey = sfuFrameTrackStateKey\(frame\);/,
  'render caches must remain shared by media track and not split canvases by transport carrier',
);

assert.match(
  mediaSecurityRuntime,
  /function shouldTreatNativeFrameErrorAsDuplicateDrop\(direction, error, senderUserId = 0\)[\s\S]*message === 'replay_detected'/,
  'native protected audio replay must be treated as duplicate carrier delivery, not a hard media-security failure',
);

assert.match(
  mediaSecurityRuntime,
  /const shouldRecoverReceiver = shouldRecoverMediaSecurityFromFrameError\(error\) \|\| transientFrameDrop;/,
  'native replay duplicate drops must not trigger media-security resync loops',
);

assert.match(
  mediaSecurityErrors,
  /message\.includes\('replay_detected'\)/,
  'media-security error normalization must expose replay_detected explicitly',
);

console.log('[gossip-sfu-dual-carrier-continuity-contract] PASS');
