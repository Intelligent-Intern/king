import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { loadViteSsrModule } from './viteSsrLoader.mjs'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')
const repoRoot = path.resolve(frontendRoot, '../../..')

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8')
}

const strictPolicy = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/strictStabilityPolicy.ts')
const plannedParking = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/plannedGossipSfuRecovery.ts')
const workspaceLifecycle = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/lifecycle.ts')
const sfuLifecycle = read('demo/video-chat/frontend-vue/src/domain/realtime/sfu/lifecycle.ts')
const screenShare = read('demo/video-chat/frontend-vue/src/domain/realtime/local/screenSharePublisher.js')
const publisherPipeline = read('demo/video-chat/frontend-vue/src/domain/realtime/local/publisherPipeline.ts')
const publisherSourceReadback = read('demo/video-chat/frontend-vue/src/domain/realtime/local/publisherSourceReadback.ts')
const publisherVideoFrameSource = read('demo/video-chat/frontend-vue/src/domain/realtime/local/publisherVideoFrameSource.ts')
const protectedBrowserVideoEncoder = read('demo/video-chat/frontend-vue/src/domain/realtime/local/protectedBrowserVideoEncoder.ts')
const remoteBrowserEncodedVideo = read('demo/video-chat/frontend-vue/src/domain/realtime/sfu/remoteBrowserEncodedVideo.ts')
const waveletProcessorPipeline = read('demo/video-chat/frontend-vue/src/lib/wavelet/processor-pipeline.ts')
const gossipRecoveryOps = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/gossipRecoveryOps.ts')
const gossipNeighborLifecycle = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/gossipNeighborLifecycle.ts')
const gossipDataLane = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts')
const packageJson = read('demo/video-chat/frontend-vue/package.json')

function requireMatch(source, pattern, message) {
  assert.match(source, pattern, `[gossip-primary-one-connect-media-runtime-contract] ${message}`)
}

requireMatch(
  strictPolicy,
  /disableSfuConnectRetry:\s*true[\s\S]*disableSfuLocalTrackPublishRetry:\s*true[\s\S]*disableGossipNeighborRenegotiate:\s*true[\s\S]*disableLocalTrackRecovery:\s*true[\s\S]*disableScreenShareSfuReconnect:\s*true/,
  'strict policy must expose explicit one-connect media retry blockers',
)
requireMatch(
  plannedParking,
  /function plannedGossipOneConnectMediaRecoveryPayload[\s\S]*one_connect_media_policy:\s*'gossip_primary_new_participant_only'[\s\S]*automatic_media_restart_allowed:\s*false[\s\S]*automatic_repair_allowed:\s*false[\s\S]*next_connect_cycle_requires_new_participant:\s*true/,
  'planned Gossip parking must include one-connect media semantics in diagnostics',
)
requireMatch(
  workspaceLifecycle,
  /function shouldStartSfuFromLifecycle\(reason = 'sfu_lifecycle_connect'\)[\s\S]*!VIDEOCHAT_MEDIA_CARRIER_CONFIG\.gossipPrimary[\s\S]*eventType:\s*'gossip_primary_sfu_lifecycle_connect_parked'/,
  'call workspace lifecycle must park SFU lifecycle connects in Gossip-primary',
)
requireMatch(
  workspaceLifecycle,
  /watch\(\s*shouldConnectSfu,[\s\S]*shouldStartSfuFromLifecycle\('should_connect_sfu_watch'\)[\s\S]*initSFU\(\);/,
  'route, snapshot, focus, and visibility state churn must not let the shouldConnectSfu watcher reconnect SFU in Gossip-primary',
)
requireMatch(
  workspaceLifecycle,
  /if \(shouldStartSfuFromLifecycle\('workspace_mount'\)\) \{\s*\n\s*initSFU\(\);/,
  'workspace mount SFU connect must also honor the Gossip-primary lifecycle guard',
)
requireMatch(
  workspaceLifecycle,
  /const shouldUseSfuRuntime = sfuRuntimeEnabled\s*\|\|\s*VIDEOCHAT_MEDIA_CARRIER_CONFIG\.gossipPrimary\s*\|\|\s*!mediaRuntimeCapabilities\.value\.stageB;[\s\S]*await switchMediaRuntimePath\('wlvc_wasm', 'capability_probe_stage_a'\);/,
  'Gossip-primary must select the WLVC runtime when stage A is available instead of falling through to blocked native/unsupported mode',
)

requireMatch(
  sfuLifecycle,
  /planned_gossip_sfu_connect_retry_parked[\s\S]*sfuConnectRetryDisabled\('sfu_connect_retry_before_active'[\s\S]*setTimeout\(\(\) => requestSfuConnect\(\), sfuConnectRetryDelayMs\)/,
  'SFU pre-active connect retry must be parked before scheduling requestSfuConnect',
)
requireMatch(
  sfuLifecycle,
  /planned_gossip_sfu_local_track_publish_retry_parked[\s\S]*function scheduleLocalTrackPublish\(attempt = 0\)[\s\S]*sfuLocalTrackPublishRetryDisabled\('sfu_local_track_publish_retry'[\s\S]*setTimeout\(\(\) => \{[\s\S]*scheduleLocalTrackPublish\(attempt \+ 1\)/,
  'SFU local track publish retry must be parked before scheduling another publish attempt',
)
requireMatch(
  sfuLifecycle,
  /planned_gossip_sfu_disconnect_reconnect_parked[\s\S]*sfu_disconnected_after_connect[\s\S]*setTimeout\(\(\) => requestSfuConnect\(\), 2000\)/,
  'SFU post-connect disconnect reconnect must be parked before the legacy reconnect timer',
)

requireMatch(
  screenShare,
  /planned_gossip_screen_share_sfu_reconnect_parked[\s\S]*function scheduleScreenSfuReconnect\(reason = 'sfu_disconnected'\)[\s\S]*parkScreenShareSfuReconnect\(reason[\s\S]*reconnectTimer = setTimeout/,
  'screen-share SFU reconnect loop must be parked before scheduling its timer',
)
requireMatch(
  screenShare,
  /const useSfuTransport = refs\.shouldConnectSfu\.value === true && !useGossipPrimaryTransport/,
  'Gossip-primary screen share must not open the screen-share SFU socket path',
)
requireMatch(
  screenShare,
  /const useGossipPrimaryTransport = VIDEOCHAT_MEDIA_CARRIER_CONFIG\.gossipPrimary[\s\S]*const useSfuTransport = refs\.shouldConnectSfu\.value === true && !useGossipPrimaryTransport[\s\S]*if \(!useGossipPrimaryTransport && !callbacks\.isWlvcRuntimePath\?\.\(\)\)/,
  'Gossip-primary screen share must not be blocked by the old SFU runtime validation',
)

requireMatch(
  publisherPipeline,
  /gossip_primary_local_track_recovery_parked[\s\S]*function scheduleLocalTrackRecovery\(reason = 'track_ended'\)[\s\S]*automaticLocalTrackRecoveryDisabled\(reason\)[\s\S]*state\.localTrackRecoveryTimer = setTimeout/,
  'local track recovery must be parked before it can restart camera or microphone capture',
)
requireMatch(
  publisherPipeline,
  /createPublisherSourceReadbackController\(\{[\s\S]*preferDomCanvasReadback:\s*VIDEOCHAT_MEDIA_CARRIER_CONFIG\.gossipPrimary/,
  'Gossip-primary WLVC capture must avoid MediaStreamTrackProcessor VideoFrame allocation and use canvas readback',
)
requireMatch(
  publisherPipeline,
  /const protectedBrowserPublisher = VIDEOCHAT_MEDIA_CARRIER_CONFIG\.gossipPrimary\s*\?\s*null\s*:\s*await maybeStartProtectedBrowserVideoEncoderPublisher\(\{/,
  'Gossip-primary must not enter the protected browser WebCodecs VideoFrame publisher path',
)
requireMatch(
  publisherSourceReadback,
  /const videoFrameSourceDisabled = preferDomCanvasReadback === true;[\s\S]*return !videoFrameSourceDisabled[\s\S]*createPublisherVideoFrameSourceReader\(\{/,
  'source readback must keep the VideoFrame reader behind the explicit DOM-canvas kill switch',
)
requireMatch(
  publisherSourceReadback,
  /return \{\s*source: result\.frame,[\s\S]*closeSource: \(\) => closePublisherVideoFrame\(result\.frame\),[\s\S]*\} finally \{\s*closeSource\(\);[\s\S]*\}/,
  'source readback must close every consumed VideoFrame in a finally block',
)
requireMatch(
  publisherVideoFrameSource,
  /readPromise\.then\(closePublisherVideoFrameReadResult\)[\s\S]*publisher_video_frame_read_timeout/,
  'VideoFrame reader timeouts must close late frames returned by the browser',
)
requireMatch(
  publisherVideoFrameSource,
  /function closePendingReadResults\(\)[\s\S]*readPromise\.then\(closePublisherVideoFrameReadResult\)/,
  'VideoFrame reader close must also close pending late frames',
)
requireMatch(
  protectedBrowserVideoEncoder,
  /finally \{\s*closePublisherVideoFrame\(thumbnailFrame\);[\s\S]*closePublisherVideoFrame\(primaryFrame\);[\s\S]*\}[\s\S]*finally \{\s*closePublisherVideoFrame\(result\.frame\);[\s\S]*\}/,
  'SFU WebCodecs fallback must close source and scaled VideoFrames when it is explicitly selected',
)
requireMatch(
  remoteBrowserEncodedVideo,
  /finally \{\s*try \{\s*videoFrame\?\.close\?\.\(\);/,
  'remote WebCodecs decoder output must close decoded VideoFrames after render attempts',
)
requireMatch(
  waveletProcessorPipeline,
  /finally \{\s*frame\.close\(\)[\s\S]*\}[\s\S]*finally \{\s*decoded\.close\(\)[\s\S]*\}/,
  'Wavelet processor must close locally-created and decoded VideoFrames',
)
requireMatch(
  gossipRecoveryOps,
  /planned_gossip_native_recovery_request_parked[\s\S]*function requestOverOpsLane[\s\S]*parkGossipNativeRecovery[\s\S]*type:\s*'gossip\/recovery\/request'/,
  'Gossip-native recovery requests must be parked before sending repair ops',
)
requireMatch(
  gossipNeighborLifecycle,
  /allowAutomaticRenegotiate = \(\) => true[\s\S]*gossip_neighbor_renegotiate_parked[\s\S]*function scheduleQueuedRenegotiate\(peer, reason = 'queued_renegotiate'\)[\s\S]*automaticRenegotiateAllowed\(peer, reason\)/,
  'dedicated Gossip neighbor queued renegotiation must be explicitly guardable and parked',
)
requireMatch(
  gossipDataLane,
  /allowAutomaticRenegotiate:\s*\(\) => !VIDEOCHAT_MEDIA_CARRIER_CONFIG\.gossipPrimary[\s\S]*disableGossipNeighborRenegotiate/,
  'Gossip-primary data lane must opt out of automatic dedicated-neighbor renegotiation',
)

assert.ok(
  packageJson.includes('gossip-primary-one-connect-media-runtime-contract.mjs'),
  '[gossip-primary-one-connect-media-runtime-contract] gossip contract suite must include this proof',
)

const sourceReadbackModule = await loadViteSsrModule(
  frontendRoot,
  '/src/domain/realtime/local/publisherSourceReadback.ts',
)

let mediaStreamTrackProcessorConstructed = 0
class FailingMediaStreamTrackProcessor {
  constructor() {
    mediaStreamTrackProcessorConstructed += 1
    throw new Error('MediaStreamTrackProcessor must not be constructed in Gossip-primary DOM readback')
  }
}

function createFakeCanvas() {
  return {
    width: 0,
    height: 0,
    getContext(type) {
      assert.equal(type, '2d')
      return {
        drawImage() {},
        getImageData() {
          return {
            data: new Uint8ClampedArray(16),
            height: 2,
            width: 2,
          }
        },
        putImageData() {},
      }
    },
  }
}

const domReadbackController = sourceReadbackModule.createPublisherSourceReadbackController({
  video: {
    dataset: {},
    readyState: 2,
    videoHeight: 360,
    videoWidth: 640,
  },
  videoTrack: {
    id: 'gossip-primary-track',
    getSettings() {
      return { frameRate: 30, height: 360, width: 640 }
    },
  },
  videoProfile: {
    encodeIntervalMs: 33,
    frameHeight: 360,
    frameWidth: 640,
    readbackIntervalMs: 33,
  },
  documentRef: {
    createElement(tagName) {
      assert.equal(tagName, 'canvas')
      return createFakeCanvas()
    },
  },
  globalScope: {
    MediaStreamTrackProcessor: FailingMediaStreamTrackProcessor,
  },
  captureCapabilities: {
    preferredCaptureBackend: 'video_frame_copy',
    supportsMediaStreamTrackProcessor: true,
    supportsVideoFrame: true,
    supportsVideoFrameClose: true,
    supportsVideoFrameCopyTo: true,
  },
  preferDomCanvasReadback: true,
})
assert.equal(mediaStreamTrackProcessorConstructed, 0, '[gossip-primary-one-connect-media-runtime-contract] Gossip-primary DOM readback must not allocate browser VideoFrames')
assert.equal(domReadbackController.sourceBackend, 'dom_canvas_compatibility_fallback')
await domReadbackController.close()

console.log('[gossip-primary-one-connect-media-runtime-contract] PASS')
