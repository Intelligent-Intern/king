import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');

function ref(value) {
  return { value };
}

function createRuntimeState() {
  return {
    mediaSecurityResyncTimer: null,
    mediaSecurityResyncReasons: new Set(),
    mediaSecurityResyncForceRekey: false,
    mediaSecuritySyncInFlight: false,
    mediaSecuritySyncPending: false,
    mediaSecuritySyncPendingForceRekey: false,
    mediaSecurityHelloSignalsSent: new Set(),
    mediaSecuritySenderKeySignalsSent: new Set(),
    mediaSecurityHelloSentAtByUserId: new Map(),
    mediaSecurityHandshakeRetryingByUserId: new Set(),
    mediaSecurityHandshakeRetryCountByUserId: new Map(),
    mediaSecurityHandshakeWatchdogTimer: null,
    mediaSecurityRecoveryLastByUserId: new Map(),
    mediaSecuritySfuPublisherFirstSeenAtByUserId: new Map(),
    mediaSecuritySenderKeySentAtBySignalKey: new Map(),
    mediaSecurityNativeFrameRecoveryLastByKey: new Map(),
    mediaSecuritySyncHintLastAtMs: 0,
    nativeFrameErrorLastLogByKey: new Map(),
    nativeAudioBridgeQuarantineByUserId: new Map(),
  };
}

function createSessionStub() {
  return {
    callId: 'call-gossip-security-parking',
    roomId: 'room-gossip-security-parking',
    userId: 101,
    participantSignature: '202',
    participantSetHash: 'current-participant-set-hash',
    epoch: 4,
    senderKeyId: 'sender-key-current',
    senderKey: {},
    peers: new Map([
      [202, {
        state: 'active',
        wrappingKey: null,
        participantSetHash: 'stale-participant-set-hash',
      }],
    ]),
    mode: 'ok',
    updateContext(context) {
      this.callId = context.callId;
      this.roomId = context.roomId;
      this.userId = context.userId;
    },
    async ensureReady() {
      return true;
    },
    telemetrySnapshot(runtimePath = 'gossip_primary') {
      return {
        runtime_path: runtimePath,
        participant_set_hash: this.participantSetHash,
        participant_signature: this.participantSignature,
        epoch: this.epoch,
        sender_key_id: this.senderKeyId,
      };
    },
    async buildHelloSignal(targetUserId, runtimePath = 'gossip_primary') {
      return {
        type: 'media-security/hello',
        target_user_id: Number(targetUserId || 0),
        payload: { kind: 'media_security_hello', runtime_path: runtimePath },
      };
    },
    async buildSenderKeySignal() {
      if (this.mode === 'participant_set_mismatch') {
        throw new Error('participant_set_mismatch');
      }
      if (this.mode === 'pending_wrap') {
        return null;
      }
      return {
        type: 'media-security/sender-key',
        target_user_id: 202,
        payload: { kind: 'media_security_sender_key' },
      };
    },
    async handleSenderKeySignal() {
      return false;
    },
    async handleHelloSignal() {
      return true;
    },
    markParticipantSet(userIds = []) {
      return { changed: false, userIds };
    },
    async rotateSenderKey() {
      return true;
    },
    canProtectForTargets() {
      return false;
    },
    canProtectNativeForTargets() {
      return false;
    },
  };
}

function createRuntimeHarness(createCallWorkspaceMediaSecurityRuntime) {
  const state = createRuntimeState();
  const session = createSessionStub();
  const diagnostics = [];
  const frames = [];
  const snapshots = [];
  const logs = [];
  const refs = {
    activeCallId: ref('call-gossip-security-parking'),
    activeRoomId: ref('room-gossip-security-parking'),
    activeSocketCallId: ref('call-gossip-security-parking'),
    connectedParticipantUsers: ref([{ userId: 202, hasSnapshotConnection: true }]),
    currentUserId: ref(101),
    hasRealtimeRoomSync: ref(true),
    isNativeWebRtcRuntimePath: () => false,
    isSocketOnline: ref(true),
    isWlvcRuntimePath: () => true,
    mediaRuntimeCapabilities: ref({ stageB: false }),
    mediaRuntimePath: ref('gossip_primary'),
    mediaSecuritySessionRef: ref(session),
    mediaSecurityStateVersion: ref(0),
    nativeAudioBridgeStatusVersion: ref(0),
    nativePeerConnectionsRef: ref(new Map()),
  };
  const runtime = createCallWorkspaceMediaSecurityRuntime({
    callbacks: {
      attachMediaSecurityNativeReceiversForPeer: () => {},
      captureClientDiagnostic: (event) => diagnostics.push(event),
      captureClientDiagnosticError: (event) => diagnostics.push(event),
      createMediaSecuritySession: () => session,
      createMediaSecurityTargetHelpers: () => ({
        clearMediaSecuritySfuPublisherSeen: () => {},
        mediaSecurityEligibleTargetIds: () => [202],
        mediaSecurityTargetIds: () => [202],
        nativeAudioBridgeBlockedReason: () => '',
        nativeAudioBridgePeerStatusMessage: () => '',
        noteMediaSecuritySfuPublisherSeen: () => {},
      }),
      ensureNativePeerConnection: () => null,
      extractDiagnosticMessage: (error) => String(error?.message || error || ''),
      mediaDebugLog: (...args) => logs.push(args),
      nativeAudioSecurityTelemetrySnapshot: () => ({}),
      requestRoomSnapshot: () => snapshots.push({ at: Date.now() }),
      scheduleNativeAudioTrackRecovery: () => false,
      scheduleNativePeerAudioTrackDeadline: () => {},
      sendNativeOffer: () => {},
      sendSocketFrame: (frame) => {
        frames.push(frame);
        return true;
      },
      setNativePeerAudioBridgeState: () => {},
      shouldSyncNativeLocalTracksBeforeOffer: () => false,
      syncNativePeerLocalTracks: async () => {},
      synchronizeNativePeerMediaElements: () => {},
    },
    constants: {
      mediaSecurityHandshakeTimeoutMs: 50,
      mediaSecurityHandshakeRetryTimeoutsMs: [50],
      mediaSecuritySfuTargetSettleMs: 25,
      mediaSecuritySfuSenderKeyPropagationMs: 350,
      nativeFrameErrorLogCooldownMs: 10,
      sfuRuntimeEnabled: true,
      strictStabilityPolicy: { enabled: false },
      MediaSecuritySession: { supportsNativeTransforms: () => false },
    },
    refs,
    state,
  });

  return { diagnostics, frames, logs, runtime, session, snapshots, state };
}

const server = await createServer({
  configFile: path.resolve(frontendRoot, 'vite.config.js'),
  logLevel: 'error',
  server: { middlewareMode: true, hmr: false },
  appType: 'custom',
});

try {
  const {
    isPlannedGossipMediaSecurityTransport,
  } = await server.ssrLoadModule('/src/domain/realtime/workspace/callWorkspace/mediaSecurityTargets.ts');
  const {
    createCallWorkspaceMediaSecurityRuntime,
  } = await server.ssrLoadModule('/src/domain/realtime/workspace/callWorkspace/mediaSecurityRuntime.ts');

  assert.equal(
    isPlannedGossipMediaSecurityTransport({ mediaRuntimePath: 'gossip_primary' }),
    true,
    'gossip_primary runtime path must be recognized as planned Gossip transport',
  );
  assert.equal(
    isPlannedGossipMediaSecurityTransport({ transportPath: 'gossip_rtc_datachannel' }),
    true,
    'RTC data-channel Gossip deliveries must be recognized as planned Gossip transport',
  );
  assert.equal(
    isPlannedGossipMediaSecurityTransport({
      mediaRuntimePath: 'wlvc_wasm',
      gossipPrimary: false,
      gossipDataLaneActive: false,
    }),
    false,
    'normal WLVC/SFU media must keep MediaSecurity blocking semantics',
  );

  const harness = createRuntimeHarness(createCallWorkspaceMediaSecurityRuntime);
  assert.equal(
    harness.runtime.currentMediaSecurityRuntimePath(),
    'gossip_primary',
    'planned Gossip runtime must label MediaSecurity diagnostics as gossip_primary',
  );
  assert.equal(
    harness.runtime.canProtectCurrentSfuTargets(),
    true,
    'closed sender-key gate must not block planned Gossip frame send',
  );
  assert.equal(
    harness.diagnostics.some((event) => event?.eventType === 'media_security_planned_gossip_parking'
      && event?.payload?.reason === 'sender_key_gate_waiting'),
    true,
    'closed sender-key gate must be diagnosed when bypassed for planned Gossip',
  );

  harness.session.mode = 'participant_set_mismatch';
  assert.equal(
    await harness.runtime.sendMediaSecuritySenderKey(202, true),
    true,
    'sender-key participant mismatch must not fail planned Gossip sender-key path',
  );

  harness.session.mode = 'pending_wrap';
  assert.equal(
    await harness.runtime.sendMediaSecuritySenderKey(202, true),
    true,
    'key-wrap delay must not fail planned Gossip sender-key path',
  );

  assert.equal(
    harness.runtime.shouldRecoverMediaSecurityFromFrameError(new Error('wrong_key_id')),
    false,
    'planned Gossip receiver frame errors must not start MediaSecurity recovery loops',
  );
  harness.runtime.scheduleMediaSecurityParticipantSync('sender_key_pending', true);
  assert.equal(
    harness.state.mediaSecurityResyncTimer,
    null,
    'planned Gossip sender-key parking must not arm a participant sync timer',
  );
  assert.equal(
    harness.snapshots.length,
    0,
    'planned Gossip MediaSecurity parking must not request transcript recovery snapshots',
  );
  assert.equal(
    harness.frames.filter((frame) => frame?.type === 'call/media-security-sync-request').length,
    0,
    'planned Gossip MediaSecurity parking must not send remote sync recovery frames',
  );
  assert.equal(
    harness.diagnostics.filter((event) => event?.eventType === 'media_security_planned_gossip_parking').length >= 4,
    true,
    'planned Gossip parking must diagnose sender-key gate, mismatch, key-wrap delay and receiver recovery',
  );
  assert.equal(
    harness.logs.some((entry) => entry?.[0] === '[MediaSecurity] planned Gossip parking'),
    true,
    'planned Gossip parking must also be visible in media debug logs',
  );
} finally {
  await server.close();
}

process.stdout.write('[gossip-media-security-parking-contract] PASS\n');
