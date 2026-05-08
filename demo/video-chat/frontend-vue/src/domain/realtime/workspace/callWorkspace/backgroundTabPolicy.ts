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

function normalizedConnectedParticipantCount(value) {
  const count = Number(value || 0);
  return Number.isFinite(count) && count > 0 ? Math.floor(count) : 0;
}

function shouldPauseSfuVideoForBackground(context = {}, documentRef = null) {
  const reason = String(context?.reason || '').trim().toLowerCase();
  if (context?.hidden === true || documentIsHidden(documentRef)) return true;
  return reason === 'pagehide' || reason === 'document_hidden';
}

const REMOTE_PUBLISHER_BACKGROUND_POLICY = 'preserve_remote_publisher_with_keyframe_marker';
const LOCAL_PREVIEW_BACKGROUND_POLICY = 'pause_local_preview_video_keep_audio_status';

export function createSfuBackgroundTabPolicy({
  callbacks = {},
  refs = {},
  documentRef = typeof document !== 'undefined' ? document : null,
} = {}) {
  const {
    captureClientDiagnostic = () => {},
    getConnectedParticipantCount = () => 0,
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

  let backgroundVideoPausedAtMs = 0;

  function mediaRuntimeIsSfu() {
    return String(mediaRuntimePath.value || '').trim() === 'wlvc_wasm';
  }

  function remotePublisherObligationActive() {
    const remotePeerCount = normalizedRemotePeerCount(getRemotePeerCount());
    const connectedParticipantCount = normalizedConnectedParticipantCount(getConnectedParticipantCount());
    return remotePeerCount > 0 || connectedParticipantCount > 1;
  }

  function diagnosticPayload(reason, track, overrides = {}) {
    const remotePeerCount = normalizedRemotePeerCount(getRemotePeerCount());
    const connectedParticipantCount = normalizedConnectedParticipantCount(getConnectedParticipantCount());
    const backgroundVideoPolicy = String(overrides.background_video_policy || REMOTE_PUBLISHER_BACKGROUND_POLICY);
    const backgroundPauseIntentional = Boolean(overrides.background_pause_intentional ?? false);
    const activePublisherLayer = String(overrides.active_publisher_layer || 'primary_keyframe_marker');
    return {
      reason: String(reason || ''),
      background_video_policy: backgroundVideoPolicy,
      browser_visibility_state: String(documentRef?.visibilityState || ''),
      background_pause_intentional: backgroundPauseIntentional,
      active_publisher_layer: activePublisherLayer,
      remote_peer_count: remotePeerCount,
      connected_participant_count: connectedParticipantCount,
      track_id: String(track?.id || ''),
      outgoing_video_quality_profile: String(callMediaPrefs.outgoingVideoQualityProfile || ''),
      media_runtime_path: String(mediaRuntimePath.value || ''),
    };
  }

  function preserveRemotePublisherObligationForBackground(context = {}, videoTrack = null) {
    const remotePeerCount = normalizedRemotePeerCount(getRemotePeerCount());
    const connectedParticipantCount = normalizedConnectedParticipantCount(getConnectedParticipantCount());
    const requested = requestWlvcFullFrameKeyframe('sfu_background_tab_publisher_marker', {
      requested_action: 'force_full_keyframe',
      background_publish_policy: REMOTE_PUBLISHER_BACKGROUND_POLICY,
      background_pause_intentional: false,
      browser_visibility_state: String(documentRef?.visibilityState || ''),
      remote_peer_count: remotePeerCount,
      connected_participant_count: connectedParticipantCount,
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

  function pauseLocalPreviewVideoForBackground(context = {}, videoTrack = null) {
    stopLocalEncodingPipeline();
    sfuClientRef.value?.unpublishTrack?.(videoTrack.id);
    localTracksPublishedToSfuRef?.set?.(false);
    backgroundVideoPausedAtMs = Date.now();
    captureClientDiagnostic({
      category: 'media',
      level: 'info',
      eventType: 'sfu_background_tab_video_paused',
      code: 'sfu_background_tab_video_paused',
      message: 'Background tab paused local preview SFU video while no remote publisher obligation was active.',
      payload: diagnosticPayload(context?.reason || 'background', videoTrack, {
        background_video_policy: LOCAL_PREVIEW_BACKGROUND_POLICY,
        background_pause_intentional: true,
        active_publisher_layer: 'local_preview_paused',
      }),
      immediate: true,
    });
    return true;
  }

  function pauseVideoForBackground(context = {}) {
    if (!shouldPauseSfuVideoForBackground(context, documentRef)) return false;
    if (!mediaRuntimeIsSfu()) return false;

    const videoTrack = firstLiveVideoTrack(localStreamRef.value);
    if (!videoTrack) return false;
    if (remotePublisherObligationActive()) {
      return preserveRemotePublisherObligationForBackground(context, videoTrack);
    }
    return pauseLocalPreviewVideoForBackground(context, videoTrack);
  }

  async function resumeVideoAfterForeground(context = {}) {
    if (backgroundVideoPausedAtMs <= 0) return false;
    const videoTrack = firstLiveVideoTrack(localStreamRef.value);
    if (!videoTrack) return false;
    const pausedForMs = Math.max(0, Date.now() - backgroundVideoPausedAtMs);

    captureClientDiagnostic({
      category: 'media',
      level: 'info',
      eventType: 'sfu_background_tab_video_resumed',
      code: 'sfu_background_tab_video_resumed',
      message: 'SFU video publishing remained active while backgrounded and refreshed after returning to the foreground.',
      payload: {
        ...diagnosticPayload(context?.reason || 'foreground', videoTrack, {
          background_video_policy: LOCAL_PREVIEW_BACKGROUND_POLICY,
          background_pause_intentional: true,
          active_publisher_layer: 'local_preview_republish',
        }),
        background_paused_for_ms: pausedForMs,
      },
      immediate: true,
    });

    const published = await publishLocalTracks();
    if (published) backgroundVideoPausedAtMs = 0;
    return Boolean(published);
  }

  return {
    pauseVideoForBackground,
    resumeVideoAfterForeground,
    shouldPauseSfuVideoForBackground: (context = {}) => shouldPauseSfuVideoForBackground(context, documentRef),
  };
}
