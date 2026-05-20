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
  const captureProfileConstraints = read('src/domain/realtime/local/sfuCaptureProfileConstraints.ts');
  const publisherPipeline = read('src/domain/realtime/local/publisherPipeline.ts');
  const publisherFrameDispatch = read('src/domain/realtime/local/publisherFrameDispatch.ts');
  const browserVideoEncoderConfig = read('src/domain/realtime/local/browserVideoEncoderConfig.ts');
  const publisherFrameTrace = read('src/domain/realtime/local/publisherFrameTrace.ts');
  const protectedBrowserVideoEncoder = read('src/domain/realtime/local/protectedBrowserVideoEncoder.ts');
  const mediaSecurityRuntime = read('src/domain/realtime/workspace/callWorkspace/mediaSecurityRuntime.ts');
  const mediaStack = read('src/domain/realtime/workspace/callWorkspace/mediaStack.ts');
  const frameDecode = read('src/domain/realtime/sfu/frameDecode.ts');
  const backgroundTabPolicy = read('src/domain/realtime/workspace/callWorkspace/backgroundTabPolicy.ts');
  const gossipDataLane = read('src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts');
  const socketLifecycle = read('src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts');
  const sfuLifecycle = read('src/domain/realtime/sfu/lifecycle.ts');
  const sfuClient = read('src/lib/sfu/sfuClient.ts');
  const sfuStore = read('../backend-king-php/domain/realtime/realtime_sfu_store.php');
  const sfuSubscriberBudget = read('../backend-king-php/domain/realtime/realtime_sfu_subscriber_budget.php');

  requireContains(policySource, "STRICT_720P30_POLICY_MODE = 'strict_720p30'", 'strict policy mode');
  requireContains(policySource, 'video_width: 1280', 'strict capture width');
  requireContains(policySource, 'video_height: 720', 'strict capture height');
  requireContains(policySource, 'video_fps: 30', 'strict capture fps');
  requireContains(policySource, 'captureWidth: STRICT_720P30_CONSTRAINTS.video_width', 'strict 720p capture width profile remains available');
  requireContains(policySource, 'captureHeight: STRICT_720P30_CONSTRAINTS.video_height', 'strict 720p capture height profile remains available');
  requireContains(policySource, 'captureFrameRate: STRICT_720P30_CONSTRAINTS.video_fps', 'strict 720p capture fps profile remains available');
  requireContains(policySource, 'frameWidth: STRICT_720P30_CONSTRAINTS.video_width', 'strict 720p frame width profile remains available');
  requireContains(policySource, 'frameHeight: STRICT_720P30_CONSTRAINTS.video_height', 'strict 720p frame height profile remains available');
  requireContains(policySource, 'fixedVideoProfile: null', 'strict production policy no longer forces 720p as the default profile');
  requireContains(policySource, 'disableAutoQuality: true', 'strict auto quality gate');
  requireContains(policySource, 'disableGossipMediaRepair: true', 'strict gossip repair gate');
  requireContains(policySource, 'disableGossipPublish: false', 'strict allows the browser gossip publish path');
  requireContains(policySource, 'disableBackgroundTabPolicy: true', 'strict background tab gate');
  requireContains(policySource, 'disableNativeRuntimeFallback: true', 'strict native runtime fallback gate');
  requireContains(policySource, 'disableRegressionImprovementProbes: true', 'strict regression improvement probe gate');
  requireContains(policySource, 'requireStrict720p30Capability: false', 'strict policy keeps 720p capability available without making it the default gate');
  requireContains(policySource, 'strictCaptureOnly: false', 'strict policy allows the planned 720p -> 360p fallback capture path');
  requireContains(policySource, 'strictFixedOutputFrame: true', 'strict fixed frame output gate');
  requireContains(policySource, 'disableSelectiveTileTransport: true', 'strict disables selective transport experiments');
  requireContains(policySource, 'quietPublisherFrameDrops: true', 'strict quietly drops unsupported publisher frames');
  requireContains(policySource, 'coalesceMediaSecurityHandshakeDiagnostics: true', 'strict coalesces handshake churn diagnostics');

  requireContains(callWorkspace, "import { CALL_STABILITY_POLICY } from './workspace/callWorkspace/strictStabilityPolicy';", 'workspace strict policy import');
  requireContains(callWorkspace, 'policy: CALL_STABILITY_POLICY', 'workspace passes strict policy into gossip lane');
  requireContains(callWorkspace, 'strictStabilityPolicy: CALL_STABILITY_POLICY', 'workspace passes strict policy into runtime helpers');

  requireContains(runtimeSwitching, 'strictStabilityPolicy?.fixedVideoProfile', 'runtime switching only uses a fixed strict profile when one is configured');
  requireContains(runtimeSwitching, 'return resolveSfuVideoQualityProfile(refs.callMediaPrefs.outgoingVideoQualityProfile);', 'runtime switching falls back to the selected/default profile under production strict mode');
  requireContains(runtimeSwitching, 'VIDEOCHAT_MEDIA_CARRIER_CONFIG.gossipPrimary', 'runtime switching keys strict active path off gossip-primary mode');
  requireContains(runtimeSwitching, "eventType: 'strict_720p30_gossip_native_fallback_blocked'", 'runtime switching blocks native fallback on active gossip path');
  requireContains(runtimeSwitching, "strictPolicyEnabled(strictStabilityPolicy, 'disableQualityRecoveryProbes')", 'runtime switching disables quality recovery probes');
  requireContains(runtimeSwitching, "strictPolicyEnabled(strictStabilityPolicy, 'disableAutoQuality')", 'runtime switching disables auto quality churn');
  requireContains(runtimeSwitching, "strictPolicyEnabled(strictStabilityPolicy, 'disableRegressionImprovementProbes')", 'runtime switching disables regression-improvement probes');

  requireContains(runtimeHealth, "strictPolicyEnabled(strictStabilityPolicy, 'disableRemoteVideoStallRecovery')", 'runtime health disables remote stall recovery');
  requireContains(runtimeHealth, "step: 'strict_720p30_disabled'", 'runtime health returns a non-recovery strict result');
  requireContains(runtimeHealth, "strictPolicyEnabled(strictStabilityPolicy, 'disableSfuSocketRecoveryReconnect')", 'runtime health disables stall socket reconnect');

  requireContains(publisherBackpressure, "strictPolicyEnabled(strictStabilityPolicy, 'disableForcedKeyframeRecovery')", 'publisher backpressure disables forced keyframe recovery');
  requireContains(publisherBackpressure, "strictPolicyEnabled(strictStabilityPolicy, 'disableSfuSocketRecoveryReconnect')", 'publisher backpressure disables stall reconnect');

  requireContains(mediaOrchestration, "strictPolicyEnabled(constants.strictStabilityPolicy, 'disableBackgroundOutgoing')", 'local media keeps the outgoing-background policy gate available');
  requireContains(mediaOrchestration, "strictPolicyEnabled(constants.strictStabilityPolicy, 'strictCaptureOnly')", 'local media can still enforce capture-only when policy explicitly enables it');
  requireContains(mediaOrchestration, "eventType: 'strict_720p30_video_capture_unavailable'", 'strict capture fallback diagnostic exists');
  requireContains(mediaOrchestration, 'width: { exact: videoProfile.captureWidth }', 'strict getUserMedia requests exact capture width');
  requireContains(mediaOrchestration, "resetBackgroundRuntimeMetrics('strict_720p30_unfiltered')", 'strict mode still has an explicit raw-camera branch when the background gate is enabled');
  requireContains(captureProfileConstraints, "profileId(videoProfile) === 'strict_720p30'", 'capture track constraints use exact mode for strict 720p30');
  requireContains(captureProfileConstraints, 'if (options?.exact === true) return { exact: target };', 'strict capture enforcement does not cap down to lower device settings');
  requireContains(publisherPipeline, "strictPolicyEnabled(constants.strictStabilityPolicy, 'disableBackgroundOutgoing')", 'publisher uses raw track when outgoing background is disabled');
  requireContains(publisherPipeline, "suppressGossipPrimary: strictPolicyEnabled(constants.strictStabilityPolicy, 'disableGossipPublish')", 'publisher suppresses gossip-primary fallback diagnostics in strict mode');
  requireContains(publisherPipeline, "strictPolicyEnabled(constants.strictStabilityPolicy, 'disableSelectiveTileTransport')", 'publisher disables selective tile transport in strict mode');
  requireContains(publisherPipeline, "suppressSfuSendFailures: quietStrictPublisherDrops", 'publisher quietly drops strict SFU send failures');
  requireContains(publisherFrameDispatch, 'suppressGossipPrimary = false', 'frame dispatch accepts strict gossip suppression');
  requireContains(publisherFrameDispatch, 'suppressSfuSendFailures = false', 'frame dispatch accepts strict SFU send failure suppression');
  requireContains(publisherFrameDispatch, '(planRequiresGossipTransport || VIDEOCHAT_MEDIA_CARRIER_CONFIG.gossipPrimary)', 'frame dispatch can enter the explicit browser gossip-primary path');
  requireContains(publisherFrameDispatch, '&& suppressGossipPrimary !== true', 'frame dispatch keeps strict gossip suppression separate from gossip-primary selection');
  requireContains(browserVideoEncoderConfig, "String(videoProfile?.id || '').trim().toLowerCase() === 'strict_720p30'", 'browser encoder sizing detects strict profile');
  requireContains(browserVideoEncoderConfig, "{ mode: 'cover', targetAspectRatio: maxWidth / maxHeight }", 'browser encoder uses fixed 16:9 strict output sizing');
  requireContains(publisherFrameTrace, 'raw_source_frame_width: frameSize.sourceWidth', 'transport metrics preserve raw source width separately under strict fixed output');
  requireContains(protectedBrowserVideoEncoder, 'raw_source_frame_width: positiveInteger(frameSize.sourceWidth, 0)', 'browser encoder metrics preserve raw source width separately under strict fixed output');

  requireContains(backgroundTabPolicy, "strictPolicyEnabled(policy, 'disableBackgroundTabPolicy')", 'background tab policy can no-op under strict mode');
  requireContains(gossipDataLane, "strictGossipMediaDisabled('disableGossipPublish')", 'gossip publish remains policy-gated while strict allows the explicit room-bound relay');
  requireContains(gossipDataLane, "strictGossipMediaDisabled('disableGossipReceiveRecovery')", 'gossip receive recovery is disabled under strict mode');
  requireContains(gossipDataLane, "strictGossipMediaDisabled()) return false;", 'gossip topology repair is disabled under strict mode');
  requireContains(socketLifecycle, "strictPolicyEnabled(strictStabilityPolicy, 'disableAutoQuality')", 'socket lifecycle absorbs media quality pressure under strict mode');
  requireContains(sfuLifecycle, 'disablePublisherFrameStallRecovery:', 'SFU lifecycle passes strict stall recovery option');
  requireContains(sfuLifecycle, 'suppressPublisherFrameDropDiagnostics:', 'SFU lifecycle suppresses noisy strict frame drop diagnostics');
  requireContains(sfuLifecycle, 'suppressDisconnectRecoveryDiagnostics: disableSfuSocketRecoveryReconnect', 'SFU lifecycle suppresses strict disconnect recovery diagnostics');
  requireContains(sfuLifecycle, 'if (disableSfuSocketRecoveryReconnect)', 'SFU lifecycle does not reconnect after active strict disconnects');
  requireContains(sfuClient, 'disablePublisherFrameStallRecovery', 'SFU client can disable publisher frame stall resubscribe');
  requireContains(sfuClient, 'disablePublisherMediaRecovery', 'SFU client can disable publisher media recovery requests');
  requireContains(sfuClient, 'suppressPublisherFrameDropDiagnostics', 'SFU client can suppress noisy strict frame drop diagnostics');
  requireContains(sfuClient, 'suppressDisconnectRecoveryDiagnostics', 'SFU client can suppress noisy strict disconnect diagnostics');
  requireContains(mediaSecurityRuntime, "strictPolicyEnabled(strictStabilityPolicy, 'coalesceMediaSecurityHandshakeDiagnostics')", 'strict mode coalesces sender-key-not-ready churn');
  requireContains(mediaSecurityRuntime, "eventType: 'media_security_sync_hint'", 'media security sync hint remains available outside strict coalescing');
  requireContains(mediaSecurityRuntime, "eventType: 'media_security_handshake_timeout'", 'media security timeout remains available outside strict coalescing');
  requireContains(mediaStack, "suppressRemoteFrameDropDiagnostics: strictPolicyEnabled(constants.strictStabilityPolicy, 'quietPublisherFrameDrops')", 'strict mode suppresses remote continuity-drop diagnostics');
  requireContains(frameDecode, 'suppressRemoteFrameDropDiagnostics = false', 'frame decoder accepts strict remote drop suppression');
  requireContains(sfuStore, 'videochat_sfu_outbound_payload_uses_strict_720p30', 'SFU store detects strict outbound payloads');
  requireContains(sfuStore, 'if (!videochat_sfu_outbound_payload_uses_strict_720p30($payload))', 'strict outbound binary send failures are quiet drops');
  requireContains(sfuSubscriberBudget, 'videochat_sfu_subscriber_frame_uses_strict_720p30', 'subscriber budget detects strict replay frames');
  requireContains(sfuSubscriberBudget, 'if (!$strict720p30)', 'strict replay slow-subscriber diagnostics are suppressed');

  const previousCarrierMode = process.env.VITE_VIDEOCHAT_MEDIA_CARRIER;
  process.env.VITE_VIDEOCHAT_MEDIA_CARRIER = 'gossip_primary';

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
      strict720p30CapabilitySupported,
      strict720p30Constraints,
      isStrict720p30Policy,
    } = await server.ssrLoadModule('/src/domain/realtime/workspace/callWorkspace/strictStabilityPolicy.ts');
    const {
      createCallWorkspaceRuntimeSwitchingHelpers,
    } = await server.ssrLoadModule('/src/domain/realtime/workspace/callWorkspace/runtimeSwitching.ts');
    const {
      createSfuBackgroundTabPolicy,
    } = await server.ssrLoadModule('/src/domain/realtime/workspace/callWorkspace/backgroundTabPolicy.ts');
    const {
      resolveBrowserEncoderFrameSize,
    } = await server.ssrLoadModule('/src/domain/realtime/local/browserVideoEncoderConfig.ts');

    const strictPolicy = resolveCallStabilityPolicy({});
    assert.equal(isStrict720p30Policy(strictPolicy), true, 'empty env defaults to strict 720p30');
    assert.equal(strictPolicyEnabled(strictPolicy, 'disableAutoQuality'), true, 'strict policy disables auto quality');
    assert.equal(strictPolicyEnabled(strictPolicy, 'disableNativeRuntimeFallback'), true, 'strict policy disables native runtime fallback');
    assert.equal(strictPolicyEnabled(strictPolicy, 'disableRegressionImprovementProbes'), true, 'strict policy disables regression improvement probes');
    assert.equal(strictPolicyEnabled(strictPolicy, 'requireStrict720p30Capability'), false, 'strict policy keeps exact 720p30 capability optional for the default 360p path');
    assert.deepEqual(strict720p30Constraints(), {
      video_width: 1280,
      video_height: 720,
      video_fps: 30,
    }, 'strict constraints must be exactly 1280x720@30');
    assert.equal(strict720p30CapabilitySupported({
      media: { camera: true, camera_720p30: true },
      runtime: { websocket: true, wlvc_encoder: true },
      constraints: { video_width: 1280, video_height: 720, video_fps: 30 },
    }), true, 'strict capability accepts exact 720p30 WLVC gossip sender');
    assert.equal(strict720p30CapabilitySupported({
      media: { camera: true, camera_720p30: true },
      runtime: { websocket: true, wlvc_encoder: false },
      constraints: { video_width: 1280, video_height: 720, video_fps: 30 },
    }), false, 'strict capability rejects missing WLVC encoder');
    assert.equal(strict720p30CapabilitySupported({
      media: { camera: true, camera_720p30: true },
      runtime: { websocket: true, wlvc_encoder: true },
      constraints: { video_width: 960, video_height: 540, video_fps: 30 },
    }), false, 'strict capability rejects lower profiles');

    const profile = strict720p30VideoProfile(strictPolicy);
    assert.equal(profile.captureWidth, 1280, 'strict 720p helper keeps 1280 capture width available');
    assert.equal(profile.captureHeight, 720, 'strict 720p helper keeps 720 capture height available');
    assert.equal(profile.captureFrameRate, 30, 'strict 720p helper keeps 30fps available');
    assert.equal(profile.frameWidth, 1280, 'strict 720p helper keeps 1280 frame width available');
    assert.equal(profile.frameHeight, 720, 'strict 720p helper keeps 720 frame height available');
    assert.equal(profile.readbackFrameRate, 30, 'strict 720p helper keeps 30fps readback available');
    const portraitFrameSize = resolveBrowserEncoderFrameSize(profile, { displayWidth: 720, displayHeight: 1280 });
    assert.equal(portraitFrameSize.frameWidth, 1280, 'strict portrait browser frames must encode as fixed 1280 width');
    assert.equal(portraitFrameSize.frameHeight, 720, 'strict portrait browser frames must encode as fixed 720 height');
    assert.equal(portraitFrameSize.framingMode, 'cover', 'strict portrait browser frames must use fixed cover framing');

    const runtimeDiagnostics = [];
    const refs = {
      activeCallId: { value: 'call-1' },
      activeRoomId: { value: 'room-1' },
      callMediaPrefs: { outgoingVideoQualityProfile: 'rescue' },
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
        captureClientDiagnostic: (diagnostic) => runtimeDiagnostics.push(diagnostic),
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
    assert.equal(helpers.currentSfuVideoProfile().id, 'rescue', 'runtime helper must use the 360p rescue profile by default when no fixed strict profile is configured');
    assert.equal(helpers.ensureSfuVideoQualityRecoveryProbeSeries(), false, 'strict mode must not schedule quality recovery probes');
    assert.equal(helpers.probeSfuVideoQualityAfterStableReadback(), false, 'strict mode must not upshift after readback success');
    assert.equal(helpers.downgradeSfuVideoQualityAfterEncodePressure(), false, 'strict mode must not downshift after pressure');
    assert.equal(await helpers.maybeFallbackToNativeRuntime('stage_a_unavailable'), false, 'active strict gossip must not fall back to native runtime');
    assert.equal(refs.mediaRuntimePath.value, 'unsupported', 'blocked native fallback must leave the sender explicitly unsupported');
    assert.ok(
      runtimeDiagnostics.some((diagnostic) => diagnostic.eventType === 'strict_720p30_gossip_native_fallback_blocked'),
      'blocked native fallback must emit an explicit diagnostic',
    );

    const backgroundPolicy = createSfuBackgroundTabPolicy({
      policy: strictPolicy,
      refs: { mediaRuntimePath: { value: 'wlvc_wasm' } },
      documentRef: { visibilityState: 'hidden' },
    });
    assert.equal(backgroundPolicy.shouldPauseSfuVideoForBackground({ hidden: true }), false, 'strict mode must not arm background-tab pause');
    assert.equal(backgroundPolicy.pauseVideoForBackground({ hidden: true }), false, 'strict mode must not pause SFU video in background');
  } finally {
    await server.close();
    if (previousCarrierMode === undefined) {
      delete process.env.VITE_VIDEOCHAT_MEDIA_CARRIER;
    } else {
      process.env.VITE_VIDEOCHAT_MEDIA_CARRIER = previousCarrierMode;
    }
  }

  console.log('[sfu-strict-720p30-runtime-contract] PASS');
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
