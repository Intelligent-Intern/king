import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

const contractName = 'local-media-session-plan-gate-contract';
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

function fail(message) {
  throw new Error(`[${contractName}] FAIL: ${message}`);
}

function read(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

function streamingPlan(planEpoch, participantSessionId = 'call-session-alpha', mediaState = 'streaming_720p30') {
  return {
    type: 'room/snapshot',
    call_id: 'call-alpha',
    room_id: 'room-alpha',
    participant_session_id: 'call-session-alpha',
    media_session_plan: {
      schema_version: 'king.video.media_session_plan.v1',
      call_id: 'call-alpha',
      room_id: 'room-alpha',
      plan_epoch: planEpoch,
      participants: [
        {
          participant_session_id: participantSessionId,
          media_state: mediaState,
          profile: mediaState === 'streaming_720p30' ? '720p30' : '',
          transport: mediaState === 'streaming_720p30' ? 'gossip' : '',
          security_policy: 'required',
        },
      ],
    },
  };
}

let server = null;

try {
  const mediaStack = read('src/domain/realtime/workspace/callWorkspace/mediaStack.ts');
  const socketLifecycle = read('src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts');
  const bridgeSource = read('src/domain/realtime/workspace/callWorkspace/mediaCapabilityPlanBridge.ts');

  assert.match(bridgeSource, /requestLocalMediaPublicationForLastPlan/);
  assert.match(bridgeSource, /registerMediaPlanLocalPublicationCallbacks/);
  assert.match(bridgeSource, /admittedWebsocketJoinKey !== key/);
  assert.match(bridgeSource, /lastCapabilityAckStoredKey !== key/);
  assert.match(socketLifecycle, /type === 'client\.capabilities\.v1\/ack'/);
  assert.match(socketLifecycle, /applyLocalMediaStateForLastPlan\('client_capabilities_ack', payload\)/);
  assert.match(mediaStack, /registerMediaPlanLocalPublicationCallbacks\(\{/);
  assert.match(mediaStack, /requestLocalMediaPublicationForActivePlan\('local_media_publish_request'/);
  assert.match(mediaStack, /publishLocalTracks: publishLocalTracksForMediaPlan/);
  assert.match(mediaStack, /publishLocalTracksToSfuIfReady: publishLocalTracksToSfuIfReadyForMediaPlan/);

  server = await createServer({
    configFile: path.resolve(frontendRoot, 'vite.config.js'),
    logLevel: 'error',
    server: { middlewareMode: true, hmr: false },
    appType: 'custom',
  });

  const bridgeModule = await server.ssrLoadModule('/src/domain/realtime/workspace/callWorkspace/mediaCapabilityPlanBridge.ts');
  await server.ssrLoadModule('/src/domain/realtime/workspace/callWorkspace/mediaStack.ts');
  const sentFrames = [];
  const diagnostics = [];
  const publishCalls = [];
  const stopCalls = [];
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
      captureClientDiagnostic: (diagnostic) => diagnostics.push(diagnostic),
      publishLocalTracks: async () => {
        publishCalls.push('plan-release');
        return true;
      },
      stopPlanBlockedLocalMedia: () => {
        stopCalls.push('plan-block');
      },
    },
    buildClientCapabilities: async ({ participantSessionId }) => ({
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
    }),
  });

  assert.equal(await bridge.requestLocalMediaPublicationForLastPlan('runtime_switching', {
    call_id: 'call-alpha',
    room_id: 'room-alpha',
    participant_session_id: 'call-session-alpha',
  }), false);
  assert.deepEqual(publishCalls, []);

  assert.equal(await bridge.sendClientCapabilities('system_welcome', {
    type: 'system/welcome',
    call_id: 'call-alpha',
    active_room_id: 'room-alpha',
    connection_id: 'call-session-alpha',
    admission: {
      requires_admission: true,
      pending_room_id: 'room-alpha',
    },
  }), false);
  assert.equal(sentFrames.length, 0);

  assert.equal(await bridge.sendClientCapabilities('system_welcome', {
    type: 'system/welcome',
    call_id: 'call-alpha',
    active_room_id: 'room-alpha',
    connection_id: 'call-session-alpha',
    admission: {
      requires_admission: false,
    },
  }), true);
  assert.equal(sentFrames.length, 1);
  assert.equal(await bridge.requestLocalMediaPublicationForLastPlan('remote_peer_count_changed', {
    call_id: 'call-alpha',
    room_id: 'room-alpha',
    participant_session_id: 'call-session-alpha',
  }), false);
  assert.deepEqual(publishCalls, []);

  bridge.handleRoomSnapshotMediaSessionPlan(streamingPlan(2));
  assert.equal(await bridge.applyLocalMediaStateForLastPlan('room_snapshot', streamingPlan(2)), false);
  assert.deepEqual(publishCalls, []);

  assert.equal(bridge.handleClientCapabilitiesAck({
    type: 'client.capabilities.v1/ack',
    ok: false,
    stored: false,
    plan_epoch: 3,
    client_capabilities: {
      participant_session_id: 'call-session-alpha',
    },
  }), false);
  assert.equal(await bridge.requestLocalMediaPublicationForLastPlan('runtime_switching', {
    call_id: 'call-alpha',
    room_id: 'room-alpha',
    participant_session_id: 'call-session-alpha',
  }), false);
  assert.deepEqual(publishCalls, []);

  assert.equal(bridge.handleClientCapabilitiesAck({
    type: 'client.capabilities.v1/ack',
    ok: true,
    stored: true,
    plan_epoch: 3,
    client_capabilities: {
      participant_session_id: 'call-session-alpha',
    },
  }), true);
  assert.equal(await bridge.applyLocalMediaStateForLastPlan('stale_plan_epoch', streamingPlan(2)), false);
  assert.deepEqual(publishCalls, []);

  bridge.handleRoomSnapshotMediaSessionPlan(streamingPlan(3, 'call-session-beta'));
  assert.equal(await bridge.applyLocalMediaStateForLastPlan('wrong_participant', streamingPlan(3, 'call-session-beta')), false);
  assert.deepEqual(publishCalls, []);

  bridge.handleRoomSnapshotMediaSessionPlan(streamingPlan(3, 'call-session-alpha', 'waiting_for_gossip'));
  assert.equal(await bridge.applyLocalMediaStateForLastPlan('waiting_for_gossip', streamingPlan(3, 'call-session-alpha', 'waiting_for_gossip')), false);
  assert.deepEqual(publishCalls, []);

  bridge.handleRoomSnapshotMediaSessionPlan(streamingPlan(3));
  assert.equal(await bridge.applyLocalMediaStateForLastPlan('matching_streaming_plan', streamingPlan(3)), true);
  assert.deepEqual(publishCalls, ['plan-release']);

  const newerCapabilitiesAck = {
    type: 'client.capabilities.v1/ack',
    ok: true,
    stored: true,
    plan_epoch: 5,
    client_capabilities: {
      participant_session_id: 'call-session-alpha',
    },
  };
  assert.equal(bridge.handleClientCapabilitiesAck(newerCapabilitiesAck), true);
  assert.equal(await bridge.applyLocalMediaStateForLastPlan('client_capabilities_ack', newerCapabilitiesAck), false);
  assert.deepEqual(stopCalls, ['plan-block']);
  assert.equal(await bridge.requestLocalMediaPublicationForLastPlan('runtime_switching', {
    call_id: 'call-alpha',
    room_id: 'room-alpha',
    participant_session_id: 'call-session-alpha',
  }), false);
  assert.deepEqual(publishCalls, ['plan-release']);

  bridge.handleRoomSnapshotMediaSessionPlan(streamingPlan(4));
  assert.equal(await bridge.applyLocalMediaStateForLastPlan('stale_snapshot_after_capability_change', streamingPlan(4)), false);
  assert.deepEqual(stopCalls, ['plan-block']);
  assert.deepEqual(publishCalls, ['plan-release']);

  bridge.handleRoomSnapshotMediaSessionPlan(streamingPlan(5));
  assert.equal(await bridge.applyLocalMediaStateForLastPlan('matching_snapshot_after_capability_change', streamingPlan(5)), true);
  assert.deepEqual(publishCalls, ['plan-release', 'plan-release']);

  assert.ok(diagnostics.some((diagnostic) => diagnostic.eventType === 'media_session_plan_local_publication_blocked'));
  assert.ok(diagnostics.some((diagnostic) => diagnostic.eventType === 'media_session_plan_local_publication_started'));
  assert.ok(diagnostics.some((diagnostic) => diagnostic.eventType === 'media_session_plan_local_publication_stopped'));

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
