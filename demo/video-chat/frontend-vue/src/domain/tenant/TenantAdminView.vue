<template>
  <section class="view-card tenant-admin-view">
    <AppPageHeader class="section" :title="t('tenant.admin_title')" />
    <section class="toolbar tenant-admin-toolbar">
      <div>
        <strong>{{ sessionState.tenantLabel || t('tenant.workspace') }}</strong>
        <span>{{ sessionState.tenantRole || t('tenant.member') }}</span>
      </div>
      <button class="btn" type="button" :disabled="state.loading" @click="loadContext">{{ t('tenant.refresh') }}</button>
    </section>
    <section class="tenant-admin-grid">
      <article class="tenant-admin-panel">
        <h2>{{ t('tenant.organizations') }}</h2>
        <p v-for="organization in state.organizations" :key="organization.id">{{ organization.name }}</p>
      </article>
      <article class="tenant-admin-panel">
        <h2>{{ t('tenant.groups') }}</h2>
        <p v-for="group in state.groups" :key="group.id">{{ group.name }}</p>
      </article>
    </section>
  </section>
</template>

<script setup>
import { onMounted, reactive } from 'vue';
import AppPageHeader from '../../components/AppPageHeader.vue';
import { sessionState } from '../auth/session';
import { fetchBackend } from '../../support/backendFetch';
import { t } from '../../modules/localization/i18nRuntime.js';

const state = reactive({
  loading: false,
  organizations: [],
  groups: [],
});

async function loadContext() {
  state.loading = true;
  try {
    const { response } = await fetchBackend('/api/admin/tenancy/context', {
      method: 'GET',
      headers: {
        accept: 'application/json',
        authorization: `Bearer ${sessionState.sessionToken}`,
      },
    });
    const payload = await response.json();
    if (!response.ok || payload?.status !== 'ok') {
      throw new Error(t('tenant.load_context_failed'));
    }
    state.organizations = Array.isArray(payload.organizations) ? payload.organizations : [];
    state.groups = Array.isArray(payload.groups) ? payload.groups : [];
  } finally {
    state.loading = false;
  }
}

onMounted(loadContext);
</script>

<style scoped>
.tenant-admin-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.tenant-admin-toolbar div {
  display: grid;
  gap: 4px;
}

.tenant-admin-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}

.tenant-admin-panel {
  display: grid;
  gap: 8px;
}
</style>
