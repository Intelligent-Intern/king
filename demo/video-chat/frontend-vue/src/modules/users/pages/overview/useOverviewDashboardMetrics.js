import { computed } from 'vue';
import { t } from '../../../localization/i18nRuntime.js';
import {
  formatUptimeSeconds,
  normalizeArray,
  normalizeNonNegativeInteger,
  normalizeOwnerHost,
  tagClassForStatus,
} from './helpers';

export function useOverviewDashboardMetrics({ infrastructureState, operationsState }) {
  const runningCallsRows = computed(() => (
    operationsState.runningCalls.map((call, index) => {
      const id = String(call?.id || call?.room_id || `running-call-${index}`).trim();
      const liveParticipants = call?.live_participants && typeof call.live_participants === 'object'
        ? call.live_participants
        : {};
      const statusLabel = String(call?.status || t('users.overview.status_live')).trim() || t('users.overview.status_live');

      return {
        id,
        call: String(call?.title || t('users.overview.untitled_call')),
        host: normalizeOwnerHost(call),
        users: normalizeNonNegativeInteger(liveParticipants.total),
        uptime: formatUptimeSeconds(call?.uptime_seconds),
        statusLabel,
        statusTagClass: tagClassForStatus(statusLabel),
      };
    })
  ));

  const liveCallsMetric = computed(() => String(normalizeNonNegativeInteger(
    operationsState.metrics.live_calls,
  )));

  const participantsMetric = computed(() => String(normalizeNonNegativeInteger(
    operationsState.metrics.concurrent_participants,
  )));

  const transportRecentFramesMetric = computed(() => String(normalizeNonNegativeInteger(
    operationsState.transport.recent_frame_count,
  )));

  const transportMatteGuidedMetric = computed(() => String(normalizeNonNegativeInteger(
    operationsState.transport.matte_guided_frame_count,
  )));

  const transportAvgSelectionMetric = computed(() => `${Math.round(Math.max(0, Number(operationsState.transport.avg_selection_tile_ratio || 0)) * 100)}%`);

  const transportAvgRoiMetric = computed(() => `${Math.round(Math.max(0, Number(operationsState.transport.avg_roi_area_ratio || 0)) * 100)}%`);

  const transportFrameKindRows = computed(() => (
    normalizeArray(operationsState.transport.frame_kinds).map((row, index) => ({
      id: `${String(row?.kind || 'kind').trim()}:${index}`,
      kind: String(row?.kind || t('users.overview.status_unknown')).trim() || t('users.overview.status_unknown'),
      frames: normalizeNonNegativeInteger(row?.frames),
      matteGuidedFrames: normalizeNonNegativeInteger(row?.matte_guided_frames),
      avgSelectionTileRatio: `${Math.round(Math.max(0, Number(row?.avg_selection_tile_ratio || 0)) * 100)}%`,
      avgRoiAreaRatio: `${Math.round(Math.max(0, Number(row?.avg_roi_area_ratio || 0)) * 100)}%`,
    }))
  ));

  const servicesByNodeId = computed(() => {
    const map = new Map();
    for (const service of infrastructureState.services) {
      const nodeId = String(service?.node_id || '').trim();
      if (nodeId === '') continue;
      if (!map.has(nodeId)) map.set(nodeId, []);
      map.get(nodeId).push(service);
    }
    return map;
  });

  const clusterHealthRows = computed(() => (
    infrastructureState.nodes.map((node) => {
      const nodeId = String(node?.id || '').trim();
      const services = servicesByNodeId.value.get(nodeId) || [];
      const roles = normalizeArray(node?.roles).map((role) => String(role || '').trim()).filter(Boolean);
      const health = String(node?.health || node?.status || t('users.overview.status_unknown')).trim().toLowerCase();
      return {
        id: nodeId || String(node?.name || t('users.overview.unknown_node')),
        node: String(node?.name || nodeId || t('users.overview.unknown_node')),
        provider: String(node?.provider || t('users.overview.unknown_provider')),
        region: String(node?.region || t('common.not_available')),
        roles: roles.length > 0 ? roles.join(', ') : t('common.not_available'),
        services: services.length > 0
          ? services.map((service) => String(service?.kind || service?.label || t('users.overview.unknown_service'))).join(', ')
          : t('common.not_available'),
        status: String(node?.status || t('users.overview.status_unknown')),
        health,
        statusTagClass: tagClassForStatus(health),
      };
    })
  ));

  const healthyNodesMetric = computed(() => {
    const total = clusterHealthRows.value.length;
    const healthy = clusterHealthRows.value.filter((row) => String(row.health).toLowerCase() === 'healthy').length;
    return `${healthy} / ${total}`;
  });

  const nodesUnderLoadMetric = computed(() => String(
    clusterHealthRows.value.filter((row) => String(row.health).toLowerCase() !== 'healthy').length,
  ));

  const providerRows = computed(() => (
    infrastructureState.providers.map((provider) => {
      const capabilities = provider?.capabilities && typeof provider.capabilities === 'object' ? provider.capabilities : {};
      const activeCapabilities = Object.entries(capabilities)
        .filter(([, value]) => Boolean(value))
        .map(([key]) => key.replaceAll('_', ' '));
      return {
        id: String(provider?.id || provider?.label || 'provider'),
        label: String(provider?.label || provider?.id || t('users.overview.provider')),
        statusLabel: String(provider?.status || t('users.overview.status_unknown')),
        capabilityLabel: activeCapabilities.length > 0 ? activeCapabilities.join(' / ') : t('users.overview.inventory_only'),
      };
    })
  ));

  const infrastructureSubtitle = computed(() => {
    const deployment = infrastructureState.deployment || {};
    const name = String(deployment.name || deployment.id || t('users.overview.deployment'));
    const publicDomain = String(deployment.public_domain || t('users.overview.local_domain'));
    const mode = String(deployment.inventory_mode || t('users.overview.inventory_auto'));
    return t('users.overview.infrastructure_subtitle', { name, domain: publicDomain, mode });
  });

  const infrastructureStatusLabel = computed(() => {
    if (infrastructureState.loading) return t('users.overview.status_loading');
    if (infrastructureState.error) return t('users.overview.status_error');
    return clusterHealthRows.value.length > 0
      ? t('users.overview.status_inventory_online')
      : t('users.overview.no_nodes');
  });

  const infrastructureStatusTagClass = computed(() => (
    infrastructureState.error ? 'warn' : 'ok'
  ));

  const telemetrySummary = computed(() => {
    const openTelemetry = infrastructureState.telemetry?.open_telemetry || {};
    if (!openTelemetry.enabled) return t('users.overview.open_telemetry_disabled');
    const metrics = openTelemetry.metrics_enabled ? t('users.overview.telemetry_metrics') : '';
    const logs = openTelemetry.logs_enabled ? t('users.overview.telemetry_logs') : '';
    const signals = [metrics, logs].filter(Boolean).join(' + ') || t('users.overview.telemetry_exporter_configured');
    return t('users.overview.telemetry_signal_transport', {
      signals,
      protocol: openTelemetry.protocol || 'grpc',
    });
  });

  const scalingModesSummary = computed(() => {
    const modes = normalizeArray(infrastructureState.scaling?.modes);
    const available = modes.filter((mode) => Boolean(mode?.available)).map((mode) => String(mode?.label || mode?.id || '').trim()).filter(Boolean);
    return available.length > 0 ? available.join(' / ') : t('users.overview.no_scaling_mode');
  });

  const routingPolicyRows = computed(() => [
    {
      topic: t('users.overview.routing_deployment_topic'),
      policy: t('users.overview.routing_deployment_policy'),
      runtime: [
        infrastructureState.deployment?.public_domain,
        infrastructureState.deployment?.api_domain,
        infrastructureState.deployment?.ws_domain,
        infrastructureState.deployment?.sfu_domain,
        infrastructureState.deployment?.cdn_domain,
      ].filter(Boolean).join(' / ') || t('common.not_available'),
      code: false,
    },
    {
      topic: t('users.overview.routing_telemetry_topic'),
      policy: t('users.overview.routing_telemetry_policy'),
      runtime: telemetrySummary.value,
      code: false,
    },
    {
      topic: t('users.overview.routing_sfu_scaling_topic'),
      policy: String(infrastructureState.scaling?.strategy || t('users.overview.status_not_reported')),
      runtime: scalingModesSummary.value,
      code: false,
    },
    {
      topic: t('users.overview.routing_write_actions_topic'),
      policy: t('users.overview.routing_write_actions_policy'),
      runtime: infrastructureState.scaling?.write_actions_enabled
        ? t('users.overview.status_enabled')
        : t('users.overview.status_disabled'),
      code: false,
    },
  ]);

  return {
    clusterHealthRows,
    healthyNodesMetric,
    infrastructureStatusLabel,
    infrastructureStatusTagClass,
    infrastructureSubtitle,
    liveCallsMetric,
    nodesUnderLoadMetric,
    participantsMetric,
    providerRows,
    routingPolicyRows,
    runningCallsRows,
    transportAvgRoiMetric,
    transportAvgSelectionMetric,
    transportFrameKindRows,
    transportMatteGuidedMetric,
    transportRecentFramesMetric,
  };
}
