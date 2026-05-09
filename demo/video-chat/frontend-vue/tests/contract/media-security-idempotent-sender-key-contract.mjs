import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

function fail(message) {
  throw new Error(`[media-security-idempotent-sender-key-contract] FAIL: ${message}`);
}

function ref(value) {
  return { value };
}

function wait(ms) {
  return new Promise((resolve) => {
    setTimeout(resolve, ms);
  });
}

function sameSet(actual, expected, message) {
  assert.deepEqual(Array.from(actual).sort(), Array.from(expected).sort(), message);
}

function createRuntimeState() {
  return {
    mediaSecurityResyncTimer: null,
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
  };
}

function createSessionStub({
  userId = 101,
  peerUserId = 202,
  participantSignature = '202',
} = {}) {
  return {
    callId: 'call-media-sec-01',
    roomId: 'room-media-sec-01',
    userId,
    participantSignature,
    epoch: 7,
    senderKeyId: 'current-sender-key',
    senderKey: {},
    state: 'active',
    receiveMode: 'valid',
    buildHelloCount: 0,
    buildSenderKeyCount: 0,
    handledSenderKeys: [],
    peers: new Map([
      [peerUserId, {
        state: 'active',
        wrappingKey: {},
        kexSuite: 'x25519_hkdf_sha256_v1',
        participantSetHash: 'current-participant-set-hash',
        transcriptHash: 'current-transcript-hash',
      }],
    ]),
    updateContext(context) {
      this.callId = context.callId;
      this.roomId = context.roomId;
      this.userId = context.userId;
    },
    async ensureReady() {
      return true;
    },
    telemetrySnapshot(runtimePath = 'wlvc_sfu') {
      return {
        runtime_path: runtimePath,
        participant_signature: this.participantSignature,
        sender_key_id: this.senderKeyId,
        epoch: this.epoch,
      };
    },
    async buildHelloSignal(targetUserId, runtimePath = 'wlvc_sfu') {
      this.buildHelloCount += 1;
      return {
        type: 'media-security/hello',
        target_user_id: Number(targetUserId || 0),
        payload: {
          kind: 'media_security_hello',
          runtime_path: runtimePath,
          epoch: this.epoch,
          sender_key_id: this.senderKeyId,
          participant_set_hash: 'current-participant-set-hash',
        },
      };
    },
    async buildSenderKeySignal(targetUserId) {
      this.buildSenderKeyCount += 1;
      return {
        type: 'media-security/sender-key',
        target_user_id: Number(targetUserId || 0),
        payload: {
          kind: 'media_security_sender_key',
          epoch: this.epoch,
          sender_key_id: this.senderKeyId,
          participant_set_hash: 'current-participant-set-hash',
        },
      };
    },
    async handleSenderKeySignal(senderUserId, payload = {}) {
      this.handledSenderKeys.push({ senderUserId, payload });
      if (this.receiveMode === 'participant_set_mismatch') {
        throw new Error('participant_set_mismatch');
      }
      if (this.receiveMode === 'pending') {
        return false;
      }
      return true;
    },
    async handleHelloSignal() {
      return true;
    },
    markParticipantSet(userIds = []) {
      const next = Array.from(new Set(userIds.map(Number).filter((id) => id > 0 && id !== this.userId)))
        .sort((left, right) => left - right);
      const nextSignature = next.join(',');
      const changed = this.participantSignature !== nextSignature;
      this.participantSignature = nextSignature;
      return { changed, userIds: next };
    },
    async rotateSenderKey(reason = 'forced') {
      this.epoch += 1;
      this.senderKeyId = `${reason}-${this.epoch}`;
      return { epoch: this.epoch, senderKeyId: this.senderKeyId, reason };
    },
    markPeerRemoved(senderUserId) {
      const peer = this.peers.get(Number(senderUserId || 0));
      if (peer) peer.state = 'removed';
    },
    canProtectForTargets() {
      return false;
    },
    canProtectNativeForTargets() {
      return false;
    },
  };
}

function createRuntimeHarness(createCallWorkspaceMediaSecurityRuntime, {
  targetIds = [202],
  hasRealtimeRoomSync = true,
  settleMs = 25,
  session = createSessionStub({ participantSignature: targetIds.join(',') }),
} = {}) {
  const state = createRuntimeState();
  let currentTargetIds = targetIds.slice();
  const diagnostics = [];
  const diagnosticErrors = [];
  const frames = [];
  const snapshots = [];
  const logs = [];
  const refs = {
    activeCallId: ref('call-media-sec-01'),
    activeRoomId: ref('room-media-sec-01'),
    activeSocketCallId: ref('call-media-sec-01'),
    connectedParticipantUsers: ref([]),
    currentUserId: ref(101),
    hasRealtimeRoomSync: ref(hasRealtimeRoomSync),
    isNativeWebRtcRuntimePath: () => false,
    isSocketOnline: ref(true),
    isWlvcRuntimePath: () => true,
    mediaRuntimeCapabilities: ref({ stageB: false }),
    mediaRuntimePath: ref('wlvc_sfu'),
    mediaSecuritySessionRef: ref(session),
    mediaSecurityStateVersion: ref(0),
    nativeAudioBridgeStatusVersion: ref(0),
    nativePeerConnectionsRef: ref(new Map()),
  };
  const runtime = createCallWorkspaceMediaSecurityRuntime({
    callbacks: {
      attachMediaSecurityNativeReceiversForPeer: () => {},
      captureClientDiagnostic: (event) => {
        diagnostics.push(event);
      },
      captureClientDiagnosticError: (...args) => {
        diagnosticErrors.push(args);
      },
      createMediaSecuritySession: () => session,
      createMediaSecurityTargetHelpers: () => ({
        clearMediaSecuritySfuPublisherSeen: () => {},
        mediaSecurityEligibleTargetIds: () => currentTargetIds.slice(),
        mediaSecurityTargetIds: () => currentTargetIds.slice(),
        nativeAudioBridgeBlockedReason: () => '',
        nativeAudioBridgePeerStatusMessage: () => '',
        noteMediaSecuritySfuPublisherSeen: () => {},
      }),
      ensureNativePeerConnection: () => null,
      extractDiagnosticMessage: (error) => String(error?.message || error || ''),
      mediaDebugLog: (...args) => {
        logs.push(args);
      },
      nativeAudioSecurityTelemetrySnapshot: () => ({}),
      requestRoomSnapshot: () => {
        snapshots.push({ at: Date.now() });
      },
      scheduleNativeAudioTrackRecovery: () => {},
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
      mediaSecuritySfuTargetSettleMs: settleMs,
      mediaSecuritySfuSenderKeyPropagationMs: 0,
      nativeFrameErrorLogCooldownMs: 10,
      sfuRuntimeEnabled: true,
      strictStabilityPolicy: { enabled: false },
      MediaSecuritySession: { supportsNativeTransforms: () => false },
    },
    refs,
    state,
  });

  return {
    diagnostics,
    diagnosticErrors,
    frames,
    logs,
    refs,
    runtime,
    session,
    snapshots,
    state,
    setTargetIds(nextTargetIds = []) {
      currentTargetIds = nextTargetIds.slice();
    },
    cleanup() {
      runtime.clearMediaSecurityResyncTimer();
      runtime.clearMediaSecurityHandshakeWatchdog();
    },
  };
}

async function assertDuplicateValidSenderKeyIsNoop(createMediaSecuritySession) {
  const alice = createMediaSecuritySession({ callId: 'call-valid-dup', roomId: 'room-valid-dup', userId: 101 });
  const bob = createMediaSecuritySession({ callId: 'call-valid-dup', roomId: 'room-valid-dup', userId: 202 });
  alice.markParticipantSet([202]);
  bob.markParticipantSet([101]);
  const aliceHello = await alice.buildHelloSignal(202, 'wlvc_sfu');
  const bobHello = await bob.buildHelloSignal(101, 'wlvc_sfu');
  await bob.handleHelloSignal(101, aliceHello.payload);
  await alice.handleHelloSignal(202, bobHello.payload);
  const senderKey = await alice.buildSenderKeySignal(202);

  assert.equal(await bob.handleSenderKeySignal(101, senderKey.payload), true, 'first valid sender-key must activate');
  const firstPeer = bob.peers.get(101);
  const firstReceiverKeyIds = Array.from(firstPeer.receiverKeys.keys());
  const firstHighestEpoch = firstPeer.highestEpoch;
  const firstParticipantSetHash = firstPeer.participantSetHash;
  const firstTranscriptHash = firstPeer.transcriptHash;

  assert.equal(await bob.handleSenderKeySignal(101, senderKey.payload), true, 'duplicate valid sender-key must be a no-op success');
  const duplicatePeer = bob.peers.get(101);
  assert.deepEqual(
    Array.from(duplicatePeer.receiverKeys.keys()),
    firstReceiverKeyIds,
    'duplicate valid sender-key must not add duplicate receiver-key cache entries',
  );
  assert.equal(duplicatePeer.highestEpoch, firstHighestEpoch, 'duplicate valid sender-key must not advance epoch state');
  assert.equal(duplicatePeer.participantSetHash, firstParticipantSetHash, 'duplicate valid sender-key must preserve participant-set cache');
  assert.equal(duplicatePeer.transcriptHash, firstTranscriptHash, 'duplicate valid sender-key must preserve transcript cache');
}

async function assertStaleSenderKeyPreservesCurrentCryptoCache(createMediaSecuritySession) {
  const alice = createMediaSecuritySession({ callId: 'call-stale-cache', roomId: 'room-stale-cache', userId: 101 });
  const bob = createMediaSecuritySession({ callId: 'call-stale-cache', roomId: 'room-stale-cache', userId: 202 });
  alice.markParticipantSet([202]);
  bob.markParticipantSet([101]);
  const staleAliceHello = await alice.buildHelloSignal(202, 'wlvc_sfu');
  const staleBobHello = await bob.buildHelloSignal(101, 'wlvc_sfu');
  await bob.handleHelloSignal(101, staleAliceHello.payload);
  await alice.handleHelloSignal(202, staleBobHello.payload);
  const staleSenderKey = await alice.buildSenderKeySignal(202);

  alice.markParticipantSet([202, 303]);
  bob.markParticipantSet([101, 303]);
  await alice.forceRekey('participant_change');
  const currentAliceHello = await alice.buildHelloSignal(202, 'wlvc_sfu');
  const currentBobHello = await bob.buildHelloSignal(101, 'wlvc_sfu');
  await bob.handleHelloSignal(101, currentAliceHello.payload);
  await alice.handleHelloSignal(202, currentBobHello.payload);
  const currentSenderKey = await alice.buildSenderKeySignal(202);
  await bob.handleSenderKeySignal(101, currentSenderKey.payload);

  const firstPeer = bob.peers.get(101);
  const currentReceiverKeyIds = Array.from(firstPeer.receiverKeys.keys());
  const currentParticipantSetHash = firstPeer.participantSetHash;
  assert.equal(
    await bob.handleSenderKeySignal(101, staleSenderKey.payload),
    false,
    'known stale sender-key must be idempotently dropped as participant-set churn',
  );
  assert.equal(
    bob.lastSenderKeySignalResult,
    'stale_participant_set',
    'known stale sender-key must be classified without falling through to KEX downgrade handling',
  );

  const afterStalePeer = bob.peers.get(101);
  assert.deepEqual(
    Array.from(afterStalePeer.receiverKeys.keys()),
    currentReceiverKeyIds,
    'stale sender-key must not clear current receiver-key cache entries',
  );
  assert.equal(
    afterStalePeer.participantSetHash,
    currentParticipantSetHash,
    'stale sender-key must not roll back the current participant-set cache',
  );

  const protectedFrame = await alice.protectFrame({
    data: new Uint8Array([7, 6, 5, 4]),
    runtimePath: 'wlvc_sfu',
    codecId: 'wlvc_ts',
    trackKind: 'video',
    frameKind: 'delta',
    trackId: 'camera-current',
    timestamp: 2000,
  });
  const decrypted = await bob.decryptFrame({
    data: protectedFrame.data,
    protected: protectedFrame.protected,
    publisherUserId: 101,
    runtimePath: 'wlvc_sfu',
    codecId: 'wlvc_ts',
    trackId: 'camera-current',
    timestamp: 2000,
  });
  assert.deepEqual(
    Array.from(new Uint8Array(decrypted)),
    [7, 6, 5, 4],
    'current cache must still decrypt current frames after a stale sender-key',
  );
}

async function assertDuplicateStaleSenderKeyCoalescesRecovery(createCallWorkspaceMediaSecurityRuntime) {
  const harness = createRuntimeHarness(createCallWorkspaceMediaSecurityRuntime, {
    targetIds: [202],
    hasRealtimeRoomSync: true,
    settleMs: 25,
  });
  try {
    await harness.runtime.sendMediaSecurityHello(202);
    await harness.runtime.sendMediaSecuritySenderKey(202);
    const helloCacheBeforeStale = new Set(harness.state.mediaSecurityHelloSignalsSent);
    const senderKeyCacheBeforeStale = new Set(harness.state.mediaSecuritySenderKeySignalsSent);
    assert.ok(helloCacheBeforeStale.size > 0, 'contract setup must have a current hello cache entry');
    assert.ok(senderKeyCacheBeforeStale.size > 0, 'contract setup must have a current sender-key cache entry');

    harness.diagnostics.length = 0;
    harness.frames.length = 0;
    harness.logs.length = 0;
    harness.session.receiveMode = 'participant_set_mismatch';
    const stalePayload = {
      kind: 'media_security_sender_key',
      device_id: 'stale-device',
      epoch: 3,
      sender_key_id: 'stale-sender-key',
      participant_set_hash: 'stale-participant-set-hash',
    };

    await harness.runtime.handleMediaSecuritySignal('media-security/sender-key', 202, stalePayload);
    await harness.runtime.handleMediaSecuritySignal('media-security/sender-key', 202, stalePayload);

    sameSet(
      harness.state.mediaSecurityHelloSignalsSent,
      helloCacheBeforeStale,
      'duplicate stale sender-key recovery must not clear current hello signal caches',
    );
    sameSet(
      harness.state.mediaSecuritySenderKeySignalsSent,
      senderKeyCacheBeforeStale,
      'duplicate stale sender-key recovery must not clear current sender-key signal caches',
    );

    const mismatchDiagnostics = harness.diagnostics.filter(
      (event) => event?.eventType === 'media_security_sender_key_participant_mismatch',
    );
    assert.equal(
      mismatchDiagnostics.length,
      1,
      'duplicate stale sender-key must emit one coalesced participant-mismatch diagnostic',
    );
    const syncRequests = harness.frames.filter(
      (frame) => frame?.type === 'call/media-security-sync-request'
        && frame?.payload?.reason === 'sender_key_participant_mismatch',
    );
    assert.equal(syncRequests.length, 1, 'duplicate stale sender-key must send one coalesced remote sync request');

    await wait(40);
    const scheduledSyncLogs = harness.logs.filter((entry) => entry?.[0] === '[MediaSecurity] scheduled participant sync');
    assert.equal(scheduledSyncLogs.length, 1, 'duplicate stale sender-key must schedule one coalesced participant sync');
  } finally {
    harness.cleanup();
  }
}

async function assertUnknownFutureSenderKeyRequestsSnapshotWithoutLoop(createCallWorkspaceMediaSecurityRuntime) {
  const harness = createRuntimeHarness(createCallWorkspaceMediaSecurityRuntime, {
    targetIds: [],
    hasRealtimeRoomSync: false,
    settleMs: 25,
    session: createSessionStub({ participantSignature: '' }),
  });
  try {
    harness.session.receiveMode = 'pending';
    await harness.runtime.handleMediaSecuritySignal('media-security/sender-key', 404, {
      kind: 'media_security_sender_key',
      device_id: 'future-device',
      epoch: 12,
      sender_key_id: 'future-sender-key',
      participant_set_hash: 'future-participant-set-hash',
    });

    assert.equal(harness.snapshots.length, 1, 'unknown/future sender-key must request an authoritative room snapshot');
    const pendingSnapshotRequests = harness.frames.filter(
      (frame) => frame?.type === 'call/media-security-sync-request'
        && frame?.target_user_id === 404
        && frame?.payload?.reason === 'sender_key_pending_snapshot',
    );
    assert.equal(
      pendingSnapshotRequests.length,
      1,
      'unknown/future sender-key must request snapshot-backed remote sync',
    );
    assert.equal(
      harness.state.mediaSecurityResyncTimer,
      null,
      'unknown/future sender-key must not start a local participant-sync loop before the snapshot catches up',
    );
    assert.equal(harness.diagnostics.length, 0, 'unknown/future pending sender-key must not emit mismatch diagnostics');
  } finally {
    harness.cleanup();
  }
}

async function assertParticipantSyncSingleFlightCoalescesReasons(createCallWorkspaceMediaSecurityRuntime) {
  const harness = createRuntimeHarness(createCallWorkspaceMediaSecurityRuntime, {
    targetIds: [202],
    hasRealtimeRoomSync: true,
    settleMs: 15,
  });
  try {
    harness.runtime.scheduleMediaSecurityParticipantSync('sender_key_participant_mismatch', false);
    harness.runtime.scheduleMediaSecurityParticipantSync('sender_key_pending_snapshot', true);
    await wait(35);

    const scheduledSyncLogs = harness.logs.filter((entry) => entry?.[0] === '[MediaSecurity] scheduled participant sync');
    assert.equal(scheduledSyncLogs.length, 1, 'participant sync must remain single-flight while the settle timer is active');
    const syncPayload = scheduledSyncLogs[0]?.[1] || {};
    assert.equal(syncPayload.forceRekey, true, 'single-flight participant sync must merge pending force-rekey state');
    const reasons = Array.isArray(syncPayload.reasons)
      ? syncPayload.reasons.map(String)
      : String(syncPayload.reason || '').split(/[,\s]+/).filter(Boolean);
    assert.ok(
      reasons.includes('sender_key_participant_mismatch'),
      'single-flight participant sync must retain the first coalesced reason',
    );
    assert.ok(
      reasons.includes('sender_key_pending_snapshot'),
      'single-flight participant sync must retain the later coalesced reason',
    );
  } finally {
    harness.cleanup();
  }
}

const server = await createServer({
  configFile: path.resolve(frontendRoot, 'vite.config.js'),
  logLevel: 'error',
  server: { middlewareMode: true, hmr: false },
  appType: 'custom',
});

const failures = [];
try {
  const {
    createMediaSecuritySession,
  } = await server.ssrLoadModule('/src/domain/realtime/media/security.ts');
  const {
    createCallWorkspaceMediaSecurityRuntime,
  } = await server.ssrLoadModule('/src/domain/realtime/workspace/callWorkspace/mediaSecurityRuntime.ts');

  const contracts = [
    ['duplicate valid sender-key is a no-op success', () => assertDuplicateValidSenderKeyIsNoop(createMediaSecuritySession)],
    ['stale sender-key preserves current crypto caches', () => assertStaleSenderKeyPreservesCurrentCryptoCache(createMediaSecuritySession)],
    ['duplicate stale sender-key coalesces recovery and preserves current signal caches', () => assertDuplicateStaleSenderKeyCoalescesRecovery(createCallWorkspaceMediaSecurityRuntime)],
    ['unknown/future sender-key is pending plus snapshot instead of loop', () => assertUnknownFutureSenderKeyRequestsSnapshotWithoutLoop(createCallWorkspaceMediaSecurityRuntime)],
    ['participant sync single-flight coalesces reasons', () => assertParticipantSyncSingleFlightCoalescesReasons(createCallWorkspaceMediaSecurityRuntime)],
  ];

  for (const [name, run] of contracts) {
    try {
      await run();
    } catch (error) {
      failures.push(`${name}: ${error?.message || error}`);
    }
  }
} finally {
  await server.close();
}

if (failures.length > 0) {
  fail(failures.join('\n'));
}

process.stdout.write('[media-security-idempotent-sender-key-contract] PASS\n');
