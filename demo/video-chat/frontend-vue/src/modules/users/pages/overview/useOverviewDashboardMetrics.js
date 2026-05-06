import { computed } from 'vue';
import { t } from '../../../localization/i18nRuntime.js';
import {
  formatUptimeSeconds,
  normalizeArray,
  normalizeNonNegativeInteger,
  normalizeOwnerHost,
  tagClassForStatus,
} from './helpers';

function percentage(value) {
  const numeric = Number(value);
  if (!Number.isFinite(numeric) || numeric < 0) return null;
  return Math.max(0, Math.min(100, numeric));
}

function formatPercentage(value) {
  const normalized = percentage(value);
  return normalized === null ? t('common.not_available') : `${Math.round(normalized)}%`;
}

function averagePercent(values) {
  const normalized = values.map(percentage).filter((value) => value !== null);
  if (normalized.length === 0) return null;
  return normalized.reduce((sum, value) => sum + value, 0) / normalized.length;
}

function providerIsActual(provider) {
  const status = String(provider?.status || '').trim().toLowerCase();
  return provider?.configured !== false && !['not_configured', 'not_detected'].includes(status);
}

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

  const liveCallsMetric = computed(() => String(normalizeNonNegativeInteger(operationsState.metrics.live_calls)));
  const participantsMetric = computed(() => String(normalizeNonNegativeInteger(operationsState.metrics.concurrent_participants)));

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

  const serverResourceRows = computed(() => (
    infrastructureState.nodes.map((node, index) => {
      const resources = node?.resources && typeof node.resources === 'object' ? node.resources : {};
      const health = String(node?.health || node?.status || t('users.overview.status_unknown')).trim().toLowerCase();
      return {
        id: String(node?.id || `node-${index}`),
        node: String(node?.name || node?.id || t('users.overview.unknown_node')),
        cpuUsage: formatPercentage(resources.cpu_usage_percent),
        ramUsage: formatPercentage(resources.memory_usage_percent),
        status: String(node?.status || t('users.overview.status_unknown')),
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
    serverResourceRows.value.filter((row) => {
      const cpu = Number.parseInt(row.cpuUsage, 10);
      const ram = Number.parseInt(row.ramUsage, 10);
      return (Number.isFinite(cpu) && cpu >= 80) || (Number.isFinite(ram) && ram >= 85);
    }).length,
  ));

  const cpuUsageMetric = computed(() => formatPercentage(averagePercent(
    infrastructureState.nodes.map((node) => node?.resources?.cpu_usage_percent),
  )));
  const ramUsageMetric = computed(() => formatPercentage(averagePercent(
    infrastructureState.nodes.map((node) => node?.resources?.memory_usage_percent),
  )));

  const providerRows = computed(() => (
    infrastructureState.providers.filter(providerIsActual).map((provider) => {
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

  const infrastructureStatusTagClass = computed(() => (infrastructureState.error ? 'warn' : 'ok'));

  return {
    clusterHealthRows,
    cpuUsageMetric,
    healthyNodesMetric,
    infrastructureStatusLabel,
    infrastructureStatusTagClass,
    infrastructureSubtitle,
    liveCallsMetric,
    nodesUnderLoadMetric,
    participantsMetric,
    providerRows,
    ramUsageMetric,
    runningCallsRows,
    serverResourceRows,
  };
}
