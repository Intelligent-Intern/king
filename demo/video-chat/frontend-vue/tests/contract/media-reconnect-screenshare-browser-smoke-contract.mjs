import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const root = path.resolve(__dirname, '../..');

function read(relativePath) {
  return fs.readFileSync(path.join(root, relativePath), 'utf8');
}

class FakeMediaTrack {
  constructor(id, kind) {
    this.id = id;
    this.kind = kind;
    this.readyState = 'live';
    this.stopCount = 0;
  }

  stop() {
    this.stopCount += 1;
    this.readyState = 'ended';
  }
}

class FakeMediaStream {
  constructor(tracks = []) {
    this.tracks = tracks;
  }

  getTracks() {
    return [...this.tracks];
  }

  getAudioTracks() {
    return this.tracks.filter((track) => track.kind === 'audio');
  }

  getVideoTracks() {
    return this.tracks.filter((track) => track.kind === 'video');
  }
}

globalThis.MediaStream = FakeMediaStream;

const lifecycleSource = `${read('src/domain/realtime/local/localStreamLifecycle.ts')
  .replaceAll('export function ', 'function ')}
globalThis.__localStreamLifecycle = { stopRetiredLocalStreams };`;
const sandbox = {
  globalThis: {},
  MediaStream: FakeMediaStream,
};
vm.runInNewContext(lifecycleSource, sandbox, { filename: 'localStreamLifecycle.ts' });
const { stopRetiredLocalStreams } = sandbox.globalThis.__localStreamLifecycle;

const cameraTrack = new FakeMediaTrack('active-camera', 'video');
const audioTrack = new FakeMediaTrack('active-microphone', 'audio');
const screenTrack = new FakeMediaTrack('active-screen-share', 'video');
const retiredCameraTrack = new FakeMediaTrack('retired-camera', 'video');
const retiredAudioTrack = new FakeMediaTrack('retired-microphone', 'audio');
const retiredScreenTrack = new FakeMediaTrack('retired-screen-share', 'video');

const activeCameraAudioStream = new FakeMediaStream([cameraTrack, audioTrack]);
const activeScreenShareStream = new FakeMediaStream([screenTrack]);
const staleReconnectStream = new FakeMediaStream([
  cameraTrack,
  audioTrack,
  screenTrack,
  retiredCameraTrack,
  retiredAudioTrack,
  retiredScreenTrack,
]);

const diagnostics = [];
stopRetiredLocalStreams(
  [staleReconnectStream],
  [activeCameraAudioStream, activeScreenShareStream],
  {
    reason: 'stale_local_media_capture_discarded',
    mediaRuntimePath: 'wlvc_sfu',
    captureDiagnostic: (entry) => diagnostics.push(entry),
  },
);

for (const track of [cameraTrack, audioTrack, screenTrack]) {
  assert.equal(track.readyState, 'live', `${track.id} must stay live after stale reconnect cleanup`);
  assert.equal(track.stopCount, 0, `${track.id} must not be stopped by stale reconnect cleanup`);
}

for (const track of [retiredCameraTrack, retiredAudioTrack, retiredScreenTrack]) {
  assert.equal(track.readyState, 'ended', `${track.id} must be stopped during stale reconnect cleanup`);
  assert.equal(track.stopCount, 1, `${track.id} must be stopped exactly once`);
}

const mediaOrchestration = read('src/domain/realtime/local/mediaOrchestration.ts');
assert.match(
  mediaOrchestration,
  /eventType: 'stale_local_media_capture_discarded'[\s\S]*active_screen_share_track_id: activeScreenShareTrackId/,
  'stale reconnect cleanup diagnostics must identify the screen-share state separately from cleanup',
);

const screenSharePublisher = read('src/domain/realtime/local/screenSharePublisher.js');
assert.match(
  screenSharePublisher,
  /const stoppedAfterReconnectAttempts = reconnectAttempts;[\s\S]*eventType: 'local_screen_share_participant_stopped'[\s\S]*cleanup_scope: 'screen_share_capture_only'[\s\S]*reconnect_attempts: stoppedAfterReconnectAttempts/s,
  'screen-share stop diagnostics must keep reconnect attempts and screen-share-only cleanup scope',
);
assert.match(
  screenSharePublisher,
  /eventType: 'local_screen_share_sfu_reconnect_exhausted'[\s\S]*cleanup_scope: 'screen_share_capture_only'/s,
  'screen-share reconnect exhaustion diagnostics must not look like camera/audio cleanup',
);

process.stdout.write('[media-reconnect-screenshare-browser-smoke-contract] PASS\n');
