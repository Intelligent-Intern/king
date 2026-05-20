export const CALL_STABILITY_POLICY_ENV_KEY = 'VITE_VIDEOCHAT_CALL_STABILITY_POLICY';
export const STRICT_720P30_POLICY_MODE = 'strict_720p30';
export const STRICT_720P30_PROFILE_ID = 'strict_720p30';

export const STRICT_720P30_CONSTRAINTS = Object.freeze({
  video_width: 1280,
  video_height: 720,
  video_fps: 30,
});

export const STRICT_720P30_VIDEO_PROFILE = Object.freeze({
  id: STRICT_720P30_PROFILE_ID,
  label: 'Strict 720p30',
  captureWidth: STRICT_720P30_CONSTRAINTS.video_width,
  captureHeight: STRICT_720P30_CONSTRAINTS.video_height,
  captureFrameRate: STRICT_720P30_CONSTRAINTS.video_fps,
  frameWidth: STRICT_720P30_CONSTRAINTS.video_width,
  frameHeight: STRICT_720P30_CONSTRAINTS.video_height,
  frameQuality: 40,
  keyFrameInterval: 30,
  encodeIntervalMs: 33,
  readbackFrameRate: 30,
  readbackIntervalMs: 33,
  maxEncodedBytesPerFrame: 4096 * 1024,
  maxKeyframeBytesPerFrame: 5120 * 1024,
  maxWireBytesPerSecond: 4800 * 1024,
  maxEncodeMs: 110,
  maxDrawImageMs: 36,
  maxReadbackMs: 56,
  maxQueueAgeMs: 320,
  maxBufferedBytes: 3584 * 1024,
  payloadSoftLimitRatio: 0.86,
  minKeyframeRetryMs: 1200,
  expectedRecovery: 'strict_720p30_drop_without_recovery',
});

const STRICT_720P30_POLICY = Object.freeze({
  mode: STRICT_720P30_POLICY_MODE,
  enabled: true,
  fixedVideoProfile: null,
  disableAutoQuality: true,
  disableQualityRecoveryProbes: true,
  disableRemoteVideoStallRecovery: true,
  disableSfuSocketRecoveryReconnect: true,
  disableSfuConnectRetry: true,
  disableSfuLocalTrackPublishRetry: true,
  disableForcedKeyframeRecovery: true,
  disableGossipMediaRepair: true,
  disableGossipNeighborRenegotiate: true,
  disableGossipPublish: false,
  disableGossipReceiveRecovery: true,
  disableLocalTrackRecovery: true,
  disableScreenShareSfuReconnect: true,
  disableBackgroundOutgoing: false,
  disableBackgroundTabPolicy: true,
  disableNativeRuntimeFallback: true,
  disableRegressionImprovementProbes: true,
  requireStrict720p30Capability: false,
  strictCaptureOnly: false,
  strictFixedOutputFrame: true,
  disableSelectiveTileTransport: true,
  quietPublisherFrameDrops: true,
  coalesceMediaSecurityHandshakeDiagnostics: true,
});

const ADAPTIVE_POLICY = Object.freeze({
  mode: 'adaptive',
  enabled: false,
  fixedVideoProfile: null,
});

function defaultPolicyEnv() {
  const env = import.meta.env;
  return env && typeof env === 'object' ? env : {};
}

function normalizePolicyMode(value) {
  const normalized = String(value || STRICT_720P30_POLICY_MODE).trim().toLowerCase();
  if (['0', 'false', 'off', 'adaptive', 'disabled'].includes(normalized)) return 'adaptive';
  if (normalized === STRICT_720P30_POLICY_MODE || normalized === 'strict-720p30' || normalized === 'strict720p30') {
    return STRICT_720P30_POLICY_MODE;
  }
  return STRICT_720P30_POLICY_MODE;
}

export function resolveCallStabilityPolicy(env = defaultPolicyEnv()) {
  const mode = normalizePolicyMode(
    env?.[CALL_STABILITY_POLICY_ENV_KEY] ?? env?.VITE_VIDEOCHAT_CALL_STABILITY_MODE,
  );
  return mode === STRICT_720P30_POLICY_MODE ? STRICT_720P30_POLICY : ADAPTIVE_POLICY;
}

export const CALL_STABILITY_POLICY = resolveCallStabilityPolicy();

export function isStrict720p30Policy(policy) {
  return Boolean(policy?.enabled && String(policy?.mode || '').trim().toLowerCase() === STRICT_720P30_POLICY_MODE);
}

export function strictPolicyEnabled(policy, flag) {
  if (!isStrict720p30Policy(policy)) return false;
  if (String(flag || '').trim() === '') return true;
  return policy?.[flag] !== false;
}

export function strict720p30VideoProfile(policy) {
  return isStrict720p30Policy(policy) && policy?.fixedVideoProfile
    ? policy.fixedVideoProfile
    : STRICT_720P30_VIDEO_PROFILE;
}

export function strict720p30Constraints() {
  return STRICT_720P30_CONSTRAINTS;
}

export function strict720p30CapabilitySupported(capabilities = {}) {
  const media = capabilities?.media && typeof capabilities.media === 'object' ? capabilities.media : {};
  const runtime = capabilities?.runtime && typeof capabilities.runtime === 'object' ? capabilities.runtime : {};
  const constraints = capabilities?.constraints && typeof capabilities.constraints === 'object'
    ? capabilities.constraints
    : {};

  return Boolean(media.camera)
    && Boolean(media.camera_720p30 ?? media.camera720p30)
    && Boolean(runtime.websocket)
    && Boolean(runtime.wlvc_encoder ?? runtime.wlvcEncoder)
    && Number(constraints.video_width ?? constraints.videoWidth) === STRICT_720P30_CONSTRAINTS.video_width
    && Number(constraints.video_height ?? constraints.videoHeight) === STRICT_720P30_CONSTRAINTS.video_height
    && Number(constraints.video_fps ?? constraints.videoFps) === STRICT_720P30_CONSTRAINTS.video_fps;
}
