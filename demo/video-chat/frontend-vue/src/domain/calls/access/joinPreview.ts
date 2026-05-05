import { nextTick } from 'vue';
import { BackgroundFilterController } from '../../realtime/background/controller';
import { buildOptionalCallAudioCaptureConstraints as defaultBuildOptionalCallAudioCaptureConstraints } from '../../realtime/media/audioCaptureConstraints';
import { callMediaPrefs } from '../../realtime/media/preferences';

function finiteNumber(value, fallback) {
  const numeric = Number(value);
  return Number.isFinite(numeric) ? numeric : fallback;
}

function resolvePreviewBackgroundFilterOptions() {
  const mode = String(callMediaPrefs.backgroundFilterMode || 'off').trim().toLowerCase() === 'blur'
    ? 'blur'
    : 'off';
  const applyOutgoing = Boolean(callMediaPrefs.backgroundApplyOutgoing);
  if (!applyOutgoing || mode !== 'blur') return { mode: 'off' };

  const backdrop = String(callMediaPrefs.backgroundBackdropMode || 'blur7').trim().toLowerCase();
  const qualityProfile = String(callMediaPrefs.backgroundQualityProfile || 'balanced').trim().toLowerCase();
  const baseBlurLevel = Math.max(0, Math.min(4, Math.round(finiteNumber(callMediaPrefs.backgroundBlurStrength, 2))));
  const blurStepPx = [1, 2, 3, 4, 5];
  let blurPx = blurStepPx[baseBlurLevel] ?? 3;
  if (backdrop === 'blur9') blurPx = Math.round(blurPx * 1.35);
  blurPx = Math.max(1, Math.min(12, blurPx));

  let detectIntervalMs = 150;
  if (qualityProfile === 'quality') detectIntervalMs = 110;
  else if (qualityProfile === 'realtime') detectIntervalMs = 190;

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

  return {
    mode,
    blurPx,
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

function buildPreviewConstraints(
  buildOptionalCallAudioCaptureConstraints = defaultBuildOptionalCallAudioCaptureConstraints,
) {
  const cameraDeviceId = String(callMediaPrefs.selectedCameraId || '').trim();
  const microphoneDeviceId = String(callMediaPrefs.selectedMicrophoneId || '').trim();
  return {
    video: cameraDeviceId === '' ? true : { deviceId: { exact: cameraDeviceId } },
    audio: buildOptionalCallAudioCaptureConstraints(true, microphoneDeviceId),
  };
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

  function stopPreview() {
    backgroundController.dispose();

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
      rawStream = await navigator.mediaDevices.getUserMedia(buildPreviewConstraints(buildOptionalCallAudioCaptureConstraints));
      applyVolumeToStreams([rawStream]);

      previewStream = rawStream;
      const backgroundOptions = resolvePreviewBackgroundFilterOptions();
      if (backgroundOptions.mode === 'blur') {
        try {
          const result = await backgroundController.apply(rawStream, backgroundOptions);
          if (result?.stream instanceof MediaStream) {
            previewStream = result.stream;
          }
        } catch {
          previewStream = rawStream;
        }
      }

      await nextTick();
      const node = previewVideoRef.value;
      if (!(node instanceof HTMLVideoElement)) return;
      node.muted = true;
      node.srcObject = previewStream;
      await node.play().catch(() => {});
      state.previewReady = true;
    } catch (error) {
      state.previewError = error instanceof Error ? error.message : 'Could not start camera preview.';
    }
  }

  function applyPreviewAudioVolume() {
    applyVolumeToStreams([rawStream, previewStream]);
  }

  async function playSpeakerTestSound() {
    if (typeof window === 'undefined') return;
    const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextCtor) return;

    let context = null;
    const audio = new Audio();
    try {
      context = new AudioContextCtor();
      const destination = context.createMediaStreamDestination();
      const oscillator = context.createOscillator();
      const gainNode = context.createGain();
      const normalizedVolume = Math.max(0, Math.min(100, Number(callMediaPrefs.speakerVolume || 100))) / 100;

      oscillator.type = 'sine';
      oscillator.frequency.value = 880;
      gainNode.gain.value = Math.max(0.01, normalizedVolume * 0.45);
      oscillator.connect(gainNode);
      gainNode.connect(destination);

      audio.srcObject = destination.stream;
      audio.playsInline = true;
      audio.muted = false;
      audio.volume = 1;

      const speakerDeviceId = String(callMediaPrefs.selectedSpeakerId || '').trim();
      if (speakerDeviceId !== '' && typeof audio.setSinkId === 'function') {
        await audio.setSinkId(speakerDeviceId).catch(() => {});
      }

      await audio.play();
      oscillator.start();
      oscillator.stop(context.currentTime + 0.22);
      await new Promise((resolve) => {
        window.setTimeout(resolve, 260);
      });
    } catch {
      // best-effort test sound; unsupported output routing must not block join.
    } finally {
      try {
        audio.pause();
      } catch {
        // ignore
      }
      audio.srcObject = null;
      if (context && typeof context.close === 'function') {
        await context.close().catch(() => {});
      }
    }
  }

  return {
    applyPreviewAudioVolume,
    playSpeakerTestSound,
    startPreview,
    stopPreview,
  };
}
