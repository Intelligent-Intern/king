import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

const contractName = 'local-camera-capture-start-contract';
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

function fail(message) {
  throw new Error(`[${contractName}] FAIL: ${message}`);
}

function read(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

class FakeTrack {
  constructor(kind, id) {
    this.kind = kind;
    this.id = id;
    this.label = `${kind} track`;
    this.readyState = 'live';
    this.enabled = true;
    this.appliedConstraints = [];
  }

  addEventListener() {}
  removeEventListener() {}
  stop() {
    this.readyState = 'ended';
  }
  getCapabilities() {
    return this.kind === 'video'
      ? {
          width: { min: 320, max: 1920 },
          height: { min: 180, max: 1080 },
          frameRate: { min: 5, max: 60 },
        }
      : {};
  }
  getSettings() {
    return this.kind === 'video'
      ? { width: 1280, height: 720, frameRate: 30 }
      : { echoCancellation: true, noiseSuppression: true, autoGainControl: true, channelCount: 1 };
  }
  async applyConstraints(constraints) {
    this.appliedConstraints.push(constraints);
  }
}

class FakeMediaStream {
  constructor(tracks = []) {
    this.id = `stream-${FakeMediaStream.nextId += 1}`;
    this.tracks = tracks;
  }

  getTracks() {
    return [...this.tracks];
  }
  getVideoTracks() {
    return this.tracks.filter((track) => track.kind === 'video');
  }
  getAudioTracks() {
    return this.tracks.filter((track) => track.kind === 'audio');
  }
}
FakeMediaStream.nextId = 0;

class FakeVideoElement {
  constructor() {
    this.dataset = {};
    this.parentElement = null;
    this.muted = false;
    this.playsInline = false;
    this.autoplay = false;
    this.readyState = 2;
    this.currentTime = 0;
    this.playCalls = 0;
    this.srcObject = null;
  }

  async play() {
    this.playCalls += 1;
    this.currentTime += 0.05;
  }
}

function installBrowserFakes(getUserMediaCalls) {
  const previous = {
    document: globalThis.document,
    HTMLVideoElement: globalThis.HTMLVideoElement,
    MediaStream: globalThis.MediaStream,
    navigator: globalThis.navigator,
    window: globalThis.window,
  };
  const localVideoContainer = {
    children: [],
    replaceChildren(node) {
      this.children = [node];
      node.parentElement = this;
    },
  };

  Object.defineProperty(globalThis, 'MediaStream', { configurable: true, value: FakeMediaStream });
  Object.defineProperty(globalThis, 'HTMLVideoElement', { configurable: true, value: FakeVideoElement });
  Object.defineProperty(globalThis, 'window', { configurable: true, value: {} });
  Object.defineProperty(globalThis, 'document', {
    configurable: true,
    value: {
      createElement(tagName) {
        if (tagName === 'video') return new FakeVideoElement();
        if (tagName === 'canvas') {
          return {
            width: 0,
            height: 0,
            getContext() {
              return {
                drawImage() {},
                getImageData() {
                  return { data: new Uint8ClampedArray(96 * 72 * 4) };
                },
              };
            },
          };
        }
        return {};
      },
      getElementById(id) {
        return id === 'local-video-container' ? localVideoContainer : null;
      },
      querySelectorAll() {
        return [];
      },
    },
  });
  Object.defineProperty(globalThis, 'navigator', {
    configurable: true,
    value: {
      mediaDevices: {
        async getUserMedia(constraints = {}) {
          getUserMediaCalls.push(constraints);
          const tracks = [];
          if (constraints.video !== false) tracks.push(new FakeTrack('video', 'real-camera-track'));
          if (constraints.audio !== false) tracks.push(new FakeTrack('audio', 'real-mic-track'));
          return new FakeMediaStream(tracks);
        },
      },
    },
  });
  delete globalThis.__kingNativeAudioMediaResources;

  return () => {
    for (const [key, value] of Object.entries(previous)) {
      if (value === undefined) {
        delete globalThis[key];
      } else {
        Object.defineProperty(globalThis, key, { configurable: true, value });
      }
    }
  };
}

let server = null;
let restoreBrowserFakes = null;

try {
  const mediaStackSource = read('src/domain/realtime/workspace/callWorkspace/mediaStack.ts');
  const mediaOrchestrationSource = read('src/domain/realtime/local/mediaOrchestration.ts');
  const localPreviewSource = read('src/domain/realtime/local/localPreviewElement.ts');
  const localCaptureWatchdogSource = read('src/domain/realtime/local/localCaptureWatchdog.ts');

  assert.match(mediaStackSource, /createLocalMediaOrchestrationHelpers\([\s\S]*canPublishLocalMediaToSfu: canPublishLocalMediaForActivePlan/);
  assert.match(mediaStackSource, /captureOnly: true/);
  assert.match(mediaOrchestrationSource, /attachLocalPreviewTrack/);
  assert.match(mediaOrchestrationSource, /canPublishLocalMedia\('local_media_publish'\)/);
  assert.match(mediaOrchestrationSource, /canPublishLocalMedia\('local_media_reconfigure'\)/);
  assert.match(localPreviewSource, /await video\.play\(\)/);
  assert.match(localCaptureWatchdogSource, /eventType: 'local_capture_stall_restart'/);
  assert.doesNotMatch(mediaOrchestrationSource, /location\.reload|window\.location\.reload/);

  server = await createServer({
    configFile: path.resolve(frontendRoot, 'vite.config.js'),
    root: frontendRoot,
    logLevel: 'error',
    server: { middlewareMode: true, hmr: false, watch: null, ws: false },
    appType: 'custom',
  });

  const { createLocalMediaOrchestrationHelpers } = await server.ssrLoadModule('/src/domain/realtime/local/mediaOrchestration.ts');
  const getUserMediaCalls = [];
  restoreBrowserFakes = installBrowserFakes(getUserMediaCalls);
  let canPublish = false;
  const sfuPublishCalls = [];
  const startEncodingCalls = [];
  const refs = {
    activeRoomId: { value: 'room-alpha' },
    activeSocketCallId: { value: 'call-alpha' },
    currentUserId: { value: 101 },
    desiredRoomId: { value: 'room-alpha' },
    encodeIntervalRef: { value: null },
    isSocketOnline: { value: true },
    localFilteredStreamRef: { value: null },
    localRawStreamRef: { value: null },
    localStreamRef: { value: null },
    localTracksRef: { value: [] },
    localVideoElement: { value: null },
    mediaRuntimePathRef: { value: 'wlvc_wasm' },
    nativePeerConnectionsRef: { value: new Map() },
    normalizedCallLayout: { value: { strategy: 'auto' } },
    sfuClientRef: {
      value: {
        publishTracks(tracks) {
          sfuPublishCalls.push(tracks);
        },
      },
    },
  };
  const helpers = createLocalMediaOrchestrationHelpers({
    backgroundBaselineCollector: { reset() {}, sampleCount: () => 0, push: () => null },
    backgroundFilterController: { dispose() {}, apply: async (stream) => ({ active: false, stream }) },
    callbacks: {
      canPublishLocalMediaToSfu: () => canPublish,
      captureClientDiagnostic: () => {},
      captureClientDiagnosticError: () => {},
      currentSfuVideoProfile: () => ({
        id: 'strict_720p30',
        captureWidth: 1280,
        captureHeight: 720,
        captureFrameRate: 30,
        frameWidth: 1280,
        frameHeight: 720,
      }),
      isSfuClientOpen: () => true,
      isWlvcRuntimePath: () => true,
      mediaDebugLog: () => {},
      normalizeRoomId: (value) => String(value || '').trim(),
      refreshCallMediaDevices: async () => {},
      resetCallBackgroundRuntimeState: () => {},
      sendSocketFrame: () => true,
      shouldMaintainNativePeerConnections: () => false,
      shouldSyncNativeLocalTracksBeforeOffer: () => false,
      syncNativePeerConnectionsWithRoster: () => {},
      syncNativePeerLocalTracks: async () => {},
      localPublisher: {
        renderCallVideoLayout: () => {},
        startEncodingPipeline: async (track) => {
          startEncodingCalls.push(track);
          return true;
        },
      },
    },
    callMediaPrefs: {
      backgroundFallbackVideoMode: 'none',
      backgroundFilterMode: 'off',
      selectedCameraId: '',
      selectedMicrophoneId: '',
    },
    captureClientDiagnosticError: () => {},
    constants: {
      activityMotionSampleMs: 250,
      activityPublishIntervalMs: 1000,
      sfuRuntimeEnabled: true,
      strictStabilityPolicy: '',
    },
    controlState: {
      cameraEnabled: true,
      handRaised: false,
      micEnabled: true,
      screenEnabled: false,
    },
    refs,
    state: {
      activityMonitorTimer: null,
      activityLastMotionSampleMs: 0,
      activityLastMotionScore: 0,
      activityLastPublishMs: 0,
      backgroundRuntimeToken: 0,
      localMediaCaptureGeneration: 0,
      localTrackRecoveryAttempts: 0,
      localTrackReconfigureInFlight: false,
      localTrackReconfigureQueuedMode: null,
      localTracksPublishedToSfu: false,
    },
  });

  assert.equal(await helpers.publishLocalTracks({ captureOnly: true, reason: 'join_initialization' }), true);
  assert.equal(getUserMediaCalls.length, 1, 'join initialization must reach getUserMedia once');
  assert.notEqual(getUserMediaCalls[0].video, false, 'camera-enabled join must request video capture');
  assert.equal(getUserMediaCalls[0].video.width.exact ?? getUserMediaCalls[0].video.width.max, 1280);
  assert.equal(getUserMediaCalls[0].video.height.exact ?? getUserMediaCalls[0].video.height.max, 720);
  assert.equal(refs.localVideoElement.value instanceof FakeVideoElement, true, 'capture-only join must attach local preview');
  assert.equal(refs.localVideoElement.value.playCalls, 1, 'capture-only local preview must attempt autoplay');
  assert.equal(refs.localStreamRef.value.getVideoTracks()[0].readyState, 'live');
  assert.equal(sfuPublishCalls.length, 0, 'plan-pending capture must not publish tracks to SFU');
  assert.equal(startEncodingCalls.length, 0, 'plan-pending capture must not start a second publisher pipeline');

  canPublish = true;
  assert.equal(await helpers.publishLocalTracks(), true);
  assert.equal(getUserMediaCalls.length, 1, 'plan release must reuse the live join capture');
  assert.equal(sfuPublishCalls.length, 1, 'plan release publishes the existing local tracks');
  assert.equal(startEncodingCalls.length, 1, 'plan release starts one publisher pipeline');

  helpers.stopActivityMonitor();
  process.stdout.write(`[${contractName}] PASS\n`);
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
} finally {
  if (server) {
    await server.close();
  }
  restoreBrowserFakes?.();
}
