export const CALL_PHONE_SPEAKER_DEVICE_ID = '__kingrt_phone_speaker__';

const speakerphoneRoutesByElement = new Map();
let speakerphoneAudioContext = null;

function browserUserAgent() {
  if (typeof navigator === 'undefined') return '';
  return String(navigator.userAgent || '');
}

function supportsHtmlMediaElementSinkId() {
  if (typeof HTMLMediaElement === 'undefined') return false;
  return typeof HTMLMediaElement.prototype?.setSinkId === 'function';
}

export function isLikelyMobileAudioDevice() {
  if (typeof navigator === 'undefined') return false;
  const ua = browserUserAgent();
  if (/Android|iPhone|iPad|iPod|Mobile/i.test(ua)) return true;
  return Number(navigator.maxTouchPoints || 0) > 1
    && typeof window !== 'undefined'
    && typeof window.matchMedia === 'function'
    && window.matchMedia('(max-width: 900px)').matches;
}

export function shouldOfferPhoneSpeakerRoute() {
  return isLikelyMobileAudioDevice() && !supportsHtmlMediaElementSinkId();
}

export function isPhoneSpeakerDeviceId(deviceId) {
  return String(deviceId || '').trim() === CALL_PHONE_SPEAKER_DEVICE_ID;
}

export function normalizeSpeakerSinkDeviceId(deviceId) {
  const normalized = String(deviceId || '').trim();
  return isPhoneSpeakerDeviceId(normalized) ? '' : normalized;
}

function closeSpeakerphoneContextIfIdle() {
  if (speakerphoneRoutesByElement.size > 0) return;
  const context = speakerphoneAudioContext;
  speakerphoneAudioContext = null;
  if (context && typeof context.close === 'function') {
    context.close().catch(() => {});
  }
}

function ensureSpeakerphoneAudioContext() {
  if (typeof window === 'undefined') return null;
  if (speakerphoneAudioContext) return speakerphoneAudioContext;
  const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
  if (!AudioContextCtor) return null;
  speakerphoneAudioContext = new AudioContextCtor({ latencyHint: 'interactive' });
  return speakerphoneAudioContext;
}

function liveAudioTracks(stream) {
  if (typeof MediaStream === 'undefined' || !(stream instanceof MediaStream)) return [];
  return stream.getAudioTracks().filter((track) => track?.readyState !== 'ended');
}

function teardownSpeakerphoneRoute(node, restoreMuted = true) {
  const route = speakerphoneRoutesByElement.get(node);
  if (!route) return;

  for (const audioNode of [route.source, route.gain]) {
    if (audioNode && typeof audioNode.disconnect === 'function') {
      try {
        audioNode.disconnect();
      } catch {
        // stale WebAudio nodes can already be disconnected.
      }
    }
  }

  if (restoreMuted && node instanceof HTMLMediaElement) {
    node.muted = Boolean(route.originalMuted);
  }
  speakerphoneRoutesByElement.delete(node);
  closeSpeakerphoneContextIfIdle();
}

function teardownDisconnectedSpeakerphoneRoutes() {
  for (const [node, route] of Array.from(speakerphoneRoutesByElement.entries())) {
    if (!(node instanceof HTMLMediaElement) || !node.isConnected || node.srcObject !== route.stream) {
      teardownSpeakerphoneRoute(node);
    }
  }
}

function ensureSpeakerphoneRoute(node, volume) {
  if (!(node instanceof HTMLMediaElement)) return false;
  const stream = typeof MediaStream !== 'undefined' && node.srcObject instanceof MediaStream ? node.srcObject : null;
  if (!stream || liveAudioTracks(stream).length === 0) {
    teardownSpeakerphoneRoute(node);
    return false;
  }

  const normalizedVolume = Math.max(0, Math.min(1, Number(volume)));
  let route = speakerphoneRoutesByElement.get(node);
  if (route && route.stream !== stream) {
    teardownSpeakerphoneRoute(node);
    route = null;
  }

  if (!route) {
    const context = ensureSpeakerphoneAudioContext();
    if (!context) return false;
    const source = context.createMediaStreamSource(stream);
    const gain = context.createGain();
    gain.gain.value = normalizedVolume;
    source.connect(gain);
    gain.connect(context.destination);
    route = {
      stream,
      source,
      gain,
      originalMuted: Boolean(node.muted),
    };
    speakerphoneRoutesByElement.set(node, route);
  } else if (route.gain) {
    route.gain.gain.value = normalizedVolume;
  }

  node.muted = true;
  if (speakerphoneAudioContext?.state === 'suspended') {
    speakerphoneAudioContext.resume().catch(() => {});
  }
  return true;
}

export function applyCallSpeakerOutputToMediaElement(node, {
  selectedSpeakerId = '',
  volume = 1,
} = {}) {
  if (!(node instanceof HTMLMediaElement)) return;
  teardownDisconnectedSpeakerphoneRoutes();

  const normalizedVolume = Math.max(0, Math.min(1, Number(volume)));
  if (isPhoneSpeakerDeviceId(selectedSpeakerId)) {
    if (ensureSpeakerphoneRoute(node, normalizedVolume)) return;
  } else {
    teardownSpeakerphoneRoute(node);
  }

  if (!node.muted) {
    node.volume = normalizedVolume;
  }

  const sinkDeviceId = normalizeSpeakerSinkDeviceId(selectedSpeakerId);
  if (sinkDeviceId !== '' && typeof node.setSinkId === 'function') {
    node.setSinkId(sinkDeviceId).catch(() => {});
  }
}

export async function playCallSpeakerTestSound({
  selectedSpeakerId = '',
  speakerVolume = 100,
} = {}) {
  if (typeof window === 'undefined') return;
  const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
  if (!AudioContextCtor) return;

  let context = null;
  const audio = new Audio();
  try {
    context = isPhoneSpeakerDeviceId(selectedSpeakerId)
      ? ensureSpeakerphoneAudioContext()
      : new AudioContextCtor({ latencyHint: 'interactive' });
    if (!context) return;
    if (context.state === 'suspended') {
      await context.resume().catch(() => {});
    }

    const oscillator = context.createOscillator();
    const gainNode = context.createGain();
    const normalizedVolume = Math.max(0, Math.min(100, Number(speakerVolume || 100))) / 100;

    oscillator.type = 'sine';
    oscillator.frequency.value = 880;
    gainNode.gain.value = Math.max(0.01, normalizedVolume * 0.45);
    oscillator.connect(gainNode);

    if (isPhoneSpeakerDeviceId(selectedSpeakerId)) {
      gainNode.connect(context.destination);
    } else {
      const destination = context.createMediaStreamDestination();
      gainNode.connect(destination);
      audio.srcObject = destination.stream;
      audio.playsInline = true;
      audio.muted = false;
      audio.volume = 1;
      const sinkDeviceId = normalizeSpeakerSinkDeviceId(selectedSpeakerId);
      if (sinkDeviceId !== '' && typeof audio.setSinkId === 'function') {
        await audio.setSinkId(sinkDeviceId).catch(() => {});
      }
      await audio.play();
    }

    oscillator.start();
    oscillator.stop(context.currentTime + 0.22);
    await new Promise((resolve) => window.setTimeout(resolve, 260));
    oscillator.disconnect();
    gainNode.disconnect();
  } catch {
    // Best-effort test sound; unsupported output routing must not block call entry.
  } finally {
    try {
      audio.pause();
    } catch {
      // ignore
    }
    audio.srcObject = null;
    if (context && context !== speakerphoneAudioContext && typeof context.close === 'function') {
      await context.close().catch(() => {});
    }
  }
}
