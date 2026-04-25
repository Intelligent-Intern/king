function diagnosticMessage(value, fallback = '') {
  if (value instanceof Error) {
    return String(value.message || fallback).trim() || fallback;
  }
  if (typeof value === 'string') {
    return value.trim() || fallback;
  }
  if (value && typeof value === 'object' && typeof value.message === 'string') {
    return String(value.message || fallback).trim() || fallback;
  }
  return fallback;
}

export function nativeAudioPlaybackBlocked(error) {
  const errorName = String(error?.name || '').trim().toLowerCase();
  const message = diagnosticMessage(error, '').trim().toLowerCase();
  return errorName === 'notallowederror'
    || message.includes('notallowederror')
    || message.includes('user gesture')
    || message.includes('play() failed because the user');
}

export function nativeAudioPlaybackInterrupted(error) {
  const errorName = String(error?.name || '').trim().toLowerCase();
  const message = diagnosticMessage(error, '').trim().toLowerCase();
  return errorName === 'aborterror'
    && (
      message.includes('interrupted by a new load request')
      || message.includes('play() request was interrupted by a new load request')
    );
}

export function summarizeNativeSender(sender) {
  const kind = String(sender?.track?.kind || '').trim().toLowerCase();
  return {
    track_kind: kind,
    track_id: String(sender?.track?.id || '').trim(),
    track_state: String(sender?.track?.readyState || '').trim().toLowerCase(),
  };
}

export function summarizeNativeReceiver(receiver) {
  return {
    track_kind: String(receiver?.track?.kind || '').trim().toLowerCase(),
    track_id: String(receiver?.track?.id || '').trim(),
    track_state: String(receiver?.track?.readyState || '').trim().toLowerCase(),
  };
}

export function summarizeNativeTransceiver(transceiver) {
  return {
    direction: String(transceiver?.direction || '').trim().toLowerCase(),
    current_direction: String(transceiver?.currentDirection || '').trim().toLowerCase(),
    mid: String(transceiver?.mid || '').trim(),
    sender: summarizeNativeSender(transceiver?.sender),
    receiver: summarizeNativeReceiver(transceiver?.receiver),
  };
}

export function nativePeerConnectionTelemetry(peer) {
  const pc = peer?.pc || null;
  const senders = typeof pc?.getSenders === 'function' ? pc.getSenders().map(summarizeNativeSender) : [];
  const receivers = typeof pc?.getReceivers === 'function' ? pc.getReceivers().map(summarizeNativeReceiver) : [];
  const transceivers = typeof pc?.getTransceivers === 'function' ? pc.getTransceivers().map(summarizeNativeTransceiver) : [];
  return {
    target_user_id: Number(peer?.userId || 0),
    connection_state: String(pc?.connectionState || '').trim().toLowerCase(),
    ice_connection_state: String(pc?.iceConnectionState || '').trim().toLowerCase(),
    ice_gathering_state: String(pc?.iceGatheringState || '').trim().toLowerCase(),
    signaling_state: String(pc?.signalingState || '').trim().toLowerCase(),
    audio_bridge_state: String(peer?.audioBridgeState || '').trim().toLowerCase(),
    remote_audio_tracks: typeof MediaStream !== 'undefined' && peer?.remoteStream instanceof MediaStream
      ? peer.remoteStream.getAudioTracks().map((track) => ({
          track_id: String(track?.id || '').trim(),
          track_state: String(track?.readyState || '').trim().toLowerCase(),
          enabled: Boolean(track?.enabled),
        }))
      : [],
    senders,
    receivers,
    transceivers,
  };
}

export function nativeSdpAudioSection(sdp) {
  const normalized = String(sdp || '').replace(/\r?\n/g, '\n');
  if (normalized.trim() === '') return '';
  const sections = normalized.split(/\nm=/);
  for (let index = 1; index < sections.length; index += 1) {
    const section = `m=${sections[index]}`;
    if (/^m=audio(?:\s|$)/.test(section)) return section;
  }
  return '';
}

export function nativeSdpSectionDirection(section) {
  const normalized = String(section || '');
  if (/\na=inactive(?:\n|$)/.test(normalized)) return 'inactive';
  if (/\na=sendonly(?:\n|$)/.test(normalized)) return 'sendonly';
  if (/\na=recvonly(?:\n|$)/.test(normalized)) return 'recvonly';
  if (/\na=sendrecv(?:\n|$)/.test(normalized)) return 'sendrecv';
  return 'sendrecv';
}

export function nativeSdpAudioSummary(sdp) {
  const audioSection = nativeSdpAudioSection(sdp);
  const portMatch = /^m=audio\s+([0-9]+)/.exec(audioSection);
  const port = portMatch ? Number(portMatch[1]) : null;
  const hasMsid = /\na=msid:[^\n]+/.test(audioSection) || /\na=ssrc:\d+ msid:/.test(audioSection);
  return {
    has_audio: audioSection !== '',
    rejected: port === 0,
    direction: audioSection === '' ? '' : nativeSdpSectionDirection(audioSection),
    has_msid: hasMsid,
  };
}

export function nativeSdpHasSendableAudio(sdp) {
  const summary = nativeSdpAudioSummary(sdp);
  if (!summary.has_audio) return false;
  if (summary.rejected) return false;
  if (summary.direction !== 'sendrecv' && summary.direction !== 'sendonly') return false;
  return summary.has_msid;
}
