import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer, transformWithOxc } from 'vite';

const contractName = 'gsp01-18-gossip-primary-plan-frame-contract';
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const callId = 'gsp01-18-call';
const roomId = 'gsp01-18-room';
const encoder = new TextEncoder();

function fail(message) {
  throw new Error(`[${contractName}] FAIL: ${message}`);
}

function sessionIdFor(peerId) {
  return `psess-${peerId}`;
}

function admittedWelcome(peerId) {
  return {
    type: 'system/welcome',
    call_id: callId,
    active_room_id: roomId,
    connection_id: sessionIdFor(peerId),
    admission: {
      requires_admission: false,
    },
  };
}

function pendingWelcome(peerId) {
  return {
    ...admittedWelcome(peerId),
    admission: {
      requires_admission: true,
      pending_room_id: roomId,
    },
  };
}

function clientCapabilities(participantSessionId) {
  return {
    schema_version: 'king.video.client_capabilities.v1',
    participant_session_id: participantSessionId,
    media: {
      camera: true,
      camera_720p30: true,
      microphone: true,
      screen_share: false,
    },
    runtime: {
      websocket: true,
      webrtc: true,
      webassembly: true,
      webcodecs: false,
      gpu: 'unknown',
      wlvc_encoder: true,
      wlvc_decoder: true,
    },
    constraints: {
      video_width: 1280,
      video_height: 720,
      video_fps: 30,
    },
  };
}

function mediaSessionPlanSnapshot(peerIds, planEpoch) {
  return {
    type: 'room/snapshot',
    call_id: callId,
    room_id: roomId,
    media_session_plan: {
      schema_version: 'king.video.media_session_plan.v1',
      call_id: callId,
      room_id: roomId,
      plan_epoch: planEpoch,
      participants: peerIds.map((peerId) => ({
        participant_session_id: sessionIdFor(peerId),
        media_state: 'streaming_720p30',
        profile: '720p30',
        transport: 'gossip',
        security_policy: 'transport_only',
      })),
    },
  };
}

function topologyHints(peerIds, topologyEpoch) {
  const hints = new Map();
  if (peerIds.length === 2) {
    hints.set(peerIds[0], [peerIds[1]]);
    hints.set(peerIds[1], [peerIds[0]]);
  } else if (peerIds.length === 3) {
    hints.set(peerIds[0], [peerIds[1]]);
    hints.set(peerIds[1], [peerIds[0], peerIds[2]]);
    hints.set(peerIds[2], [peerIds[1]]);
  } else {
    fail(`unsupported peer count ${peerIds.length}`);
  }

  return new Map(Array.from(hints.entries()).map(([peerId, neighbors]) => [
    peerId,
    {
      lane: 'ops',
      type: 'topology_hint',
      room_id: roomId,
      call_id: callId,
      peer_id: peerId,
      topology_epoch: topologyEpoch,
      neighbors: neighbors.map((neighborPeerId) => ({
        peer_id: neighborPeerId,
        transport: 'rtc_datachannel',
      })),
    },
  ]));
}

function assertBidirectionalBoundedTopology(hints) {
  for (const [peerId, hint] of hints.entries()) {
    assert.ok(hint.neighbors.length <= 5, `${peerId} must have no more than five assigned Gossip neighbors`);
    for (const neighbor of hint.neighbors) {
      const reverse = hints.get(neighbor.peer_id);
      assert.ok(
        reverse?.neighbors?.some((entry) => entry.peer_id === peerId),
        `${peerId} to ${neighbor.peer_id} must be bidirectional`,
      );
      assert.equal(neighbor.transport, 'rtc_datachannel', 'active Gossip media must use direct data-channel links');
    }
  }
}

function assertActiveGossipGateUsesPlanGatedPrimaryProof() {
  const packageJson = JSON.parse(fs.readFileSync(path.join(frontendRoot, 'package.json'), 'utf8'));
  const activeGate = String(packageJson.scripts?.['test:contract:gossip'] || '');

  assert.ok(
    activeGate.includes('gsp01-18-gossip-primary-plan-frame-contract.mjs'),
    'active Gossip release gate must include the plan-gated gossip_primary proof',
  );
  for (const staleContract of [
    'gossip-harness-faults-contract.mjs',
    'gossip-local-5-peer-network-harness-contract.mjs',
    'gossip-telemetry-contract.mjs',
    'gossip-rollout-gate-contract.mjs',
    'gossip-sfu-baseline-rollout-gate-contract.mjs',
    'gossip-native-recovery-contract.mjs',
    'gossip-dedicated-neighbor-lifecycle-contract.mjs',
    'gossip-neighbor-renegotiate-stack-contract.mjs',
    'gossip-authoritative-topology-repair-contract.mjs',
    'realtime-gossipmesh-runtime-contract.sh',
    'gossip-publisher-pipeline-decoupling-contract.mjs',
    'gossip-primary-fallback-backtrace-contract.mjs',
    'gossip-media-carrier-integration-smoke-contract.mjs',
    'gossip-sfu-dual-carrier-continuity-contract.mjs',
    'gossip-stale-target-pruning-contract.mjs',
    'gossip-neighbor-health-repair-contract.mjs',
    'gossip-neighbor-health-topology-repair-contract.mjs',
    'gossip-native-binary-data-plane-contract.mjs',
    'kingrt-three-user-regression-harness-contract.mjs',
    'gossip-docs-process-contract.mjs',
  ]) {
    assert.equal(
      activeGate.includes(staleContract),
      false,
      `active Gossip release gate must not require stale contract ${staleContract}`,
    );
  }
  assert.ok(
    activeGate.includes('gossip-media-frame-v1-contract.mjs')
      && activeGate.includes('gossip-primary-health-gate-contract.mjs')
      && activeGate.includes('gossip-server-no-media-fanout-contract.mjs')
      && activeGate.includes('../backend-king-php/tests/realtime-gossipmesh-room-state-topology-contract.sh')
      && activeGate.includes('gossip-outbound-live-publication-contract.mjs')
      && activeGate.includes('gossip-live-receive-decode-route-contract.mjs'),
    'active Gossip release gate must retain current gossip_primary frame, health, no-fanout, topology, receive, and publication proofs',
  );
}

function encodedFrameFor(peerId, frameLabel) {
  const bytes = encoder.encode(`${peerId}:${frameLabel}`);
  return {
    publisherId: peerId,
    publisherUserId: peerId,
    trackId: 'camera-main',
    type: 'keyframe',
    data: bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength),
    codecId: 'wlvc_v1',
    runtimeId: 'wlvc_sfu',
    timestamp: Date.now(),
    transportMetrics: {
      profile_frame_width: 1280,
      profile_frame_height: 720,
      profile_frame_rate: 30,
    },
  };
}

async function importTransformedSource(source, filename) {
  const transformed = await transformWithOxc(source, filename);
  return import(`data:text/javascript;base64,${Buffer.from(transformed.code).toString('base64')}`);
}

async function loadPublisherFrameDispatchForGossipPrimary() {
  const sourcePath = path.join(frontendRoot, 'src/domain/realtime/local/publisherFrameDispatch.ts');
  const source = fs.readFileSync(sourcePath, 'utf8');
  const config = {
    mode: 'gossip_primary',
    gossipPrimary: true,
    sfuFirst: false,
    sfuMirror: false,
    gossipMayPublishWithoutSfu: true,
    sfuRequiredBeforeGossip: false,
    sfuSendIsOptional: false,
    sfuFallbackAllowed: false,
    diagnosticsLabel: 'media_carrier_gossip_primary',
  };
  const patched = source
    .replace(
      "import { VIDEOCHAT_MEDIA_CARRIER_CONFIG } from '../../../lib/gossipmesh/featureFlags';\n",
      `const VIDEOCHAT_MEDIA_CARRIER_CONFIG = ${JSON.stringify(config)};\n`,
    )
    .replace(
      "import { reportSfuClientUnavailableAfterEncode } from './publisherPipelineSendFailures';\n",
      "function reportSfuClientUnavailableAfterEncode() {}\n",
    );
  assert.notEqual(patched, source, 'publisher dispatch test must inject gossip_primary carrier config');
  return importTransformedSource(patched, 'publisherFrameDispatch.gsp01-18.ts');
}

async function assertGossipPrimaryDoesNotUseSfuFallback() {
  const { dispatchPublisherFrame } = await loadPublisherFrameDispatchForGossipPrimary();
  const diagnostics = [];
  let sfuLookupCount = 0;
  let gossipPublishCount = 0;
  const result = await dispatchPublisherFrame({
    frame: encodedFrameFor('alice', 'fallback-disabled'),
    trackId: 'camera-main',
    mediaRuntimePath: 'wlvc_publisher',
    currentOpenSfuClient: () => {
      sfuLookupCount += 1;
      throw new Error('SFU fallback must not be reached in gossip_primary');
    },
    getSfuClientBufferedAmount: () => 0,
    publishLocalEncodedFrameToGossip: () => {
      gossipPublishCount += 1;
      return false;
    },
    captureClientDiagnostic: (event) => diagnostics.push(event),
    captureClientDiagnosticError: () => {},
  });

  assert.equal(gossipPublishCount, 1, 'gossip_primary must attempt Gossip publication first');
  assert.equal(sfuLookupCount, 0, 'gossip_primary must not look up or use an SFU fallback');
  assert.deepEqual(
    {
      ok: result.ok,
      gossipPublished: result.gossipPublished,
      sfuSent: result.sfuSent,
      alternatePathSuppressed: result.alternatePathSuppressed,
    },
    {
      ok: false,
      gossipPublished: false,
      sfuSent: false,
      alternatePathSuppressed: true,
    },
    'gossip_primary publish failure must fail closed instead of falling back to SFU',
  );
  assert.ok(
    diagnostics.some((event) => event?.eventType === 'gossip_primary_publish_failed'),
    'suppressed SFU fallback must be visible in diagnostics',
  );
}

function createParticipant({
  peerId,
  peerIds,
  network,
  modules,
}) {
  const {
    GossipController,
    createCallWorkspaceMediaCapabilityBridge,
    gossipFrameMessageFromEncodedFrame,
    sfuFrameFromGossipMessage,
  } = modules;
  const sequenceMap = new Map();
  const sentSocketFrames = [];
  const diagnostics = [];
  const publishedMessages = [];
  const receivedFrames = [];
  const controller = new GossipController(roomId, callId);

  controller.setDataLaneConfig({
    enabled: true,
    mode: 'active',
    publish: true,
    receive: true,
    diagnosticsLabel: 'gossip_data_active',
  });
  controller.setDataTransport({
    kind: 'rtc_datachannel',
    sendData: (targetPeerId, message, fromPeerId) => {
      network.transmissions.push({
        from_peer_id: String(fromPeerId || ''),
        target_peer_id: String(targetPeerId || ''),
        frame_id: String(message?.frame_id || ''),
        transport_kind: 'rtc_datachannel',
      });
      network.participants.get(String(targetPeerId))?.controller.handleData(String(targetPeerId), message, String(fromPeerId || ''));
    },
  });
  controller.onDataMessage((delivery) => {
    const frame = sfuFrameFromGossipMessage(delivery.message, delivery);
    if (frame) {
      receivedFrames.push({
        ...frame,
        receivingPeerId: delivery.receiving_peer_id,
        fromPeerId: delivery.from_peer_id,
      });
    }
  });
  for (const id of peerIds) {
    controller.addPeer(id);
  }

  const bridge = createCallWorkspaceMediaCapabilityBridge({
    refs: {
      activeCallId: { value: callId },
      activeSocketCallId: { value: callId },
      desiredRoomId: { value: roomId },
      serverRoomId: { value: roomId },
      participantSessionId: { value: sessionIdFor(peerId) },
      sendSocketFrame: (frame) => {
        sentSocketFrames.push(frame);
        return true;
      },
    },
    callbacks: {
      captureClientDiagnostic: (event) => diagnostics.push(event),
      publishLocalTracks: async () => {
        const message = gossipFrameMessageFromEncodedFrame(
          encodedFrameFor(peerId, `plan-release-${publishedMessages.length + 1}`),
          sequenceMap,
          {
            peerId,
            callId,
            roomId,
            plainRelay: true,
          },
        );
        assert.ok(message, `${peerId} must build an outbound Gossip media frame`);
        assert.equal(message.type, 'gossip.media.frame.v1');
        assert.equal(message.runtime_path, 'gossip_primary_direct');
        assert.equal(message.protection_mode, 'transport_only');
        assert.equal(message.profile, 'video_720p30');
        publishedMessages.push(message);
        controller.publishFrame(peerId, message);
        return true;
      },
      stopPlanBlockedLocalMedia: () => {
        fail(`${peerId} should not stop media after the matching plan is active`);
      },
    },
    buildClientCapabilities: async ({ participantSessionId }) => clientCapabilities(participantSessionId),
  });

  return {
    peerId,
    controller,
    bridge,
    sentSocketFrames,
    diagnostics,
    publishedMessages,
    receivedFrames,
  };
}

async function admitCapabilitiesAndPlan(participant, peerIds, planEpoch) {
  assert.equal(
    await participant.bridge.requestLocalMediaPublicationForLastPlan('before_admitted_join', {
      call_id: callId,
      room_id: roomId,
      participant_session_id: sessionIdFor(participant.peerId),
    }),
    false,
    'local media must stay blocked before admitted join, capabilities ack, and plan',
  );
  assert.equal(
    await participant.bridge.sendClientCapabilities('pending_lobby', pendingWelcome(participant.peerId)),
    false,
    'pending admission must not publish client capabilities',
  );
  assert.equal(
    await participant.bridge.sendClientCapabilities('admitted_join', admittedWelcome(participant.peerId)),
    true,
    'admitted websocket join must publish client capabilities',
  );
  assert.equal(participant.sentSocketFrames.length, 1, 'only the client capabilities ops frame should be sent before media');
  assert.equal(participant.sentSocketFrames[0].type, 'client/capabilities.v1');
  assert.equal(participant.sentSocketFrames[0].participant_session_id, sessionIdFor(participant.peerId));
  assert.equal(participant.sentSocketFrames[0].media.camera_720p30, true);
  assert.equal(participant.sentSocketFrames[0].runtime.webrtc, true);

  assert.equal(
    participant.bridge.handleClientCapabilitiesAck({
      type: 'client.capabilities.v1/ack',
      ok: true,
      stored: true,
      call_id: callId,
      room_id: roomId,
      plan_epoch: planEpoch,
      client_capabilities: {
        participant_session_id: sessionIdFor(participant.peerId),
      },
    }),
    true,
    'stored client capabilities ack must open the media-session-plan gate',
  );
  participant.bridge.handleRoomSnapshotMediaSessionPlan(mediaSessionPlanSnapshot(peerIds, planEpoch));
}

async function runPlanToGossipScenario({
  scenarioName,
  peerIds,
  modules,
  planEpoch,
}) {
  const network = {
    participants: new Map(),
    transmissions: [],
  };
  for (const peerId of peerIds) {
    const participant = createParticipant({
      peerId,
      peerIds,
      network,
      modules,
    });
    network.participants.set(peerId, participant);
  }

  const hints = topologyHints(peerIds, planEpoch);
  assertBidirectionalBoundedTopology(hints);
  for (const [peerId, hint] of hints.entries()) {
    const participant = network.participants.get(peerId);
    assert.equal(participant.controller.applyTopologyHint(peerId, hint), true, `${scenarioName} topology must apply for ${peerId}`);
    assert.deepEqual(
      participant.controller.getPeer(peerId).neighbor_set,
      hint.neighbors.map((neighbor) => neighbor.peer_id),
      `${scenarioName} must use exactly the server-provided neighbor list for ${peerId}`,
    );
  }

  try {
    for (const participant of network.participants.values()) {
      await admitCapabilitiesAndPlan(participant, peerIds, planEpoch);
    }

    for (const participant of network.participants.values()) {
      assert.equal(
        await participant.bridge.requestLocalMediaPublicationForLastPlan(`${scenarioName}_matching_plan`, {
          call_id: callId,
          room_id: roomId,
          participant_session_id: sessionIdFor(participant.peerId),
        }),
        true,
        `${scenarioName} ${participant.peerId} must publish after admitted join, capabilities ack, and media_session_plan`,
      );
    }

    assert.ok(network.transmissions.length > 0, `${scenarioName} must send frames over the Gossip data lane`);
    assert.ok(
      network.transmissions.every((entry) => entry.transport_kind === 'rtc_datachannel'),
      `${scenarioName} must not use server media fanout for Gossip frames`,
    );
    for (const participant of network.participants.values()) {
      assert.ok(
        participant.sentSocketFrames.every((frame) => frame.type === 'client/capabilities.v1'),
        `${scenarioName} server lane must carry capabilities only, not media frames`,
      );
    }

    for (const publisherId of peerIds) {
      const publisher = network.participants.get(publisherId);
      assert.equal(publisher.publishedMessages.length, 1, `${scenarioName} ${publisherId} must publish one local Gossip frame`);
      for (const receiverId of peerIds.filter((id) => id !== publisherId)) {
        const receiver = network.participants.get(receiverId);
        const frame = receiver.receivedFrames.find((entry) => (
          entry.publisherId === publisherId
          && entry.trackId === 'camera-main'
          && entry.transportPath === 'gossip_primary_direct'
        ));
        assert.ok(frame, `${scenarioName} ${receiverId} must receive ${publisherId}'s Gossip frame`);
        assert.equal(frame.protectionMode, 'transport_only');
        assert.equal(frame.gossipProfile, 'video_720p30');
        assert.ok(frame.data.byteLength > 0, `${scenarioName} ${receiverId} must receive media payload bytes`);
      }
    }

    return network;
  } finally {
    for (const participant of network.participants.values()) {
      participant.controller.dispose();
    }
  }
}

let server = null;

try {
  assertActiveGossipGateUsesPlanGatedPrimaryProof();

  server = await createServer({
    configFile: path.resolve(frontendRoot, 'vite.config.js'),
    logLevel: 'error',
    server: { middlewareMode: true, hmr: false },
    appType: 'custom',
  });
  const [
    { GossipController },
    mediaCapabilityBridge,
    gossipFrameEnvelope,
    { resolveVideochatMediaCarrierConfig },
  ] = await Promise.all([
    server.ssrLoadModule('/src/lib/gossipmesh/gossipController.ts'),
    server.ssrLoadModule('/src/domain/realtime/workspace/callWorkspace/mediaCapabilityPlanBridge.ts'),
    server.ssrLoadModule('/src/domain/realtime/workspace/callWorkspace/gossipMediaFrameEnvelope.ts'),
    server.ssrLoadModule('/src/lib/gossipmesh/mediaCarrierMode.ts'),
  ]);

  assert.deepEqual(
    resolveVideochatMediaCarrierConfig({ VITE_VIDEOCHAT_MEDIA_CARRIER: 'gossip_primary' }),
    {
      envKey: 'VITE_VIDEOCHAT_MEDIA_CARRIER',
      mode: 'gossip_primary',
      gossipPrimary: true,
      sfuFirst: false,
      sfuMirror: false,
      gossipMayPublishWithoutSfu: true,
      sfuRequiredBeforeGossip: false,
      sfuSendIsOptional: false,
      sfuFallbackAllowed: false,
      diagnosticsLabel: 'media_carrier_gossip_primary',
    },
    'gossip_primary carrier config must explicitly disable SFU fallback dependency',
  );

  const modules = {
    GossipController,
    createCallWorkspaceMediaCapabilityBridge: mediaCapabilityBridge.createCallWorkspaceMediaCapabilityBridge,
    gossipFrameMessageFromEncodedFrame: gossipFrameEnvelope.gossipFrameMessageFromEncodedFrame,
    sfuFrameFromGossipMessage: gossipFrameEnvelope.sfuFrameFromGossipMessage,
  };

  await runPlanToGossipScenario({
    scenarioName: 'two_peer',
    peerIds: ['alice', 'bob'],
    modules,
    planEpoch: 18,
  });
  await runPlanToGossipScenario({
    scenarioName: 'three_peer',
    peerIds: ['alice', 'bob', 'charlie'],
    modules,
    planEpoch: 19,
  });
  await assertGossipPrimaryDoesNotUseSfuFallback();

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
