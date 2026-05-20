import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { createServer } from 'vite'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')

function read(relativePath) {
  return fs.readFileSync(path.join(frontendRoot, relativePath), 'utf8')
}

function readVideoChat(relativePath) {
  return fs.readFileSync(path.join(frontendRoot, '..', relativePath), 'utf8')
}

function makeFrame(width, height, seed) {
  const data = new Uint8ClampedArray(width * height * 4)
  for (let y = 0; y < height; y += 1) {
    for (let x = 0; x < width; x += 1) {
      const offset = ((y * width) + x) * 4
      data[offset] = (x + seed) % 256
      data[offset + 1] = (y * 2 + seed) % 256
      data[offset + 2] = ((x + y) * 3 + seed) % 256
      data[offset + 3] = 255
    }
  }
  return new ImageData(data, width, height)
}

if (typeof globalThis.ImageData === 'undefined') {
  globalThis.ImageData = class ImageData {
    constructor(data, width, height) {
      this.data = data
      this.width = width
      this.height = height
    }
  }
}

function assertThirtyFrameCadence(frameKinds, label) {
  assert.equal(frameKinds[0], 'keyframe', `${label}: first frame must be keyframe`)
  assert.deepEqual(frameKinds.slice(1, 30), Array(29).fill('delta'), `${label}: 29 deltas must follow the first keyframe`)
  assert.equal(frameKinds[30], 'keyframe', `${label}: frame 31 must be the next keyframe`)
}

const packageJson = JSON.parse(read('package.json'))
const workspaceConfig = read('src/domain/realtime/workspace/config.ts')
const strictPolicy = read('src/domain/realtime/workspace/callWorkspace/strictStabilityPolicy.ts')
const waveletCodec = read('src/lib/wavelet/codec.ts')
const wasmCodec = read('src/lib/wasm/wasm-codec.ts')
const publisherPipeline = read('src/domain/realtime/local/publisherPipeline.ts')
const publisherKeyframeDownscale = read('src/domain/realtime/local/publisherKeyframeDownscale.ts')
const publisherFrameTrace = read('src/domain/realtime/local/publisherFrameTrace.ts')
const publisherSourceReadback = read('src/domain/realtime/local/publisherSourceReadback.ts')
const publisherFrameDispatch = read('src/domain/realtime/local/publisherFrameDispatch.ts')
const mediaCarrierMode = read('src/lib/gossipmesh/mediaCarrierMode.ts')
const gossipEnvelope = read('src/domain/realtime/workspace/callWorkspace/gossipMediaFrameEnvelope.ts')
const gossipMediaFrameContract = JSON.parse(readVideoChat('contracts/v1/gossip-media-frame.contract.json'))
const forbiddenClientBehaviors = gossipMediaFrameContract?.publication_gate?.ops_lane_authority?.forbidden_client_behaviors || []

assert.ok(
  String(packageJson.scripts['test:contract:gossip'] || '').includes('gossip-codec-cadence-contract.mjs'),
  'gossip contract suite must include codec cadence proof',
)
assert.ok(workspaceConfig.includes("export const DEFAULT_SFU_VIDEO_QUALITY_PROFILE = 'rescue';"), '360p rescue profile must remain the default')
assert.match(workspaceConfig, /rescue:\s*Object\.freeze\(\{[\s\S]*captureWidth:\s*640,[\s\S]*captureHeight:\s*360,[\s\S]*captureFrameRate:\s*(?:30|LOCAL_CAMERA_CAPTURE_FRAME_RATE),[\s\S]*frameWidth:\s*640,[\s\S]*frameHeight:\s*360,[\s\S]*keyFrameInterval:\s*SFU_WLVC_KEYFRAME_INTERVAL,[\s\S]*encodeIntervalMs:\s*SFU_WLVC_ENCODE_INTERVAL_MS,/)
assert.ok(workspaceConfig.includes('export const SFU_WLVC_KEYFRAME_INTERVAL = 30;'), 'WLVC keyframe cadence must be keyframe plus 29 deltas')
assert.ok(workspaceConfig.includes('export const SFU_WLVC_ENCODE_INTERVAL_MS = 33;'), 'default WLVC cadence must target 30 fps')
assert.match(strictPolicy, /STRICT_720P30_VIDEO_PROFILE[\s\S]*keyFrameInterval:\s*30,[\s\S]*encodeIntervalMs:\s*33,[\s\S]*readbackFrameRate:\s*30,/)
assert.match(waveletCodec, /keyFrameInterval:\s*30,/, 'TypeScript WLVC codec must default to a 30-frame cadence')
assert.match(waveletCodec, /this\.frameCount\s*%\s*this\.config\.keyFrameInterval\s*===\s*0/, 'TypeScript WLVC codec must derive keyframes from the configured cadence')
assert.ok(wasmCodec.includes('function frameTypeFromWlvcPayload'), 'WASM wrapper must read keyframe/delta type from WLVC payload bytes')
assert.ok(wasmCodec.includes('type: frameTypeFromWlvcPayload(data)'), 'WASM wrapper must not report every encoded frame as keyframe')
assert.match(publisherPipeline, /const nextKeyFrameInterval = Math\.max\([\s\S]*Number\(videoProfile\.keyFrameInterval \|\| 1\)[\s\S]*keyFrameInterval:\s*nextKeyFrameInterval,/, 'publisher must pass profile codec cadence into the WLVC encoder')
assert.match(publisherPipeline, /const frameSize = resolvePublisherFrameSize\(video, videoProfile, videoTrack\)/, 'publisher readback must keep applying the selected profile after a first-frame retry')
assert.match(publisherPipeline, /encodedFrameType = sfuFrameTypeFromWlvcData\(encoded\.data, encoded\.type\);/)
assert.match(publisherPipeline, /codecId:\s*currentSfuCodecId\(/, 'publisher frames must carry the actual WLVC codec id')
assert.match(publisherFrameTrace, /encoder\?\.sfuCodecId[\s\S]*wlvc_wasm[\s\S]*wlvc_ts/, 'publisher codec trace must prefer explicit WLVC codec metadata')
assert.match(gossipEnvelope, /codec_runtime:\s*\{[\s\S]*encoder:\s*codecRuntimeEncoder/)
assert.match(gossipEnvelope, /frame_kind:\s*frameKind[\s\S]*sequence:\s*frameSequence[\s\S]*dependency:\s*\{[\s\S]*requires_keyframe_before_delta:\s*true/)
assert.match(gossipEnvelope, /frame\?\.codecId[\s\S]*frame\?\.codec_id[\s\S]*frame\?\.codecRuntime\?\.codec_id[\s\S]*frame\?\.codec_runtime\?\.codec_id/, 'Gossip envelope must preserve publisher codec metadata')
assert.match(publisherPipeline, /const maxEncodedKeyframeBudgetBytes = Math\.max\([\s\S]*videoProfile\.maxKeyframeBytesPerFrame[\s\S]*constants\.sfuWlvcMaxKeyframeFrameBytes/, 'publisher must expose an explicit keyframe byte budget')
assert.match(publisherPipeline, /const maxEncodedPayloadBytes = encodedFrameType === 'delta' \|\| tilePatchMetadata[\s\S]*\? maxEncodedFrameBudgetBytes[\s\S]*: maxEncodedKeyframeBudgetBytes;/, 'first keyframe must use the keyframe budget rather than the delta budget')
assert.match(publisherPipeline, /const restoredEncoder = await ensureFullFrameEncoder\(firstKeyframeOriginalFrameSize,[\s\S]*preserveFirstKeyframeDownscaleRetry:\s*true/, 'publisher must restore the selected-profile encoder after the one-frame first-keyframe downscale')
assert.match(publisherPipeline, /first_keyframe_downscale_single_frame:\s*true[\s\S]*first_keyframe_downscale_next_profile_keyframe_interval/, 'publisher must expose runtime metrics for the restored selected-profile cadence')
assert.doesNotMatch(publisherPipeline, /firstKeyframeDownscaleFrameSize|currentReadbackVideoProfile/, 'first-keyframe budget downscale must not become a hidden profile downgrade')
assert.match(publisherKeyframeDownscale, /keyFrameInterval,[\s\S]*first_keyframe_downscale_retry_count: 1[\s\S]*keyframe_interval_after_downscale/, 'first-keyframe downscale proof must preserve cadence telemetry')
assert.match(publisherSourceReadback, /function scaleCopiedVideoFrameImageData\(\{ imageData, sourceFrameSize, targetFrameSize, trace \}\)/, 'publisher source readback must include explicit downscale/crop helper')
assert.match(publisherSourceReadback, /targetFrameSize:\s*frameSize[\s\S]*budgetExceeded:\s*true[\s\S]*Publisher VideoFrame copyTo RGBA exceeded the active SFU profile budget before WLVC encode/, 'downscale/readback budget failures must be explicit before WLVC encode')
assert.ok(forbiddenClientBehaviors.includes('sfu_fallback'), 'Gossip media contract must forbid SFU fallback in the publish gate')
assert.ok(forbiddenClientBehaviors.includes('media_security_fallback'), 'Gossip media contract must forbid security fallback in the publish gate')
assert.match(mediaCarrierMode, /sfuFallbackAllowed:\s*false/, 'Gossip media carrier contract must not allow SFU fallback')
assert.doesNotMatch(
  `${publisherFrameDispatch}\n${mediaCarrierMode}\n${gossipEnvelope}`,
  /background_(?:replacement_)?fallback|media_security_fallback/,
  'G360 cadence publish surface must not introduce background or security fallback paths',
)
assert.doesNotMatch(
  `${publisherPipeline}\n${publisherKeyframeDownscale}\n${publisherFrameDispatch}`,
  /location\.reload|window\.location|document\.location|history\.go\(/,
  'publisher encode path must not hide reconnect/reload strategy inside codec cadence handling',
)

const server = await createServer({
  root: frontendRoot,
  logLevel: 'error',
  server: { middlewareMode: true, hmr: false },
})

try {
  const { WaveletVideoEncoder } = await server.ssrLoadModule('/src/lib/wavelet/codec.ts')
  const { SFU_VIDEO_QUALITY_PROFILES, DEFAULT_SFU_VIDEO_QUALITY_PROFILE } = await server.ssrLoadModule('/src/domain/realtime/workspace/config.ts')
  const { resolveVideochatMediaCarrierConfig } = await server.ssrLoadModule('/src/lib/gossipmesh/mediaCarrierMode.ts')
  const { dispatchPublisherFrame } = await server.ssrLoadModule('/src/domain/realtime/local/publisherFrameDispatch.ts')
  const { gossipFrameMessageFromEncodedFrame } = await server.ssrLoadModule('/src/domain/realtime/workspace/callWorkspace/gossipMediaFrameEnvelope.ts')
  const defaultProfile = SFU_VIDEO_QUALITY_PROFILES[DEFAULT_SFU_VIDEO_QUALITY_PROFILE]
  assert.equal(defaultProfile.frameWidth, 640, 'default profile must encode 360p width')
  assert.equal(defaultProfile.frameHeight, 360, 'default profile must encode 360p height')
  assert.equal(defaultProfile.captureFrameRate, 30, 'default profile must request 30 fps capture')
  assert.equal(defaultProfile.keyFrameInterval, 30, 'default profile must use 30-frame keyframe cadence')
  assert.equal(SFU_VIDEO_QUALITY_PROFILES.quality.frameWidth, 1280, '720p quality tier must remain available')
  assert.equal(SFU_VIDEO_QUALITY_PROFILES.quality.frameHeight, 720, '720p quality tier must remain available')

  for (const profileId of ['rescue', 'realtime', 'balanced', 'quality']) {
    const profile = SFU_VIDEO_QUALITY_PROFILES[profileId]
    assert.equal(profile.captureFrameRate, 30, `${profileId} must target 30 fps capture`)
    assert.equal(profile.keyFrameInterval, 30, `${profileId} must request a 30-frame keyframe interval`)
    assert.ok(profile.maxEncodedBytesPerFrame > 0, `${profileId} must expose a positive delta frame byte budget`)
    assert.ok(profile.maxKeyframeBytesPerFrame > profile.maxEncodedBytesPerFrame, `${profileId} must expose a larger first-keyframe byte budget`)

    const profileEncoder = new WaveletVideoEncoder({
      quality: profile.frameQuality,
      levels: 1,
      keyFrameInterval: profile.keyFrameInterval,
    })
    const profileKinds = []
    for (let index = 0; index < 31; index += 1) {
      profileKinds.push(profileEncoder.encodeFrame(makeFrame(64, 40, index), index + 1).type)
    }
    assertThirtyFrameCadence(profileKinds, `${profileId} profile`)
  }

  const encoder = new WaveletVideoEncoder({
    quality: defaultProfile.frameQuality,
    levels: 3,
    keyFrameInterval: defaultProfile.keyFrameInterval,
  })
  const frameKinds = []
  for (let index = 0; index < 61; index += 1) {
    const encoded = encoder.encodeFrame(makeFrame(defaultProfile.frameWidth, defaultProfile.frameHeight, index), index + 1)
    frameKinds.push(encoded.type)
    const bytes = new Uint8Array(encoded.data)
    assert.equal(bytes[5] === 0 ? 'keyframe' : 'delta', encoded.type, `WLVC header frame type must match encoded.type at frame ${index + 1}`)
    const byteBudget = encoded.type === 'keyframe'
      ? defaultProfile.maxKeyframeBytesPerFrame
      : defaultProfile.maxEncodedBytesPerFrame
    assert.ok(encoded.data.byteLength <= byteBudget, `encoded frame ${index + 1} must fit default ${encoded.type} byte budget`)
  }

  assertThirtyFrameCadence(frameKinds, 'default 360p profile')
  assert.deepEqual(frameKinds.slice(31, 60), Array(29).fill('delta'), 'frames 32-60 must be deltas')
  assert.equal(frameKinds[60], 'keyframe', 'frame 61 must restart the cadence')

  const gossipFrameMessage = gossipFrameMessageFromEncodedFrame({
    publisherId: 'alice',
    publisherUserId: 'alice',
    trackId: 'camera',
    timestamp: 1000,
    type: 'keyframe',
    data: new Uint8Array([1, 2, 3, 4]).buffer,
    codecId: 'wlvc_ts',
    runtimeId: 'wlvc_sfu',
    transportMetrics: {
      outgoing_video_quality_profile: 'rescue',
      selected_video_quality_profile: 'rescue',
      profile_frame_width: 640,
      profile_frame_height: 360,
      profile_frame_rate: 30,
    },
  }, new Map(), {
    peerId: 'alice',
    callId: 'call-g360',
    roomId: 'room-g360',
    plainRelay: true,
  })
  assert.equal(gossipFrameMessage.codec_id, 'wlvc_v1', 'Gossip envelope must publish the WLVC external codec id')
  assert.equal(gossipFrameMessage.codec_runtime.encoder, 'wlvc_ts', 'Gossip envelope must preserve WLVC runtime encoder metadata')
  assert.equal(gossipFrameMessage.outgoing_video_quality_profile, 'rescue', 'Gossip envelope must carry the active 360p profile hint')
  assert.equal(gossipFrameMessage.profile_frame_width, 640, 'Gossip envelope must carry the active profile width')
  assert.equal(gossipFrameMessage.profile_frame_height, 360, 'Gossip envelope must carry the active profile height')

  const gossipPrimaryConfig = resolveVideochatMediaCarrierConfig({ VITE_VIDEOCHAT_MEDIA_CARRIER: 'gossip_primary' })
  assert.equal(gossipPrimaryConfig.sfuFallbackAllowed, false, 'gossip_primary must not enable SFU fallback')
  assert.equal(gossipPrimaryConfig.sfuRequiredBeforeGossip, false, 'gossip_primary must not require SFU before Gossip publication')

  let sfuLookupCount = 0
  const dispatchResult = await dispatchPublisherFrame({
    frame: { trackId: 'camera' },
    trackId: 'camera',
    mediaRuntimePath: 'wlvc_sfu',
    currentOpenSfuClient: () => {
      sfuLookupCount += 1
      throw new Error('SFU lookup must not run in gossip_primary')
    },
    getSfuClientBufferedAmount: () => 0,
    publishLocalEncodedFrameToGossip: () => true,
    captureClientDiagnostic: () => {},
    captureClientDiagnosticError: () => {},
  })
  assert.equal(sfuLookupCount, 0, 'gossip_primary dispatch must not look up SFU after Gossip publication')
  assert.equal(dispatchResult.gossipPublished, true, 'gossip_primary dispatch must publish to Gossip')
  assert.equal(dispatchResult.sfuSent, false, 'gossip_primary dispatch must not send SFU fallback')
  assert.equal(dispatchResult.alternatePathSuppressed, true, 'gossip_primary dispatch must suppress alternate media paths')

  process.stdout.write('[gossip-codec-cadence-contract] PASS\n')
} finally {
  await server.close()
}
