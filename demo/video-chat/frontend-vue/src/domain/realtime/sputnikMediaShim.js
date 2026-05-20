const SPUTNIK_SHIM_KEY = '__kingrtSputnikMediaShimInstalled';

function queryValue(route, key) {
  const value = route?.query?.[key];
  if (Array.isArray(value)) return String(value[0] || '').trim();
  return String(value || '').trim();
}

function truthy(value) {
  return /^(?:1|true|yes|on)$/i.test(String(value || '').trim());
}

function positiveInt(value, fallback, min, max) {
  const parsed = Number.parseInt(String(value || ''), 10);
  if (!Number.isFinite(parsed) || parsed < min) return fallback;
  return Math.min(max, parsed);
}

function displayName(value) {
  const normalized = String(value || '').trim().replace(/\s+/g, ' ');
  return normalized === '' ? 'Sputnik' : normalized.slice(0, 64);
}

function wantsTrack(constraint) {
  return constraint !== false && constraint !== null && typeof constraint !== 'undefined';
}

function bindNativeMediaDeviceMethod(mediaDevices, methodName) {
  const method = mediaDevices && typeof mediaDevices[methodName] === 'function'
    ? mediaDevices[methodName]
    : null;
  return method ? method.bind(mediaDevices) : null;
}

function createVideoTrack(config) {
  if (typeof document === 'undefined' || typeof window === 'undefined') return null;
  const canvas = document.createElement('canvas');
  canvas.width = config.width;
  canvas.height = config.height;
  const context = canvas.getContext('2d');
  if (!context || typeof canvas.captureStream !== 'function') return null;

  let frame = 0;
  const render = () => {
    frame += 1;
    const hue = (frame * 9) % 360;
    context.fillStyle = `hsl(${hue}, 72%, 22%)`;
    context.fillRect(0, 0, config.width, config.height);
    context.fillStyle = `hsl(${(hue + 105) % 360}, 82%, 52%)`;
    context.fillRect((frame * 13) % Math.max(1, config.width - 92), 0, 92, config.height);
    context.fillStyle = 'rgba(0, 0, 0, 0.48)';
    context.fillRect(0, config.height - 64, config.width, 64);
    context.fillStyle = '#fff';
    context.font = '700 26px system-ui, sans-serif';
    context.fillText(config.name, 22, config.height - 24);
    context.font = '600 13px system-ui, sans-serif';
    context.fillText(`f${frame}`, config.width - 64, config.height - 24);
  };
  render();

  const timer = window.setInterval(render, Math.max(33, Math.round(1000 / config.fps)));
  const stream = canvas.captureStream(config.fps);
  const [track] = stream.getVideoTracks();
  if (!track) {
    window.clearInterval(timer);
    return null;
  }

  const stop = track.stop.bind(track);
  track.stop = () => {
    window.clearInterval(timer);
    stop();
  };
  return track;
}

function createAudioTrack(config) {
  if (typeof window === 'undefined') return null;
  const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
  if (typeof AudioContextCtor !== 'function') return null;

  const context = new AudioContextCtor();
  const destination = context.createMediaStreamDestination();
  const oscillator = context.createOscillator();
  const gain = context.createGain();
  oscillator.type = 'sine';
  oscillator.frequency.value = config.toneHz;
  gain.gain.value = 0.0001;
  oscillator.connect(gain);
  gain.connect(destination);
  oscillator.start();
  void context.resume?.().catch?.(() => {});

  const pulse = () => {
    const now = context.currentTime;
    gain.gain.cancelScheduledValues(now);
    gain.gain.setValueAtTime(0.04, now);
    gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.18);
  };
  pulse();
  const timer = window.setInterval(pulse, config.pulseMs);
  const [track] = destination.stream.getAudioTracks();
  if (!track) {
    window.clearInterval(timer);
    void context.close?.();
    return null;
  }

  const stop = track.stop.bind(track);
  track.stop = () => {
    window.clearInterval(timer);
    try {
      oscillator.stop();
    } catch {
      // already stopped
    }
    void context.close?.();
    stop();
  };
  return track;
}

export function resolveSputnikRouteConfig(route) {
  const enabled = truthy(queryValue(route, 'sputnik'));
  return {
    autoJoin: truthy(queryValue(route, 'auto_join')) || truthy(queryValue(route, 'sputnik_auto_join')),
    enabled,
    fps: positiveInt(queryValue(route, 'sputnik_fps'), 10, 1, 30),
    height: positiveInt(queryValue(route, 'sputnik_h'), 360, 120, 1080),
    name: displayName(queryValue(route, 'sputnik_name') || queryValue(route, 'name')),
    pulseMs: positiveInt(queryValue(route, 'sputnik_pulse_ms'), 1800, 250, 10000),
    toneHz: positiveInt(queryValue(route, 'sputnik_tone'), 440, 80, 2000),
    width: positiveInt(queryValue(route, 'sputnik_w'), 640, 160, 1920),
  };
}

export function sputnikWorkspaceQuery(config) {
  if (!config?.enabled) return {};
  return {
    auto_join: config.autoJoin ? '1' : '0',
    sputnik: '1',
    sputnik_fps: String(config.fps || 10),
    sputnik_name: displayName(config.name),
    sputnik_pulse_ms: String(config.pulseMs || 1800),
    sputnik_tone: String(config.toneHz || 440),
  };
}

export function installSputnikMediaDeviceShim(config) {
  if (!config?.enabled || typeof navigator === 'undefined') return false;
  if (navigator[SPUTNIK_SHIM_KEY]) return true;

  const originalMediaDevices = navigator.mediaDevices || {};
  const mediaDevices = {};
  mediaDevices.getUserMedia = async (constraints = {}) => {
    const tracks = [];
    if (wantsTrack(constraints.video)) {
      const videoTrack = createVideoTrack(config);
      if (videoTrack) tracks.push(videoTrack);
    }
    if (wantsTrack(constraints.audio)) {
      const audioTrack = createAudioTrack(config);
      if (audioTrack) tracks.push(audioTrack);
    }
    if (tracks.length === 0) {
      throw new DOMException('Sputnik media constraints requested no tracks.', 'NotFoundError');
    }
    return new MediaStream(tracks);
  };
  mediaDevices.enumerateDevices = async () => ([
    { deviceId: 'sputnik-video', groupId: 'sputnik', kind: 'videoinput', label: `${config.name} camera` },
    { deviceId: 'sputnik-audio', groupId: 'sputnik', kind: 'audioinput', label: `${config.name} beep` },
    { deviceId: 'sputnik-speaker', groupId: 'sputnik', kind: 'audiooutput', label: 'Default speaker' },
  ]);
  for (const methodName of ['addEventListener', 'removeEventListener', 'dispatchEvent']) {
    const boundMethod = bindNativeMediaDeviceMethod(originalMediaDevices, methodName);
    if (boundMethod) {
      mediaDevices[methodName] = boundMethod;
    }
  }
  const getSupportedConstraints = bindNativeMediaDeviceMethod(originalMediaDevices, 'getSupportedConstraints');
  if (getSupportedConstraints) {
    mediaDevices.getSupportedConstraints = getSupportedConstraints;
  }

  try {
    Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: mediaDevices });
  } catch {
    navigator.mediaDevices = mediaDevices;
  }
  Object.defineProperty(navigator, SPUTNIK_SHIM_KEY, { configurable: true, value: true });
  return true;
}
