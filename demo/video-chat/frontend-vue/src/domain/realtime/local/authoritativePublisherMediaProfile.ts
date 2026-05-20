import { activeMediaSessionPlanSelectedProfile } from '../workspace/callWorkspace/mediaCapabilityPlanBridge.ts';

function positiveInt(value: unknown, fallback = 0): number {
  const normalized = Number(value);
  return Number.isFinite(normalized) && normalized > 0 ? Math.floor(normalized) : fallback;
}

function stringValue(value: unknown, fallback = ''): string {
  const text = String(value ?? '').trim();
  return text === '' ? fallback : text.slice(0, 128);
}

function intervalMsForFps(fps: number, fallback = 0): number {
  return fps > 0 ? Math.max(1, Math.round(1000 / fps)) : fallback;
}

function selectedPlanHasPublisherShape(selectedPlan: Record<string, any> | null | undefined): boolean {
  return positiveInt(selectedPlan?.width) > 0
    && positiveInt(selectedPlan?.height) > 0
    && positiveInt(selectedPlan?.fps) > 0
    && stringValue(selectedPlan?.transport) !== '';
}

export function normalizeAuthoritativePublisherTransport(value: unknown): string {
  const normalized = stringValue(value).toLowerCase();
  if (['gossip', 'gossip_primary', 'gossip_primary_direct', 'gossip_rtc_datachannel', 'planned_gossip'].includes(normalized)) {
    return 'gossip';
  }
  if (normalized === 'sfu' || normalized === 'webrtc_sfu') return 'sfu';
  return normalized;
}

export function resolveAuthoritativePublisherMediaProfile(baseProfile: Record<string, any> = {}) {
  const selectedPlan = activeMediaSessionPlanSelectedProfile();
  if (!selectedPlanHasPublisherShape(selectedPlan)) return baseProfile;

  const width = positiveInt(selectedPlan?.width, positiveInt(baseProfile.captureWidth));
  const height = positiveInt(selectedPlan?.height, positiveInt(baseProfile.captureHeight));
  const fps = positiveInt(selectedPlan?.fps, positiveInt(baseProfile.captureFrameRate));
  const keyFrameInterval = positiveInt(selectedPlan?.keyframe_interval, positiveInt(baseProfile.keyFrameInterval, 1));
  const readbackIntervalMs = intervalMsForFps(fps, positiveInt(baseProfile.readbackIntervalMs || baseProfile.encodeIntervalMs));
  const transport = normalizeAuthoritativePublisherTransport(selectedPlan?.transport);
  const planId = stringValue(selectedPlan?.plan_id, stringValue(selectedPlan?.profile, 'selected'));

  return Object.freeze({
    ...baseProfile,
    id: `orchestrator_${planId}_${transport}_${width}x${height}_${fps}fps_kf${keyFrameInterval}`,
    label: stringValue(baseProfile.label, stringValue(selectedPlan?.profile, planId)),
    captureWidth: width,
    captureHeight: height,
    captureFrameRate: fps,
    frameWidth: width,
    frameHeight: height,
    keyFrameInterval,
    encodeIntervalMs: readbackIntervalMs,
    readbackFrameRate: fps,
    readbackIntervalMs,
    authoritativeMediaSessionPlan: true,
    authoritativePlanEpoch: positiveInt(selectedPlan?.plan_epoch, 1),
    authoritativePlanId: planId,
    authoritativePlanProfile: stringValue(selectedPlan?.profile),
    authoritativeTransport: transport,
    authoritativeCodecPath: stringValue(selectedPlan?.codec_path),
    captureExact: selectedPlan?.capture_exact === true,
  });
}
