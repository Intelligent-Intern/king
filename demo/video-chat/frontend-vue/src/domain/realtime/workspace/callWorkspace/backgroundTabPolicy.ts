function documentIsHidden(documentRef) {
  return documentRef?.visibilityState === 'hidden';
}

function firstLiveVideoTrack(stream) {
  if (!stream || typeof stream.getVideoTracks !== 'function') return null;
  const tracks = stream.getVideoTracks();
  return tracks.find((track) => String(track?.readyState || '').toLowerCase() === 'live') || null;
}

function normalizedRemotePeerCount(value) {
  const count = Number(value || 0);
  return Number.isFinite(count) && count > 0 ? Math.floor(count) : 0;
}

function shouldPauseSfuVideoForBackground(context = {}, documentRef = null) {
  const reason = String(context?.reason || '').trim().toLowerCase();
  if (context?.hidden === true || documentIsHidden(documentRef)) return true;
  return reason === 'pagehide' || reason === 'document_hidden';
}

export function createSfuBackgroundTabPolicy({
  callbacks = {},
  refs = {},
  documentRef = typeof document !== 'undefined' ? document : null,
} = {}) {
  let backgroundVideoPaused = false;
  let backgroundVideoPausedAtMs = 0;
  let backgroundPauseCount = 0;

  const {
    captureClientDiagnostic = () => {},
    getRemotePeerCount = () => 0,
    publishLocalTracks = async () => false,
    requestWlvcFullFrameKeyframe = () => false,
    stopLocalEncodingPipeline = () => {},
  } = callbacks;

  const {
    callMediaPrefs = {},
    localStreamRef = {},
    localTracksPublishedToSfuRef = null,
    mediaRuntimePath = {},
    sfuClientRef = {},
  } = refs;

  function mediaRuntimeIsSfu() {
    return String(mediaRuntimePath.value || '').trim() === 'wlvc_wasm';
  }

  function diagnosticPayload(reason, track) {
    const remotePeerCount = normalizedRemotePeerCount(getRemotePeerCount());
    return {
      reason: String(reason || ''),
      background_video_policy: remotePeerCount > 0
        ? 'preserve_remote_publisher_with_keyframe_marker'
        : 'pause_local_preview_video_keep_audio_status',
      browser_visibility_state: String(documentRef?.visibilityState || ''),
      background_pause_intentional: remotePeerCount <= 0,
      active_publisher_layer: remotePeerCount > 0 ? 'primary_keyframe_marker' : 'none',
      remote_peer_count: remotePeerCount,
      track_id: String(track?.id || ''),
      outgoing_video_quality_profile: String(callMediaPrefs.outgoingVideoQualityProfile || ''),
      media_runtime_path: String(mediaRuntimePath.value || ''),
    };
  }

  function preserveRemotePublisherObligationForBackground(context = {}, videoTrack = null) {
    const remotePeerCount = normalizedRemotePeerCount(getRemotePeerCount());
    if (remotePeerCount <= 0) return false;

    const requested = requestWlvcFullFrameKeyframe('sfu_background_tab_publisher_marker', {
      requested_action: 'force_full_keyframe',
      background_publish_policy: 'preserve_remote_publisher_with_keyframe_marker',
      background_pause_intentional: false,
      browser_visibility_state: String(documentRef?.visibilityState || ''),
      remote_peer_count: remotePeerCount,
      track_id: String(videoTrack?.id || ''),
    });

    captureClientDiagnostic({
      category: 'media',
      level: 'warning',
      eventType: 'sfu_background_tab_publisher_obligation_preserved',
      code: 'sfu_background_tab_publisher_obligation_preserved',
      message: 'Background tab kept the SFU publisher obligation active for remote participants and requested a fresh keyframe marker.',
      payload: {
        ...diagnosticPayload(context?.reason || 'background', videoTrack),
        keyframe_marker_requested: Boolean(requested),
      },
      immediate: true,
    });
    return true;
  }

  function pauseVideoForBackground(context = {}) {
    if (!shouldPauseSfuVideoForBackground(context, documentRef)) return false;
    if (!mediaRuntimeIsSfu()) return false;
    if (backgroundVideoPaused) return false;

    const videoTrack = firstLiveVideoTrack(localStreamRef.value);
    if (!videoTrack) return false;
    if (preserveRemotePublisherObligationForBackground(context, videoTrack)) return true;

    backgroundVideoPaused = true;
    backgroundVideoPausedAtMs = Date.now();
    backgroundPauseCount += 1;
    stopLocalEncodingPipeline();

    try {
      sfuClientRef.value?.unpublishTrack?.(videoTrack.id);
      localTracksPublishedToSfuRef?.set?.(false);
    } catch {
      // The foreground reconnect path republishes the track if the socket changed.
    }

    captureClientDiagnostic({
      category: 'media',
      level: 'warning',
      eventType: 'sfu_background_tab_video_paused',
      code: 'sfu_background_tab_video_paused',
      message: 'SFU video publishing was paused because the browser moved the call tab into the background.',
      payload: {
        ...diagnosticPayload(context?.reason || 'background', videoTrack),
        background_pause_count: backgroundPauseCount,
      },
      immediate: true,
    });
    return true;
  }

  async function resumeVideoAfterForeground(context = {}) {
    if (!backgroundVideoPaused) return false;
    const videoTrack = firstLiveVideoTrack(localStreamRef.value);
    const pausedForMs = Math.max(0, Date.now() - backgroundVideoPausedAtMs);
    backgroundVideoPaused = false;
    backgroundVideoPausedAtMs = 0;

    captureClientDiagnostic({
      category: 'media',
      level: 'info',
      eventType: 'sfu_background_tab_video_resumed',
      code: 'sfu_background_tab_video_resumed',
      message: 'SFU video publishing is resuming after the call tab returned to the foreground.',
      payload: {
        ...diagnosticPayload(context?.reason || 'foreground', videoTrack),
        background_paused_for_ms: pausedForMs,
      },
      immediate: true,
    });

    await publishLocalTracks();
    return true;
  }

  return {
    pauseVideoForBackground,
    resumeVideoAfterForeground,
    shouldPauseSfuVideoForBackground: (context = {}) => shouldPauseSfuVideoForBackground(context, documentRef),
  };
}
