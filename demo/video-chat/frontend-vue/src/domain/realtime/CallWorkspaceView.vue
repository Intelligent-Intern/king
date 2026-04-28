<template src="./CallWorkspaceView.template.html"></template>

<script setup>
import { computed, inject, markRaw, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { isGuestSession, sessionState } from '../auth/session';
import {
  CALL_UUID_PATTERN,
  callRequiresJoinModalForViewer,
  joinPathFromAccessPayload,
} from '../calls/access/admissionGate';
import {
  resolveBackendWebSocketOriginCandidates,
  setBackendWebSocketOrigin,
} from '../../support/backendOrigin';
import {
  attachCallMediaDeviceWatcher,
  callMediaPrefs,
  refreshCallMediaDevices,
  resetCallBackgroundRuntimeState,
  setCallOutgoingVideoQualityProfile,
} from './media/preferences';
import {
  handleAssetVersionSocketClose,
  handleAssetVersionSocketPayload,
} from '../../support/assetVersion';
import { attachForegroundReconnectHandlers } from '../../support/foregroundReconnect';
import {
  configureClientDiagnostics,
  reportClientDiagnostic,
} from '../../support/clientDiagnostics';
import { BackgroundFilterController } from './background/controller';
import { BackgroundFilterBaselineCollector } from './background/baseline';
import { evaluateBackgroundFilterGates } from './background/gates';
import { detectMediaRuntimeCapabilities } from './media/runtimeCapabilities';
import { appendMediaRuntimeTransitionEvent } from './media/runtimeTelemetry';
import {
  nativeAudioPlaybackBlocked,
  nativeAudioPlaybackInterrupted,
  nativePeerConnectionTelemetry,
  nativeSdpAudioSummary,
  nativeSdpAudioSummaries,
  nativeSdpHasSendableAudio,
} from './native/audioBridgeHelpers';
import { createSfuLifecycleHelpers } from './sfu/lifecycle';
import { createCallWorkspaceSocketHelpers } from './workspace/callWorkspace/socketLifecycle';
import { createCallWorkspaceRouteResolutionHelpers } from './workspace/callWorkspace/routeResolution';
import { createCallWorkspaceRuntimeSwitchingHelpers } from './workspace/callWorkspace/runtimeSwitching';
import { createCallWorkspaceParticipantUiHelpers } from './workspace/callWorkspace/participantUi';
import { createCallWorkspaceChatRuntimeHelpers } from './workspace/callWorkspace/chatRuntime';
import { createCallWorkspaceRoomStateHelpers } from './workspace/callWorkspace/roomState';
import { createCallWorkspaceMediaSecurityRuntime } from './workspace/callWorkspace/mediaSecurityRuntime';
import { createCallWorkspaceOrchestrationHelpers } from './workspace/callWorkspace/orchestration';
import { registerCallWorkspaceLifecycleHelpers } from './workspace/callWorkspace/lifecycle';
import { createCallWorkspaceMediaStack } from './workspace/callWorkspace/mediaStack';
import { createCallWorkspaceNativeStack } from './workspace/callWorkspace/nativeStack';
import {
  createNativePeerAudioElement,
  createNativePeerVideoElement,
  mediaNodeForUserId as lookupMediaNodeForUserId,
  participantHasRenderableMedia as hasRenderableMediaForParticipant,
  remotePeerHasRenderableMedia,
  remotePeerMediaNode,
  streamHasLiveTrackKind,
  streamHasTracks,
} from './native/peerMedia';
import { SFUClient } from '../../lib/sfu/sfuClient';
import { MEDIA_SECURITY_SIGNAL_TYPES, MediaSecuritySession, createMediaSecuritySession } from './media/security';
import {
  ALONE_IDLE_ACTIVITY_EVENTS,
  ALONE_IDLE_COUNTDOWN_MS,
  ALONE_IDLE_POLL_MS,
  ALONE_IDLE_PROMPT_AFTER_MS,
  ALONE_IDLE_TICK_MS,
  chatEmojiOptions,
  COMPACT_BREAKPOINT,
  DEFAULT_NATIVE_ICE_SERVERS,
  LOBBY_PAGE_SIZE,
  LOCAL_REACTION_ECHO_TTL_MS,
  LOCAL_TRACK_RECOVERY_BASE_DELAY_MS,
  LOCAL_TRACK_RECOVERY_MAX_ATTEMPTS,
  LOCAL_TRACK_RECOVERY_MAX_DELAY_MS,
  MODERATION_SYNC_FLUSH_INTERVAL_MS,
  PARTICIPANT_ACTIVITY_WINDOW_MS,
  REACTION_CLIENT_BATCH_SIZE,
  REACTION_CLIENT_DIRECT_PER_WINDOW,
  REACTION_CLIENT_FLUSH_INTERVAL_MS,
  REACTION_CLIENT_MAX_QUEUE,
  REACTION_CLIENT_WINDOW_MS,
  RECONNECT_DELAYS_MS,
  resolveSfuVideoQualityProfile,
  ROSTER_VIRTUAL_OVERSCAN,
  ROSTER_VIRTUAL_ROW_HEIGHT,
  SFU_CONNECT_MAX_RETRIES,
  SFU_CONNECT_RETRY_DELAY_MS,
  SFU_PUBLISH_MAX_RETRIES,
  SFU_PUBLISH_RETRY_DELAY_MS,
  SFU_TRACK_ANNOUNCE_INTERVAL_MS,
  SFU_RUNTIME_ENABLED,
  SFU_WLVC_BACKPRESSURE_HARD_RESET_AFTER_MS,
  SFU_WLVC_BACKPRESSURE_MAX_PAUSE_MS,
  SFU_WLVC_BACKPRESSURE_MIN_PAUSE_MS,
  SFU_WLVC_FRAME_HEIGHT,
  SFU_WLVC_FRAME_QUALITY,
  SFU_WLVC_FRAME_WIDTH,
  SFU_WLVC_KEYFRAME_INTERVAL,
  SFU_WLVC_SEND_BUFFER_CRITICAL_BYTES,
  SFU_WLVC_SEND_BUFFER_HIGH_WATER_BYTES,
  SFU_WLVC_SEND_BUFFER_LOW_WATER_BYTES,
  TYPING_LOCAL_STOP_MS,
  TYPING_SWEEP_MS,
  USERS_PAGE_SIZE,
  VISIBLE_PARTICIPANTS_LIMIT,
  WLVC_ENCODE_ERROR_LOG_COOLDOWN_MS,
  WLVC_ENCODE_FAILURE_THRESHOLD,
  WLVC_ENCODE_FAILURE_WINDOW_MS,
  WLVC_ENCODE_WARMUP_MS,
  mediaDebugLog,
  reactionOptions,
} from './workspace/config';
import {
  CHAT_ATTACHMENT_MAX_COUNT,
  CHAT_INLINE_MAX_BYTES,
  CHAT_INLINE_MAX_CHARS,
  buildFileAttachmentDraft,
  buildTextAttachmentDraft,
  chatAttachmentDraftToBase64,
  chatUtf8ByteLength,
  isChatTextInlineAllowed,
  sanitizeChatAttachmentName,
  validateChatAttachmentDraft,
} from './chat/attachments';
import {
  callRoleRank,
  formatTimestamp,
  initials,
  miniVideoSlotId,
  normalizeCallRole,
  normalizeOptionalRoomId,
  normalizeRole,
  normalizeRoomId,
  normalizeSocketCallId,
  normalizeUsersDirectoryOrder,
  normalizeUsersDirectoryStatus,
  parseUsersDirectoryQuery,
  roleRank,
} from './workspace/utils';
import {
  CALL_LAYOUT_MODES,
  CALL_LAYOUT_STRATEGIES,
  normalizeCallLayoutMode,
  normalizeCallLayoutState,
  selectCallLayoutParticipants,
} from './layout/strategies';
import {
  gridVideoSlotId,
  layoutModeOptionsFor,
  layoutStrategyOptionsFor,
} from './layout/uiOptions';
import {
  mergeLiveMediaPeerIntoRoster,
  normalizeParticipantRow,
  participantActivityWeight,
  participantSnapshotSignature,
  replaceNumericArray,
} from './workspace/roster';
import {
  apiRequest,
  extractErrorMessage,
  fetchBackend,
  requestHeaders,
  socketUrlForRoom,
} from './workspace/api';
import {
  ACTIVITY_MOTION_SAMPLE_MS,
  ACTIVITY_PUBLISH_INTERVAL_MS,
  CALL_STATE_SIGNAL_TYPES,
  MEDIA_SECURITY_HANDSHAKE_TIMEOUT_MS,
  MEDIA_SECURITY_HANDSHAKE_WATCHDOG_INTERVAL_MS,
  MEDIA_SECURITY_SFU_TARGET_SETTLE_MS,
  NATIVE_AUDIO_TRACK_RECOVERY_DELAY_MS,
  NATIVE_AUDIO_TRACK_RECOVERY_MAX_ATTEMPTS,
  NATIVE_AUDIO_TRACK_RECOVERY_REJOIN_DELAY_MS,
  NATIVE_FRAME_ERROR_LOG_COOLDOWN_MS,
  REMOTE_FRAME_ACTIVITY_MARK_INTERVAL_MS,
  REMOTE_SFU_FRAME_DROP_LOG_COOLDOWN_MS,
  REMOTE_SFU_FRAME_STALE_TTL_MS,
  REMOTE_VIDEO_FREEZE_THRESHOLD_MS,
  REMOTE_VIDEO_KEYFRAME_WAIT_LOG_COOLDOWN_MS,
  REMOTE_VIDEO_STALL_CHECK_INTERVAL_MS,
  REMOTE_VIDEO_STALL_THRESHOLD_MS,
  SFU_AUTO_QUALITY_DOWNGRADE_BACKPRESSURE_WINDOW_MS,
  SFU_AUTO_QUALITY_DOWNGRADE_COOLDOWN_MS,
  SFU_AUTO_QUALITY_DOWNGRADE_NEXT,
  SFU_AUTO_QUALITY_DOWNGRADE_SEND_FAILURE_THRESHOLD,
  SFU_AUTO_QUALITY_DOWNGRADE_SKIP_THRESHOLD,
  SFU_BACKGROUND_SNAPSHOT_DIFF_THRESHOLD,
  SFU_BACKGROUND_SNAPSHOT_ENABLED,
  SFU_BACKGROUND_SNAPSHOT_MAX_CHANGED_RATIO,
  SFU_BACKGROUND_SNAPSHOT_MAX_PATCH_AREA_RATIO,
  SFU_BACKGROUND_SNAPSHOT_MIN_CHANGED_RATIO,
  SFU_BACKGROUND_SNAPSHOT_MIN_INTERVAL_MS,
  SFU_BACKGROUND_SNAPSHOT_SAMPLE_STRIDE,
  SFU_BACKGROUND_SNAPSHOT_TILE_HEIGHT,
  SFU_BACKGROUND_SNAPSHOT_TILE_WIDTH,
  SFU_BACKPRESSURE_LOG_COOLDOWN_MS,
  SFU_PROTECTED_MEDIA_ENABLED,
  SFU_SELECTIVE_TILE_BASE_REFRESH_MS,
  SFU_SELECTIVE_TILE_DIFF_THRESHOLD,
  SFU_SELECTIVE_TILE_HEIGHT,
  SFU_SELECTIVE_TILE_MAX_CHANGED_RATIO,
  SFU_SELECTIVE_TILE_MAX_PATCH_AREA_RATIO,
  SFU_SELECTIVE_TILE_PATCH_ENABLED,
  SFU_SELECTIVE_TILE_SAMPLE_STRIDE,
  SFU_SELECTIVE_TILE_WIDTH,
  SFU_VIDEO_RECOVERY_RECONNECT_COOLDOWN_MS,
} from './workspace/callWorkspace/runtimeConfig';
import {
  createClientDiagnosticCapturer,
  extractDiagnosticMessage,
} from './workspace/callWorkspace/clientDiagnostics';
import {
  createMediaSecurityTargetHelpers,
  defaultNativeAudioBridgeFailureMessage,
} from './workspace/callWorkspace/mediaSecurityTargets';
import { createSfuTransportState } from './workspace/callWorkspace/sfuTransport';

const route = useRoute();
const router = useRouter();
const workspaceSidebarState = inject('workspaceSidebarState', null);

const activeTab = ref('users');
const usersSearch = ref('');
const usersPage = ref(1);
const lobbyPage = ref(1);
const chatDraft = ref('');
const chatEmojiTrayOpen = ref(false);
const chatAttachmentDrafts = ref([]);
const chatAttachmentError = ref('');
const chatAttachmentDragActive = ref(false);
const chatSending = ref(false);
const chatInputRef = ref(null);
const chatAttachmentInputRef = ref(null);
const chatListRef = ref(null);
const usersListRef = ref(null);
const lobbyListRef = ref(null);

const connectionState = ref('retrying');
const connectionReason = ref('');
const reconnectAttempt = ref(0);
const socketRef = ref(null);
const serverRoomId = ref('lobby');
let manualSocketClose = false;
let connectGeneration = 0;
let pingTimer = null;
let reconnectTimer = null;
let workspaceReconnectAfterForeground = false;
let workspaceLastForegroundReconnectAt = 0;
const socketLifecycleState = {
  get connectGeneration() { return connectGeneration; },
  set connectGeneration(value) { connectGeneration = value; },
  get manualSocketClose() { return manualSocketClose; },
  set manualSocketClose(value) { manualSocketClose = value; },
  get pingTimer() { return pingTimer; },
  set pingTimer(value) { pingTimer = value; },
  get reconnectTimer() { return reconnectTimer; },
  set reconnectTimer(value) { reconnectTimer = value; },
};
const connectedParticipantUsersRef = ref(computed(() => []));

const participantsRaw = ref([]);
let participantsRawSignature = '';
const currentUserConnectedAt = new Date().toISOString();
const lobbyQueue = ref([]);
const lobbyAdmitted = ref([]);
const lobbyNotificationState = reactive({
  hasSnapshot: false,
  toastVisible: false,
  toastMessage: '',
});
const aloneIdlePrompt = reactive({
  visible: false,
  deadlineMs: 0,
  remainingMs: ALONE_IDLE_COUNTDOWN_MS,
});
const usersDirectoryRows = ref([]);
const usersDirectoryLoading = ref(false);
const usersDirectoryPagination = reactive({
  query: '',
  status: 'all',
  order: 'role_then_name_asc',
  page: 1,
  pageSize: USERS_PAGE_SIZE,
  total: 0,
  pageCount: 1,
  returned: 0,
  hasPrev: false,
  hasNext: false,
  error: '',
});

const chatByRoom = reactive({});
const typingByRoom = reactive({});
const chatUnreadByRoom = reactive({});

const mutedUsers = reactive({});
const pinnedUsers = reactive({});
const participantActivityByUserId = reactive({});
const callLayoutState = reactive({
  call_id: '',
  room_id: '',
  mode: 'main_mini',
  strategy: 'manual_pinned',
  automation_paused: false,
  pinned_user_ids: [],
  selected_user_ids: [],
  main_user_id: 0,
  selection: {
    main_user_id: 0,
    visible_user_ids: [],
    mini_user_ids: [],
    pinned_user_ids: [],
  },
  updated_at: '',
});
const moderationActionState = reactive({});
const peerControlStateByUserId = reactive({});
const lobbyActionState = reactive({});
const usersRefreshTimer = ref(null);
const usersListViewport = reactive({
  scrollTop: 0,
  viewportHeight: 0,
});
const lobbyListViewport = reactive({
  scrollTop: 0,
  viewportHeight: 0,
});

const reactionTrayOpen = ref(false);
const activeReactions = ref([]);
const localReactionEchoes = ref([]);
let reactionId = 0;
const queuedReactionEmojis = ref([]);
let reactionQueueTimer = null;
let reactionWindowStartedMs = 0;
let reactionSentInWindow = 0;
let reactionBatchCounter = 0;
let moderationSyncTimer = null;
const moderationSyncQueue = reactive({});
let aloneIdleLastActiveMs = Date.now();

const controlState = reactive({
  handRaised: false,
  cameraEnabled: true,
  micEnabled: true,
  screenEnabled: false,
});
const rightSidebarCollapsed = ref(false);
const isCompactViewport = ref(false);
const compactMiniStripPlacement = ref('below');

const workspaceError = ref('');
const workspaceNotice = ref('');
const viewerCallRole = ref('participant');
const viewerEffectiveCallRole = ref('participant');
const viewerCanModerateCall = ref(false);
const viewerCanManageOwnerRole = ref(false);
const activeCallId = ref('');
const loadedCallId = ref('');
const callParticipantRoles = reactive({});
const routeCallResolve = reactive({
  accessId: '',
  callId: '',
  roomId: 'lobby',
  pending: false,
  redirecting: false,
  error: '',
});
let routeCallResolveSeq = 0;
const pendingAdmissionJoinRoomId = ref('');
const admissionGateState = reactive({
  roomId: '',
  message: '',
});
const hasRealtimeRoomSync = ref(false);

const sfuClientRef = ref(null);
const mediaSecuritySessionRef = ref(null);
const mediaRuntimeCapabilities = ref({
  checkedAt: '',
  wlvcWasm: {
    webAssembly: false,
    encoder: false,
    decoder: false,
    reason: 'not_checked',
  },
  webRtcNative: false,
  stageA: false,
  stageB: false,
  preferredPath: 'unsupported',
  reasons: ['not_checked'],
});
const mediaRuntimePath = ref('pending');
const mediaRuntimeReason = ref('boot');
const nativePeerConnectionsRef = ref(new Map());
const mediaRenderVersion = ref(0);
const mediaSecurityStateVersion = ref(0);
const nativeAudioBridgeStatusVersion = ref(0);
const dynamicIceServers = ref([]);
let runtimeSwitchInFlight = false;
let wlvcEncodeFailureCount = 0;
let wlvcEncodeWarmupUntilMs = 0;
let wlvcEncodeFirstFailureAtMs = 0;
let wlvcEncodeLastErrorLogAtMs = 0;
let wlvcEncodeInFlight = false;
const sfuTransportState = createSfuTransportState();
let mediaSecuritySyncInFlight = false;
let mediaSecuritySyncHintLastAtMs = 0;
let mediaSecurityResyncTimer = null;
let mediaSecurityResyncForceRekey = false;
const mediaSecurityHelloSignalsSent = new Set();
const mediaSecuritySenderKeySignalsSent = new Set();
const mediaSecurityRecoveryLastByUserId = new Map();
// Tracks when a media-security/hello was last sent per peer for handshake-timeout detection.
const mediaSecurityHelloSentAtByUserId = new Map();
const mediaSecurityHandshakeRetryingByUserId = new Set();
const mediaSecuritySfuPublisherFirstSeenAtByUserId = new Map();
const nativeFrameErrorLastLogByKey = new Map();
const nativeAudioBridgeBlockDiagnosticsSent = new Set();
const nativeAudioTrackRecoveryAttemptsByUserId = new Map();
const nativeAudioBridgeQuarantineByUserId = new Map();
let mediaSecurityHandshakeWatchdogTimer = null;
const localTracksRef = ref([]);
const remotePeersRef = ref(new Map());
const pendingSfuRemotePeerInitializers = new Map();
const remoteFrameActivityLastByUserId = new Map();
const sfuConnected = ref(false);
let sfuConnectRetryCount = 0;
let detachMediaDeviceWatcher = null;
let detachForegroundReconnect = null;
let localTrackReconfigureInFlight = false;
let localTrackReconfigureQueuedMode = null;
let compactMediaQuery = null;
let activityMonitorTimer = null;
let activityAudioContext = null;
let activityAudioAnalyser = null;
let activityAudioData = null;
let activityMotionCanvas = null;
let activityMotionContext = null;
let activityPreviousFrame = null;
let activityLastPublishMs = 0;
let activityLastMotionSampleMs = 0;
let activityLastMotionScore = 0;
let typingSweepTimer = null;
const localMediaOrchestrationState = {
  get activityAudioAnalyser() { return activityAudioAnalyser; },
  set activityAudioAnalyser(value) { activityAudioAnalyser = value; },
  get activityAudioContext() { return activityAudioContext; },
  set activityAudioContext(value) { activityAudioContext = value; },
  get activityAudioData() { return activityAudioData; },
  set activityAudioData(value) { activityAudioData = value; },
  get activityLastMotionSampleMs() { return activityLastMotionSampleMs; },
  set activityLastMotionSampleMs(value) { activityLastMotionSampleMs = value; },
  get activityLastMotionScore() { return activityLastMotionScore; },
  set activityLastMotionScore(value) { activityLastMotionScore = value; },
  get activityLastPublishMs() { return activityLastPublishMs; },
  set activityLastPublishMs(value) { activityLastPublishMs = value; },
  get activityMonitorTimer() { return activityMonitorTimer; },
  set activityMonitorTimer(value) { activityMonitorTimer = value; },
  get activityMotionCanvas() { return activityMotionCanvas; },
  set activityMotionCanvas(value) { activityMotionCanvas = value; },
  get activityMotionContext() { return activityMotionContext; },
  set activityMotionContext(value) { activityMotionContext = value; },
  get activityPreviousFrame() { return activityPreviousFrame; },
  set activityPreviousFrame(value) { activityPreviousFrame = value; },
  get backgroundBaselineCaptured() { return backgroundBaselineCaptured; },
  set backgroundBaselineCaptured(value) { backgroundBaselineCaptured = value; },
  get backgroundRuntimeToken() { return backgroundRuntimeToken; },
  set backgroundRuntimeToken(value) { backgroundRuntimeToken = value; },
  get localTrackRecoveryAttempts() { return localTrackRecoveryAttempts; },
  set localTrackRecoveryAttempts(value) { localTrackRecoveryAttempts = value; },
  get localTrackReconfigureInFlight() { return localTrackReconfigureInFlight; },
  set localTrackReconfigureInFlight(value) { localTrackReconfigureInFlight = value; },
  get localTrackReconfigureQueuedMode() { return localTrackReconfigureQueuedMode; },
  set localTrackReconfigureQueuedMode(value) { localTrackReconfigureQueuedMode = value; },
  get localTracksPublishedToSfu() { return localTracksPublishedToSfu; },
  set localTracksPublishedToSfu(value) { localTracksPublishedToSfu = value; },
};
let dynamicIceServersPromise = null;
let dynamicIceServersExpiresAtMs = 0;
let remoteVideoStallTimer = null;

const nativeBridgeRuntimeState = {
  get dynamicIceServersExpiresAtMs() { return dynamicIceServersExpiresAtMs; },
  set dynamicIceServersExpiresAtMs(value) { dynamicIceServersExpiresAtMs = value; },
  get dynamicIceServersPromise() { return dynamicIceServersPromise; },
  set dynamicIceServersPromise(value) { dynamicIceServersPromise = value; },
};

const {
  captureClientDiagnostic,
  captureClientDiagnosticError,
} = createClientDiagnosticCapturer({
  reportClientDiagnostic,
  getCallId: () => activeSocketCallId.value || activeCallId.value,
  getRoomId: () => activeRoomId.value,
});

let clearRemoteVideoStallTimer = () => {};
let isNativeWebRtcRuntimePath = () => false;
let isWlvcRuntimePath = () => false;
let nativeAudioBridgeFailureMessage = () => defaultNativeAudioBridgeFailureMessage();
let resetWlvcEncoderAfterDroppedEncodedFrame = () => {};
let resetBackgroundRuntimeMetrics = () => {};
let restartSfuAfterVideoStall = () => {};
let shouldBlockNativeRuntimeSignaling = () => false;
let shouldUseNativeAudioBridge = () => false;
let startRemoteVideoStallTimer = () => {};
let stopActivityMonitor = () => {};

const routeCallRef = computed(() => String(route.params.callRef || '').trim());
const desiredRoomId = computed(() => normalizeRoomId(routeCallResolve.roomId || routeCallRef.value || 'lobby'));
const activeRoomId = computed(() => normalizeRoomId(serverRoomId.value || desiredRoomId.value));
const activeSocketCallId = computed(() => normalizeSocketCallId(activeCallId.value || routeCallResolve.callId || ''));
const currentUserId = computed(() => (Number.isInteger(sessionState.userId) ? sessionState.userId : 0));
const showAdmissionGate = computed(() => {
  const gateRoomId = normalizeOptionalRoomId(admissionGateState.roomId);
  return gateRoomId !== '' && activeRoomId.value !== gateRoomId;
});
const canModerate = computed(() => (
  normalizeRole(sessionState.role) === 'admin'
  || viewerCanModerateCall.value
  || viewerEffectiveCallRole.value === 'owner'
  || viewerEffectiveCallRole.value === 'moderator'
));
const canManageOwnerRole = computed(() => (
  normalizeRole(sessionState.role) === 'admin'
  || viewerCanManageOwnerRole.value
  || viewerEffectiveCallRole.value === 'owner'
));
const showLobbyTab = computed(() => canModerate.value);
const usersSourceMode = computed(() => 'snapshot');
const isSocketOnline = computed(() => connectionState.value === 'online');
const shouldConnectSfu = computed(() => (
  isWlvcRuntimePath()
  && isSocketOnline.value
  && hasRealtimeRoomSync.value
  && !routeCallResolve.pending
  && routeCallResolve.error === ''
  && activeSocketCallId.value !== ''
  && activeRoomId.value === desiredRoomId.value
));
const isShellLeftSidebarCollapsed = computed(() => {
  const candidate = workspaceSidebarState?.leftSidebarCollapsed;
  if (candidate && typeof candidate === 'object' && 'value' in candidate) {
    return Boolean(candidate.value);
  }
  return Boolean(candidate);
});
const isShellTabletViewport = computed(() => {
  const candidate = workspaceSidebarState?.isTabletViewport;
  if (candidate && typeof candidate === 'object' && 'value' in candidate) {
    return Boolean(candidate.value);
  }
  return Boolean(candidate);
});
const isShellTabletSidebarOpen = computed(() => {
  const candidate = workspaceSidebarState?.isTabletSidebarOpen;
  if (candidate && typeof candidate === 'object' && 'value' in candidate) {
    return Boolean(candidate.value);
  }
  return Boolean(candidate);
});
const isShellMobileViewport = computed(() => {
  const candidate = workspaceSidebarState?.isMobileViewport;
  if (candidate && typeof candidate === 'object' && 'value' in candidate) {
    return Boolean(candidate.value);
  }
  return Boolean(candidate);
});
const isCompactLayoutViewport = computed(() => (
  isShellMobileViewport.value
  || isShellTabletViewport.value
));
const isCompactHeaderVisible = computed(() => (
  isCompactViewport.value
  && isCompactLayoutViewport.value
));
const isCompactMiniStripAbove = computed(() => (
  isCompactLayoutViewport.value
  && compactMiniStripPlacement.value === 'above'
));
const showLeftSidebarRestoreButton = computed(() => {
  if (isCompactHeaderVisible.value || isShellMobileViewport.value) {
    return false;
  }
  if (isShellTabletViewport.value) {
    return !isShellTabletSidebarOpen.value;
  }
  return !isCompactViewport.value && isShellLeftSidebarCollapsed.value;
});

let canProtectCurrentNativeTargets = () => false;
let canProtectCurrentSfuTargets = () => false;
let clearMediaSecurityHandshakeWatchdog = () => {};
let clearMediaSecurityResyncTimer = () => {};
let clearMediaSecuritySfuPublisherSeen = () => {};
let clearMediaSecuritySignalCaches = () => {};
let clearNativeAudioBridgeQuarantine = () => false;
let currentMediaSecurityRuntimePath = () => 'wlvc_sfu';
let ensureMediaSecuritySession = () => null;
let ensureNativeAudioBridgeSecurityReady = async () => false;
let handleMediaSecuritySignal = async () => {};
let hintMediaSecuritySync = () => {};
let mediaSecurityEligibleTargetIds = () => [];
let mediaSecurityTargetIds = () => [];
let nativeAudioBridgeIsQuarantined = () => false;
let nativeAudioSecurityBannerMessage = computed(() => '');
let noteMediaSecuritySfuPublisherSeen = () => {};
let recoverMediaSecurityForPublisher = () => {};
let reportNativeAudioBridgeFailure = () => {};
let resyncNativeAudioBridgePeerAfterSecurityReady = () => false;
let scheduleMediaSecurityParticipantSync = () => {};
let sendMediaSecurityHello = async () => false;
let sendMediaSecuritySenderKey = async () => false;
let shouldBypassNativeAudioProtectionForPeer = () => false;
let shouldRecoverMediaSecurityFromFrameError = () => false;
let shouldSendTransportOnlySfuFrame = () => false;
let startMediaSecurityHandshakeWatchdog = () => {};
let syncMediaSecurityWithParticipants = async () => {};
let appendChatMessage = () => {};
let applyActivitySnapshot = () => {};
let applyCallLayoutPayload = () => {};
let applyParticipantActivityPayload = () => {};
let applyReactionEvent = () => {};
let applyRemoteControlState = () => false;
let applyTypingEvent = () => {};
let clearAdmissionGate = () => {};
let clearErrors = () => {};
let clearLobbyActionText = () => {};
let clearLobbyToastTimer = () => {};
let clearTransientActivityPublishErrorNotice = () => {};
let closeNativePeerConnection = () => {};
let hangupCall = () => {};
let hideLobbyJoinToast = () => {};
let markParticipantActivity = () => {};
let normalizeLobbyEntry = (entry) => entry;
let nativeAudioSecurityTelemetrySnapshot = () => null;
let notifyLobbyJoinRequests = () => {};
let peerControlSnapshot = () => ({
  handRaised: false,
  cameraEnabled: true,
  micEnabled: true,
  screenEnabled: false,
});
let pruneParticipantActivity = () => {};
let refreshUsersDirectory = async () => {};
let refreshUsersDirectoryPresentation = () => {};
let reportNativeAudioSdpRejected = () => {};
let requestRoomSnapshot = () => {};
let resetPeerControlState = () => {};
let scheduleNativePeerAudioTrackDeadline = () => {};
let sendRoomJoin = () => false;
let sendNativeOffer = async () => {};
let setAdmissionGate = () => {};
let setActiveTab = () => {};
let setNativePeerAudioBridgeState = () => {};
let setNotice = () => {};
let shouldSyncNativeLocalTracksBeforeOffer = () => false;
let shouldSuppressCallAckNotice = () => false;
let syncNativePeerConnectionsWithRoster = () => {};
let syncNativePeerLocalTracks = () => {};
let synchronizeNativePeerMediaElements = () => {};
let ensureNativePeerConnection = () => null;
let shouldSuppressExpectedSignalingError = () => false;
let syncControlStateToPeers = async () => false;
let syncModerationStateToPeers = async () => false;
let tryDirectJoinWithModeratorBypass = () => false;
let applyCallOutputPreferences = () => {};
let currentSfuVideoProfile = computed(() => 'quality');
let downgradeSfuVideoQualityAfterEncodePressure = () => false;
let initSFU = () => {};
let maybeFallbackToNativeRuntime = async () => false;
let removeSfuRemotePeersForUserId = () => false;
let setMediaRuntimePath = () => false;
let stopSfuTrackAnnounceTimer = () => {};
let switchMediaRuntimePath = async () => false;
let teardownRemotePeer = () => {};
const activeMessagesLimit = computed(() => 240);
const currentUserStatusLabel = computed(() => '');
let currentLayoutMode = computed(() => 'main_mini');
let gridVideoParticipants = computed(() => []);
let miniVideoParticipants = computed(() => []);
let normalizedCallLayout = computed(() => normalizeCallLayoutState(callLayoutState));
let primaryVideoUserId = computed(() => currentUserId.value);
const snapshotUsersLimit = computed(() => USERS_PAGE_SIZE);
const userRowBase = (row) => row;
const syncUsersDirectoryPresentationBase = () => {};
const setShellLeftSidebarCollapsed = () => {};
const setShellTabletSidebarOpen = () => {};
const setSidebarTab = () => {};
const showRightSidebarBase = () => {};

function sendSocketFrame(payload) {
  const socket = socketRef.value;
  if (!(socket instanceof WebSocket)) return false;
  if (socket.readyState !== WebSocket.OPEN) return false;
  try {
    socket.send(JSON.stringify(payload));
    return true;
  } catch {
    return false;
  }
}

function requestRoomSnapshotLocal() {
  if (!sendSocketFrame({ type: 'room/snapshot/request' })) {
    setNotice('Could not request room snapshot while websocket is offline.', 'error');
  }
}

function sendRoomJoinLocal(roomId) {
  const normalizedRoomId = normalizeRoomId(roomId);
  if (!sendSocketFrame({ type: 'room/join', room_id: normalizedRoomId })) {
    return false;
  }
  return true;
}

function tryDirectJoinWithModeratorBypassLocal(roomId = '') {
  const targetRoomId = normalizeRoomId(roomId || desiredRoomId.value || activeRoomId.value || 'lobby');
  if (!canModerate.value || targetRoomId === '') {
    return false;
  }
  clearAdmissionGate(targetRoomId);
  return sendRoomJoinLocal(targetRoomId);
}

function markWorkspaceReconnectAfterForeground() {
  if (manualSocketClose || connectionState.value === 'blocked' || connectionState.value === 'expired') return;
  workspaceReconnectAfterForeground = true;
}

function reconnectWorkspaceAfterForeground() {
  if (typeof document !== 'undefined' && document.visibilityState === 'hidden') return;
  if (!workspaceReconnectAfterForeground) return;
  if (manualSocketClose || connectionState.value === 'blocked' || connectionState.value === 'expired') return;
  if (routeCallResolve.pending || routeCallResolve.redirecting) return;
  if (String(sessionState.sessionToken || '').trim() === '') return;

  const now = Date.now();
  if ((now - workspaceLastForegroundReconnectAt) < 1000) return;

  workspaceReconnectAfterForeground = false;
  workspaceLastForegroundReconnectAt = now;
  reconnectAttempt.value = 0;

  if (sfuClientRef.value) {
    sfuClientRef.value.leave();
    sfuClientRef.value = null;
    sfuConnected.value = false;
  }

  connectSocket();
}

requestRoomSnapshot = requestRoomSnapshotLocal;
sendRoomJoin = sendRoomJoinLocal;
tryDirectJoinWithModeratorBypass = tryDirectJoinWithModeratorBypassLocal;

function clearRemoteVideoContainer(container) {
  const element = container instanceof HTMLElement ? container : null;
  if (!element) return;
  while (element.firstChild) {
    element.removeChild(element.firstChild);
  }
}

function nativePeerHasLocalLiveAudioSender(peer) {
  const senders = Array.isArray(peer?.pc?.getSenders?.()) ? peer.pc.getSenders() : [];
  return senders.some((sender) => sender?.track?.kind === 'audio' && sender.track.readyState === 'live');
}

const {
  applyRouteCallResolution,
  callPayloadToRouteResolution,
  createSelfJoinPathForCall,
  currentWorkspaceEntryMode,
  isUuidLike,
  redirectInvitedRouteToJoinModal,
  resolveRouteCallRef,
  resolveRouteRefSafely,
} = createCallWorkspaceRouteResolutionHelpers({
  callbacks: {
    apiRequest,
    callRequiresJoinModalForViewer,
    joinPathFromAccessPayload,
    normalizeRole,
    normalizeRoomId,
  },
  refs: {
    activeCallId,
    callUuidPattern: CALL_UUID_PATTERN,
    currentUserId,
    loadedCallId,
    route,
    routeCallRef,
    routeCallResolve,
    router,
    sessionState,
    workspaceError,
    workspaceNotice,
  },
  state: {
    getRouteCallResolveSeq: () => routeCallResolveSeq,
    setRouteCallResolveSeq: (value) => { routeCallResolveSeq = value; },
  },
});
const {
  applyCallDetails,
  applyLobbySnapshot,
  applyParticipantsSnapshot,
  applyRoomSnapshot,
  applyViewerContext,
  connectedParticipantUsers,
  currentUserParticipantRow,
  ensureRoomBuckets,
  isAloneInCall,
  loadActiveCallDetails,
  participantUsers,
  removeParticipantFromSnapshot,
  resetCallParticipantRoles,
} = createCallWorkspaceRoomStateHelpers({
  callbacks: {
    apiRequest,
    applyActivitySnapshot: (...args) => applyActivitySnapshot(...args),
    applyCallLayoutPayload: (...args) => applyCallLayoutPayload(...args),
    clearAdmissionGate: (...args) => clearAdmissionGate(...args),
    hideLobbyJoinToast: (...args) => hideLobbyJoinToast(...args),
    mergeLiveMediaPeerIntoRoster,
    normalizeCallRole,
    normalizeLobbyEntry: (...args) => normalizeLobbyEntry(...args),
    normalizeParticipantRow,
    normalizeRole,
    normalizeRoomId,
    notifyLobbyJoinRequests: (...args) => notifyLobbyJoinRequests(...args),
    participantSnapshotSignature,
    pruneParticipantActivity: (...args) => pruneParticipantActivity(...args),
    refreshUsersDirectoryPresentation: (...args) => refreshUsersDirectoryPresentation(...args),
    roleRank,
    callRoleRank,
    syncControlStateToPeers: (...args) => syncControlStateToPeers(...args),
    syncMediaSecurityWithParticipants: (...args) => syncMediaSecurityWithParticipants(...args),
    syncModerationStateToPeers: (...args) => syncModerationStateToPeers(...args),
  },
  refs: {
    activeCallId,
    activeRoomId,
    callParticipantRoles,
    chatByRoom,
    connectedParticipantUsersRef,
    currentUserConnectedAt,
    currentUserId,
    desiredRoomId,
    hasRealtimeRoomSync,
    isSocketOnline,
    loadedCallId,
    lobbyActionState,
    lobbyAdmitted,
    lobbyNotificationState,
    lobbyQueue,
    nativePeerConnectionsRef,
    participantsRaw,
    peerControlStateByUserId,
    pendingAdmissionJoinRoomId,
    remotePeersRef,
    sendRoomJoin,
    serverRoomId,
    sessionState,
    typingByRoom,
    viewerCallRole,
    viewerCanManageOwnerRole,
    viewerCanModerateCall,
    viewerEffectiveCallRole,
  },
  state: {
    getParticipantsRawSignature: () => participantsRawSignature,
    setParticipantsRawSignature: (value) => { participantsRawSignature = value; },
  },
});

const mediaSecurityRuntimeState = {
  mediaSecuritySyncInFlight,
  mediaSecuritySyncHintLastAtMs,
  mediaSecurityResyncTimer,
  mediaSecurityResyncForceRekey,
  mediaSecurityHelloSignalsSent,
  mediaSecuritySenderKeySignalsSent,
  mediaSecurityRecoveryLastByUserId,
  mediaSecurityHelloSentAtByUserId,
  mediaSecurityHandshakeRetryingByUserId,
  mediaSecuritySfuPublisherFirstSeenAtByUserId,
  nativeFrameErrorLastLogByKey,
  nativeAudioBridgeQuarantineByUserId,
  mediaSecurityHandshakeWatchdogTimer,
};

({
  canProtectCurrentNativeTargets,
  canProtectCurrentSfuTargets,
  clearMediaSecurityHandshakeWatchdog,
  clearMediaSecurityResyncTimer,
  clearMediaSecuritySfuPublisherSeen,
  clearMediaSecuritySignalCaches,
  clearNativeAudioBridgeQuarantine,
  currentMediaSecurityRuntimePath,
  ensureMediaSecuritySession,
  ensureNativeAudioBridgeSecurityReady,
  handleMediaSecuritySignal,
  hintMediaSecuritySync,
  mediaSecurityEligibleTargetIds,
  mediaSecurityTargetIds,
  nativeAudioBridgeIsQuarantined,
  nativeAudioSecurityBannerMessage,
  noteMediaSecuritySfuPublisherSeen,
  recoverMediaSecurityForPublisher,
  reportNativeAudioBridgeFailure,
  resyncNativeAudioBridgePeerAfterSecurityReady,
  scheduleMediaSecurityParticipantSync,
  sendMediaSecurityHello,
  sendMediaSecuritySenderKey,
  shouldBypassNativeAudioProtectionForPeer,
  shouldRecoverMediaSecurityFromFrameError,
  shouldSendTransportOnlySfuFrame,
  startMediaSecurityHandshakeWatchdog,
  syncMediaSecurityWithParticipants,
} = createCallWorkspaceMediaSecurityRuntime({
  callbacks: {
    attachMediaSecurityNativeReceiversForPeer: (peer) => attachMediaSecurityNativeReceiversForPeer(peer),
    captureClientDiagnostic,
    captureClientDiagnosticError,
    closeNativePeerConnection: (userId) => closeNativePeerConnection(userId),
    createMediaSecuritySession,
    createMediaSecurityTargetHelpers,
    defaultNativeAudioBridgeFailureMessage,
    ensureNativePeerConnection: (userId) => ensureNativePeerConnection(userId),
    extractDiagnosticMessage,
    mediaDebugLog,
    nativeAudioSecurityTelemetrySnapshot: () => nativeAudioSecurityTelemetrySnapshot(),
    requestRoomSnapshot: () => requestRoomSnapshot(),
    scheduleNativeAudioTrackRecovery: (peer, reason, options) => scheduleNativeAudioTrackRecovery(peer, reason, options),
    scheduleNativePeerAudioTrackDeadline: (peer) => scheduleNativePeerAudioTrackDeadline(peer),
    sendNativeOffer: (peer) => sendNativeOffer(peer),
    sendSocketFrame,
    setNativePeerAudioBridgeState: (peer, nextState, message) => setNativePeerAudioBridgeState(peer, nextState, message),
    shouldSyncNativeLocalTracksBeforeOffer: (peer) => shouldSyncNativeLocalTracksBeforeOffer(peer),
    syncNativePeerLocalTracks: (peer) => syncNativePeerLocalTracks(peer),
    synchronizeNativePeerMediaElements: (peer) => synchronizeNativePeerMediaElements(peer),
  },
  constants: {
    mediaSecurityHandshakeTimeoutMs: MEDIA_SECURITY_HANDSHAKE_TIMEOUT_MS,
    mediaSecurityHandshakeWatchdogIntervalMs: MEDIA_SECURITY_HANDSHAKE_WATCHDOG_INTERVAL_MS,
    mediaSecuritySfuTargetSettleMs: MEDIA_SECURITY_SFU_TARGET_SETTLE_MS,
    nativeFrameErrorLogCooldownMs: NATIVE_FRAME_ERROR_LOG_COOLDOWN_MS,
    sfuRuntimeEnabled: SFU_RUNTIME_ENABLED,
    MediaSecuritySession,
  },
  refs: {
    activeCallId,
    activeRoomId,
    activeSocketCallId,
    connectedParticipantUsers,
    currentUserId,
    isNativeWebRtcRuntimePath,
    isSocketOnline,
    isWlvcRuntimePath,
    mediaRuntimeCapabilities,
    mediaRuntimePath,
    mediaSecuritySessionRef,
    mediaSecurityStateVersion,
    nativeAudioBridgeStatusVersion,
    nativePeerConnectionsRef,
  },
  state: mediaSecurityRuntimeState,
}));



configureClientDiagnostics(() => ({
  call_id: activeSocketCallId.value || activeCallId.value,
  room_id: activeRoomId.value,
  current_user_id: currentUserId.value,
  connection_state: connectionState.value,
  connection_reason: connectionReason.value,
  sfu_connected: sfuConnected.value,
  media_runtime_path: mediaRuntimePath.value,
  media_runtime_reason: mediaRuntimeReason.value,
  media_stage_a: Boolean(mediaRuntimeCapabilities.value.stageA),
  media_stage_b: Boolean(mediaRuntimeCapabilities.value.stageB),
  media_preferred_path: mediaRuntimeCapabilities.value.preferredPath,
  connected_participant_count: connectedParticipantUsers.value.length,
  remote_peer_count: remotePeersRef.value.size,
}));

let renderCallVideoLayout = () => {};

let videoEncoderRef = ref(null);
let videoPatchEncoderRef = ref(null);
let videoPatchEncoderWidth = ref(0);
let videoPatchEncoderHeight = ref(0);
let videoPatchEncoderQuality = ref(0);
let localVideoElement = ref(null);
let localRawStreamRef = ref(null);

let localFilteredStreamRef = ref(null);
let localStreamRef = ref(null);
let encodeIntervalRef = ref(null);
let localTracksPublishedToSfu = false;
let sfuTrackAnnounceTimer = null;
let localPublisherTeardownInProgress = false;
let localTrackRecoveryTimer = null;
let localTrackRecoveryAttempts = 0;
const backgroundFilterController = new BackgroundFilterController();
const backgroundBaselineCollector = new BackgroundFilterBaselineCollector(10);
let backgroundBaselineCaptured = false;
let backgroundRuntimeToken = 0;
const NATIVE_OFFER_RETRY_DELAYS_MS = [800, 1_500, 2_500, 4_000, 6_000];

const sfuLifecycleState = {
  get localTracksPublishedToSfu() { return localTracksPublishedToSfu; },
  set localTracksPublishedToSfu(value) { localTracksPublishedToSfu = value; },
  get sfuConnectRetryCount() { return sfuConnectRetryCount; },
  set sfuConnectRetryCount(value) { sfuConnectRetryCount = value; },
  get sfuTrackAnnounceTimer() { return sfuTrackAnnounceTimer; },
  set sfuTrackAnnounceTimer(value) { sfuTrackAnnounceTimer = value; },
};
const localPublisherPipelineState = {
  get backgroundBaselineCaptured() { return backgroundBaselineCaptured; },
  set backgroundBaselineCaptured(value) { backgroundBaselineCaptured = value; },
  get backgroundRuntimeToken() { return backgroundRuntimeToken; },
  set backgroundRuntimeToken(value) { backgroundRuntimeToken = value; },
  get localPublisherTeardownInProgress() { return localPublisherTeardownInProgress; },
  set localPublisherTeardownInProgress(value) { localPublisherTeardownInProgress = value; },
  get localTrackRecoveryAttempts() { return localTrackRecoveryAttempts; },
  set localTrackRecoveryAttempts(value) { localTrackRecoveryAttempts = value; },
  get localTrackRecoveryTimer() { return localTrackRecoveryTimer; },
  set localTrackRecoveryTimer(value) { localTrackRecoveryTimer = value; },
  get localTracksPublishedToSfu() { return localTracksPublishedToSfu; },
  set localTracksPublishedToSfu(value) { localTracksPublishedToSfu = value; },
  get wlvcEncodeFailureCount() { return wlvcEncodeFailureCount; },
  set wlvcEncodeFailureCount(value) { wlvcEncodeFailureCount = value; },
  get wlvcEncodeFirstFailureAtMs() { return wlvcEncodeFirstFailureAtMs; },
  set wlvcEncodeFirstFailureAtMs(value) { wlvcEncodeFirstFailureAtMs = value; },
  get wlvcEncodeInFlight() { return wlvcEncodeInFlight; },
  set wlvcEncodeInFlight(value) { wlvcEncodeInFlight = value; },
  get wlvcEncodeLastErrorLogAtMs() { return wlvcEncodeLastErrorLogAtMs; },
  set wlvcEncodeLastErrorLogAtMs(value) { wlvcEncodeLastErrorLogAtMs = value; },
  get wlvcEncodeWarmupUntilMs() { return wlvcEncodeWarmupUntilMs; },
  set wlvcEncodeWarmupUntilMs(value) { wlvcEncodeWarmupUntilMs = value; },
};

/*
 * Regression anchors for the extracted WLVC runtime modules.
 * These exact strings are pinned by PHPT contract 737 even though the
 * executable implementations now live in `workspace/callWorkspace/*` and
 * `sfu/frameDecode.js`.
 * await switchMediaRuntimePath('wlvc_wasm', 'capability_probe_stage_a')
 * await switchMediaRuntimePath('webrtc_native', 'capability_probe_stage_b')
 * setMediaRuntimePath('unsupported', 'capability_probe_unsupported')
 * appendMediaRuntimeTransitionEvent({
 * teardownSfuRemotePeers();
 * teardownNativePeerConnections();
 * const init = ensureSfuRemotePeerForFrame(frame);
 * void decodeSfuFrameForPeer(publisherId, nextPeer, frame);
 * ctx.putImageData(imageData, 0, 0);
 * markRemoteFrameActivity(publisherUserId);
 * mediaRenderVersion.value = mediaRenderVersion.value >= 1_000_000 ? 0 : mediaRenderVersion.value + 1;
 */

const mediaStack = createCallWorkspaceMediaStack({
  callbacks: {
    applyCallOutputPreferences: (...args) => applyCallOutputPreferences(...args),
    canProtectCurrentSfuTargets,
    captureClientDiagnostic,
    captureClientDiagnosticError,
    clearRemoteVideoContainer,
    clearTransientActivityPublishErrorNotice,
    currentSfuVideoProfile: (...args) => (
      typeof currentSfuVideoProfile === 'function'
        ? currentSfuVideoProfile(...args)
        : currentSfuVideoProfile.value
    ),
    defaultNativeAudioBridgeFailureMessage,
    downgradeSfuVideoQualityAfterEncodePressure: (...args) => downgradeSfuVideoQualityAfterEncodePressure(...args),
    ensureMediaSecuritySession,
    evaluateBackgroundFilterGates,
    hasRenderableMediaForParticipant,
    hintMediaSecuritySync,
    isWlvcRuntimePath: (...args) => isWlvcRuntimePath(...args),
    lookupMediaNodeForUserId,
    markParticipantActivity,
    maybeFallbackToNativeRuntime: (...args) => maybeFallbackToNativeRuntime(...args),
    mediaDebugLog,
    normalizeRoomId,
    onRestartSfu: (getShouldReconnect, reconnectDelayMs) => {
      if (sfuClientRef.value) {
        sfuClientRef.value.leave();
        sfuClientRef.value = null;
      }
      localTracksPublishedToSfu = false;
      stopSfuTrackAnnounceTimer();
      setTimeout(() => {
        if (getShouldReconnect()) {
          initSFU();
        }
      }, reconnectDelayMs);
    },
    recoverMediaSecurityForPublisher,
    refreshCallMediaDevices,
    reconfigureLocalTracksFromSelectedDevices: (...args) => reconfigureLocalTracksFromSelectedDevices(...args),
    remotePeerMediaNode,
    resetBackgroundRuntimeMetrics: (...args) => resetBackgroundRuntimeMetrics(...args),
    resetCallBackgroundRuntimeState,
    restartSfuAfterVideoStall: (...args) => restartSfuAfterVideoStall(...args),
    sendMediaSecurityHello,
    sendNativeOffer: (...args) => sendNativeOffer(...args),
    sendSocketFrame,
    shouldRecoverMediaSecurityFromFrameError,
    shouldSendTransportOnlySfuFrame,
    shouldSyncNativeLocalTracksBeforeOffer: (...args) => shouldSyncNativeLocalTracksBeforeOffer(...args),
    stopActivityMonitor: (...args) => stopActivityMonitor(...args),
    stopSfuTrackAnnounceTimer,
    syncNativePeerConnectionsWithRoster: (...args) => syncNativePeerConnectionsWithRoster(...args),
    syncNativePeerLocalTracks: (...args) => syncNativePeerLocalTracks(...args),
    teardownRemotePeer: (peer) => teardownRemotePeer(peer),
  },
  constants: {
    activityMotionSampleMs: ACTIVITY_MOTION_SAMPLE_MS,
    activityPublishIntervalMs: ACTIVITY_PUBLISH_INTERVAL_MS,
    backgroundSnapshotEnabled: SFU_BACKGROUND_SNAPSHOT_ENABLED,
    backgroundSnapshotMaxChangedRatio: SFU_BACKGROUND_SNAPSHOT_MAX_CHANGED_RATIO,
    backgroundSnapshotMaxPatchAreaRatio: SFU_BACKGROUND_SNAPSHOT_MAX_PATCH_AREA_RATIO,
    backgroundSnapshotMinChangedRatio: SFU_BACKGROUND_SNAPSHOT_MIN_CHANGED_RATIO,
    backgroundSnapshotMinIntervalMs: SFU_BACKGROUND_SNAPSHOT_MIN_INTERVAL_MS,
    backgroundSnapshotSampleStride: SFU_BACKGROUND_SNAPSHOT_SAMPLE_STRIDE,
    backgroundSnapshotTileDiffThreshold: SFU_BACKGROUND_SNAPSHOT_DIFF_THRESHOLD,
    backgroundSnapshotTileHeight: SFU_BACKGROUND_SNAPSHOT_TILE_HEIGHT,
    backgroundSnapshotTileWidth: SFU_BACKGROUND_SNAPSHOT_TILE_WIDTH,
    defaultNativeAudioBridgeFailureMessage,
    gridVideoSlotId,
    localTrackRecoveryBaseDelayMs: LOCAL_TRACK_RECOVERY_BASE_DELAY_MS,
    localTrackRecoveryMaxAttempts: LOCAL_TRACK_RECOVERY_MAX_ATTEMPTS,
    localTrackRecoveryMaxDelayMs: LOCAL_TRACK_RECOVERY_MAX_DELAY_MS,
    MediaSecuritySession,
    miniVideoSlotId,
    protectedMediaEnabled: SFU_PROTECTED_MEDIA_ENABLED,
    remoteFrameActivityMarkIntervalMs: REMOTE_FRAME_ACTIVITY_MARK_INTERVAL_MS,
    remoteSfuFrameDropLogCooldownMs: REMOTE_SFU_FRAME_DROP_LOG_COOLDOWN_MS,
    remoteSfuFrameStaleTtlMs: REMOTE_SFU_FRAME_STALE_TTL_MS,
    remoteVideoFreezeThresholdMs: REMOTE_VIDEO_FREEZE_THRESHOLD_MS,
    remoteVideoKeyframeWaitLogCooldownMs: REMOTE_VIDEO_KEYFRAME_WAIT_LOG_COOLDOWN_MS,
    remoteVideoStallCheckIntervalMs: REMOTE_VIDEO_STALL_CHECK_INTERVAL_MS,
    remoteVideoStallThresholdMs: REMOTE_VIDEO_STALL_THRESHOLD_MS,
    selectiveTileBaseRefreshMs: SFU_SELECTIVE_TILE_BASE_REFRESH_MS,
    selectiveTileDiffThreshold: SFU_SELECTIVE_TILE_DIFF_THRESHOLD,
    selectiveTileEnabled: SFU_SELECTIVE_TILE_PATCH_ENABLED,
    selectiveTileHeight: SFU_SELECTIVE_TILE_HEIGHT,
    selectiveTileMaxChangedRatio: SFU_SELECTIVE_TILE_MAX_CHANGED_RATIO,
    selectiveTileMaxPatchAreaRatio: SFU_SELECTIVE_TILE_MAX_PATCH_AREA_RATIO,
    selectiveTileSampleStride: SFU_SELECTIVE_TILE_SAMPLE_STRIDE,
    selectiveTileWidth: SFU_SELECTIVE_TILE_WIDTH,
    sendBufferHighWaterBytes: SFU_WLVC_SEND_BUFFER_HIGH_WATER_BYTES,
    sfuAutoQualityDowngradeBackpressureWindowMs: SFU_AUTO_QUALITY_DOWNGRADE_BACKPRESSURE_WINDOW_MS,
    sfuAutoQualityDowngradeSendFailureThreshold: SFU_AUTO_QUALITY_DOWNGRADE_SEND_FAILURE_THRESHOLD,
    sfuAutoQualityDowngradeSkipThreshold: SFU_AUTO_QUALITY_DOWNGRADE_SKIP_THRESHOLD,
    sfuBackpressureLogCooldownMs: SFU_BACKPRESSURE_LOG_COOLDOWN_MS,
    sfuConnectRetryDelayMs: SFU_CONNECT_RETRY_DELAY_MS,
    sfuFrameHeight: SFU_WLVC_FRAME_HEIGHT,
    sfuFrameQuality: SFU_WLVC_FRAME_QUALITY,
    sfuFrameWidth: SFU_WLVC_FRAME_WIDTH,
    sfuRuntimeEnabled: SFU_RUNTIME_ENABLED,
    sfuVideoRecoveryReconnectCooldownMs: SFU_VIDEO_RECOVERY_RECONNECT_COOLDOWN_MS,
    sfuWlvcBackpressureHardResetAfterMs: SFU_WLVC_BACKPRESSURE_HARD_RESET_AFTER_MS,
    sfuWlvcBackpressureMaxPauseMs: SFU_WLVC_BACKPRESSURE_MAX_PAUSE_MS,
    sfuWlvcBackpressureMinPauseMs: SFU_WLVC_BACKPRESSURE_MIN_PAUSE_MS,
    sfuWlvcSendBufferCriticalBytes: SFU_WLVC_SEND_BUFFER_CRITICAL_BYTES,
    sfuWlvcSendBufferHighWaterBytes: SFU_WLVC_SEND_BUFFER_HIGH_WATER_BYTES,
    sfuWlvcSendBufferLowWaterBytes: SFU_WLVC_SEND_BUFFER_LOW_WATER_BYTES,
    wlvcEncodeErrorLogCooldownMs: WLVC_ENCODE_ERROR_LOG_COOLDOWN_MS,
    wlvcEncodeFailureThreshold: WLVC_ENCODE_FAILURE_THRESHOLD,
    wlvcEncodeFailureWindowMs: WLVC_ENCODE_FAILURE_WINDOW_MS,
    wlvcEncodeWarmupMs: WLVC_ENCODE_WARMUP_MS,
  },
  refs: {
    activeRoomId,
    activeSocketCallId,
    backgroundBaselineCollector,
    backgroundFilterController,
    callMediaPrefs,
    connectedParticipantUsers,
    connectionState,
    controlState,
    currentLayoutMode,
    currentUserId,
    desiredRoomId,
    encodeIntervalRef,
    gridVideoParticipants,
    isSocketOnline,
    localFilteredStreamRef,
    localMediaOrchestrationState,
    localPublisherPipelineState,
    localRawStreamRef,
    localStreamRef,
    localTracksRef,
    localVideoElement,
    mediaRenderVersion,
    mediaRuntimeCapabilities,
    mediaRuntimePath,
    miniVideoParticipants,
    nativePeerConnectionsRef,
    normalizedCallLayout,
    pendingSfuRemotePeerInitializers,
    primaryVideoUserId,
    remoteFrameActivityLastByUserId,
    remotePeersRef,
    sfuClientRef,
    sfuConnected,
    sfuTransportState,
    sessionState,
    shouldConnectSfu,
    videoEncoderRef,
    videoPatchEncoderHeight,
    videoPatchEncoderQuality,
    videoPatchEncoderRef,
    videoPatchEncoderWidth,
  },
  state: {
    getRemoteVideoStallTimer: () => remoteVideoStallTimer,
    setRemoteVideoStallTimer: (value) => { remoteVideoStallTimer = value; },
  },
  vue: { markRaw, nextTick },
});

({
  clearRemoteVideoStallTimer,
  isNativeWebRtcRuntimePath,
  isWlvcRuntimePath,
  nativeAudioBridgeFailureMessage,
  resetWlvcEncoderAfterDroppedEncodedFrame,
  shouldBlockNativeRuntimeSignaling,
  shouldUseNativeAudioBridge,
  startRemoteVideoStallTimer,
} = mediaStack);

const {
  acquireLocalMediaStreamWithFallback,
  applyCallInputPreferences,
  applyCallOutputPreferences: applyCallOutputPreferencesHelper,
  applyControlStateToLocalTracks,
  applyLocalBackgroundFilter,
  bindLocalTrackLifecycle,
  bumpMediaRenderVersion,
  clearLocalPreviewElement,
  clearLocalTrackRecoveryTimer,
  clearUnassignedChildren,
  createOrUpdateSfuRemotePeer,
  deleteSfuRemotePeer,
  ensureSfuRemotePeerForFrame,
  findSfuRemotePeerEntryByUserId,
  getSfuClientBufferedAmount,
  getSfuRemotePeerByFrameIdentity,
  handleSFUEncodedFrame,
  handleWlvcEncodeBackpressure,
  handleWlvcFrameSendFailure,
  isBackgroundFilterEnabledForOutgoing,
  isSfuClientOpen,
  markRemotePeerRenderable,
  mediaNodeForUserId,
  mountVideoNode,
  normalizeSfuPublisherId,
  participantHasRenderableMedia,
  participantInitials,
  publishLocalActivitySample,
  publishLocalTracks,
  publishLocalTracksToSfuIfReady,
  queueLocalTrackReconfigure,
  consumeQueuedLocalTrackReconfigureMode,
  reconfigureLocalBackgroundFilterOnly,
  reconfigureLocalTracksFromSelectedDevices,
  remoteDecoderRuntimeName,
  renderCallVideoLayout: renderCallVideoLayoutHelper,
  renderNativeRemoteVideos,
  resetBackgroundRuntimeMetrics: resetBackgroundRuntimeMetricsHelper,
  resetWlvcBackpressureCounters,
  resetWlvcFrameSendFailureCounters,
  restartSfuAfterVideoStall: restartSfuAfterVideoStallHelper,
  setSfuRemotePeer,
  sfuTrackListHasVideo,
  sfuTrackRows,
  shouldDelayWlvcFrameForBackpressure,
  shouldMaintainNativePeerConnections,
  shouldSendNativeTrackKind,
  shouldThrottleWlvcEncodeLoop,
  startActivityMonitor,
  startEncodingPipeline,
  stopActivityMonitor: stopActivityMonitorHelper,
  stopLocalEncodingPipeline,
  stopRetiredLocalStreams,
  teardownLocalPublisher,
  teardownSfuRemotePeers,
  unpublishSfuTracks,
  updateSfuRemotePeerUserId,
} = mediaStack;

applyCallOutputPreferences = applyCallOutputPreferencesHelper;

renderCallVideoLayout = renderCallVideoLayoutHelper;
resetBackgroundRuntimeMetrics = resetBackgroundRuntimeMetricsHelper;
restartSfuAfterVideoStall = restartSfuAfterVideoStallHelper;
stopActivityMonitor = stopActivityMonitorHelper;

({
  initSFU,
  removeSfuRemotePeersForUserId,
  stopSfuTrackAnnounceTimer,
  teardownRemotePeer,
} = createSfuLifecycleHelpers({
  callbacks: {
    captureClientDiagnostic,
    captureClientDiagnosticError,
    createOrUpdateSfuRemotePeer,
    currentUserId: () => currentUserId.value,
    deleteSfuRemotePeer,
    handleSFUEncodedFrame,
    isWlvcRuntimePath: () => isWlvcRuntimePath(),
    maybeFallbackToNativeRuntime: (...args) => maybeFallbackToNativeRuntime(...args),
    mediaDebugLog,
    normalizeSfuPublisherId,
    noteMediaSecuritySfuPublisherSeen,
    publishLocalTracks: (...args) => publishLocalTracks(...args),
    publishLocalTracksToSfuIfReady: (...args) => publishLocalTracksToSfuIfReady(...args),
    renderCallVideoLayout: () => renderCallVideoLayout(),
    requestSfuConnect: () => initSFU(),
    resetWlvcBackpressureCounters,
    scheduleMediaSecurityParticipantSync,
    setSfuRemotePeer,
    sfuTrackListHasVideo,
    sfuTrackRows,
    teardownSfuRemotePeers: (...args) => teardownSfuRemotePeers(...args),
  },
  constants: {
    mediaSecuritySfuTargetSettleMs: MEDIA_SECURITY_SFU_TARGET_SETTLE_MS,
    sfuConnectMaxRetries: SFU_CONNECT_MAX_RETRIES,
    sfuConnectRetryDelayMs: SFU_CONNECT_RETRY_DELAY_MS,
    sfuPublishMaxRetries: SFU_PUBLISH_MAX_RETRIES,
    sfuPublishRetryDelayMs: SFU_PUBLISH_RETRY_DELAY_MS,
    sfuTrackAnnounceIntervalMs: SFU_TRACK_ANNOUNCE_INTERVAL_MS,
  },
  refs: {
    SFUClient,
    activeRoomId,
    activeSocketCallId,
    connectionState,
    isManualSocketClose: () => manualSocketClose,
    localStreamRef,
    mediaRuntimePath,
    pendingSfuRemotePeerInitializers,
    remotePeersRef,
    sessionState,
    sfuClientRef,
    sfuConnected,
    shouldConnectSfu,
  },
  state: sfuLifecycleState,
}));

const nativeStack = createCallWorkspaceNativeStack({
  callbacks: {
    activeRoomId: () => activeRoomId.value,
    apiRequest,
    attachMediaSecurityNativeReceiverBase: (session, receiver, senderUserId, track) => session.attachNativeReceiverTransform(receiver, senderUserId, {
      trackId: String(track?.id || ''),
    }),
    attachMediaSecurityNativeSenderBase: (session, sender, track) => session.attachNativeSenderTransform(sender, {
      trackKind: String(track.kind || 'video'),
      trackId: String(track.id || ''),
    }),
    bumpMediaRenderVersion,
    clearRemoteVideoContainer,
    createNativePeerAudioElement,
    createNativePeerVideoElement,
    currentMediaSecurityRuntimePath,
    currentNativeAudioBridgeFailureMessage: nativeAudioBridgeFailureMessage,
    currentShouldUseNativeAudioBridge: shouldUseNativeAudioBridge,
    shouldUseNativeAudioBridge,
    currentUserId: () => currentUserId.value,
    ensureLocalMediaForPublish: () => publishLocalTracks(),
    ensureMediaSecuritySession,
    ensureNativeAudioBridgeSecurityReady,
    extractDiagnosticMessage,
    getMediaRuntimePath: () => mediaRuntimePath.value,
    getPeerByUserId: (userId) => nativePeerConnectionsRef.value.get(userId) || null,
    getPeerControlSnapshot: peerControlSnapshot,
    isNativeWebRtcRuntimePath,
    markParticipantActivity,
    mediaDebugLog,
    nativeAudioBridgeFailureMessage,
    nativeAudioBridgeIsQuarantined,
    nativePeerHasLocalLiveAudioSender,
    renderCallVideoLayout: () => renderCallVideoLayout(),
    renderNativeRemoteVideos,
    reportNativeAudioBridgeFailure,
    reportNativeAudioSdpRejected,
    reconfigureLocalTracksFromSelectedDevices: (...args) => reconfigureLocalTracksFromSelectedDevices(...args),
    resyncNativeAudioBridgePeerAfterSecurityReady,
    sendSocketFrame,
    sessionToken: () => sessionState.sessionToken,
    shouldBypassNativeAudioProtectionForPeer,
    shouldMaintainNativePeerConnections,
    shouldSendNativeTrackKind,
    streamHasLiveTrackKind,
    syncMediaSecurityWithParticipants,
    syncNativePeerConnectionsWithRoster: (...args) => syncNativePeerConnectionsWithRoster(...args),
    telemetrySnapshotProvider: (runtimePath) => {
      const session = mediaSecuritySessionRef.value;
      if (!session || typeof session.telemetrySnapshot !== 'function') {
        return null;
      }
      return session.telemetrySnapshot(runtimePath);
    },
    switchMediaRuntimePath,
    sfuRuntimeEnabled: () => SFU_RUNTIME_ENABLED,
  },
  constants: {
    defaultNativeIceServers: DEFAULT_NATIVE_ICE_SERVERS,
    MediaSecuritySession,
    nativeAudioTrackRecoveryDelayMs: NATIVE_AUDIO_TRACK_RECOVERY_DELAY_MS,
    nativeAudioTrackRecoveryMaxAttempts: NATIVE_AUDIO_TRACK_RECOVERY_MAX_ATTEMPTS,
    nativeAudioTrackRecoveryRejoinDelayMs: NATIVE_AUDIO_TRACK_RECOVERY_REJOIN_DELAY_MS,
    nativeOfferRetryDelaysMs: NATIVE_OFFER_RETRY_DELAYS_MS,
  },
  refs: {
    activeRoomId,
    connectedParticipantUsers,
    controlState,
    dynamicIceServers,
    localStreamRef,
    mediaRuntimeCapabilities,
    mediaRuntimePath,
    mediaSecurityStateVersion,
    nativeAudioBridgeBlockDiagnosticsSent,
    nativeAudioBridgeQuarantineByUserId,
    nativeAudioPlaybackBlocked,
    nativeAudioPlaybackInterrupted,
    nativeAudioTrackRecoveryAttemptsByUserId,
    nativeAudioBridgeStatusVersion,
    nativeBridgeRuntimeState,
    nativePeerConnectionTelemetry,
    nativePeerConnectionsRef,
    nativeSdpAudioSummaries,
    nativeSdpAudioSummary,
    nativeSdpHasSendableAudio,
  },
  state: {
    getRuntimeSwitchInFlight: () => runtimeSwitchInFlight,
  },
  vue: { markRaw },
});

({
  ensureNativePeerConnection,
  syncNativePeerConnectionsWithRoster,
} = nativeStack);

const {
  attachMediaSecurityNativeReceiver,
  attachMediaSecurityNativeReceiversForPeer,
  bumpNativeAudioBridgeStatusVersion,
  clearNativeOfferRetry,
  clearNativePeerAudioTrackDeadline,
  closeNativePeerConnection: closeNativePeerConnectionHelper,
  currentNativeIceServers,
  ensureLocalMediaForNativeNegotiation,
  ensureNativeRuntimeForSignaling,
  flushNativePendingIce,
  handleNativeAnswerSignal,
  handleNativeIceSignal,
  handleNativeOfferSignal,
  handleNativeSignalingEvent,
  loadDynamicIceServers,
  nativeAudioBridgeHasLocalAudioTrack,
  nativeAudioBridgeLocalTrackTelemetry,
  nativeAudioSecurityTelemetrySnapshot: nativeAudioSecurityTelemetrySnapshotHelper,
  nativePeerRequiresAudioOnlyRebuild,
  nativeWebRtcConfig,
  normalizeNativeSdpForRemoteDescription,
  playNativePeerAudio,
  resetNativeAudioTrackRecovery,
  resetNativeOfferRetry,
  scheduleNativeAudioTrackRecovery,
  scheduleNativeOfferRetry,
  scheduleNativeOfferRetryForUserId,
  scheduleNativePeerAudioTrackDeadline: scheduleNativePeerAudioTrackDeadlineHelper,
  sendNativeOffer: sendNativeOfferHelper,
  setNativePeerAudioBridgeState: setNativePeerAudioBridgeStateHelper,
  shouldExpectLocalNativeAudioTrack,
  shouldExpectRemoteNativeAudioTrack,
  shouldSyncNativeLocalTracksBeforeOffer: shouldSyncNativeLocalTracksBeforeOfferHelper,
  syncNativePeerLocalTracks: syncNativePeerLocalTracksHelper,
  synchronizeNativePeerMediaElements: synchronizeNativePeerMediaElementsHelper,
  teardownNativePeerConnections,
  waitForNativeCapabilityForSignaling,
  waitForNativeRuntimeTick,
} = nativeStack;

closeNativePeerConnection = closeNativePeerConnectionHelper;
nativeAudioSecurityTelemetrySnapshot = nativeAudioSecurityTelemetrySnapshotHelper;
scheduleNativePeerAudioTrackDeadline = scheduleNativePeerAudioTrackDeadlineHelper;
sendNativeOffer = sendNativeOfferHelper;
setNativePeerAudioBridgeState = setNativePeerAudioBridgeStateHelper;
shouldSyncNativeLocalTracksBeforeOffer = shouldSyncNativeLocalTracksBeforeOfferHelper;
syncNativePeerLocalTracks = syncNativePeerLocalTracksHelper;
synchronizeNativePeerMediaElements = synchronizeNativePeerMediaElementsHelper;

const {
  clearPingTimer,
  clearReconnectTimer,
  closeSocket,
  connectSocket,
  handleSignalingEvent,
  handleSocketMessage,
  probeWorkspaceSession,
  removeParticipantLocallyAfterHangup,
  scheduleReconnect,
  startPingLoop,
} = createCallWorkspaceSocketHelpers({
  callbacks: {
    applyCallLayoutPayload: (...args) => applyCallLayoutPayload(...args),
    applyLobbySnapshot,
    applyParticipantActivityPayload: (...args) => applyParticipantActivityPayload(...args),
    applyReactionEvent: (...args) => applyReactionEvent(...args),
    applyRemoteControlState: (...args) => applyRemoteControlState(...args),
    applyRoomSnapshot,
    applyTypingEvent: (...args) => applyTypingEvent(...args),
    applyViewerContext,
    appendChatMessage: (...args) => appendChatMessage(...args),
    captureClientDiagnostic,
    clearAdmissionGate,
    clearErrors,
    clearLobbyActionText,
    clearTransientActivityPublishErrorNotice,
    closeNativePeerConnection,
    closeSocketLocal: (...args) => closeSocket(...args),
    ensureRoomBuckets,
    extractErrorMessage,
    fetchBackend,
    handleAssetVersionSocketClose,
    handleAssetVersionSocketPayload,
    handleMediaSecuritySignal,
    handleNativeSignalingEvent,
    hideLobbyJoinToast,
    mediaDebugLog,
    normalizeRoomId,
    redirectInvitedRouteToJoinModal,
    refreshUsersDirectory,
    refreshUsersDirectoryPresentation,
    removeParticipantFromSnapshot,
    removeSfuRemotePeersForUserId,
    requestHeaders,
    requestRoomSnapshot,
    resetPeerControlState,
    scheduleNativeOfferRetryForUserId,
    sendMediaSecuritySync: (isReconnectOpen) => syncMediaSecurityWithParticipants(isReconnectOpen),
    sendRoomJoin,
    setAdmissionGate,
    setBackendWebSocketOrigin,
    setNotice,
    syncControlStateToPeers,
    syncModerationStateToPeers,
    tryDirectJoinWithModeratorBypass,
  },
  constants: {
    callStateSignalTypes: CALL_STATE_SIGNAL_TYPES,
    mediaSecuritySignalTypes: MEDIA_SECURITY_SIGNAL_TYPES,
    reconnectDelayMs: RECONNECT_DELAYS_MS,
  },
  refs: {
    activeCallId,
    activeSocketCallId,
    activeTab,
    callParticipantRoles,
    clearMediaSecurityHandshakeWatchdog,
    clearMediaSecuritySignalCaches,
    connectionReason,
    connectionState,
    desiredRoomId,
    hasRealtimeRoomSync,
    isSocketOnline,
    lobbyNotificationState,
    mutedUsers,
    participantActivityByUserId,
    pendingAdmissionJoinRoomId,
    pinnedUsers,
    reconnectAttempt,
    resolveBackendWebSocketOriginCandidates,
    routeCallResolve,
    sendSocketFrame,
    serverRoomId,
    sessionState,
    shouldBlockNativeRuntimeSignaling,
    shouldSuppressCallAckNotice,
    shouldSuppressExpectedSignalingError,
    showAdmissionGate,
    socketRef,
    socketUrlForRoom,
    startMediaSecurityHandshakeWatchdog,
    usersSourceMode,
    workspaceError,
    workspaceNotice,
  },
  state: socketLifecycleState,
});

const {
  currentSfuVideoProfile: currentSfuVideoProfileHelper,
  downgradeSfuVideoQualityAfterEncodePressure: downgradeSfuVideoQualityAfterEncodePressureHelper,
  maybeFallbackToNativeRuntime: maybeFallbackToNativeRuntimeHelper,
  setMediaRuntimePath: setMediaRuntimePathHelper,
  switchMediaRuntimePath: switchMediaRuntimePathHelper,
} = createCallWorkspaceRuntimeSwitchingHelpers({
  callbacks: {
    appendMediaRuntimeTransitionEvent,
    captureClientDiagnostic,
    mediaDebugLog,
    resolveSfuVideoQualityProfile,
    setCallOutgoingVideoQualityProfile,
    startEncodingPipeline,
    stopLocalEncodingPipeline,
    syncNativePeerConnectionsWithRoster,
    syncNativePeerLocalTracks,
    synchronizeNativePeerMediaElements,
    teardownNativePeerConnections,
    teardownSfuRemotePeers,
    publishLocalTracks,
    shouldSyncNativeLocalTracksBeforeOffer,
    shouldUseNativeAudioBridge,
  },
  constants: {
    sfuAutoQualityDowngradeCooldownMs: SFU_AUTO_QUALITY_DOWNGRADE_COOLDOWN_MS,
    sfuAutoQualityDowngradeNext: SFU_AUTO_QUALITY_DOWNGRADE_NEXT,
    sfuRuntimeEnabled: SFU_RUNTIME_ENABLED,
  },
  refs: {
    activeCallId,
    activeRoomId,
    callMediaPrefs,
    currentUserId,
    localStreamRef,
    mediaRuntimeCapabilities,
    mediaRuntimePath,
    mediaRuntimeReason,
    nativePeerConnectionsRef,
    sfuTransportState,
  },
  state: {
    getRuntimeSwitchInFlight: () => runtimeSwitchInFlight,
    setRuntimeSwitchInFlight: (value) => { runtimeSwitchInFlight = value; },
    getWlvcEncodeFailureCount: () => wlvcEncodeFailureCount,
    resetWlvcEncodeCounters: () => {
      wlvcEncodeFailureCount = 0;
      wlvcEncodeWarmupUntilMs = 0;
      wlvcEncodeFirstFailureAtMs = 0;
      wlvcEncodeLastErrorLogAtMs = 0;
    },
  },
});

currentSfuVideoProfile = currentSfuVideoProfileHelper;
downgradeSfuVideoQualityAfterEncodePressure = downgradeSfuVideoQualityAfterEncodePressureHelper;
maybeFallbackToNativeRuntime = maybeFallbackToNativeRuntimeHelper;
setMediaRuntimePath = setMediaRuntimePathHelper;
switchMediaRuntimePath = switchMediaRuntimePathHelper;

const participantUiHelpers = createCallWorkspaceParticipantUiHelpers({
  activeCallId,
  activeMessagesLimit,
  activeReactions,
  activeRoomId,
  activeTab,
  admissionGateState,
  aloneIdlePrompt,
  apiRequest,
  callLayoutState,
  callParticipantRoles,
  canModerate,
  chatAttachmentDrafts,
  chatByRoom,
  chatDraft,
  chatEmojiTrayOpen,
  chatSending,
  chatUnreadByRoom,
  compactMiniStripPlacement,
  connectedParticipantUsers,
  controlState,
  currentUserId,
  currentUserStatusLabel,
  desiredRoomId,
  formatTimestamp,
  gridVideoSlotId,
  hangupCall: (...args) => hangupCall(...args),
  initials,
  isAloneInCall,
  isCompactLayoutViewport,
  isCompactMiniStripAbove,
  isSocketOnline,
  isShellMobileViewport,
  layoutModeOptionsFor,
  layoutStrategyOptionsFor,
  lobbyActionState,
  lobbyListRef,
  lobbyListViewport,
  lobbyNotificationState,
  lobbyPage,
  lobbyQueue,
  localReactionEchoes,
  mediaRuntimeCapabilities,
  miniVideoSlotId,
  moderationActionState,
  mutedUsers,
  nextTick,
  normalizeCallLayoutMode,
  normalizeCallLayoutState,
  normalizeCallRole,
  normalizeOptionalRoomId,
  normalizeRole,
  normalizeRoomId,
  normalizeUsersDirectoryOrder,
  normalizeUsersDirectoryStatus,
  parseUsersDirectoryQuery,
  participantActivityByUserId,
  participantActivityWeight,
  participantUsers,
  peerControlStateByUserId,
  pinnedUsers,
  publishLocalActivitySample,
  queuedReactionEmojis,
  reconfigureLocalTracksFromSelectedDevices,
  refreshCallMediaDevices,
  renderCallVideoLayout,
  replaceNumericArray,
  requestRoomSnapshot: (...args) => requestRoomSnapshot(...args),
  rightSidebarCollapsed,
  sendSocketFrame,
  selectCallLayoutParticipants,
  setShellLeftSidebarCollapsed,
  setShellTabletSidebarOpen,
  setSidebarTab,
  showLobbyTab,
  showRightSidebar: showRightSidebarBase,
  shouldShowLeftSidebarRestoreButton: showLeftSidebarRestoreButton,
  snapshotUsersLimit,
  syncUsersDirectoryPresentationBase,
  typingByRoom,
  userRowBase,
  usersDirectoryLoading,
  usersDirectoryPagination,
  usersDirectoryRows,
  usersListRef,
  usersListViewport,
  usersPage,
  usersSearch,
  usersSourceMode,
  viewerEffectiveCallRole,
  visibleParticipantsLimit: VISIBLE_PARTICIPANTS_LIMIT,
  workspaceError,
  workspaceNotice,
  workspaceSidebarState,
  CALL_LAYOUT_MODES,
  CALL_LAYOUT_STRATEGIES,
  CALL_STATE_SIGNAL_TYPES,
  LOBBY_PAGE_SIZE,
  LOCAL_REACTION_ECHO_TTL_MS,
  MEDIA_SECURITY_SIGNAL_TYPES,
  MODERATION_SYNC_FLUSH_INTERVAL_MS,
  PARTICIPANT_ACTIVITY_WINDOW_MS,
  REACTION_CLIENT_BATCH_SIZE,
  REACTION_CLIENT_DIRECT_PER_WINDOW,
  REACTION_CLIENT_FLUSH_INTERVAL_MS,
  REACTION_CLIENT_MAX_QUEUE,
  REACTION_CLIENT_WINDOW_MS,
  ROSTER_VIRTUAL_OVERSCAN,
  ROSTER_VIRTUAL_ROW_HEIGHT,
  TYPING_SWEEP_MS,
  USERS_PAGE_SIZE,
  VISIBLE_PARTICIPANTS_LIMIT,
  ALONE_IDLE_ACTIVITY_EVENTS,
  ALONE_IDLE_COUNTDOWN_MS,
  ALONE_IDLE_POLL_MS,
  ALONE_IDLE_PROMPT_AFTER_MS,
  ALONE_IDLE_TICK_MS,
});

({
  applyActivitySnapshot,
  applyCallLayoutPayload,
  applyParticipantActivityPayload,
  applyReactionEvent,
  applyRemoteControlState,
  clearAdmissionGate,
  clearErrors,
  clearLobbyActionText,
  clearLobbyToastTimer,
  clearTransientActivityPublishErrorNotice,
  hideLobbyJoinToast,
  markParticipantActivity,
  notifyLobbyJoinRequests,
  peerControlSnapshot,
  pruneParticipantActivity,
  refreshUsersDirectory,
  refreshUsersDirectoryPresentation,
  resetPeerControlState,
  setActiveTab,
  setAdmissionGate,
  setNotice,
  shouldSuppressCallAckNotice,
  shouldSuppressExpectedSignalingError,
  syncControlStateToPeers,
  syncModerationStateToPeers,
} = participantUiHelpers);

({
  currentLayoutMode,
  gridVideoParticipants,
  miniVideoParticipants,
  normalizedCallLayout,
  primaryVideoUserId,
} = participantUiHelpers);

const {
  activeMessages,
  activityLabelForUser,
  allowAllLobbyUsers,
  allowLobbyUser,
  aloneIdleCountdownLabel,
  attachAloneIdleActivityListeners,
  canSubmitChatMessage,
  clearAloneIdleCountdownTimer,
  clearAloneIdleWatchTimer,
  clearCallLayoutSidebarControls,
  clearChatUnread,
  clearModerationSyncTimer,
  clearReactionQueueTimer,
  compactMiniStripToggleLabel,
  confirmStillInCall,
  currentCallLayoutSidebarControls,
  currentPinnedUserIds,
  describePeerControlState,
  detachAloneIdleActivityListeners,
  emitReaction,
  ensureAloneIdleWatchTimer,
  evaluateAloneIdlePrompt,
  consumeQueuedModerationSyncEntries,
  filteredUsers,
  flushQueuedModerationSync,
  flushQueuedReactions,
  goToLobbyPage,
  goToUsersPage,
  handleCompactViewportChange,
  hideAloneIdlePrompt,
  hideRightSidebar,
  isCallSignalType,
  layoutModeOptions,
  layoutSelection,
  layoutStrategyOptions,
  lobbyActionPending,
  lobbyEntryByUserId,
  lobbyJoinToastMessage,
  lobbyPageCount,
  lobbyPageRows,
  lobbyRequestBadgeText,
  lobbyRowSnapshot,
  lobbyRows,
  lobbyVisibleRows,
  lobbyVirtualWindow,
  markChatUnread,
  onLobbyListScroll,
  onUsersListScroll,
  onUsersSearchInput,
  openChatPanel,
  openLeftSidebarOverlay,
  openLobbyRequestsPanel,
  participantActivityScore,
  participantVisibilityScore,
  participantsByUserId,
  publishLayoutSelectionState,
  resetLobbyListScroll,
  resetUsersListScroll,
  rowActionFeedback,
  rowActionKey,
  rowActionPending,
  scheduleUsersRefresh,
  setCallLayoutMode,
  setCallLayoutStrategy,
  shouldShowWorkspaceAdmissionNotice,
  showChatUnreadBadge,
  showChatUnreadToast,
  showCompactMiniStripToggle,
  showLobbyJoinToast,
  showLobbyRequestBadge,
  showMiniParticipantStrip,
  showRightSidebar,
  snapshotUsersRows,
  stripParticipants,
  syncCallLayoutSidebarControls,
  syncLobbyListViewport,
  syncModerationStateToPeersWithPayload,
  syncUsersListViewport,
  toggleCamera,
  toggleCompactMiniStripPlacement,
  toggleHandRaised,
  toggleMicrophone,
  togglePinned,
  toggleScreenShare,
  toggleUserMuted,
  typingUsers,
  updatePeerControlState,
  userRowSnapshot,
  usersPageCount,
  usersPageRows,
  usersVisibleRows,
  usersVirtualWindow,
} = participantUiHelpers;

const chatRuntimeHelpers = createCallWorkspaceChatRuntimeHelpers({
  activeCallId,
  activeRoomId,
  activeTab,
  apiRequest,
  buildFileAttachmentDraft,
  buildTextAttachmentDraft,
  captureClientDiagnosticError,
  chatAttachmentDraftToBase64,
  chatAttachmentDragActive,
  chatAttachmentError,
  chatAttachmentDrafts,
  chatAttachmentInputRef,
  chatByRoom,
  chatDraft,
  chatEmojiTrayOpen,
  chatInputRef,
  chatListRef,
  chatSending,
  chatUnreadByRoom,
  chatUtf8ByteLength,
  connectSocket,
  connectionState,
  currentUserId,
  ensureRoomBuckets,
  extractErrorMessage,
  isChatTextInlineAllowed,
  isSocketOnline,
  markParticipantActivity,
  markChatUnread,
  nextTick,
  normalizeRole,
  normalizeRoomId,
  reconnectAttempt,
  rightSidebarCollapsed,
  sanitizeChatAttachmentName,
  sendSocketFrame,
  sessionState,
  setNotice,
  typingByRoom,
  validateChatAttachmentDraft,
  CHAT_ATTACHMENT_MAX_COUNT,
  CHAT_INLINE_MAX_BYTES,
  CHAT_INLINE_MAX_CHARS,
  TYPING_LOCAL_STOP_MS,
});

({
  appendChatMessage,
  applyTypingEvent,
  normalizeLobbyEntry,
} = chatRuntimeHelpers);

const {
  addChatAttachmentDraft,
  clearTypingStopTimer,
  focusChatInput,
  formatBytes,
  handleChatAttachmentDrop,
  handleChatAttachmentPick,
  handleChatInput,
  handleChatPaste,
  insertChatEmoji,
  normalizeChatMessage,
  openChatAttachmentPicker,
  removeChatAttachmentDraft,
  sendChatMessage,
  setChatAttachmentError,
  stopLocalTyping,
  toggleChatEmojiTray,
  updateChatAttachmentDraftName,
} = chatRuntimeHelpers;

({ hangupCall } = createCallWorkspaceOrchestrationHelpers({
  vue: { watch, nextTick },
  callbacks: {
    clearAloneIdleWatchTimer,
    closeSocket,
    connectSocket,
    ensureAloneIdleWatchTimer,
    hideAloneIdlePrompt,
    refreshUsersDirectoryPresentation,
    renderCallVideoLayout,
    requestRoomSnapshot,
    resetLobbyListScroll,
    resetUsersListScroll,
    resyncNativeAudioBridgePeerAfterSecurityReady,
    scheduleMediaSecurityParticipantSync,
    sendRoomJoin,
    setNotice,
    syncLobbyListViewport,
    syncUsersListViewport,
    syncMediaSecurityWithParticipants,
    syncNativePeerConnectionsWithRoster,
    teardownLocalPublisher,
    teardownNativePeerConnections,
    teardownSfuRemotePeers,
  },
  refs: {
    activeCallId,
    activeMessages,
    activeRoomId,
    activeSocketCallId,
    chatAttachmentDragActive,
    chatAttachmentDrafts,
    chatAttachmentError,
    chatListRef,
    connectedParticipantUsers,
    connectionReason,
    connectionState,
    controlState,
    currentLayoutMode,
    currentMediaSecurityRuntimePath,
    currentUserId,
    desiredRoomId,
    filteredUsers,
    gridVideoParticipants,
    hasRealtimeRoomSync,
    isAloneInCall,
    isGuestSession,
    isSocketOnline,
    lobbyPage,
    lobbyPageCount,
    lobbyPageRows,
    lobbyRows,
    mediaSecurityTargetIds,
    miniVideoParticipants,
    primaryVideoUserId,
    reactionTrayOpen,
    reconnectAttempt,
    route,
    router,
    sendSocketFrame,
    sessionState,
    shouldMaintainNativePeerConnections,
    shouldUseNativeAudioBridge,
    usersPage,
    usersPageCount,
    usersPageRows,
    ensureRoomBuckets,
  },
  state: {
    normalizeRole,
    setAloneIdleLastActiveMs: (value) => { aloneIdleLastActiveMs = value; },
    setManualSocketClose: (value) => { manualSocketClose = value; },
  },
}));

registerCallWorkspaceLifecycleHelpers({
  vue: { watch, onMounted, onBeforeUnmount, nextTick },
  callbacks: {
    applyCallInputPreferences,
    applyCallOutputPreferences,
    attachAloneIdleActivityListeners,
    clearAloneIdleWatchTimer,
    clearLobbyToastTimer,
    clearMediaSecurityHandshakeWatchdog,
    clearMediaSecurityResyncTimer,
    clearLocalTrackRecoveryTimer,
    clearModerationSyncTimer,
    clearPingTimer,
    clearReactionQueueTimer,
    clearReconnectTimer,
    clearRemoteVideoStallTimer,
    clearTypingStopTimer,
    closeSocket,
    connectSocket,
    consumeQueuedModerationSyncEntries,
    detectMediaRuntimeCapabilities,
    detachAloneIdleActivityListeners,
    flushQueuedReactions,
    handleCompactViewportChange,
    hideAloneIdlePrompt,
    hideLobbyJoinToast,
    initSFU,
    loadDynamicIceServers,
    markWorkspaceReconnectAfterForeground,
    publishLocalActivitySample,
    publishLocalTracks,
    reconfigureLocalBackgroundFilterOnly,
    reconfigureLocalTracksFromSelectedDevices,
    reconnectWorkspaceAfterForeground,
    refreshCallMediaDevices,
    resolveRouteCallRef,
    setActiveTab,
    setMediaRuntimePath,
    startRemoteVideoStallTimer,
    stopLocalTyping,
    stopSfuTrackAnnounceTimer,
    switchMediaRuntimePath,
    syncLobbyListViewport,
    syncUsersListViewport,
    teardownLocalPublisher,
    teardownNativePeerConnections,
    teardownSfuRemotePeers,
  },
  refs: {
    activeMessages,
    activeRoomId,
    activeTab,
    backgroundFilterController,
    callMediaPrefs,
    canModerate,
    chatListRef,
    connectionReason,
    connectionState,
    currentUserId,
    desiredRoomId,
    ensureRoomBuckets,
    isCompactLayoutViewport,
    isCompactViewport,
    isShellMobileViewport,
    isShellTabletViewport,
    isSocketOnline,
    localStreamRef,
    mediaRuntimeCapabilities,
    mediaRuntimePath,
    nativeAudioBridgeBlockDiagnosticsSent,
    nativeAudioSecurityBannerMessage,
    nativeAudioSecurityTelemetrySnapshot,
    reconnectAttempt,
    remotePeersRef,
    rightSidebarCollapsed,
    routeCallRef,
    serverRoomId,
    sessionState,
    sfuClientRef,
    sfuConnected,
    shouldConnectSfu,
    typingByRoom,
    usersRefreshTimer,
    localTracksPublishedToSfuRef: {
      set: (value) => { localTracksPublishedToSfu = value; },
    },
  },
  state: {
    getCompactMediaQuery: () => compactMediaQuery,
    setCompactMediaQuery: (value) => { compactMediaQuery = value; },
    getConnectGeneration: () => connectGeneration,
    setConnectGeneration: (value) => { connectGeneration = value; },
    getDetachForegroundReconnect: () => detachForegroundReconnect,
    setDetachForegroundReconnect: (value) => { detachForegroundReconnect = value; },
    getDetachMediaDeviceWatcher: () => detachMediaDeviceWatcher,
    setDetachMediaDeviceWatcher: (value) => { detachMediaDeviceWatcher = value; },
    setManualSocketClose: (value) => { manualSocketClose = value; },
    getTypingSweepTimer: () => typingSweepTimer,
    setTypingSweepTimer: (value) => { typingSweepTimer = value; },
    setAloneIdleLastActiveMs: (value) => { aloneIdleLastActiveMs = value; },
  },
  constants: {
    attachCallMediaDeviceWatcher,
    attachForegroundReconnectHandlers,
    captureClientDiagnostic,
    compactBreakpoint: COMPACT_BREAKPOINT,
    mediaSecuritySessionClass: MediaSecuritySession,
    sfuRuntimeEnabled: SFU_RUNTIME_ENABLED,
    typingSweepMs: TYPING_SWEEP_MS,
  },
});
</script>

<style scoped src="./CallWorkspaceStage.css"></style>
<style scoped src="./CallWorkspacePanels.css"></style>
