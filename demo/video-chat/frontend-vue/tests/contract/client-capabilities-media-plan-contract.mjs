import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

const contractName = 'client-capabilities-media-plan-contract';
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

function fail(message) {
  throw new Error(`[${contractName}] FAIL: ${message}`);
}

function read(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

function assertNoForbiddenData(value, label) {
  const forbiddenKey = /(?:^|_)(?:token|cookie|credential|secret|sdp|ice|candidate|frame|raw_frame|encoded_frame|protected_frame|device_label|label)(?:$|_)/i;
  const forbiddenValue = /(?:secret-token|cookie=value|v=0|candidate:|raw-frame|encoded-frame-bytes|private-device-label)/i;

  function visit(current, location) {
    if (current === null || current === undefined) return;
    if (typeof current === 'string') {
      assert.doesNotMatch(current, forbiddenValue, `${label} leaked forbidden value at ${location}`);
      return;
    }
    if (typeof current !== 'object') return;
    for (const [key, entry] of Object.entries(current)) {
      assert.doesNotMatch(key, forbiddenKey, `${label} leaked forbidden key ${location}.${key}`);
      visit(entry, `${location}.${key}`);
    }
  }

  visit(value, '$');
}

let server = null;

try {
  const capabilitiesSource = read('src/domain/realtime/media/clientCapabilities.ts');
  const planSource = read('src/domain/realtime/media/mediaSessionPlan.ts');
  const bridgeSource = read('src/domain/realtime/workspace/callWorkspace/mediaCapabilityPlanBridge.ts');
  const socketLifecycleSource = read('src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts');
  const wrapperSource = read('src/domain/realtime/mediaRuntimeCapabilities.ts');

  assert.match(capabilitiesSource, /CLIENT_CAPABILITIES_SCHEMA_VERSION = 'king\.video\.client_capabilities\.v1'/);
  assert.match(capabilitiesSource, /export async function buildClientCapabilitiesV1/);
  assert.match(capabilitiesSource, /export function redactClientCapabilitiesV1/);
  assert.match(capabilitiesSource, /export function buildClientCapabilitiesFrame/);
  assert.match(capabilitiesSource, /strict720p30Constraints/);
  assert.match(capabilitiesSource, /strict720p30CapabilitySupported/);
  assert.match(capabilitiesSource, /capture\?\.hasWorkerCapturePath && runtime\?\.stageA/);
  assert.match(planSource, /MEDIA_SESSION_PLAN_SCHEMA_VERSION = 'king\.video\.media_session_plan\.v1'/);
  assert.match(planSource, /CALL_MEDIA_STATE_VALUES/);
  assert.match(planSource, /export function normalizeMediaSessionPlanFromSnapshot/);
  assert.match(planSource, /export function mediaSessionPlanDiagnosticPayload/);
  assert.match(bridgeSource, /export function createCallWorkspaceMediaCapabilityBridge/);
  assert.match(bridgeSource, /export function resolveClientCapabilitiesContext/);
  assert.match(bridgeSource, /export function hasSnapshotMediaSessionPlan/);
  assert.match(bridgeSource, /isAdmittedWebsocketJoinPayload/);
  assert.match(bridgeSource, /capabilityChangeKey/);
  assert.match(bridgeSource, /handleClientCapabilitiesAck/);
  assert.match(bridgeSource, /canPublishLocalMediaForLastPlan/);
  assert.match(bridgeSource, /requestLocalMediaPublicationForLastPlan/);
  assert.match(socketLifecycleSource, /createCallWorkspaceMediaCapabilityBridge/);
  assert.match(socketLifecycleSource, /sendClientCapabilities\('system_welcome', payload\)/);
  assert.match(socketLifecycleSource, /handleRoomSnapshotMediaSessionPlan\(payload\)/);
  assert.match(socketLifecycleSource, /sendClientCapabilities\('room_snapshot', payload\)/);
  assert.match(socketLifecycleSource, /handleClientCapabilitiesAck\(payload\)/);
  assert.match(socketLifecycleSource, /applyLocalMediaStateForLastPlan\('room_snapshot', payload\)/);
  assert.match(wrapperSource, /export \{ detectMediaRuntimeCapabilities \} from '\.\/media\/runtimeCapabilities\.ts';/);
  assert.doesNotMatch(wrapperSource, /return c\(\)/);

  server = await createServer({
    configFile: path.resolve(frontendRoot, 'vite.config.js'),
    logLevel: 'error',
    server: { middlewareMode: true, hmr: false },
    appType: 'custom',
  });

  const capabilitiesModule = await server.ssrLoadModule('/src/domain/realtime/media/clientCapabilities.ts');
  const planModule = await server.ssrLoadModule('/src/domain/realtime/media/mediaSessionPlan.ts');
  const bridgeModule = await server.ssrLoadModule('/src/domain/realtime/workspace/callWorkspace/mediaCapabilityPlanBridge.ts');

  const capabilities = capabilitiesModule.redactClientCapabilitiesV1({
    participant_session_id: 'call-session-alpha',
    media: {
      camera: true,
      camera_720p30: true,
      microphone: true,
      screen_share: true,
    },
    runtime: {
      websocket: true,
      webrtc: true,
      webassembly: true,
      webcodecs: false,
      gpu: 'available_or_unknown',
      wlvc_encoder: true,
      wlvc_decoder: true,
    },
    constraints: {
      video_width: 1280,
      video_height: 720,
      video_fps: 30,
    },
    token: 'secret-token',
    cookie: 'cookie=value',
    sdp: 'v=0',
    ice_candidates: ['candidate:private'],
    encoded_frame: 'encoded-frame-bytes',
    device_label: 'private-device-label',
  });

  assert.equal(capabilities.schema_version, 'king.video.client_capabilities.v1');
  assert.equal(capabilities.media.camera_720p30, true);
  assert.equal(capabilities.runtime.wlvc_encoder, true);
  assert.equal(capabilities.constraints.video_width, 1280);
  assertNoForbiddenData(capabilities, 'client.capabilities.v1');

  const capabilityFrame = capabilitiesModule.buildClientCapabilitiesFrame({
    ...capabilities,
    token: 'secret-token',
    cookie: 'cookie=value',
    sdp: 'v=0',
    ice_candidates: ['candidate:private'],
    encoded_frame: 'encoded-frame-bytes',
    device_label: 'private-device-label',
  }, {
    callId: 'call-alpha',
    roomId: 'room-alpha',
    reason: 'system_welcome',
  });
  assert.equal(capabilityFrame.type, 'client/capabilities.v1');
  assert.equal(capabilityFrame.call_id, 'call-alpha');
  assert.equal(capabilityFrame.room_id, 'room-alpha');
  assertNoForbiddenData(capabilityFrame, 'client.capabilities.v1 frame');

  const normalizedCapabilityFrame = capabilitiesModule.buildClientCapabilitiesFrame({
    participantSessionId: 'call-session-beta',
    media: {
      camera: 'supported',
      camera720p30: 'true',
      microphone: 1,
      screenShare: 'on',
    },
    runtime: {
      websocket: true,
      webrtc: true,
      webAssembly: true,
      webCodecs: false,
      gpu: 'secret-token',
      wlvcEncoder: 'yes',
      wlvcDecoder: 'yes',
    },
    constraints: {
      videoWidth: 1280,
      videoHeight: 720,
      videoFps: 30,
    },
    token: 'secret-token',
    cookie: 'cookie=value',
    sdp: 'v=0',
    ice_candidates: ['candidate:private'],
    encoded_frame: 'encoded-frame-bytes',
    device_label: 'private-device-label',
  }, {
    callId: 'call-beta',
    roomId: 'room-beta',
    reason: ' Capability Probe !! ',
  });
  assert.equal(normalizedCapabilityFrame.type, 'client/capabilities.v1');
  assert.equal(normalizedCapabilityFrame.schema_version, 'king.video.client_capabilities.v1');
  assert.equal(normalizedCapabilityFrame.reason, 'capability_probe');
  assert.equal(normalizedCapabilityFrame.media.camera_720p30, true);
  assert.equal(normalizedCapabilityFrame.runtime.gpu, 'unknown');
  assert.equal(normalizedCapabilityFrame.runtime.wlvc_encoder, true);
  assert.equal(normalizedCapabilityFrame.constraints.video_width, 1280);
  assertNoForbiddenData(normalizedCapabilityFrame, 'normalized client.capabilities.v1 frame');

  const previousWebSocket = globalThis.WebSocket;
  globalThis.WebSocket = class ContractWebSocket {};
  try {
    const strictBuiltCapabilities = await capabilitiesModule.buildClientCapabilitiesV1({
      participantSessionId: 'strict-session',
      runtimeCapabilities: {
        stageA: true,
        stageB: true,
        webRtcNative: true,
        wlvcWasm: {
          webAssembly: true,
          encoder: true,
          decoder: true,
        },
      },
      captureCapabilities: {
        hasWorkerCapturePath: true,
        hasAnyCapturePath: true,
        supportsDomCanvasFallback: true,
      },
    });
    assert.equal(strictBuiltCapabilities.media.camera, true);
    assert.equal(strictBuiltCapabilities.media.camera_720p30, true);
    assert.equal(strictBuiltCapabilities.runtime.wlvc_encoder, true);
    assert.deepEqual(strictBuiltCapabilities.constraints, {
      video_width: 1280,
      video_height: 720,
      video_fps: 30,
      mobile: false,
      browser_family: 'unknown',
    });
    assert.equal(strictBuiltCapabilities.codec.preferred_path, 'unsupported');
    assert.equal(strictBuiltCapabilities.codec.wasm, true);
    assert.equal(strictBuiltCapabilities.network.backpressure.ratio, 0);

    const domFallbackOnlyCapabilities = await capabilitiesModule.buildClientCapabilitiesV1({
      participantSessionId: 'fallback-only-session',
      runtimeCapabilities: {
        stageA: true,
        stageB: true,
        webRtcNative: true,
        wlvcWasm: {
          webAssembly: true,
          encoder: true,
          decoder: true,
        },
      },
      captureCapabilities: {
        hasWorkerCapturePath: false,
        hasAnyCapturePath: true,
        supportsDomCanvasFallback: true,
      },
    });
    assert.equal(domFallbackOnlyCapabilities.media.camera, false);
    assert.equal(domFallbackOnlyCapabilities.media.camera_720p30, false);
    assert.deepEqual(domFallbackOnlyCapabilities.constraints, {
      video_width: 1280,
      video_height: 720,
      video_fps: 30,
      mobile: false,
      browser_family: 'unknown',
    });
  } finally {
    if (previousWebSocket === undefined) {
      delete globalThis.WebSocket;
    } else {
      globalThis.WebSocket = previousWebSocket;
    }
  }

  assert.deepEqual(
    bridgeModule.resolveClientCapabilitiesContext({
      activeSocketCallId: { value: '' },
      activeCallId: { value: 'call-beta' },
      serverRoomId: { value: '' },
      desiredRoomId: { value: 'room-beta' },
    }, {}),
    {
      callId: 'call-beta',
      roomId: 'room-beta',
      participantSessionId: '',
    },
  );

  assert.deepEqual(planModule.CALL_MEDIA_STATE_VALUES, [
    'waiting_for_capabilities',
    'waiting_for_gossip',
    'streaming_720p30',
    'throttled_50',
    'throttled_25',
    'stuck_not_sending',
    'audio_only',
    'video_unavailable',
    'blocked_capability',
    'left',
  ]);
  assert.deepEqual(planModule.MEDIA_SESSION_PLAN_STATE_VALUES, [
    'pending',
    'connecting',
    'gossip_720p30',
    'gossip_360p30',
    'gossip_360p5',
    'sfu_720p30',
    'sfu_320p30',
    'ready',
    'failed',
  ]);

  const plan = planModule.normalizeMediaSessionPlanV1({
    schema_version: 'king.video.media_session_plan.v1',
    call_id: 'call-alpha',
    room_id: 'room-alpha',
    plan_epoch: 3,
    participants: [
      {
        participant_session_id: 'call-session-alpha',
        media_state: 'streaming_720p30',
        profile: '720p30',
        transport: 'gossip',
        security_policy: 'transport_only',
        token: 'secret-token',
        sdp: 'v=0',
        ice_candidates: ['candidate:private'],
        frame: 'raw-frame',
      },
    ],
  });

  assert.equal(plan.schema_version, 'king.video.media_session_plan.v1');
  assert.equal(plan.call_id, 'call-alpha');
  assert.equal(plan.plan_epoch, 3);
  assert.deepEqual(plan.participants, [
    {
      participant_session_id: 'call-session-alpha',
      media_state: 'streaming_720p30',
      profile: '720p30',
      transport: 'gossip',
      security_policy: 'transport_only',
      stuck_reason: '',
    },
  ]);
  assertNoForbiddenData(plan, 'media_session_plan.v1');

  const snapshotPlan = planModule.normalizeMediaSessionPlanFromSnapshot({
    media_session_plan: {
      schema_version: 'king.video.media_session_plan.v1',
      call_id: 'call-alpha',
      room_id: 'room-alpha',
      plan_epoch: '7',
      participants: [
        {
          participantSessionId: 'call-session-alpha',
          mediaState: 'STREAMING_720P30',
          profile: '720p30',
          transport: 'gossip',
          securityPolicy: 'transport_only',
          token: 'secret-token',
          sdp: 'v=0',
          ice_candidates: ['candidate:private'],
          frame: 'raw-frame',
        },
        {
          participant_session_id: 'call-session-beta',
          media_state: 'sending_4k',
          profile: '4k',
          transport: 'sfu_unknown',
          security_policy: 'blocked',
          encoded_frame: 'encoded-frame-bytes',
          device_label: 'private-device-label',
        },
        {
          participant_session_id: 'call-session-gamma',
          media_state: 'waiting_for_gossip',
          profile: '',
          transport: '',
          security_policy: 'transport_only',
        },
      ],
      token: 'secret-token',
      cookie: 'cookie=value',
    },
  });
  assert.equal(snapshotPlan.schema_version, 'king.video.media_session_plan.v1');
  assert.equal(snapshotPlan.plan_epoch, 7);
  assert.deepEqual(snapshotPlan.participants, [
    {
      participant_session_id: 'call-session-alpha',
      media_state: 'streaming_720p30',
      profile: '720p30',
      transport: 'gossip',
      security_policy: 'transport_only',
      stuck_reason: '',
    },
    {
      participant_session_id: 'call-session-beta',
      media_state: 'blocked_capability',
      profile: '4k',
      transport: 'sfu_unknown',
      security_policy: 'blocked',
      stuck_reason: '',
    },
    {
      participant_session_id: 'call-session-gamma',
      media_state: 'waiting_for_gossip',
      profile: '',
      transport: '',
      security_policy: 'transport_only',
      stuck_reason: '',
    },
  ]);
  assert.ok(snapshotPlan.participants.every((participant) => planModule.CALL_MEDIA_STATE_VALUES.includes(participant.media_state)));
  assertNoForbiddenData(snapshotPlan, 'normalized snapshot media_session_plan.v1');

  const camelCaseSnapshotPlan = planModule.normalizeMediaSessionPlanFromSnapshot({
    mediaSessionPlan: {
      callId: 'call-beta',
      roomId: 'room-beta',
      planEpoch: 0,
      participants: [
        {
          participantSessionId: 'call-session-delta',
          mediaState: 'left',
          securityPolicy: 'transport_only',
        },
      ],
    },
  });
  assert.equal(camelCaseSnapshotPlan.call_id, 'call-beta');
  assert.equal(camelCaseSnapshotPlan.room_id, 'room-beta');
  assert.equal(camelCaseSnapshotPlan.plan_epoch, 1);
  assert.deepEqual(camelCaseSnapshotPlan.state_catalog, planModule.CALL_MEDIA_STATE_VALUES);
  assert.equal(camelCaseSnapshotPlan.participants[0].media_state, 'left');

  const diagnostic = planModule.mediaSessionPlanDiagnosticPayload(snapshotPlan);
  assert.equal(diagnostic.schema_version, 'king.video.media_session_plan.v1');
  assert.equal(diagnostic.participant_count, 3);
  assert.deepEqual(Object.keys(diagnostic.state_counts), planModule.CALL_MEDIA_STATE_VALUES);
  assert.equal(diagnostic.state_counts.streaming_720p30, 1);
  assert.equal(diagnostic.state_counts.blocked_capability, 1);
  assert.equal(diagnostic.state_counts.waiting_for_gossip, 1);
  assert.equal(diagnostic.state_counts.audio_only, 0);
  assert.equal(diagnostic.state_counts.video_unavailable, 0);
  assert.equal(diagnostic.state_counts.waiting_for_capabilities, 0);
  assertNoForbiddenData(diagnostic, 'media_session_plan.v1 diagnostic');

  const nativeTalkAudioPlan = planModule.normalizeMediaSessionPlanV1({
    schema_version: 'king.video.media_session_plan.v1',
    call_id: 'call-audio',
    room_id: 'room-audio',
    plan_epoch: 9,
    participants: [
      {
        participant_session_id: 'call-session-audio-only',
        media_state: 'audio_only',
        profile: '',
        transport: '',
        security_policy: 'transport_only',
      },
      {
        participant_session_id: 'call-session-video-unavailable',
        media_state: 'video_unavailable',
        profile: '',
        transport: '',
        security_policy: 'transport_only',
      },
      {
        participant_session_id: 'call-session-blocked',
        media_state: 'blocked_capability',
        profile: '',
        transport: '',
        security_policy: 'blocked',
      },
    ],
  });
  assert.equal(nativeTalkAudioPlan.participants[0].media_state, 'audio_only');
  assert.equal(nativeTalkAudioPlan.participants[0].transport, '');
  assert.equal(nativeTalkAudioPlan.participants[0].security_policy, 'transport_only');
  assert.equal(nativeTalkAudioPlan.participants[1].media_state, 'video_unavailable');
  assert.equal(nativeTalkAudioPlan.participants[1].security_policy, 'transport_only');
  assert.equal(planModule.mediaSessionPlanHasGossipTransport(nativeTalkAudioPlan, {
    participantSessionId: 'call-session-audio-only',
  }), false);
  assert.equal(planModule.mediaSessionPlanAllowsLocalPublication(nativeTalkAudioPlan, {
    callId: 'call-audio',
    roomId: 'room-audio',
    participantSessionId: 'call-session-audio-only',
    minPlanEpoch: 9,
  }), true, 'plain native WebRTC talk audio must not wait for a 720p Gossip video sender');
  assert.equal(planModule.mediaSessionPlanAllowsLocalPublication(nativeTalkAudioPlan, {
    callId: 'call-audio',
    roomId: 'room-audio',
    participantSessionId: 'call-session-video-unavailable',
    minPlanEpoch: 9,
  }), false, 'video_unavailable has no native talk-audio candidate and must not start local publication');
  assert.equal(planModule.mediaSessionPlanAllowsLocalPublication(nativeTalkAudioPlan, {
    callId: 'call-audio',
    roomId: 'room-audio',
    participantSessionId: 'call-session-blocked',
    minPlanEpoch: 9,
  }), false, 'blocked_capability must remain fail-closed');

  const sentFrames = [];
  const diagnostics = [];
  let screenShare = true;
  const bridge = bridgeModule.createCallWorkspaceMediaCapabilityBridge({
    refs: {
      activeCallId: { value: 'call-alpha' },
      desiredRoomId: { value: 'room-alpha' },
      sendSocketFrame: (frame) => {
        sentFrames.push(frame);
        return true;
      },
    },
    callbacks: {
      captureClientDiagnostic: (diagnostic) => {
        diagnostics.push(diagnostic);
      },
    },
    buildClientCapabilities: async ({ participantSessionId }) => ({
      ...capabilities,
      participant_session_id: participantSessionId,
      media: {
        ...capabilities.media,
        screen_share: screenShare,
      },
      token: 'secret-token',
      cookie: 'cookie=value',
      sdp: 'v=0',
      ice_candidates: ['candidate:private'],
      encoded_frame: 'encoded-frame-bytes',
      device_label: 'private-device-label',
    }),
  });

  const pendingAdmissionSent = await bridge.sendClientCapabilities('system_welcome', {
    type: 'system/welcome',
    call_id: 'call-alpha',
    active_room_id: 'room-alpha',
    connection_id: 'call-session-alpha',
    admission: {
      requires_admission: true,
      pending_room_id: 'room-alpha',
    },
  });
  assert.equal(pendingAdmissionSent, false);
  assert.equal(sentFrames.length, 0);

  const prematureSnapshotSent = await bridge.sendClientCapabilities('room_snapshot', {
    type: 'room/snapshot',
    call_id: 'call-alpha',
    room_id: 'room-alpha',
    participant_session_id: 'call-session-alpha',
  });
  assert.equal(prematureSnapshotSent, false);
  assert.equal(sentFrames.length, 0);

  const bridgeSent = await bridge.sendClientCapabilities('system_welcome', {
    type: 'system/welcome',
    call_id: 'call-alpha',
    active_room_id: 'room-alpha',
    connection_id: 'call-session-alpha',
    admission: {
      requires_admission: false,
    },
  });
  assert.equal(bridgeSent, true);
  assert.equal(sentFrames.length, 1);
  assert.equal(sentFrames[0].type, 'client/capabilities.v1');
  assert.equal(sentFrames[0].participant_session_id, 'call-session-alpha');
  assertNoForbiddenData(sentFrames[0], 'bridge client.capabilities.v1 frame');

  const duplicateBridgeSent = await bridge.sendClientCapabilities('room_snapshot', {
    call_id: 'call-alpha',
    room_id: 'room-alpha',
    participant_session_id: 'call-session-alpha',
  });
  assert.equal(duplicateBridgeSent, false);
  assert.equal(sentFrames.length, 1);

  screenShare = false;
  const changedBridgeSent = await bridge.sendClientCapabilities('room_snapshot', {
    call_id: 'call-alpha',
    room_id: 'room-alpha',
    participant_session_id: 'call-session-alpha',
  });
  assert.equal(changedBridgeSent, true);
  assert.equal(sentFrames.length, 2);
  assert.equal(sentFrames[1].media.screen_share, false);
  assertNoForbiddenData(sentFrames[1], 'bridge changed client.capabilities.v1 frame');

  assert.equal(bridge.canPublishLocalMediaForLastPlan({
    call_id: 'call-alpha',
    room_id: 'room-alpha',
    participant_session_id: 'call-session-alpha',
  }), false);

  const handledPlan = bridge.handleRoomSnapshotMediaSessionPlan({
    media_session_plan: {
      ...plan,
      plan_epoch: 3,
      token: 'secret-token',
      sdp: 'v=0',
      ice_candidates: ['candidate:private'],
      frame: 'raw-frame',
    },
  });
  assert.equal(handledPlan.schema_version, 'king.video.media_session_plan.v1');
  assert.equal(handledPlan.participants.length, 1);
  assert.deepEqual(bridge.getLastMediaSessionPlan(), handledPlan);
  assert.equal(bridge.getLastMediaSessionPlanDiagnostic().participant_count, 1);
  assert.equal(bridge.getLastMediaSessionPlanDiagnostic().media_session_plan_present, true);
  assert.equal(bridge.canPublishLocalMediaForLastPlan({
    call_id: 'call-alpha',
    room_id: 'room-alpha',
    participant_session_id: 'call-session-alpha',
  }), true);
  assert.equal(bridge.handleClientCapabilitiesAck({
    type: 'client.capabilities.v1/ack',
    ok: true,
    stored: true,
    schema_version: 'king.video.client_capabilities.v1',
    plan_epoch: 3,
    client_capabilities: {
      participant_session_id: 'call-session-alpha',
    },
  }), true);
  assert.equal(bridge.canPublishLocalMediaForLastPlan({
    call_id: 'call-alpha',
    room_id: 'room-alpha',
    participant_session_id: 'call-session-alpha',
  }), true);
  assert.equal(bridge.canPublishLocalMediaForLastPlan({
    call_id: 'call-alpha',
    room_id: 'room-alpha',
    participant_session_id: 'call-session-beta',
  }), false);
  assert.equal(planModule.mediaSessionPlanAllowsLocalPublication(handledPlan, {
    callId: 'call-alpha',
    roomId: 'room-alpha',
    participantSessionId: 'call-session-alpha',
    minPlanEpoch: 4,
  }), false);
  assertNoForbiddenData(handledPlan, 'bridge media_session_plan.v1');
  assertNoForbiddenData(bridge.getLastMediaSessionPlanDiagnostic(), 'bridge media plan diagnostic');
  assert.ok(diagnostics.some((diagnostic) => diagnostic.eventType === 'client_capabilities_sent'));
  assert.ok(diagnostics.some((diagnostic) => diagnostic.eventType === 'client_capabilities_ack_stored'));
  assert.ok(diagnostics.some((diagnostic) => diagnostic.eventType === 'media_session_plan_received'));
  for (const diagnostic of diagnostics) {
    assertNoForbiddenData(diagnostic.payload, `diagnostic ${diagnostic.eventType}`);
  }

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
}
