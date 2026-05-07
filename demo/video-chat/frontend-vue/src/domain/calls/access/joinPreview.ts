import { nextTick } from 'vue';
import { BackgroundFilterController } from '../../realtime/background/controller';
import { buildOptionalCallAudioCaptureConstraints as defaultBuildOptionalCallAudioCaptureConstraints } from '../../realtime/media/audioCaptureConstraints';
import {
  callMediaPrefs,
  refreshCallMediaDevices,
  waitForCallMediaDeviceRelease,
} from '../../realtime/media/preferences';
import { capturePreviewMediaWithCameraFallback } from '../../realtime/media/cameraCaptureConstraints';
import { playCallSpeakerTestSound } from '../../realtime/media/speakerOutputRouting';

function finiteNumber(value, fallback) {
  const numeric = Number(value);
  return Number.isFinite(numeric) ? numeric : fallback;
}

function resolvePreviewBackgroundFilterOptions() {
  const requestedMode = String(callMediaPrefs.backgroundFilterMode || 'off').trim().toLowerCase();
  const mode = requestedMode === 'replace' ? 'replace' : requestedMode === 'blur' ? 'blur' : 'off';
  const applyOutgoing = Boolean(callMediaPrefs.backgroundApplyOutgoing);
  if (!applyOutgoing || (mode !== 'blur' && mode !== 'replace')) return { mode: 'off' };

  const backdrop = String(callMediaPrefs.backgroundBackdropMode || 'blur7').trim().toLowerCase();
  const isExclusionBackdrop = backdrop === 'exclusion';
  const qualityProfile = String(callMediaPrefs.backgroundQualityProfile || 'balanced').trim().toLowerCase();
  const baseBlurLevel = Math.max(0, Math.min(4, Math.round(finiteNumber(callMediaPrefs.backgroundBlurStrength, 2))));
  const blurStepPx = [8, 12, 18, 26, 34];
  let blurPx = blurStepPx[baseBlurLevel] ?? 3;
  if (backdrop === 'blur9') blurPx = Math.round(blurPx * 1.55);
  blurPx = Math.max(1, Math.min(64, blurPx));

  let detectIntervalMs = 1;
  if (qualityProfile === 'quality') detectIntervalMs = 1;
  else if (qualityProfile === 'realtime') detectIntervalMs = 1;

  let temporalSmoothingAlpha = 0.28;
  if (qualityProfile === 'quality') temporalSmoothingAlpha = 0.22;
  else if (qualityProfile === 'realtime') temporalSmoothingAlpha = 0.38;

  const maskVariant = Math.max(1, Math.min(10, Math.round(finiteNumber(callMediaPrefs.backgroundMaskVariant, 4))));
  const transitionGain = Math.max(1, Math.min(10, Math.round(finiteNumber(callMediaPrefs.backgroundBlurTransition, 10))));
  const requestedProcessWidth = Math.max(320, Math.min(1920, Math.round(finiteNumber(callMediaPrefs.backgroundMaxProcessWidth, 960))));
  const requestedProcessFps = Math.max(8, Math.min(30, Math.round(finiteNumber(callMediaPrefs.backgroundMaxProcessFps, 24))));
  let processWidthCap = 720;
  let processFpsCap = 15;
  if (qualityProfile === 'quality') {
    processWidthCap = 960;
    processFpsCap = 24;
  } else if (qualityProfile === 'realtime') {
    processWidthCap = 640;
    processFpsCap = 12;
  }
  const backgroundColor = isExclusionBackdrop
    ? '#061a4a'
    : (mode === 'replace' && backdrop === 'green' ? 'var(--color-success)' : '');

  return {
    mode,
    backgroundColor,
    backgroundImageUrl: mode === 'replace' && !backgroundColor
      ? String(callMediaPrefs.backgroundReplacementImageUrl || '').trim()
      : '',
    blurPx,
    mattePreset: isExclusionBackdrop ? 'replace' : (backdrop === 'blur9' ? 'hard_blur' : 'weak_blur'),
    detectIntervalMs,
    temporalSmoothingAlpha,
    preferFastMatte: qualityProfile !== 'quality',
    maskVariant,
    transitionGain,
    maxProcessWidth: Math.max(320, Math.min(processWidthCap, requestedProcessWidth)),
    maxProcessFps: Math.max(8, Math.min(processFpsCap, requestedProcessFps)),
    autoDisableOnOverload: false,
  };
}

function applyVolumeToStreams(streams) {
  const volume = Math.max(0, Math.min(100, Number(callMediaPrefs.microphoneVolume || 100))) / 100;
  const seenTracks = new Set();
  for (const stream of streams) {
    if (!(stream instanceof MediaStream)) continue;
    for (const track of stream.getAudioTracks()) {
      if (seenTracks.has(track)) continue;
      seenTracks.add(track);
      if (typeof track.applyConstraints === 'function') {
        track.applyConstraints({ volume }).catch(() => {});
      }
    }
  }
}

function buildPreviewAudioConstraints(
  buildOptionalCallAudioCaptureConstraints = defaultBuildOptionalCallAudioCaptureConstraints,
) {
  const microphoneDeviceId = String(callMediaPrefs.selectedMicrophoneId || '').trim();
  return buildOptionalCallAudioCaptureConstraints(true, microphoneDeviceId);
}

function stopStreams(streams) {
  const seenStreams = new Set();
  for (const stream of streams) {
    if (!(stream instanceof MediaStream) || seenStreams.has(stream)) continue;
    seenStreams.add(stream);
    for (const track of stream.getTracks()) {
      track.stop();
    }
  }
}

export function createJoinAccessPreviewController({
  previewVideoRef,
  state,
  buildOptionalCallAudioCaptureConstraints = defaultBuildOptionalCallAudioCaptureConstraints,
}) {
  const backgroundController = new BackgroundFilterController();
  let rawStream = null;
  let previewStream = null;
  let micLevelAudioContext = null;
  let micLevelSource = null;
  let micLevelAnalyser = null;
  let micLevelData = null;
  let micLevelFrame = 0;
  let micLevelMonitorToken = 0;
  let previewStartToken = 0;

  function stopMicLevelMonitor() {
    micLevelMonitorToken += 1;
    if (micLevelFrame !== 0 && typeof cancelAnimationFrame === 'function') {
      cancelAnimationFrame(micLevelFrame);
    }
    micLevelFrame = 0;

    for (const node of [micLevelSource, micLevelAnalyser]) {
      if (node && typeof node.disconnect === 'function') {
        try {
          node.disconnect();
        } catch {
          // ignore stale audio node cleanup failures
        }
      }
    }
    micLevelSource = null;
    micLevelAnalyser = null;
    micLevelData = null;

    if (micLevelAudioContext && typeof micLevelAudioContext.close === 'function') {
      micLevelAudioContext.close().catch(() => {});
    }
    micLevelAudioContext = null;
    state.micLevelPercent = 0;
  }

  function sampleMicLevel(token) {
    if (token !== micLevelMonitorToken) return;
    if (!micLevelAnalyser || !micLevelData) {
      state.micLevelPercent = 0;
      return;
    }

    micLevelAnalyser.getByteTimeDomainData(micLevelData);
    let energy = 0;
    let peak = 0;
    for (let index = 0; index < micLevelData.length; index += 1) {
      const centered = (micLevelData[index] - 128) / 128;
      energy += centered * centered;
      peak = Math.max(peak, Math.abs(centered));
    }

    const rms = Math.sqrt(energy / micLevelData.length);
    const micScale = Math.max(0, Math.min(100, Number(callMediaPrefs.microphoneVolume || 100))) / 100;
    const gated = Math.max(0, Math.max(rms * 8.6, peak * 1.28) - 0.02);
    const normalized = Math.min(1, gated / 0.98);
    state.micLevelPercent = Math.max(0, Math.min(100, Math.round(normalized * 100 * micScale * 3)));

    if (typeof requestAnimationFrame === 'function') {
      micLevelFrame = requestAnimationFrame(() => sampleMicLevel(token));
    }
  }

  function startMicLevelMonitor(stream) {
    stopMicLevelMonitor();
    if (!(stream instanceof MediaStream) || stream.getAudioTracks().length === 0) return;
    if (typeof window === 'undefined') return;
    const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextCtor) return;

    const token = micLevelMonitorToken + 1;
    micLevelMonitorToken = token;
    try {
      const context = new AudioContextCtor({ latencyHint: 'interactive' });
      const source = context.createMediaStreamSource(stream);
      const analyser = context.createAnalyser();
      analyser.fftSize = 256;
      analyser.smoothingTimeConstant = 0.08;
      source.connect(analyser);

      micLevelAudioContext = context;
      micLevelSource = source;
      micLevelAnalyser = analyser;
      micLevelData = new Uint8Array(analyser.fftSize);
      sampleMicLevel(token);
    } catch {
      if (token === micLevelMonitorToken) state.micLevelPercent = 0;
    }
  }

  function stopPreview() {
    previewStartToken += 1;
    backgroundController.dispose();
    stopMicLevelMonitor();

    const node = previewVideoRef.value;
    if (node instanceof HTMLVideoElement) {
      try {
        node.pause();
      } catch {
        // ignore stale preview node cleanup failures
      }
      node.srcObject = null;
    }

    stopStreams([rawStream, previewStream]);
    rawStream = null;
    previewStream = null;
    state.previewReady = false;
  }

  async function startPreview() {
    stopPreview();
    const token = previewStartToken + 1;
    previewStartToken = token;
    state.previewReady = false;
    state.previewError = '';

    if (
      typeof navigator === 'undefined'
      || !navigator.mediaDevices
      || typeof navigator.mediaDevices.getUserMedia !== 'function'
    ) {
      state.previewError = 'Camera preview is not supported in this browser.';
      return;
    }

    try {
      const nextRawStream = await capturePreviewMediaWithCameraFallback({
        audio: buildPreviewAudioConstraints(buildOptionalCallAudioCaptureConstraints),
        cameraDeviceId: callMediaPrefs.selectedCameraId,
      });
      if (token !== previewStartToken) {
        stopStreams([nextRawStream]);
        return;
      }
      rawStream = nextRawStream;
      applyVolumeToStreams([rawStream]);
      startMicLevelMonitor(rawStream);
      void refreshCallMediaDevices({ force: true, requestPermissions: false });

      let nextPreviewStream = rawStream;
      const backgroundOptions = resolvePreviewBackgroundFilterOptions();
      if (backgroundOptions.mode === 'blur' || backgroundOptions.mode === 'replace') {
        try {
          const result = await backgroundController.apply(rawStream, backgroundOptions);
          if (token !== previewStartToken) {
            stopStreams([rawStream, result?.stream]);
            return;
          }
          if (result?.stream instanceof MediaStream) {
            nextPreviewStream = result.stream;
          }
        } catch {
          nextPreviewStream = rawStream;
        }
      }
      previewStream = nextPreviewStream;

      await nextTick();
      if (token !== previewStartToken) {
        stopStreams([rawStream, previewStream]);
        return;
      }
      const node = previewVideoRef.value;
      if (!(node instanceof HTMLVideoElement)) return;
      node.muted = true;
      node.srcObject = previewStream;
      await node.play().catch(() => {});
      state.previewReady = true;
    } catch (error) {
      if (token !== previewStartToken) return;
      state.previewError = error instanceof Error ? error.message : 'Could not start camera preview.';
    }
  }

  async function releasePreviewForCallEntry() {
    stopPreview();
    await waitForCallMediaDeviceRelease();
  }

  function applyPreviewAudioVolume() {
    applyVolumeToStreams([rawStream, previewStream]);
  }

  async function playSpeakerTestSound() {
    await playCallSpeakerTestSound(callMediaPrefs);
  }

  return {
    applyPreviewAudioVolume,
    playSpeakerTestSound,
    releasePreviewForCallEntry,
    startPreview,
    stopPreview,
  };
}
