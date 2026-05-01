export function createNativePeerVideoElement(userId) {
  const video = document.createElement('video');
  video.className = 'remote-video';
  video.autoplay = true;
  video.playsInline = true;
  video.dataset.userId = String(userId);
  return video;
}

export function createNativePeerAudioElement(userId) {
  const audio = document.createElement('audio');
  audio.autoplay = true;
  audio.playsInline = true;
  audio.hidden = true;
  audio.dataset.userId = String(userId);
  audio.dataset.role = 'native-audio-bridge';
  audio.setAttribute('aria-hidden', 'true');
  const root = document.querySelector('.workspace-call-view');
  if (root instanceof HTMLElement) {
    root.appendChild(audio);
  }
  return audio;
}

export function remotePeerMediaNode(peer) {
  if (!peer || typeof peer !== 'object') return null;
  if (typeof HTMLCanvasElement !== 'undefined' && peer.decodedCanvas instanceof HTMLCanvasElement) return peer.decodedCanvas;
  if (typeof HTMLVideoElement !== 'undefined' && peer.video instanceof HTMLVideoElement) return peer.video;
  return null;
}

export function streamHasTracks(stream) {
  if (typeof MediaStream === 'undefined' || !(stream instanceof MediaStream)) return false;
  return stream.getTracks().some((track) => track?.readyState !== 'ended');
}

export function streamHasLiveTrackKind(stream, kind) {
  if (typeof MediaStream === 'undefined' || !(stream instanceof MediaStream)) return false;
  const normalizedKind = String(kind || '').trim().toLowerCase();
  return stream.getTracks().some((track) => (
    String(track?.kind || '').trim().toLowerCase() === normalizedKind
    && track?.readyState !== 'ended'
  ));
}

export function remotePeerHasRenderableMedia(peer) {
  if (!peer || typeof peer !== 'object') return false;
  if (
    typeof HTMLCanvasElement !== 'undefined'
    && peer.decodedCanvas instanceof HTMLCanvasElement
    && Number(peer.frameCount || 0) > 0
  ) {
    return true;
  }
  if (streamHasLiveTrackKind(peer.remoteStream, 'video') || streamHasLiveTrackKind(peer.stream, 'video')) return true;
  if (typeof HTMLVideoElement !== 'undefined' && peer.video instanceof HTMLVideoElement) {
    return streamHasLiveTrackKind(peer.video.srcObject, 'video') || Number(peer.video.readyState || 0) > 0;
  }
  return false;
}

export function participantHasRenderableMedia({
  currentUserId,
  localFilteredStream,
  localRawStream,
  localStream,
  nativePeers,
  remotePeers,
  userId,
}) {
  const normalizedUserId = Number(userId);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return false;
  if (normalizedUserId === currentUserId) {
    return streamHasTracks(localStream)
      || streamHasTracks(localFilteredStream)
      || streamHasTracks(localRawStream);
  }
  for (const peer of remotePeers) {
    if (Number(peer?.userId || 0) === normalizedUserId && remotePeerHasRenderableMedia(peer)) {
      return true;
    }
  }
  for (const peer of nativePeers) {
    if (Number(peer?.userId || 0) === normalizedUserId && remotePeerHasRenderableMedia(peer)) {
      return true;
    }
  }
  return false;
}

export function mediaNodeForUserId({
  currentUserId,
  localVideoElement,
  nativePeers,
  remotePeers,
  userId,
}) {
  const normalizedUserId = Number(userId);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return null;
  if (normalizedUserId === currentUserId) {
    return localVideoElement instanceof HTMLVideoElement ? localVideoElement : null;
  }
  for (const peer of remotePeers) {
    if (Number(peer?.userId || 0) === normalizedUserId && remotePeerHasRenderableMedia(peer)) {
      return remotePeerMediaNode(peer);
    }
  }
  for (const peer of nativePeers) {
    if (Number(peer?.userId || 0) === normalizedUserId && remotePeerHasRenderableMedia(peer)) {
      return remotePeerMediaNode(peer);
    }
  }
  for (const peer of remotePeers) {
    if (Number(peer?.userId || 0) === normalizedUserId) {
      return remotePeerMediaNode(peer);
    }
  }
  for (const peer of nativePeers) {
    if (Number(peer?.userId || 0) === normalizedUserId) {
      return remotePeerMediaNode(peer);
    }
  }
  return null;
}
