import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const root = path.resolve(__dirname, '../..');

function read(relativePath) {
  return fs.readFileSync(path.join(root, relativePath), 'utf8');
}

const mediaOrchestration = read('src/domain/realtime/local/mediaOrchestration.ts');
const localStreamLifecycle = read('src/domain/realtime/local/localStreamLifecycle.ts');
const screenSharePublisher = read('src/domain/realtime/local/screenSharePublisher.js');

assert.match(
  localStreamLifecycle,
  /export function stopRetiredLocalStreams\(retiredStreams, preservedStreams = \[\], options = \{\}\)/,
  'retired stream cleanup must accept explicit cleanup options',
);
assert.match(
  localStreamLifecycle,
  /protectedTrackIds[\s\S]*local_media_cleanup_preserved_active_track[\s\S]*track\.stop\(\)/,
  'retired stream cleanup must preserve protected active tracks before stopping stale tracks',
);
assert.match(
  mediaOrchestration,
  /function activeLocalMediaStreamsForCleanup\(\)[\s\S]*refs\.localStreamRef\.value[\s\S]*refs\.localRawStreamRef\.value[\s\S]*refs\.localFilteredStreamRef\.value[\s\S]*screenShareStream/s,
  'stale local capture cleanup must preserve current camera, microphone, and screen-share streams',
);
assert.match(
  mediaOrchestration,
  /function discardStaleLocalMediaCapture[\s\S]*stopRetiredLocalStreams\(streams, activeLocalMediaStreamsForCleanup\(\), \{[\s\S]*reason: 'stale_local_media_capture_discarded'[\s\S]*stale_local_media_capture_discarded/s,
  'stale local capture cleanup must use active-stream preservation and emit a reconnect-safe diagnostic',
);
assert.match(
  mediaOrchestration,
  /message: 'Stale local media capture was discarded without stopping active camera, microphone, or screen-share tracks\.'/,
  'stale capture diagnostic must distinguish reconnect cleanup from active media shutdown',
);
assert.match(
  screenSharePublisher,
  /local_screen_share_sfu_reconnect_exhausted[\s\S]*cleanup_scope: 'screen_share_capture_only'/,
  'screen-share reconnect exhaustion must report screen-share-only cleanup scope',
);
assert.match(
  screenSharePublisher,
  /const stoppedAfterReconnectAttempts = reconnectAttempts;[\s\S]*cleanup_scope: 'screen_share_capture_only'[\s\S]*reconnect_attempts: stoppedAfterReconnectAttempts/s,
  'screen-share stop diagnostics must preserve reconnect attempt context before cleanup resets',
);

process.stdout.write('[media-reconnect-screenshare-stability-contract] PASS\n');
