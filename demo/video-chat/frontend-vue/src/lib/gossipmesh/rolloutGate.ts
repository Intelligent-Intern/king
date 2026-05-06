const COUNTER_NAMES = [
  'sent',
  'received',
  'forwarded',
  'dropped',
  'duplicates',
  'ttl_exhausted',
  'late_drops',
  'stale_generation_drops',
  'server_fanout_avoided',
  'peer_outbound_fanout',
  'rtc_datachannel_sends',
  'in_memory_harness_sends',
  'topology_repairs_requested',
  'would_publish_frames',
  'participant_set_recoveries',
  'participant_set_recovery_in_flight',
  'protected_decrypt_failures',
  'keyframe_requests',
  'stale_target_prunes',
  'encoder_lifecycle_closes',
  'send_backpressure_aborts',
];

const FORBIDDEN_GATE_FIELDS = new Set([
  'payload',
  'payload_base64',
  'data',
  'data_base64',
  'protected',
  'protectedframe',
  'protected_frame',
  'frame',
  'frames',
  'offer',
  'answer',
  'candidate',
  'candidates',
  'sdp',
  'icemid',
  'ice_candidate',
  'iceservers',
  'socket',
  'websocket',
  'authorization',
  'cookie',
  'token',
  'secret',
  'raw_media_key',
  'private_key',
  'shared_secret',
]);

const DEFAULT_THRESHOLDS = {
  minPeerCount: 3,
  minNeighborCount: 3,
  maxDuplicateRate: 0.02,
  maxTtlExhaustionRate: 0.01,
  maxLateDropRate: 0.01,
  maxRepairRate: 0.05,
  maxParticipantSetRecoveryRate: 0.02,
  maxParticipantSetRecoveryInFlight: 0,
  maxProtectedFrameDecryptFailureRate: 0.01,
  maxKeyframeRequestRate: 0.08,
  maxStaleTargetPruneRate: 0.02,
  maxEncoderLifecycleCloseRate: 0.01,
  maxSendBackpressureAbortRate: 0.01,
};

export function deriveGossipRolloutGateState(input = {}, options = {}) {
  if (containsForbiddenGateField(input)) {
    return inertGateState('forbidden_media_or_signaling_field');
  }

  const thresholds = { ...DEFAULT_THRESHOLDS, ...(options.thresholds || {}) };
  const requestedMode = normalizeMode(options.mode || input.data_lane_mode || input.mode);
  if (requestedMode === 'off') {
    return {
      ...inertGateState('data_lane_off'),
      data_lane_mode: 'off',
    };
  }

  const aggregate = sanitizeAggregate(input);
  const backendGate = input.rollout_gate && typeof input.rollout_gate === 'object' ? input.rollout_gate : null;
  const receivedDenominator = Math.max(1, aggregate.totals.received + aggregate.totals.duplicates);
  const forwardDenominator = Math.max(1, aggregate.totals.forwarded + aggregate.totals.ttl_exhausted);
  const sentReceiveDenominator = Math.max(1, aggregate.totals.sent + aggregate.totals.received);
  const repairDenominator = Math.max(1, aggregate.peer_count);
  const duplicateRate = sanitizeRate(backendGate?.duplicate_rate, boundedRate(aggregate.totals.duplicates, receivedDenominator));
  const ttlExhaustionRate = sanitizeRate(backendGate?.ttl_exhaustion_rate, boundedRate(aggregate.totals.ttl_exhausted, forwardDenominator));
  const lateDropRate = sanitizeRate(backendGate?.late_drop_rate, boundedRate(aggregate.totals.late_drops, sentReceiveDenominator));
  const repairRate = sanitizeRate(backendGate?.repair_rate, boundedRate(aggregate.totals.topology_repairs_requested, repairDenominator));
  const baselineDenominator = Math.max(1, aggregate.baseline_sample_count || sentReceiveDenominator);
  const participantSetRecoveryRate = sanitizeRate(
    backendGate?.participant_set_recovery_rate,
    boundedRate(aggregate.totals.participant_set_recoveries, baselineDenominator),
  );
  const protectedFrameDecryptFailureRate = sanitizeRate(
    backendGate?.protected_decrypt_failure_rate,
    boundedRate(aggregate.totals.protected_decrypt_failures, baselineDenominator),
  );
  const keyframeRequestRate = sanitizeRate(
    backendGate?.keyframe_request_rate,
    boundedRate(aggregate.totals.keyframe_requests, baselineDenominator),
  );
  const staleTargetPruneRate = sanitizeRate(
    backendGate?.stale_target_prune_rate,
    boundedRate(aggregate.totals.stale_target_prunes, baselineDenominator),
  );
  const encoderLifecycleCloseRate = sanitizeRate(
    backendGate?.encoder_lifecycle_close_rate,
    boundedRate(aggregate.totals.encoder_lifecycle_closes, baselineDenominator),
  );
  const sendBackpressureAbortRate = sanitizeRate(
    backendGate?.send_backpressure_abort_rate,
    boundedRate(aggregate.totals.send_backpressure_aborts, baselineDenominator),
  );
  const rtcReady = aggregate.peer_count >= thresholds.minPeerCount
    && aggregate.rtc_peer_count >= aggregate.peer_count
    && aggregate.min_neighbor_count >= thresholds.minNeighborCount
    && aggregate.max_topology_epoch > 0;
  const telemetryReady = Boolean(backendGate?.telemetry_ready)
    || ((aggregate.totals.sent + aggregate.totals.received) > 0
      && duplicateRate <= thresholds.maxDuplicateRate
      && ttlExhaustionRate <= thresholds.maxTtlExhaustionRate
      && lateDropRate <= thresholds.maxLateDropRate
      && repairRate <= thresholds.maxRepairRate);
  const participantSetRecoveryInFlight = aggregate.totals.participant_set_recovery_in_flight;
  const mediaSecurityRecoveryReady = participantSetRecoveryInFlight <= thresholds.maxParticipantSetRecoveryInFlight
    && participantSetRecoveryRate <= thresholds.maxParticipantSetRecoveryRate
    && protectedFrameDecryptFailureRate <= thresholds.maxProtectedFrameDecryptFailureRate;
  const sfuBaselineHealthy = mediaSecurityRecoveryReady
    && keyframeRequestRate <= thresholds.maxKeyframeRequestRate
    && staleTargetPruneRate <= thresholds.maxStaleTargetPruneRate
    && encoderLifecycleCloseRate <= thresholds.maxEncoderLifecycleCloseRate
    && sendBackpressureAbortRate <= thresholds.maxSendBackpressureAbortRate;
  const blockingBuckets = [];
  if (!rtcReady) blockingBuckets.push('rtc_topology_unready');
  if (!telemetryReady) blockingBuckets.push('gossip_telemetry_noisy');
  if (participantSetRecoveryInFlight > thresholds.maxParticipantSetRecoveryInFlight) blockingBuckets.push('participant_set_recovery_in_flight');
  if (participantSetRecoveryRate > thresholds.maxParticipantSetRecoveryRate) blockingBuckets.push('participant_set_recovery_storm');
  if (protectedFrameDecryptFailureRate > thresholds.maxProtectedFrameDecryptFailureRate) blockingBuckets.push('protected_decrypt_burst');
  if (keyframeRequestRate > thresholds.maxKeyframeRequestRate) blockingBuckets.push('keyframe_storm');
  if (staleTargetPruneRate > thresholds.maxStaleTargetPruneRate) blockingBuckets.push('stale_target_prune_storm');
  if (encoderLifecycleCloseRate > thresholds.maxEncoderLifecycleCloseRate) blockingBuckets.push('encoder_lifecycle_close_storm');
  if (sendBackpressureAbortRate > thresholds.maxSendBackpressureAbortRate) blockingBuckets.push('send_backpressure_abort_storm');
  const activeAllowed = requestedMode === 'active' && rtcReady && telemetryReady && sfuBaselineHealthy && mediaSecurityRecoveryReady;
  const decision = requestedMode === 'shadow'
    ? 'shadow_observe'
    : (activeAllowed ? 'active_allowed_diagnostic' : 'sfu_first_explicit');

  return {
    kind: 'gossip_rollout_gate_state',
    data_lane_mode: requestedMode,
    decision,
    active_allowed: activeAllowed,
    observational_only: requestedMode !== 'active' || !activeAllowed,
    sfu_first: !activeAllowed,
    rtc_ready: rtcReady,
    telemetry_ready: telemetryReady,
    sfu_baseline_healthy: sfuBaselineHealthy,
    media_security_recovery_ready: mediaSecurityRecoveryReady,
    blocking_buckets: blockingBuckets,
    peer_count: aggregate.peer_count,
    rtc_peer_count: aggregate.rtc_peer_count,
    min_neighbor_count: aggregate.min_neighbor_count,
    max_topology_epoch: aggregate.max_topology_epoch,
    duplicate_rate: duplicateRate,
    ttl_exhaustion_rate: ttlExhaustionRate,
    late_drop_rate: lateDropRate,
    repair_rate: repairRate,
    participant_set_recovery_rate: participantSetRecoveryRate,
    participant_set_recovery_in_flight: participantSetRecoveryInFlight,
    protected_decrypt_failure_rate: protectedFrameDecryptFailureRate,
    keyframe_request_rate: keyframeRequestRate,
    stale_target_prune_rate: staleTargetPruneRate,
    encoder_lifecycle_close_rate: encoderLifecycleCloseRate,
    send_backpressure_abort_rate: sendBackpressureAbortRate,
    thresholds,
    counters: aggregate.totals,
  };
}

function inertGateState(reason) {
  return {
    kind: 'gossip_rollout_gate_state',
    data_lane_mode: 'off',
    decision: 'sfu_first_explicit',
    active_allowed: false,
    observational_only: true,
    sfu_first: true,
    rtc_ready: false,
    telemetry_ready: false,
    sfu_baseline_healthy: false,
    media_security_recovery_ready: false,
    blocking_buckets: [reason],
    reason,
    peer_count: 0,
    rtc_peer_count: 0,
    min_neighbor_count: 0,
    max_topology_epoch: 0,
    duplicate_rate: 0,
    ttl_exhaustion_rate: 0,
    late_drop_rate: 0,
    repair_rate: 0,
    participant_set_recovery_rate: 0,
    participant_set_recovery_in_flight: 0,
    protected_decrypt_failure_rate: 0,
    keyframe_request_rate: 0,
    stale_target_prune_rate: 0,
    encoder_lifecycle_close_rate: 0,
    send_backpressure_abort_rate: 0,
    thresholds: DEFAULT_THRESHOLDS,
    counters: emptyCounters(),
  };
}

function sanitizeAggregate(input) {
  const rolloutGate = input.rollout_gate && typeof input.rollout_gate === 'object' ? input.rollout_gate : null;
  const sfuBaseline = input.sfu_baseline_health && typeof input.sfu_baseline_health === 'object'
    ? input.sfu_baseline_health
    : (input.sfu_baseline && typeof input.sfu_baseline === 'object' ? input.sfu_baseline : null);
  const mediaSecurityBaseline = input.media_security_readiness && typeof input.media_security_readiness === 'object'
    ? input.media_security_readiness
    : (input.media_security && typeof input.media_security === 'object' ? input.media_security : null);
  const peers = input.peers && typeof input.peers === 'object' ? Object.values(input.peers) : [];
  const peerCount = clampInt(input.peer_count ?? peers.length, 0, 1000);
  const transports = input.transports && typeof input.transports === 'object' ? input.transports : {};
  const rtcPeerCount = clampInt(input.rtc_peer_count ?? transports.rtc_datachannel ?? 0, 0, 1000);
  const peerNeighborCounts = peers.map((peer) => clampInt(peer?.neighbor_count, 0, 1000)).filter((value) => value > 0);
  const peerTopologyEpochs = peers.map((peer) => clampInt(peer?.topology_epoch, 0, 1_000_000_000));
  const totals = sanitizeCounters(input.totals || input.counters || {});
  mergeBaselineCounters(totals, sfuBaseline);
  mergeBaselineCounters(totals, mediaSecurityBaseline);
  const baselineSampleCount = clampInt(
    sfuBaseline?.sample_count ?? sfuBaseline?.baseline_sample_count ?? rolloutGate?.baseline_sample_count,
    0,
    1_000_000_000,
  );

  if (rolloutGate) {
    return {
      peer_count: peerCount || clampInt(rolloutGate.peer_count, 0, 1000),
      rtc_peer_count: rtcPeerCount || clampInt(rolloutGate.rtc_peer_count, 0, 1000),
      min_neighbor_count: clampInt(rolloutGate.min_neighbor_count, peerNeighborCounts.length > 0 ? Math.min(...peerNeighborCounts) : 0, 1000),
      max_topology_epoch: clampInt(rolloutGate.max_topology_epoch, peerTopologyEpochs.length > 0 ? Math.max(...peerTopologyEpochs) : 0, 1_000_000_000),
      baseline_sample_count: baselineSampleCount,
      totals,
    };
  }

  return {
    peer_count: peerCount,
    rtc_peer_count: rtcPeerCount,
    min_neighbor_count: peerNeighborCounts.length > 0 ? Math.min(...peerNeighborCounts) : clampInt(input.neighbor_count, 0, 1000),
    max_topology_epoch: peerTopologyEpochs.length > 0 ? Math.max(...peerTopologyEpochs) : clampInt(input.topology_epoch, 0, 1_000_000_000),
    baseline_sample_count: baselineSampleCount,
    totals,
  };
}

function mergeBaselineCounters(totals, baseline) {
  if (!baseline || typeof baseline !== 'object') return;
  const aliases = {
    participant_set_recoveries: ['participant_set_recoveries', 'participant_set_recovery_events', 'media_security_participant_set_recoveries'],
    participant_set_recovery_in_flight: ['participant_set_recovery_in_flight', 'media_security_recovery_in_flight'],
    protected_decrypt_failures: ['protected_decrypt_failures', 'protected_frame_decrypt_failures', 'sfu_protected_frame_decrypt_failed'],
    keyframe_requests: ['keyframe_requests', 'full_keyframe_requests', 'sfu_remote_full_keyframe_requests'],
    stale_target_prunes: ['stale_target_prunes', 'target_not_in_room_prunes', 'gossip_assigned_neighbor_prunes'],
    encoder_lifecycle_closes: ['encoder_lifecycle_closes', 'protected_browser_video_encoder_closes'],
    send_backpressure_aborts: ['send_backpressure_aborts', 'sfu_send_backpressure_aborts'],
  };
  for (const [target, keys] of Object.entries(aliases)) {
    const value = keys.reduce((sum, key) => sum + clampInt(baseline[key], 0, 1_000_000_000), 0);
    if (value > 0) {
      totals[target] = clampInt(totals[target] + value, 0, 1_000_000_000);
    }
  }
}

function sanitizeCounters(input) {
  const counters = emptyCounters();
  for (const name of COUNTER_NAMES) {
    counters[name] = clampInt(input?.[name], 0, 1_000_000_000);
  }
  return counters;
}

function emptyCounters() {
  return Object.fromEntries(COUNTER_NAMES.map((name) => [name, 0]));
}

function normalizeMode(value) {
  const mode = String(value || '').trim().toLowerCase();
  return mode === 'shadow' || mode === 'active' ? mode : 'off';
}

function boundedRate(numerator, denominator) {
  return Math.max(0, Math.min(1, numerator / Math.max(1, denominator)));
}

function sanitizeRate(value, fallback) {
  const number = Number(value);
  if (!Number.isFinite(number)) return fallback;
  return Math.max(0, Math.min(1, number));
}

function clampInt(value, fallback, max) {
  const number = Number(value);
  if (!Number.isFinite(number)) return fallback;
  return Math.max(0, Math.min(max, Math.floor(number)));
}

function containsForbiddenGateField(value) {
  if (!value || typeof value !== 'object') return false;
  for (const [key, child] of Object.entries(value)) {
    if (FORBIDDEN_GATE_FIELDS.has(String(key || '').trim().toLowerCase())) return true;
    if (child && typeof child === 'object' && containsForbiddenGateField(child)) return true;
  }
  return false;
}
