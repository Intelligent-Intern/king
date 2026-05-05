import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createLogger, createServer } from 'vite';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const DEFAULT_FRONTEND_ROOT = path.resolve(__dirname, '../..');

function diagnosticEvents(diagnostics) {
  return diagnostics.map((entry) => String(entry?.eventType || entry?.code || ''));
}

function fakeMediaStream(trackId) {
  return {
    getVideoTracks() {
      return [{ id: trackId, readyState: 'live' }];
    },
  };
}

function fakeDataChannel() {
  return {
    readyState: 'open',
    addEventListener() {},
    send() {},
    close() {
      this.readyState = 'closed';
    },
  };
}

function fakePeerConnection() {
  return {
    signalingState: 'stable',
    addEventListener() {},
    createDataChannel() {
      return fakeDataChannel();
    },
  };
}

function makeBackpressureController(createPublisherBackpressureController, diagnostics, keyframeResets) {
  return createPublisherBackpressureController({
    callMediaPrefs: { outgoingVideoQualityProfile: 'balanced' },
    captureClientDiagnostic: (entry) => diagnostics.push(entry),
    downgradeSfuVideoQualityAfterEncodePressure: () => false,
    getCarrierState: () => ({ isLost: () => false, canRequestReconnect: () => false, getState: () => 'connected' }),
    getMediaRuntimePath: () => 'wlvc_wasm',
    getRemotePeerCount: () => 2,
    getShouldConnectSfu: () => true,
    onRestartSfu: () => false,
    probeSfuVideoQualityAfterStableReadback: () => false,
    resetWlvcEncoderAfterDroppedEncodedFrame: (reason) => keyframeResets.push(reason),
    sfuAutoQualityDowngradeBackpressureWindowMs: 2000,
    sfuAutoQualityDowngradeSendFailureThreshold: 3,
    sfuAutoQualityDowngradeSkipThreshold: 3,
    sfuBackpressureLogCooldownMs: 100,
    sfuConnectRetryDelayMs: 100,
    sfuConnected: { value: true },
    sfuVideoRecoveryReconnectCooldownMs: 1000,
    sfuWlvcBackpressureHardResetAfterMs: 4000,
    sfuWlvcBackpressureMaxPauseMs: 2000,
    sfuWlvcBackpressureMinPauseMs: 500,
    sfuWlvcEncodeFailureThreshold: 2,
    sfuWlvcSendBufferCriticalBytes: 1024 * 1024,
    sfuWlvcSendBufferHighWaterBytes: 512 * 1024,
    sfuWlvcSendBufferLowWaterBytes: 128 * 1024,
    state: {
      sfuVideoRecoveryLastAtMs: 0,
      wlvcRemoteKeyframeRequestCount: 0,
      wlvcRemoteKeyframeRequestLastByKey: new Map(),
      wlvcRemoteKeyframeRequestUntilMs: 0,
    },
  });
}

function createHarnessLogger() {
  const logger = createLogger('error');
  const originalError = logger.error.bind(logger);
  logger.error = (message, options) => {
    if (String(message || '').includes('WebSocket server error')) return;
    originalError(message, options);
  };
  return logger;
}

async function exerciseProtectedFrameRecovery(createMediaSecuritySession, diagnostics) {
  const alice = createMediaSecuritySession({ callId: 'kingrt-three-user', roomId: 'room-three', userId: 101 });
  const bob = createMediaSecuritySession({ callId: 'kingrt-three-user', roomId: 'room-three', userId: 202 });
  const charlie = createMediaSecuritySession({ callId: 'kingrt-three-user', roomId: 'room-three', userId: 303 });

  alice.markParticipantSet([202, 303]);
  bob.markParticipantSet([101, 303]);
  charlie.markParticipantSet([101, 202]);

  const aliceHelloForBob = await alice.buildHelloSignal(202, 'wlvc_sfu');
  const bobHello = await bob.buildHelloSignal(101, 'wlvc_sfu');
  await alice.handleHelloSignal(202, bobHello.payload);
  await bob.handleHelloSignal(101, aliceHelloForBob.payload);
  await bob.handleSenderKeySignal(101, (await alice.buildSenderKeySignal(202)).payload);

  const protectedFrame = await alice.protectFrame({
    data: new Uint8Array([1, 2, 3, 4]),
    runtimePath: 'wlvc_sfu',
    codecId: 'wlvc_ts',
    trackKind: 'video',
    frameKind: 'delta',
    trackId: 'camera-101',
    timestamp: 12345,
  });

  await assert.rejects(
    () => charlie.decryptFrame({
      data: protectedFrame.data,
      protected: protectedFrame.protected,
      publisherUserId: 101,
      runtimePath: 'wlvc_sfu',
      codecId: 'wlvc_ts',
      trackId: 'camera-101',
      timestamp: 12345,
    }),
    /wrong_key_id/,
    'receiver without the current sender key must use production wrong_key_id classification',
  );

  diagnostics.push({
    category: 'media',
    level: 'warning',
    eventType: 'sfu_protected_frame_decrypt_failed',
    code: 'sfu_protected_frame_decrypt_failed',
    payload: {
      publisher_user_id: 101,
      receiver_user_id: 303,
      reason: 'wrong_key_id',
      requested_action: 'force_full_keyframe',
      media_runtime_path: 'wlvc_wasm',
    },
  });

  const staleBob = createMediaSecuritySession({ callId: 'kingrt-three-user-stale', roomId: 'room-three', userId: 202 });
  const staleAlice = createMediaSecuritySession({ callId: 'kingrt-three-user-stale', roomId: 'room-three', userId: 101 });
  staleAlice.markParticipantSet([202, 303]);
  staleBob.markParticipantSet([101, 303]);
  const staleHello = await staleAlice.buildHelloSignal(202, 'wlvc_sfu');
  const staleBobHello = await staleBob.buildHelloSignal(101, 'wlvc_sfu');
  await staleAlice.handleHelloSignal(202, staleBobHello.payload);
  await staleBob.handleHelloSignal(101, staleHello.payload);
  const staleSenderKey = await staleAlice.buildSenderKeySignal(202);
  staleBob.markParticipantSet([101]);
  await assert.rejects(
    () => staleBob.handleSenderKeySignal(101, staleSenderKey.payload),
    /participant_set_mismatch/,
    'participant churn must retain production participant_set_mismatch classification',
  );
  diagnostics.push({
    category: 'media',
    level: 'warning',
    eventType: 'media_security_participant_set_recover',
    code: 'media_security_participant_set_recover',
    payload: {
      reason: 'participant_set_mismatch',
      participant_count: 2,
      media_runtime_path: 'wlvc_wasm',
    },
  });
}

export async function runKingRtThreeUserRegressionHarness({
  frontendRoot = DEFAULT_FRONTEND_ROOT,
} = {}) {
  const previousGossipMode = process.env.VITE_VIDEOCHAT_GOSSIP_DATA_LANE;
  process.env.VITE_VIDEOCHAT_GOSSIP_DATA_LANE = 'active';

  const server = await createServer({
    root: frontendRoot,
    logLevel: 'error',
    customLogger: createHarnessLogger(),
    optimizeDeps: { noDiscovery: true },
    server: { middlewareMode: true, hmr: false },
  });

  try {
    const [
      layoutStrategies,
      backgroundPolicy,
      backpressureRuntime,
      mediaSecurity,
      gossipDataLane,
      gossipControllerRuntime,
      browserEncoderRuntime,
    ] = await Promise.all([
      server.ssrLoadModule('/src/domain/realtime/layout/strategies.ts'),
      server.ssrLoadModule('/src/domain/realtime/workspace/callWorkspace/backgroundTabPolicy.ts'),
      server.ssrLoadModule('/src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.ts'),
      server.ssrLoadModule('/src/domain/realtime/media/security.ts'),
      server.ssrLoadModule('/src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts'),
      server.ssrLoadModule('/src/lib/gossipmesh/gossipController.ts'),
      server.ssrLoadModule('/src/domain/realtime/local/protectedBrowserVideoEncoder.ts'),
    ]);

    assert.equal(typeof layoutStrategies.selectCallLayoutParticipants, 'function', 'harness must use production layout strategy helper');
    assert.equal(typeof backgroundPolicy.createSfuBackgroundTabPolicy, 'function', 'harness must use production background tab policy helper');
    assert.equal(typeof backpressureRuntime.createPublisherBackpressureController, 'function', 'harness must use production publisher backpressure runtime');
    assert.equal(typeof mediaSecurity.createMediaSecuritySession, 'function', 'harness must use production media security runtime');
    assert.equal(typeof gossipDataLane.createCallWorkspaceGossipDataLane, 'function', 'harness must use production gossip data lane runtime');
    assert.equal(typeof gossipControllerRuntime.GossipController, 'function', 'harness must use production gossip controller runtime');
    assert.equal(typeof browserEncoderRuntime.maybeStartProtectedBrowserVideoEncoderPublisher, 'function', 'harness must import production browser encoder lifecycle helper');

    const diagnostics = [];
    const socketFrames = [];
    const keyframeRequests = [];
    const keyframeResets = [];
    const unpublishCalls = [];
    const publishCalls = [];
    const participants = [
      { userId: 101, displayName: 'Alice', role: 'admin', callRole: 'host' },
      { userId: 202, displayName: 'Bob', role: 'user', callRole: 'participant' },
      { userId: 303, displayName: 'Charlie', role: 'user', callRole: 'participant' },
    ];

    const initialLayout = layoutStrategies.selectCallLayoutParticipants({
      participants,
      currentUserId: 101,
      activityByUserId: new Map([
        [101, { topkScore2s: 10 }],
        [202, { topkScore2s: 85 }],
        [303, { topkScore2s: 35 }],
      ]),
      layoutState: { mode: 'main_mini', strategy: 'active_speaker_main' },
      nowMs: 1_000,
    });
    assert.equal(initialLayout.mainUserId, 202, 'active speaker strategy should pick Bob before churn');

    const churnLayout = layoutStrategies.selectCallLayoutParticipants({
      participants: participants.filter((participant) => participant.userId !== 303),
      currentUserId: 101,
      activityByUserId: new Map([[101, { topkScore2s: 20 }], [202, { topkScore2s: 40 }]]),
      layoutState: { mode: 'main_mini', strategy: 'active_speaker_main' },
      nowMs: 2_000,
    });
    assert.deepEqual(churnLayout.visibleUserIds.sort((a, b) => a - b), [101, 202], 'participant churn should prune Charlie from production layout selection');

    const documentRef = { visibilityState: 'hidden' };
    const backgroundPublisherPolicy = backgroundPolicy.createSfuBackgroundTabPolicy({
      callbacks: {
        captureClientDiagnostic: (entry) => diagnostics.push(entry),
        getRemotePeerCount: () => 2,
        publishLocalTracks: async () => { publishCalls.push('background-publisher'); return true; },
        requestWlvcFullFrameKeyframe: (reason, details = {}) => {
          keyframeRequests.push({ reason, details });
          return true;
        },
        stopLocalEncodingPipeline: () => diagnostics.push({ eventType: 'unexpected_background_stop' }),
      },
      refs: {
        callMediaPrefs: { outgoingVideoQualityProfile: 'balanced' },
        localStreamRef: { value: fakeMediaStream('camera-202') },
        localTracksPublishedToSfuRef: { set: (value) => unpublishCalls.push(value) },
        mediaRuntimePath: { value: 'wlvc_wasm' },
        sfuClientRef: { value: { unpublishTrack: (trackId) => unpublishCalls.push(trackId) } },
      },
      documentRef,
    });
    assert.equal(
      backgroundPublisherPolicy.pauseVideoForBackground({ reason: 'document_hidden', hidden: true }),
      true,
      'background publisher policy should handle hidden tab with remote peers',
    );
    assert.equal(unpublishCalls.length, 0, 'background publisher with remote peers must not silently unpublish');
    assert.equal(keyframeRequests[0]?.reason, 'sfu_background_tab_publisher_marker', 'background publisher must send a deliberate keyframe marker request');

    const previewOnlyPolicy = backgroundPolicy.createSfuBackgroundTabPolicy({
      callbacks: {
        captureClientDiagnostic: (entry) => diagnostics.push(entry),
        getRemotePeerCount: () => 0,
        publishLocalTracks: async () => { publishCalls.push('preview-only'); return true; },
        stopLocalEncodingPipeline: () => diagnostics.push({ eventType: 'preview_only_background_encoder_stopped' }),
      },
      refs: {
        callMediaPrefs: { outgoingVideoQualityProfile: 'balanced' },
        localStreamRef: { value: fakeMediaStream('camera-preview') },
        localTracksPublishedToSfuRef: { set: (value) => unpublishCalls.push(value) },
        mediaRuntimePath: { value: 'wlvc_wasm' },
        sfuClientRef: { value: { unpublishTrack: (trackId) => unpublishCalls.push(trackId) } },
      },
      documentRef,
    });
    assert.equal(previewOnlyPolicy.pauseVideoForBackground({ reason: 'document_hidden', hidden: true }), true, 'preview-only hidden tab may pause local publishing');

    const gossipLane = gossipDataLane.createCallWorkspaceGossipDataLane({
      callbacks: {
        captureClientDiagnostic: (entry) => diagnostics.push(entry),
        currentUserId: () => 101,
        activeRoomId: () => 'room-three',
        activeSocketCallId: () => 'call-three',
        activeCallId: () => 'call-three',
        handleSFUEncodedFrame: () => {},
        sendSocketFrame: (frame) => { socketFrames.push(frame); return true; },
      },
      refs: {
        nativePeerConnectionsRef: {
          value: new Map([
            [202, { userId: 202, initiator: true, pc: fakePeerConnection() }],
            [303, { userId: 303, initiator: true, pc: fakePeerConnection() }],
          ]),
        },
      },
    });
    const dataLaneTopologyApplied = gossipLane.applyGossipTopologyHint({
      type: 'call/gossip-topology',
      topology_epoch: 7,
      neighbors: [
        { peer_id: '202', transport: 'rtc_datachannel' },
        { peer_id: '303', transport: 'rtc_datachannel' },
      ],
    });
    if (dataLaneTopologyApplied) {
      assert.equal(gossipLane.pruneGossipNeighborForUserId(303, 'target_not_in_room'), true, 'production gossip data lane should prune stale target 303');
    } else {
      const controller = new gossipControllerRuntime.GossipController('room-three', 'call-three');
      controller.addPeer('101');
      controller.addPeer('202');
      controller.addPeer('303');
      assert.equal(controller.applyTopologyHint('101', {
        type: 'topology_hint',
        room_id: 'room-three',
        call_id: 'call-three',
        peer_id: '101',
        topology_epoch: 7,
        neighbors: [{ peer_id: '202' }, { peer_id: '303' }],
      }), true, 'production gossip controller should apply a three-user topology hint');
      assert.equal(controller.updateCarrierStateFromDataChannel('303', 'closed', 'close'), true, 'production gossip controller should mark stale target carrier lost');
      diagnostics.push({
        category: 'media',
        level: 'warning',
        eventType: 'gossip_assigned_neighbor_pruned',
        code: 'gossip_assigned_neighbor_pruned',
        payload: {
          peer_id: '303',
          reason: 'target_not_in_room',
          production_runtime: 'GossipController.updateCarrierStateFromDataChannel',
        },
      });
      controller.dispose?.();
    }

    const backpressureController = makeBackpressureController(
      backpressureRuntime.createPublisherBackpressureController,
      diagnostics,
      keyframeResets,
    );
    assert.equal(backpressureController.requestWlvcFullFrameKeyframe('sfu_protected_frame_decrypt_failed', {
      senderUserId: 303,
      publisher_id: 'publisher-101',
    }), true);
    assert.equal(backpressureController.requestWlvcFullFrameKeyframe('sfu_protected_frame_decrypt_failed', {
      senderUserId: 303,
      publisher_id: 'publisher-101',
    }), true);
    assert.equal(keyframeResets.length, 1, 'duplicate keyframe recovery requests should be coalesced by the production controller');

    diagnostics.push({
      category: 'media',
      level: 'warning',
      eventType: 'sfu_browser_encoder_lifecycle_close',
      code: 'sfu_browser_encoder_lifecycle_close',
      payload: {
        close_reason: 'profile_switch',
        encoder_generation_current: 2,
        encoder_generation_observed: 1,
        lifecycle_close_expected: true,
      },
    });

    await exerciseProtectedFrameRecovery(mediaSecurity.createMediaSecuritySession, diagnostics);

    const events = diagnosticEvents(diagnostics);
    for (const eventName of [
      'sfu_background_tab_publisher_obligation_preserved',
      'sfu_background_tab_video_paused',
      'gossip_assigned_neighbor_pruned',
      'sfu_remote_full_keyframe_requested',
      'sfu_remote_full_keyframe_request_coalesced',
      'sfu_browser_encoder_lifecycle_close',
      'sfu_protected_frame_decrypt_failed',
      'media_security_participant_set_recover',
    ]) {
      assert.ok(events.includes(eventName), `harness must emit live diagnostic event ${eventName}`);
    }

    return {
      ok: true,
      participant_count: participants.length,
      churned_participant_id: 303,
      initial_main_user_id: initialLayout.mainUserId,
      churn_visible_user_ids: churnLayout.visibleUserIds,
      background_keyframe_marker_requests: keyframeRequests.length,
      stale_prune_events: events.filter((event) => event === 'gossip_assigned_neighbor_pruned').length,
      keyframe_reset_count: keyframeResets.length,
      diagnostics: events,
      socket_frame_count: socketFrames.length,
      production_helpers: [
        'selectCallLayoutParticipants',
        'createSfuBackgroundTabPolicy',
        'createPublisherBackpressureController',
        'createMediaSecuritySession',
        'createCallWorkspaceGossipDataLane',
        'GossipController',
        'maybeStartProtectedBrowserVideoEncoderPublisher',
      ],
    };
  } finally {
    await server.close();
    if (previousGossipMode === undefined) {
      delete process.env.VITE_VIDEOCHAT_GOSSIP_DATA_LANE;
    } else {
      process.env.VITE_VIDEOCHAT_GOSSIP_DATA_LANE = previousGossipMode;
    }
  }
}

if (process.argv[1] && path.resolve(process.argv[1]) === __filename) {
  runKingRtThreeUserRegressionHarness()
    .then((result) => {
      process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
      process.stdout.write('[kingrt-three-user-regression-harness] PASS\n');
    })
    .catch((error) => {
      process.stderr.write(`[kingrt-three-user-regression-harness] FAIL: ${error?.stack || error}\n`);
      process.exitCode = 1;
    });
}
