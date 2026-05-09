import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

function fail(message) {
  throw new Error(`[sfu-strict-720p30-runtime-contract] FAIL: ${message}`);
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

function read(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

async function main() {
  const policySource = read('src/domain/realtime/workspace/callWorkspace/strictStabilityPolicy.ts');
  const callWorkspace = read('src/domain/realtime/CallWorkspaceView.vue');
  const runtimeSwitching = read('src/domain/realtime/workspace/callWorkspace/runtimeSwitching.ts');
  const runtimeHealth = read('src/domain/realtime/workspace/callWorkspace/runtimeHealth.ts');
  const publisherBackpressure = read('src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.ts');
  const mediaOrchestration = read('src/domain/realtime/local/mediaOrchestration.ts');
  const backgroundTabPolicy = read('src/domain/realtime/workspace/callWorkspace/backgroundTabPolicy.ts');
  const gossipDataLane = read('src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts');
  const socketLifecycle = read('src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts');
  const sfuLifecycle = read('src/domain/realtime/sfu/lifecycle.ts');
  const sfuClient = read('src/lib/sfu/sfuClient.ts');

  requireContains(policySource, "STRICT_720P30_POLICY_MODE = 'strict_720p30'", 'strict policy mode');
  requireContains(policySource, 'captureWidth: 1280', 'strict capture width');
  requireContains(policySource, 'captureHeight: 720', 'strict capture height');
  requireContains(policySource, 'captureFrameRate: 30', 'strict capture fps');
  requireContains(policySource, 'frameWidth: 1280', 'strict frame width');
  requireContains(policySource, 'frameHeight: 720', 'strict frame height');
  requireContains(policySource, 'disableAutoQuality: true', 'strict auto quality gate');
  requireContains(policySource, 'disableGossipMediaRepair: true', 'strict gossip repair gate');
  requireContains(policySource, 'disableBackgroundTabPolicy: true', 'strict background tab gate');
  requireContains(policySource, 'strictCaptureOnly: true', 'strict capture fallback gate');

  requireContains(callWorkspace, "import { CALL_STABILITY_POLICY } from './workspace/callWorkspace/strictStabilityPolicy';", 'workspace strict policy import');
  requireContains(callWorkspace, 'policy: CALL_STABILITY_POLICY', 'workspace passes strict policy into gossip lane');
  requireContains(callWorkspace, 'strictStabilityPolicy: CALL_STABILITY_POLICY', 'workspace passes strict policy into runtime helpers');

  requireContains(runtimeSwitching, 'return strict720p30VideoProfile(strictStabilityPolicy);', 'runtime switching returns fixed strict video profile');
  requireContains(runtimeSwitching, "strictPolicyEnabled(strictStabilityPolicy, 'disableQualityRecoveryProbes')", 'runtime switching disables quality recovery probes');
  requireContains(runtimeSwitching, "strictPolicyEnabled(strictStabilityPolicy, 'disableAutoQuality')", 'runtime switching disables auto quality churn');

  requireContains(runtimeHealth, "strictPolicyEnabled(strictStabilityPolicy, 'disableRemoteVideoStallRecovery')", 'runtime health disables remote stall recovery');
  requireContains(runtimeHealth, "step: 'strict_720p30_disabled'", 'runtime health returns a non-recovery strict result');
  requireContains(runtimeHealth, "strictPolicyEnabled(strictStabilityPolicy, 'disableSfuSocketRecoveryReconnect')", 'runtime health disables stall socket reconnect');

  requireContains(publisherBackpressure, "strictPolicyEnabled(strictStabilityPolicy, 'disableForcedKeyframeRecovery')", 'publisher backpressure disables forced keyframe recovery');
  requireContains(publisherBackpressure, "strictPolicyEnabled(strictStabilityPolicy, 'disableSfuSocketRecoveryReconnect')", 'publisher backpressure disables stall reconnect');

  requireContains(mediaOrchestration, "strictPolicyEnabled(constants.strictStabilityPolicy, 'disableBackgroundOutgoing')", 'local media disables outgoing background filters');
  requireContains(mediaOrchestration, "strictPolicyEnabled(constants.strictStabilityPolicy, 'strictCaptureOnly')", 'local media enforces strict capture-only fallback');
  requireContains(mediaOrchestration, "eventType: 'strict_720p30_video_capture_unavailable'", 'strict capture fallback diagnostic exists');

  requireContains(backgroundTabPolicy, "strictPolicyEnabled(policy, 'disableBackgroundTabPolicy')", 'background tab policy can no-op under strict mode');
  requireContains(gossipDataLane, "strictGossipMediaDisabled('disableGossipPublish')", 'gossip publish is disabled under strict mode');
  requireContains(gossipDataLane, "strictGossipMediaDisabled('disableGossipReceiveRecovery')", 'gossip receive recovery is disabled under strict mode');
  requireContains(gossipDataLane, "strictGossipMediaDisabled()) return false;", 'gossip topology repair is disabled under strict mode');
  requireContains(socketLifecycle, "strictPolicyEnabled(strictStabilityPolicy, 'disableAutoQuality')", 'socket lifecycle absorbs media quality pressure under strict mode');
  requireContains(sfuLifecycle, 'disablePublisherFrameStallRecovery:', 'SFU lifecycle passes strict stall recovery option');
  requireContains(sfuClient, 'disablePublisherFrameStallRecovery', 'SFU client can disable publisher frame stall resubscribe');
  requireContains(sfuClient, 'disablePublisherMediaRecovery', 'SFU client can disable publisher media recovery requests');

  const server = await createServer({
    root: frontendRoot,
    logLevel: 'error',
    server: { middlewareMode: true, hmr: false },
  });

  try {
    const {
      resolveCallStabilityPolicy,
      strict720p30VideoProfile,
      strictPolicyEnabled,
      isStrict720p30Policy,
    } = await server.ssrLoadModule('/src/domain/realtime/workspace/callWorkspace/strictStabilityPolicy.ts');
    const {
      createCallWorkspaceRuntimeSwitchingHelpers,
    } = await server.ssrLoadModule('/src/domain/realtime/workspace/callWorkspace/runtimeSwitching.ts');
    const {
      createSfuBackgroundTabPolicy,
    } = await server.ssrLoadModule('/src/domain/realtime/workspace/callWorkspace/backgroundTabPolicy.ts');

    const strictPolicy = resolveCallStabilityPolicy({});
    assert.equal(isStrict720p30Policy(strictPolicy), true, 'empty env defaults to strict 720p30');
    assert.equal(strictPolicyEnabled(strictPolicy, 'disableAutoQuality'), true, 'strict policy disables auto quality');

    const profile = strict720p30VideoProfile(strictPolicy);
    assert.equal(profile.captureWidth, 1280, 'strict capture width must be 1280');
    assert.equal(profile.captureHeight, 720, 'strict capture height must be 720');
    assert.equal(profile.captureFrameRate, 30, 'strict capture fps must be 30');
    assert.equal(profile.frameWidth, 1280, 'strict frame width must be 1280');
    assert.equal(profile.frameHeight, 720, 'strict frame height must be 720');
    assert.equal(profile.readbackFrameRate, 30, 'strict readback fps must be 30');

    const refs = {
      activeCallId: { value: 'call-1' },
      activeRoomId: { value: 'room-1' },
      callMediaPrefs: { outgoingVideoQualityProfile: 'realtime' },
      currentUserId: { value: 1 },
      localStreamRef: { value: null },
      mediaRuntimeCapabilities: { value: { stageA: true, stageB: false, preferredPath: 'wlvc_wasm', reasons: [] } },
      mediaRuntimePath: { value: 'wlvc_wasm' },
      mediaRuntimeReason: { value: '' },
      nativePeerConnectionsRef: { value: new Map() },
      sfuTransportState: {},
    };
    const helpers = createCallWorkspaceRuntimeSwitchingHelpers({
      callbacks: {
        appendMediaRuntimeTransitionEvent: () => {},
        captureClientDiagnostic: () => {},
        mediaDebugLog: () => {},
        resetSfuOutboundMediaAfterProfileSwitch: () => fail('strict mode must not reset outbound media for profile switch'),
        resolveSfuVideoQualityProfile: (value) => ({ id: value, captureWidth: 1, captureHeight: 1, captureFrameRate: 1 }),
        setCallOutgoingVideoQualityProfile: () => fail('strict mode must not switch outgoing video quality'),
        startEncodingPipeline: async () => false,
        stopLocalEncodingPipeline: () => {},
        syncNativePeerConnectionsWithRoster: () => {},
        syncNativePeerLocalTracks: async () => {},
        synchronizeNativePeerMediaElements: () => {},
        teardownNativePeerConnections: () => {},
        teardownSfuRemotePeers: () => {},
        publishLocalTracks: async () => false,
        shouldSyncNativeLocalTracksBeforeOffer: () => false,
        shouldUseNativeAudioBridge: () => false,
      },
      constants: {
        sfuAutoQualityDowngradeCooldownMs: 1,
        sfuAutoQualityDowngradeNext: { realtime: 'rescue' },
        sfuAutoQualityRecoveryProbeDelaysMs: [1],
        sfuRuntimeEnabled: true,
        strictStabilityPolicy: strictPolicy,
      },
      refs,
      state: {
        getRuntimeSwitchInFlight: () => false,
        setRuntimeSwitchInFlight: () => {},
        getWlvcEncodeFailureCount: () => 0,
        resetWlvcEncodeCounters: () => {},
      },
    });
    assert.equal(helpers.currentSfuVideoProfile().id, 'strict_720p30', 'runtime helper must expose fixed strict profile');
    assert.equal(helpers.ensureSfuVideoQualityRecoveryProbeSeries(), false, 'strict mode must not schedule quality recovery probes');
    assert.equal(helpers.probeSfuVideoQualityAfterStableReadback(), false, 'strict mode must not upshift after readback success');
    assert.equal(helpers.downgradeSfuVideoQualityAfterEncodePressure(), false, 'strict mode must not downshift after pressure');

    const backgroundPolicy = createSfuBackgroundTabPolicy({
      policy: strictPolicy,
      refs: { mediaRuntimePath: { value: 'wlvc_wasm' } },
      documentRef: { visibilityState: 'hidden' },
    });
    assert.equal(backgroundPolicy.shouldPauseSfuVideoForBackground({ hidden: true }), false, 'strict mode must not arm background-tab pause');
    assert.equal(backgroundPolicy.pauseVideoForBackground({ hidden: true }), false, 'strict mode must not pause SFU video in background');
  } finally {
    await server.close();
  }

  console.log('[sfu-strict-720p30-runtime-contract] PASS');
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
