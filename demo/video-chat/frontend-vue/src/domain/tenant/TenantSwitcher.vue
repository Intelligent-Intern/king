<template>
  <section class="tenant-switcher" :aria-label="t('tenant.context_aria')">
    <span class="tenant-switcher-label">{{ currentLabel }}</span>
    <select
      class="tenant-switcher-select"
      :value="String(sessionState.tenantId || '')"
      :disabled="state.loading || state.switching || state.tenants.length <= 1"
      @change="handleChange"
    >
      <option v-for="tenant in state.tenants" :key="tenant.id" :value="String(tenant.id)">
        {{ tenant.label || tenant.uuid || tenant.id }}
      </option>
    </select>
  </section>
</template>

<script setup>
import { computed, onMounted, reactive } from 'vue';
import { loadAvailableTenants, sessionState, switchActiveTenant } from '../auth/session';
import { t } from '../../modules/localization/i18nRuntime.js';

const state = reactive({
  loading: false,
  switching: false,
  tenants: [],
});

const currentLabel = computed(() => sessionState.tenantLabel || t('tenant.workspace'));

async function loadTenants() {
  if (!sessionState.sessionToken) return;
  state.loading = true;
  try {
    state.tenants = await loadAvailableTenants();
  } catch {
    state.tenants = sessionState.tenantId > 0
      ? [{ id: sessionState.tenantId, label: currentLabel.value }]
      : [];
  } finally {
    state.loading = false;
  }
}

async function handleChange(event) {
  const tenantId = Number.parseInt(String(event?.target?.value || ''), 10);
  if (!Number.isInteger(tenantId) || tenantId <= 0 || tenantId === sessionState.tenantId) return;
  state.switching = true;
  try {
    await switchActiveTenant(tenantId);
    window.location.reload();
  } finally {
    state.switching = false;
  }
}

onMounted(loadTenants);
</script>

<style scoped>
.tenant-switcher {
  display: grid;
  gap: 4px;
  padding: 8px 12px;
  border-top: 1px solid var(--border-subtle);
}

.tenant-switcher-label {
  overflow: hidden;
  color: var(--text-secondary);
  font-size: 12px;
  line-height: 1.2;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.tenant-switcher-select {
  width: 100%;
  min-height: 34px;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: var(--bg-input);
  color: #0b1324;
  font: inherit;
}
</style>
