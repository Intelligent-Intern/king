import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

const contractName = 'vst-41-camera-proof-contract';
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

function read(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

function createDeferred() {
  let resolve;
  let reject;
  const promise = new Promise((promiseResolve, promiseReject) => {
    resolve = promiseResolve;
    reject = promiseReject;
  });
  return { promise, resolve, reject };
}

async function waitFor(predicate, label, timeoutMs = 500) {
  const startedAt = Date.now();
  while (Date.now() - startedAt < timeoutMs) {
    if (predicate()) return;
    await new Promise((resolve) => setTimeout(resolve, 5));
  }
  throw new Error(`[${contractName}] timed out waiting for ${label}`);
}

class FakeMediaTrack {
  constructor(kind, id) {
    this.kind = kind;
    this.id = id;
    this.label = id;
    this.enabled = true;
    this.readyState = 'live';
    this.appliedConstraints = [];
    this.listeners = new Map();
  }

  addEventListener(type, listener) {
    if (!this.listeners.has(type)) this.listeners.set(type, new Set());
    this.listeners.get(type).add(listener);
  }

  removeEventListener(type, listener) {
    this.listeners.get(type)?.delete(listener);
  }

  emit(type) {
    for (const listener of this.listeners.get(type) || []) {
      listener({ type, target: this });
    }
  }

  stop() {
    this.readyState = 'ended';
    this.emit('ended');
  }

  async applyConstraints(constraints) {
    this.appliedConstraints.push(constraints);
  }

  getCapabilities() {
    return {
      width: { min: 160, max: 1920 },
      height: { min: 90, max: 1080 },
      frameRate: { min: 1, max: 60 },
    };
  }

  getSettings() {
    if (this.kind === 'video') {
      return { width: 1280, height: 720, frameRate: 30 };
    }
    return {
      echoCancellation: true,
      noiseSuppression: true,
      autoGainControl: true,
      channelCount: 1,
    };
  }
}

class FakeMediaStream {
  constructor(tracks = [], label = '') {
    this.tracks = [...tracks];
    this.label = label;
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

function installMediaPolyfills() {
  Object.defineProperty(globalThis, 'MediaStream', {
    configurable: true,
    value: FakeMediaStream,
  });
  Object.defineProperty(globalThis, 'HTMLVideoElement', {
    configurable: true,
    value: class HTMLVideoElement {},
  });
  Object.defineProperty(globalThis, 'HTMLMediaElement', {
    configurable: true,
    value: class HTMLMediaElement {},
  });
}

function streamFromConstraints(label, constraints) {
  const tracks = [];
  if (constraints?.video !== false) {
    tracks.push(new FakeMediaTrack('video', `${label}-video`));
  }
  if (constraints?.audio !== false) {
    tracks.push(new FakeMediaTrack('audio', `${label}-audio`));
  }
  return new FakeMediaStream(tracks, label);
}

function baseState() {
  return {
    activityAudioAnalyser: null,
    activityAudioContext: null,
    activityAudioData: null,
    activityLastMotionSampleMs: 0,
    activityLastMotionScore: 0,
    activityLastPublishMs: 0,
    activityMonitorTimer: null,
    activityMotionCanvas: null,
    activityMotionContext: null,
    activityPreviousFrame: null,
    backgroundBaselineCaptured: false,
    backgroundRuntimeToken: 0,
    localMediaCaptureGeneration: 0,
    localPublisherTeardownInProgress: false,
    localTrackRecoveryAttempts: 0,
    localTrackRecoveryTimer: null,
    localTrackReconfigureInFlight: false,
    localTrackReconfigureQueuedMode: null,
    localTracksPublishedToSfu: false,
    wlvcEncodeFailureCount: 0,
    wlvcEncodeFirstFailureAtMs: 0,
    wlvcEncodeInFlight: false,
    wlvcEncodeLastErrorLogAtMs: 0,
    wlvcEncodeWarmupUntilMs: 0,
  };
}

function createMediaHarness(createLocalMediaOrchestrationHelpers, stopRetiredLocalStreams, getUserMedia, options = {}) {
  const diagnostics = [];
  const errors = [];
  const publishCalls = [];
  const unpublishCalls = [];
  const encodingStarts = [];
  const refs = {
    activeRoomId: { value: 'room-alpha' },
    activeSocketCallId: { value: 'call-alpha' },
    currentUserId: { value: 42 },
    desiredRoomId: { value: 'room-alpha' },
    encodeIntervalRef: { value: null },
    isSocketOnline: { value: false },
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
        publishTracks: (tracks) => publishCalls.push(tracks),
        unpublishTrack: (trackId) => unpublishCalls.push(trackId),
      },
    },
    videoEncoderRef: { value: null },
    videoPatchEncoderHeight: { value: 0 },
    videoPatchEncoderQuality: { value: 0 },
    videoPatchEncoderRef: { value: null },
    videoPatchEncoderWidth: { value: 0 },
  };
  const state = baseState();
  const controlState = {
    cameraEnabled: options.cameraEnabled !== false,
    handRaised: false,
    micEnabled: options.micEnabled !== false,
    screenEnabled: false,
  };
  const callMediaPrefs = {
    backgroundApplyOutgoing: false,
    backgroundFallbackVideoMode: 'none',
    backgroundFilterMode: 'off',
    microphoneVolume: 100,
    selectedCameraId: '',
    selectedMicrophoneId: '',
    selectedSpeakerId: '',
    speakerVolume: 100,
  };
  let activeEncodingTrackId = '';
  let encodingStops = 0;

  Object.defineProperty(globalThis, 'navigator', {
    configurable: true,
    value: { mediaDevices: { getUserMedia } },
  });

  const helpers = createLocalMediaOrchestrationHelpers({
    backgroundBaselineCollector: {
      push: () => null,
      reset: () => {},
      sampleCount: () => 0,
    },
    backgroundFilterController: {
      apply: async (stream) => ({ active: false, backend: 'none', reason: 'off', stream }),
      dispose: () => {},
    },
    callbacks: {
      captureClientDiagnostic: (diagnostic) => diagnostics.push(diagnostic),
      captureClientDiagnosticError: (code, error, payload) => errors.push({ code, error, payload }),
      clearTransientActivityPublishErrorNotice: () => {},
      currentSfuVideoProfile: () => ({
        id: 'balanced',
        captureWidth: 1280,
        captureHeight: 720,
        captureFrameRate: 30,
        encodeIntervalMs: 33,
        frameHeight: 180,
        frameWidth: 320,
        keyFrameInterval: 30,
        maxWireBytesPerSecond: 250000,
        readbackFrameRate: 30,
        readbackIntervalMs: 33,
      }),
      evaluateBackgroundFilterGates: () => ({
        detectPass: true,
        fpsPass: true,
        loadPass: true,
        pass: true,
      }),
      isSfuClientOpen: () => true,
      isWlvcRuntimePath: () => true,
      canPublishLocalMediaToSfu: () => true,
      localPublisher: {
        bindLocalTrackLifecycle: () => {},
        clearLocalPreviewElement: () => {},
        renderCallVideoLayout: () => {},
        startEncodingPipeline: async (track) => {
          activeEncodingTrackId = track.id;
          encodingStarts.push(track.id);
          return true;
        },
        stopLocalEncodingPipeline: () => {
          activeEncodingTrackId = '';
          encodingStops += 1;
        },
        stopRetiredLocalStreams,
        unpublishSfuTracks: (tracks) => {
          for (const track of tracks || []) {
            if (track?.id) unpublishCalls.push(track.id);
          }
        },
      },
      markParticipantActivity: () => {},
      mediaDebugLog: () => {},
      normalizeRoomId: (roomId) => String(roomId || '').trim(),
      refreshCallMediaDevices: async () => {},
      resetCallBackgroundRuntimeState: () => {},
      sendNativeOffer: async () => {},
      sendSocketFrame: () => true,
      shouldMaintainNativePeerConnections: () => false,
      shouldSyncNativeLocalTracksBeforeOffer: () => false,
      syncControlStateToPeers: () => 0,
      syncNativePeerConnectionsWithRoster: () => {},
      syncNativePeerLocalTracks: async () => {},
    },
    callMediaPrefs,
    captureClientDiagnosticError: (code, error, payload) => errors.push({ code, error, payload }),
    constants: {
      activityMotionSampleMs: 250,
      activityPublishIntervalMs: 500,
      sfuRuntimeEnabled: true,
      strictStabilityPolicy: 'adaptive',
    },
    controlState,
    refs,
    state,
  });

  return {
    callMediaPrefs,
    controlState,
    diagnostics,
    encodingStarts,
    errors,
    get activeEncodingTrackId() {
      return activeEncodingTrackId;
    },
    get encodingStops() {
      return encodingStops;
    },
    helpers,
    publishCalls,
    refs,
    state,
    unpublishCalls,
  };
}

function assertSourceContracts() {
  const lifecycle = read('src/domain/realtime/sfu/lifecycle.ts');
  const mediaOrchestration = read('src/domain/realtime/local/mediaOrchestration.ts');
  const participantUi = read('src/domain/realtime/workspace/callWorkspace/participantUi.ts');
  const publisherPipeline = read('src/domain/realtime/local/publisherPipeline.ts');
  const watchdog = read('src/domain/realtime/local/localCaptureWatchdog.ts');

  assert.match(
    lifecycle,
    /onConnected:\s*\(\)\s*=>\s*\{[\s\S]*scheduleLocalTrackPublish\(\);[\s\S]*\}/,
    'SFU join must schedule local track publication when the media socket connects',
  );
  assert.match(
    mediaOrchestration,
    /async function publishLocalTracks[\s\S]*navigator\.mediaDevices\.getUserMedia[\s\S]*refs\.localTracksRef\.value = stream\.getTracks\(\)/,
    'publishLocalTracks must own real browser capture before publishing local tracks',
  );
  assert.match(
    mediaOrchestration,
    /function queueLocalTrackReconfigure[\s\S]*localTrackReconfigureQueuedMode[\s\S]*async function reconfigureLocalTracksFromSelectedDevices/,
    'camera device changes must have a sequential reconfigure queue',
  );
  assert.match(
    participantUi,
    /function toggleCamera\(\)\s*\{[\s\S]*controlState\.cameraEnabled = !controlState\.cameraEnabled;[\s\S]*void reconfigureLocalTracksFromSelectedDevices\(\);/,
    'camera toggle must route through local track reconfiguration',
  );
  assert.match(
    publisherPipeline,
    /track\.addEventListener\('ended', \(\) => \{[\s\S]*scheduleLocalTrackRecovery\(`track_\$\{String\(track\?\.kind \|\| 'media'\)\.toLowerCase\(\)\}_ended`\);[\s\S]*\}\);/,
    'local track ended lifecycle must schedule local-track recovery',
  );

  const localRecoveryRegion = publisherPipeline.match(/function scheduleLocalTrackRecovery[\s\S]*?function bindLocalTrackLifecycle/)?.[0] || '';
  assert.doesNotMatch(
    localRecoveryRegion,
    /requestSfuConnect|location\.reload|window\.location|hangupCall|reload\(/,
    'local track recovery must not force a call reconnect or page reload',
  );
  assert.doesNotMatch(
    watchdog,
    /requestSfuConnect|location\.reload|window\.location|hangupCall|reload\(/,
    'local capture freeze watchdog must restart local tracks only',
  );
}

async function assertJoinPublishesCameraCapture(createSfuLifecycleHelpers, createLocalMediaOrchestrationHelpers, stopRetiredLocalStreams) {
  const lifecyclePublishCalls = [];
  let connected = false;
  class FakeSfuClient {
    constructor(handlers) {
      this.handlers = handlers;
    }

    connect() {
      connected = true;
      this.handlers.onConnected();
    }
  }

  const lifecycle = createSfuLifecycleHelpers({
    callbacks: {
      captureClientDiagnostic: () => {},
      captureClientDiagnosticError: () => {},
      clearMediaSecuritySfuPublisherSeen: () => false,
      createOrUpdateSfuRemotePeer: () => {},
      currentUserId: () => 42,
      deleteSfuRemotePeer: () => {},
      handleSFUEncodedFrame: () => {},
      handleSfuPublisherPressure: () => {},
      isWlvcRuntimePath: () => true,
      maybeFallbackToNativeRuntime: () => {
        throw new Error('join capture must not fall back while scheduling local publication');
      },
      mediaDebugLog: () => {},
      normalizeSfuPublisherId: (value) => String(value || ''),
      noteMediaSecuritySfuPublisherSeen: () => false,
      publishLocalTracks: async () => {
        lifecyclePublishCalls.push('publishLocalTracks');
        return true;
      },
      publishLocalTracksToSfuIfReady: () => false,
      renderCallVideoLayout: () => {},
      requestSfuConnect: () => {
        throw new Error('initial SFU join must not reconnect while scheduling local publication');
      },
      requestWlvcFullFrameKeyframe: () => {},
      resetWlvcBackpressureCounters: () => {},
      scheduleMediaSecurityParticipantSync: () => {},
      setSfuRemotePeer: () => {},
      sfuTrackListHasVideo: () => false,
      sfuTrackRows: () => [],
      stopLocalEncodingPipeline: () => {},
    },
    constants: {
      mediaSecuritySfuTargetSettleMs: 1,
      sfuConnectMaxRetries: 0,
      sfuConnectRetryDelayMs: 1,
      sfuPublishMaxRetries: 0,
      sfuPublishRetryDelayMs: 1,
      sfuTrackAnnounceIntervalMs: 1000,
      strictStabilityPolicy: 'adaptive',
    },
    refs: {
      SFUClient: FakeSfuClient,
      activeRoomId: { value: 'room-alpha' },
      activeSocketCallId: { value: 'call-alpha' },
      connectionState: { value: 'online' },
      isManualSocketClose: () => false,
      localStreamRef: { value: null },
      mediaRuntimePath: { value: 'wlvc_wasm' },
      remotePeersRef: { value: new Map() },
      sessionState: {
        displayName: 'Ada',
        sessionToken: 'token-alpha',
        userId: 42,
      },
      sfuClientRef: { value: null },
      sfuConnected: { value: false },
      shouldConnectSfu: { value: true },
    },
    state: {
      localTracksPublishedToSfu: false,
      sfuConnectRetryCount: 0,
      sfuTrackAnnounceTimer: null,
    },
  });

  lifecycle.initSFU();
  assert.equal(connected, true, 'SFU join path connects the media socket');
  assert.deepEqual(lifecyclePublishCalls, ['publishLocalTracks'], 'SFU onConnected schedules publishLocalTracks exactly once');
  lifecycle.stopSfuTrackAnnounceTimer();

  const gumCalls = [];
  const harness = createMediaHarness(
    createLocalMediaOrchestrationHelpers,
    stopRetiredLocalStreams,
    async (constraints) => {
      gumCalls.push(constraints);
      return streamFromConstraints(`join-${gumCalls.length - 1}`, constraints);
    },
    { cameraEnabled: true },
  );

  assert.equal(await harness.helpers.publishLocalTracks(), true, 'publishLocalTracks succeeds with camera enabled');
  assert.equal(gumCalls.length, 1, 'camera-enabled join capture calls getUserMedia');
  assert.notEqual(gumCalls[0].video, false, 'camera-enabled join capture requests a video track');
  assert.notEqual(gumCalls[0].audio, false, 'camera-enabled join capture keeps microphone capture available');
  assert.equal(harness.refs.localStreamRef.value.getVideoTracks().length, 1, 'join stream exposes a local camera video track');
  assert.deepEqual(
    harness.publishCalls.at(-1).map((track) => track.kind),
    ['video', 'audio'],
    'join publishes captured local video and audio metadata to the SFU client',
  );
}

async function assertSequentialCameraToggleQueue(createLocalMediaOrchestrationHelpers, stopRetiredLocalStreams) {
  const secondCaptureGate = createDeferred();
  const gumCalls = [];
  const capturedStreams = [];
  const harness = createMediaHarness(
    createLocalMediaOrchestrationHelpers,
    stopRetiredLocalStreams,
    (constraints) => {
      const index = gumCalls.length;
      gumCalls.push(constraints);
      const stream = streamFromConstraints(`toggle-${index}`, constraints);
      capturedStreams.push(stream);
      if (index === 1) {
        return secondCaptureGate.promise.then(() => stream);
      }
      return Promise.resolve(stream);
    },
    { cameraEnabled: true },
  );

  assert.equal(await harness.helpers.publishLocalTracks(), true, 'initial camera publication succeeds');
  const initialStream = harness.refs.localStreamRef.value;
  assert.equal(initialStream.label, 'toggle-0');
  assert.equal(harness.activeEncodingTrackId, 'toggle-0-video');

  harness.controlState.cameraEnabled = false;
  const cameraOffReconfigure = harness.helpers.reconfigureLocalTracksFromSelectedDevices();
  await waitFor(
    () => harness.state.localTrackReconfigureInFlight === true && gumCalls.length === 2,
    'first camera-off reconfigure capture',
  );

  harness.controlState.cameraEnabled = true;
  const queuedResult = await harness.helpers.reconfigureLocalTracksFromSelectedDevices();
  assert.equal(queuedResult, false, 'camera-on toggle queues behind the in-flight camera-off reconfigure');
  assert.equal(harness.state.localTrackReconfigureQueuedMode, 'devices', 'camera-on toggle uses the device reconfigure queue');

  secondCaptureGate.resolve();
  assert.equal(await cameraOffReconfigure, true, 'camera-off reconfigure completes before the queued camera-on pass');
  await waitFor(
    () => gumCalls.length === 3
      && harness.state.localTrackReconfigureInFlight === false
      && harness.refs.localStreamRef.value?.label === 'toggle-2',
    'queued camera-on reconfigure completion',
  );

  assert.equal(gumCalls[1].video, false, 'camera-off reconfigure does not request stale video capture');
  assert.notEqual(gumCalls[2].video, false, 'queued camera-on reconfigure reacquires camera video');
  assert.equal(harness.state.localTrackReconfigureQueuedMode, null, 'reconfigure queue is drained after the queued camera-on pass');
  assert.deepEqual(
    harness.encodingStarts,
    ['toggle-0-video', 'toggle-2-video'],
    'encoding pipeline is restarted only for live camera video tracks',
  );
  assert.ok(harness.encodingStops >= 1, 'camera-off pass stops the previous encoding pipeline');
  assert.equal(harness.activeEncodingTrackId, 'toggle-2-video', 'final encoding pipeline points at the latest camera track');

  for (const stream of capturedStreams.slice(0, 2)) {
    for (const track of stream.getTracks()) {
      assert.equal(track.readyState, 'ended', `retired ${stream.label} track ${track.id} must be stopped`);
    }
  }
  for (const track of harness.refs.localStreamRef.value.getTracks()) {
    assert.equal(track.readyState, 'live', `final track ${track.id} remains live`);
  }
  assert.equal(harness.refs.localRawStreamRef.value.label, 'toggle-2', 'raw stream ref points at the final capture');
  assert.equal(harness.refs.localFilteredStreamRef.value.label, 'toggle-2', 'filtered stream ref points at the final capture');
  assert.deepEqual(
    harness.refs.localTracksRef.value.map((track) => track.id),
    ['toggle-2-video', 'toggle-2-audio'],
    'local track ref contains only the final camera-on tracks',
  );
  assert.ok(
    harness.unpublishCalls.includes('toggle-0-video') && harness.unpublishCalls.includes('toggle-1-audio'),
    'retired SFU tracks are unpublished across camera off/on turnover',
  );
}

async function assertLocalEndedAndFreezeRecovery(createLocalPublisherPipelineHelpers, createLocalCaptureWatchdog) {
  const recoveredReasons = [];
  const diagnostics = [];
  let reloads = 0;
  Object.defineProperty(globalThis, 'location', {
    configurable: true,
    value: { reload: () => { reloads += 1; } },
  });
  const stream = new FakeMediaStream([
    new FakeMediaTrack('video', 'ended-video'),
    new FakeMediaTrack('audio', 'ended-audio'),
  ], 'ended-stream');
  const state = {
    backgroundBaselineCaptured: false,
    backgroundRuntimeToken: 0,
    localPublisherTeardownInProgress: false,
    localTrackRecoveryAttempts: 0,
    localTrackRecoveryTimer: null,
    wlvcEncodeFailureCount: 0,
    wlvcEncodeFirstFailureAtMs: 0,
    wlvcEncodeInFlight: false,
    wlvcEncodeLastErrorLogAtMs: 0,
    wlvcEncodeWarmupUntilMs: 0,
  };
  const refs = {
    encodeIntervalRef: { value: null },
    isSocketOnline: { value: true },
    connectionState: { value: 'online' },
    localFilteredStreamRef: { value: stream },
    localRawStreamRef: { value: stream },
    localStreamRef: { value: stream },
    localTracksRef: { value: stream.getTracks() },
    localVideoElement: { value: null },
    mediaRuntimePathRef: { value: 'wlvc_wasm' },
    sfuClientRef: { value: null },
    videoEncoderRef: { value: null },
    videoPatchEncoderHeight: { value: 0 },
    videoPatchEncoderQuality: { value: 0 },
    videoPatchEncoderRef: { value: null },
    videoPatchEncoderWidth: { value: 0 },
  };
  const pipeline = createLocalPublisherPipelineHelpers({
    backgroundBaselineCollector: { reset: () => {} },
    backgroundFilterController: { dispose: () => {} },
    callbacks: {
      captureClientDiagnostic: (diagnostic) => diagnostics.push(diagnostic),
      captureClientDiagnosticError: () => {},
      currentSfuVideoProfile: () => ({ id: 'balanced' }),
      isWlvcRuntimePath: () => true,
      maybeFallbackToNativeRuntime: () => {
        throw new Error('local track recovery must not force runtime fallback');
      },
      mediaDebugLog: () => {},
      reconfigureLocalTracksFromSelectedDevices: async () => {
        recoveredReasons.push('local-track-reconfigure');
        return true;
      },
      renderCallVideoLayout: () => {},
      resetBackgroundRuntimeMetrics: () => {},
      resetWlvcBackpressureCounters: () => {},
      resetWlvcFrameSendFailureCounters: () => {},
      stopActivityMonitor: () => {},
      stopSfuTrackAnnounceTimer: () => {},
    },
    captureClientDiagnosticError: () => {},
    constants: {
      localTrackRecoveryBaseDelayMs: 1,
      localTrackRecoveryMaxAttempts: 2,
      localTrackRecoveryMaxDelayMs: 2,
      strictStabilityPolicy: 'adaptive',
    },
    refs,
    state,
  });

  pipeline.bindLocalTrackLifecycle(stream);
  stream.getVideoTracks()[0].emit('ended');
  await waitFor(
    () => recoveredReasons.length === 1
      || diagnostics.some((diagnostic) => diagnostic?.eventType === 'gossip_primary_local_track_recovery_parked'),
    'local track ended recovery decision',
  );
  assert.equal(reloads, 0, 'local track ended recovery does not reload the page');
  if (recoveredReasons.length === 1) {
    assert.equal(state.localTrackRecoveryAttempts, 0, 'successful local track recovery resets attempts');
  } else {
    assert.equal(state.localTrackRecoveryTimer, null, 'parked local track recovery does not leave a retry timer behind');
  }

  const originalSetInterval = globalThis.setInterval;
  const originalClearInterval = globalThis.clearInterval;
  let intervalCallback = null;
  globalThis.setInterval = (callback, delayMs) => {
    assert.equal(delayMs, 4000, 'freeze watchdog keeps its focused local capture cadence');
    intervalCallback = callback;
    return 41;
  };
  globalThis.clearInterval = () => {};

  try {
    const freezeRecoveries = [];
    const watchdogStream = new FakeMediaStream([
      new FakeMediaTrack('video', 'freeze-video'),
    ], 'freeze-stream');
    const watchdog = createLocalCaptureWatchdog({
      captureDiagnostic: (diagnostic) => diagnostics.push(diagnostic),
      controlState: { cameraEnabled: true },
      isLocalScreenShareActive: () => false,
      reconfigureLocalTracks: async () => {
        freezeRecoveries.push('local-freeze-reconfigure');
        return true;
      },
      refs: {
        localStreamRef: { value: watchdogStream },
        localVideoElement: { value: null },
      },
      state: { localTrackReconfigureInFlight: false },
    });

    assert.equal(watchdog.start(watchdogStream, 'frozen_preview'), true, 'freeze watchdog starts for live local camera video');
    intervalCallback();
    intervalCallback();
    await waitFor(() => freezeRecoveries.length === 1, 'local freeze watchdog recovery');
    watchdog.stop();
    assert.equal(reloads, 0, 'local freeze recovery does not reload the page');
    assert.ok(
      diagnostics.some((diagnostic) => diagnostic?.eventType === 'local_capture_stall_restart'),
      'local freeze recovery emits a local-track restart diagnostic',
    );
  } finally {
    globalThis.setInterval = originalSetInterval;
    globalThis.clearInterval = originalClearInterval;
  }
}

installMediaPolyfills();
assertSourceContracts();

const server = await createServer({
  configFile: path.resolve(frontendRoot, 'vite.config.js'),
  logLevel: 'error',
  server: { middlewareMode: true, hmr: false, watch: null, ws: false },
  appType: 'custom',
});

try {
  const { createSfuLifecycleHelpers } = await server.ssrLoadModule('/src/domain/realtime/sfu/lifecycle.ts');
  const { createLocalMediaOrchestrationHelpers } = await server.ssrLoadModule('/src/domain/realtime/local/mediaOrchestration.ts');
  const { stopRetiredLocalStreams } = await server.ssrLoadModule('/src/domain/realtime/local/localStreamLifecycle.ts');
  const { createLocalPublisherPipelineHelpers } = await server.ssrLoadModule('/src/domain/realtime/local/publisherPipeline.ts');
  const { createLocalCaptureWatchdog } = await server.ssrLoadModule('/src/domain/realtime/local/localCaptureWatchdog.ts');

  await assertJoinPublishesCameraCapture(
    createSfuLifecycleHelpers,
    createLocalMediaOrchestrationHelpers,
    stopRetiredLocalStreams,
  );
  await assertSequentialCameraToggleQueue(createLocalMediaOrchestrationHelpers, stopRetiredLocalStreams);
  await assertLocalEndedAndFreezeRecovery(createLocalPublisherPipelineHelpers, createLocalCaptureWatchdog);
} finally {
  await server.close();
}

process.stdout.write(`[${contractName}] PASS\n`);
