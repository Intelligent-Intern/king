<template src="./OverviewView.template.html"></template>

<script setup>
import { onBeforeUnmount, onMounted, reactive } from 'vue';
import { useRouter } from 'vue-router';
import { currentBackendOrigin, fetchBackend } from '../../../../support/backendFetch';
import { logoutSession, refreshSession, sessionState } from '../../../../domain/auth/session';
import { t } from '../../../localization/i18nRuntime.js';
import { normalizeArray, normalizeNonNegativeInteger } from './helpers';
import { useOverviewDashboardMetrics } from './useOverviewDashboardMetrics';

const router = useRouter();
let infrastructureLoadSeq = 0;
let operationsLoadSeq = 0;
let operationsRefreshTimer = null;

const infrastructureState = reactive({
  loading: false,
  error: '',
  lastLoadedAt: '',
  deployment: {},
  providers: [],
  nodes: [],
  services: [],
});

const operationsState = reactive({
  loading: false,
  error: '',
  lastLoadedAt: '',
  metrics: {
    live_calls: 0,
    concurrent_participants: 0,
  },
  runningCalls: [],
});

const {
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
} = useOverviewDashboardMetrics({ infrastructureState, operationsState });

function requestHeaders(includeBody = false) {
  const token = String(sessionState.sessionToken || '').trim();
  const headers = { accept: 'application/json' };
  if (includeBody) headers['content-type'] = 'application/json';
  if (token !== '') headers.authorization = `Bearer ${token}`;
  return headers;
}

function extractErrorMessage(payload, fallback) {
  const message = payload && typeof payload === 'object' ? payload?.error?.message : '';
  return typeof message === 'string' && message.trim() !== '' ? message.trim() : fallback;
}

async function apiRequest(path, { method = 'GET', query = null, body = null } = {}, allowRefreshRetry = true) {
  let response = null;
  try {
    const result = await fetchBackend(path, {
      method,
      query,
      headers: requestHeaders(body !== null),
      body: body === null ? undefined : JSON.stringify(body),
    });
    response = result.response;
  } catch (error) {
    const message = error instanceof Error ? error.message.trim() : '';
    if (message === '' || /failed to fetch|socket|connection/i.test(message)) {
      throw new Error(t('errors.api.backend_unreachable', { origin: currentBackendOrigin() }));
    }
    throw new Error(message);
  }

  let payload = null;
  try {
    payload = await response.json();
  } catch {
    payload = null;
  }

  if (!response.ok) {
    if ((response.status === 401 || response.status === 403) && allowRefreshRetry) {
      const refreshResult = await refreshSession();
      if (refreshResult?.ok) return apiRequest(path, { method, query, body }, false);
      await logoutSession();
      await router.push('/login');
      throw new Error(t('errors.api.session_expired'));
    }
    throw new Error(extractErrorMessage(payload, t('errors.api.request_failed_status', { status: response.status })));
  }

  if (!payload || payload.status !== 'ok') {
    throw new Error(t('errors.api.invalid_payload'));
  }

  return payload;
}

function applyInfrastructurePayload(payload) {
  infrastructureState.deployment = payload?.deployment && typeof payload.deployment === 'object' ? payload.deployment : {};
  infrastructureState.providers = normalizeArray(payload?.providers);
  infrastructureState.nodes = normalizeArray(payload?.nodes);
  infrastructureState.services = normalizeArray(payload?.services);
  infrastructureState.lastLoadedAt = String(payload?.time || new Date().toISOString());
}

async function loadInfrastructure() {
  const seq = ++infrastructureLoadSeq;
  infrastructureState.loading = true;
  infrastructureState.error = '';
  try {
    const payload = await apiRequest('/api/admin/infrastructure');
    if (seq !== infrastructureLoadSeq) return;
    applyInfrastructurePayload(payload);
  } catch (error) {
    if (seq !== infrastructureLoadSeq) return;
    infrastructureState.error = error instanceof Error ? error.message : t('users.overview.load_infrastructure_failed');
  } finally {
    if (seq === infrastructureLoadSeq) infrastructureState.loading = false;
  }
}

function applyVideoOperationsPayload(payload) {
  const metrics = payload?.metrics && typeof payload.metrics === 'object' ? payload.metrics : {};
  operationsState.metrics = {
    live_calls: normalizeNonNegativeInteger(metrics.live_calls),
    concurrent_participants: normalizeNonNegativeInteger(metrics.concurrent_participants),
  };
  operationsState.runningCalls = normalizeArray(payload?.running_calls);
  operationsState.lastLoadedAt = String(payload?.time || new Date().toISOString());
}

async function loadVideoOperations({ background = false } = {}) {
  const seq = ++operationsLoadSeq;
  if (!background) operationsState.loading = true;
  operationsState.error = '';
  try {
    const payload = await apiRequest('/api/admin/video-operations');
    if (seq !== operationsLoadSeq) return;
    applyVideoOperationsPayload(payload);
  } catch (error) {
    if (seq !== operationsLoadSeq) return;
    operationsState.error = error instanceof Error ? error.message : t('users.overview.load_video_operations_failed');
  } finally {
    if (seq === operationsLoadSeq) operationsState.loading = false;
  }
}

function startVideoOperationsRefreshLoop() {
  if (operationsRefreshTimer !== null) return;
  operationsRefreshTimer = window.setInterval(() => {
    void loadInfrastructure();
    void loadVideoOperations({ background: true });
  }, 15000);
}

function stopVideoOperationsRefreshLoop() {
  if (operationsRefreshTimer === null) return;
  window.clearInterval(operationsRefreshTimer);
  operationsRefreshTimer = null;
}

onMounted(() => {
  void loadInfrastructure();
  void loadVideoOperations();
  startVideoOperationsRefreshLoop();
});

onBeforeUnmount(() => {
  stopVideoOperationsRefreshLoop();
});
</script>

<style scoped src="./OverviewView.css"></style>
