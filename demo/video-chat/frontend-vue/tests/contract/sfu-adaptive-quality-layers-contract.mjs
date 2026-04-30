import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL, fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-adaptive-quality-layers-contract] FAIL: ${message}`);
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
  const packageJson = read('package.json');
  const adaptiveLayers = read('src/domain/realtime/sfu/adaptiveQualityLayers.js');
  const receiverFeedback = read('src/domain/realtime/sfu/receiverFeedback.js');
  const frameDecode = read('src/domain/realtime/sfu/frameDecode.js');
  const browserRenderer = read('src/domain/realtime/sfu/remoteBrowserEncodedVideo.js');
  const renderScheduler = read('src/domain/realtime/sfu/remoteRenderScheduler.js');
  const videoLayout = read('src/domain/realtime/workspace/callWorkspace/videoLayout.js');
  const mediaStack = read('src/domain/realtime/workspace/callWorkspace/mediaStack.js');
  const runtimeHealth = read('src/domain/realtime/workspace/callWorkspace/runtimeHealth.js');
  const runtimeSwitching = read('src/domain/realtime/workspace/callWorkspace/runtimeSwitching.js');
  const socketLifecycle = read('src/domain/realtime/workspace/callWorkspace/socketLifecycle.js');
  const sfuTransport = read('src/domain/realtime/workspace/callWorkspace/sfuTransport.js');
  const sfuClient = read('src/lib/sfu/sfuClient.ts');
  const workspaceView = read('src/domain/realtime/CallWorkspaceView.vue');
  const sfuGateway = read('../backend-king-php/domain/realtime/realtime_sfu_gateway.php');
  const sfuStore = read('../backend-king-php/domain/realtime/realtime_sfu_store.php');
  const sfuSubscriberBudget = read('../backend-king-php/domain/realtime/realtime_sfu_subscriber_budget.php');
  const sfuBrokerReplay = read('../backend-king-php/domain/realtime/realtime_sfu_broker_replay.php');

  requireContains(packageJson, 'sfu-adaptive-quality-layers-contract.mjs', 'SFU contract suite includes adaptive layer proof');

  requireContains(adaptiveLayers, 'SFU_ADAPTIVE_LAYER_PREFERENCES', 'adaptive layer helper declares canonical layer preferences');
  requireContains(adaptiveLayers, "'prefer_primary_video_layer'", 'primary layer request is explicit');
  requireContains(adaptiveLayers, "'prefer_thumbnail_video_layer'", 'thumbnail layer request is explicit');
  requireContains(adaptiveLayers, "[SFU_ADAPTIVE_LAYER_PREFERENCES.PRIMARY]: 'quality'", 'primary layer targets the best automatic profile');
  requireContains(adaptiveLayers, "[SFU_ADAPTIVE_LAYER_PREFERENCES.THUMBNAIL]: 'realtime'", 'thumbnail layer remains usable instead of rescue-only');
  requireContains(adaptiveLayers, 'visibleParticipantCountForPeer', 'layer decisions can keep two-person grid quality high');
  requireContains(adaptiveLayers, 'REMOTE_RENDER_SURFACE_ROLES.GRID', 'grid role participates in automatic layer selection');

  requireContains(renderScheduler, 'callVideoVisibleParticipantCount', 'surface role binding records visible participant count');
  requireContains(videoLayout, 'visibleParticipantCount = participants.length', 'grid layout passes actual visible count');
  requireContains(videoLayout, 'const visibleParticipantCount = 1 + miniParticipants.length', 'main layout passes primary plus mini count');

  requireContains(receiverFeedback, 'maybeSendReceiverLayerPreference', 'receiver feedback exposes layer preference requests');
  requireContains(receiverFeedback, 'buildSfuLayerPreferencePayload', 'receiver feedback sends structured layer payload');
  requireContains(receiverFeedback, 'shouldSendSfuLayerPreference', 'receiver feedback throttles layer preference churn per track');
  requireContains(receiverFeedback, '`sfu_receiver_${layerPreference}_layer_preference`', 'receiver feedback reason preserves requested layer');
  requireContains(frameDecode, 'receiverFeedback.maybeSendReceiverLayerPreference', 'WLVC receiver sends layer preference after accepted render');
  requireContains(browserRenderer, 'requestRemoteSfuLayerPreference', 'browser decoder path sends layer preference after accepted render');

  requireContains(mediaStack, 'setSubscriberLayerPreference', 'receiver layer preference goes to the SFU socket');
  requireContains(mediaStack, "requestedAction === 'prefer_primary_video_layer' || requestedAction === 'prefer_thumbnail_video_layer'", 'pure layer preference does not force global publisher pressure');
  requireContains(sfuClient, "type: 'sfu/layer-preference'", 'SFU client sends server-authoritative subscriber layer preference');
  requireContains(sfuStore, "'sfu/layer-preference'", 'SFU backend accepts layer preference control frames');
  requireContains(sfuGateway, 'videochat_sfu_apply_subscriber_layer_preference($sfuClients[$clientId], $msg)', 'SFU gateway stores subscriber layer preference server-side');
  requireContains(sfuSubscriberBudget, 'videochat_sfu_subscriber_frame_route_decision', 'SFU server routes frames by subscriber preference');
  requireContains(sfuSubscriberBudget, 'thumbnail_subscriber_delta_cadence', 'thumbnail subscribers cannot force primary receivers down');
  requireContains(sfuBrokerReplay, '$subscriber = []', 'cross-worker replay receives subscriber state for layer routing');

  requireContains(mediaStack, 'payload?.requested_action || (requestFullKeyframe ? ', 'media stack preserves explicit adaptive layer actions');
  requireContains(runtimeHealth, 'payload?.requested_action || (requestFullKeyframe ? ', 'runtime health preserves explicit adaptive layer actions');
  requireContains(socketLifecycle, 'prefer_primary_video_layer', 'publisher handles primary layer requests');
  requireContains(socketLifecycle, 'prefer_thumbnail_video_layer', 'publisher handles thumbnail layer requests');
  requireContains(socketLifecycle, 'function sfuTransportStateForSocketLifecycle()', 'socket lifecycle guards adaptive layer state wiring');
  requireContains(socketLifecycle, 'fallbackSfuTransportState', 'socket lifecycle cannot crash when isolated tests omit SFU transport state');
  requireContains(socketLifecycle, 'sfuRemotePrimaryLayerRequestedUntilMs', 'publisher protects active primary layer from thumbnail downshift');
  requireContains(socketLifecycle, 'ignoredThumbnailRequest', 'publisher ignores thumbnail downshift while a primary request is active');
  requireContains(socketLifecycle, "direction: 'up'", 'primary layer request triggers automatic upshift');
  requireContains(socketLifecycle, "requested_video_quality_profile: requestedVideoQualityProfile || 'quality'", 'primary layer asks for quality profile');
  requireContains(socketLifecycle, "requested_video_quality_profile: requestedVideoQualityProfile || 'realtime'", 'thumbnail layer asks for realtime profile');

  requireContains(runtimeSwitching, 'bypassQualityRecoveryCooldown', 'targeted primary request bypasses normal slow recovery cooldown');
  requireContains(runtimeSwitching, 'requestedProfileForDirection', 'profile switcher can jump to requested automatic layer profile');
  requireContains(runtimeSwitching, "'sfu_remote_thumbnail_layer_requested'", 'thumbnail pressure has explicit downgrade reason');
  requireContains(sfuTransport, 'sfuRemotePrimaryLayerRequestedUntilMs', 'transport state stores primary layer TTL');
  const socketHelperStart = workspaceView.indexOf('createCallWorkspaceSocketHelpers({');
  const socketHelperEnd = workspaceView.indexOf('state: socketLifecycleState', socketHelperStart);
  assert.ok(socketHelperStart >= 0 && socketHelperEnd > socketHelperStart, 'CallWorkspaceView socket helper block must be present');
  assert.ok(
    workspaceView.slice(socketHelperStart, socketHelperEnd).includes('sfuTransportState,'),
    'CallWorkspaceView must inject sfuTransportState into socket lifecycle helpers before adaptive layer messages arrive',
  );

  const moduleUrl = pathToFileURL(path.resolve(frontendRoot, 'src/domain/realtime/sfu/adaptiveQualityLayers.js')).href;
  const adaptiveModule = await import(moduleUrl);
  assert.equal(
    adaptiveModule.sfuLayerPreferenceForRemoteSurfaceRole('fullscreen'),
    'primary',
    'fullscreen must request the primary layer',
  );
  assert.equal(
    adaptiveModule.sfuLayerPreferenceForRemoteSurfaceRole('main'),
    'primary',
    'main participant must request the primary layer',
  );
  assert.equal(
    adaptiveModule.sfuLayerPreferenceForRemoteSurfaceRole('grid', { visibleParticipantCount: 2 }),
    'primary',
    'two-person grid must keep primary quality instead of thumbnail-only video',
  );
  assert.equal(
    adaptiveModule.sfuLayerPreferenceForRemoteSurfaceRole('grid', { visibleParticipantCount: 4 }),
    'thumbnail',
    'larger grid must request thumbnail delivery',
  );
  assert.equal(
    adaptiveModule.buildSfuLayerPreferencePayload({ layerPreference: 'primary', renderSurfaceRole: 'main' }).requested_action,
    'prefer_primary_video_layer',
    'primary payload must use the primary layer action',
  );
  assert.equal(
    adaptiveModule.buildSfuLayerPreferencePayload({ layerPreference: 'thumbnail', renderSurfaceRole: 'mini' }).requested_video_quality_profile,
    'realtime',
    'thumbnail payload must target realtime, not rescue',
  );

  process.stdout.write('[sfu-adaptive-quality-layers-contract] PASS\n');
}

main().catch((error) => {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
});
