import { ref } from 'vue';
import { buildOptionalCallAudioCaptureConstraints } from '../domain/realtime/media/audioCaptureConstraints';
import { callMediaPrefs } from '../domain/realtime/media/preferences';

export function useWorkspaceMicLevelMonitor({ isCallWorkspace, isMobileViewport }) {
  const micLevelPercent = ref(0);
  let micLevelStream = null;
  let micLevelAudioContext = null;
  let micLevelSource = null;
  let micLevelAnalyser = null;
  let micLevelData = null;
  let micLevelFrame = 0;
  let micLevelMonitorToken = 0;
  let micLevelMonitorOwnsStream = false;

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
          // stale audio nodes can already be disconnected.
        }
      }
    }
    micLevelSource = null;
    micLevelAnalyser = null;
    micLevelData = null;

    if (
      micLevelMonitorOwnsStream
      && typeof MediaStream !== 'undefined'
      && micLevelStream instanceof MediaStream
    ) {
      for (const track of micLevelStream.getTracks()) {
        try {
          track.stop();
        } catch {
          // ignore stale track cleanup failures.
        }
      }
    }
    micLevelMonitorOwnsStream = false;
    micLevelStream = null;

    if (micLevelAudioContext && typeof micLevelAudioContext.close === 'function') {
      micLevelAudioContext.close().catch(() => {});
    }
    micLevelAudioContext = null;
    micLevelPercent.value = 0;
  }

  function sampleMicLevel(token) {
    if (token !== micLevelMonitorToken) return;
    if (!micLevelAnalyser || !micLevelData) {
      micLevelPercent.value = 0;
      return;
    }

    micLevelAnalyser.getByteTimeDomainData(micLevelData);
    let energy = 0;
    let peak = 0;
    for (let index = 0; index < micLevelData.length; index += 1) {
      const centered = (micLevelData[index] - 128) / 128;
      energy += centered * centered;
      const amplitude = Math.abs(centered);
      if (amplitude > peak) peak = amplitude;
    }

    const rms = Math.sqrt(energy / micLevelData.length);
    const micScale = Math.max(0, Math.min(100, Number(callMediaPrefs.microphoneVolume || 100))) / 100;
    const gated = Math.max(0, Math.max(rms * 8.6, peak * 1.28) - 0.02);
    const normalized = Math.min(1, gated / 0.98);
    const boostedPercent = normalized * 100 * micScale * 3;
    micLevelPercent.value = Math.max(0, Math.min(100, Math.round(boostedPercent)));

    if (typeof requestAnimationFrame === 'function') {
      micLevelFrame = requestAnimationFrame(() => sampleMicLevel(token));
    }
  }

  function attachMicLevelStream(stream, { ownsStream = false } = {}) {
    stopMicLevelMonitor();
    if (!isCallWorkspace.value) return;
    if (
      typeof window === 'undefined'
      || typeof MediaStream === 'undefined'
      || !(stream instanceof MediaStream)
      || stream.getAudioTracks().length === 0
    ) {
      return;
    }

    const token = micLevelMonitorToken + 1;
    micLevelMonitorToken = token;
    const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextCtor) {
      if (ownsStream) {
        for (const track of stream.getTracks()) {
          track.stop();
        }
      }
      return;
    }

    try {
      const context = new AudioContextCtor({ latencyHint: 'interactive' });
      const source = context.createMediaStreamSource(stream);
      const analyser = context.createAnalyser();
      analyser.fftSize = 256;
      analyser.smoothingTimeConstant = 0.08;
      source.connect(analyser);

      micLevelStream = stream;
      micLevelMonitorOwnsStream = ownsStream;
      micLevelAudioContext = context;
      micLevelSource = source;
      micLevelAnalyser = analyser;
      micLevelData = new Uint8Array(analyser.fftSize);
      sampleMicLevel(token);
    } catch {
      if (token === micLevelMonitorToken) {
        micLevelPercent.value = 0;
      }
      if (ownsStream) {
        for (const track of stream.getTracks()) {
          track.stop();
        }
      }
    }
  }

  async function startMicLevelMonitor() {
    stopMicLevelMonitor();
    if (!isCallWorkspace.value || isMobileViewport.value) return;
    if (
      typeof navigator === 'undefined'
      || !navigator.mediaDevices
      || typeof navigator.mediaDevices.getUserMedia !== 'function'
    ) {
      return;
    }

    const token = micLevelMonitorToken + 1;
    micLevelMonitorToken = token;
    const selectedMicId = String(callMediaPrefs.selectedMicrophoneId || '').trim();
    const audioConstraints = buildOptionalCallAudioCaptureConstraints(true, selectedMicId);

    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: audioConstraints, video: false });
      if (token !== micLevelMonitorToken) {
        for (const track of stream.getTracks()) {
          track.stop();
        }
        return;
      }
      attachMicLevelStream(stream, { ownsStream: true });
    } catch {
      if (token === micLevelMonitorToken) {
        micLevelPercent.value = 0;
      }
    }
  }

  return {
    attachMicLevelStream,
    micLevelPercent,
    startMicLevelMonitor,
    stopMicLevelMonitor,
  };
}
