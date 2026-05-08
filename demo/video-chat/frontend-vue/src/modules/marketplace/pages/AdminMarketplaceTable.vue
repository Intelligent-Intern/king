<template>
  <AdminTableFrame class="marketplace-table-wrap">
    <table class="marketplace-table">
      <thead>
        <tr>
          <th>{{ t('marketplace.name') }}</th>
          <th>{{ t('marketplace.manufacturer') }}</th>
          <th>{{ t('marketplace.website') }}</th>
          <th>{{ t('marketplace.actions') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="app in rows" :key="rowKey(app)">
          <td :data-label="t('marketplace.name')">
            <div class="marketplace-name">{{ app.name }}</div>
            <div class="marketplace-subline">{{ categoryLabel(app.category) }}</div>
            <div v-if="catalogApp(app)" class="marketplace-subline marketplace-call-app-state">
              {{ t('marketplace.call_app_state', { state: callAppStateLabel(app) }) }}
            </div>
          </td>
          <td :data-label="t('marketplace.manufacturer')">
            <div>{{ app.manufacturer || t('common.not_available') }}</div>
            <div class="marketplace-subline">{{ t('marketplace.updated', { date: formatDateTime(app.updated_at) }) }}</div>
          </td>
          <td :data-label="t('marketplace.website')">
            <a v-if="app.website" class="marketplace-link" :href="app.website" target="_blank" rel="noreferrer noopener">
              {{ app.website }}
            </a>
            <span v-else class="marketplace-subline">{{ t('marketplace.no_website') }}</span>
          </td>
          <td :data-label="t('marketplace.actions')">
            <div class="actions-inline">
              <AppIconButton
                v-if="catalogApp(app)"
                icon="/assets/orgas/kingrt/icons/add.png"
                :title="installTitle(app)"
                :disabled="installingAppKey === callAppKey(app) || !canInstallCallApp(app)"
                @click="$emit('install-call-app', app)"
              />
              <AppIconButton
                v-if="!isCatalogOnly(app)"
                icon="/assets/orgas/kingrt/icons/gear.png"
                :title="t('marketplace.edit_app')"
                @click="$emit('edit-app', app)"
              />
              <AppIconButton
                v-if="!isCatalogOnly(app)"
                icon="/assets/orgas/kingrt/icons/remove_user.png"
                :title="t('marketplace.delete_app')"
                :disabled="mutatingAppId === app.id"
                danger
                @click="$emit('delete-app', app)"
              />
            </div>
          </td>
        </tr>
        <tr v-if="rows.length === 0">
          <td colspan="4" class="marketplace-empty-cell">{{ t('marketplace.empty_filter') }}</td>
        </tr>
      </tbody>
    </table>
  </AdminTableFrame>
</template>

<script setup>
import AppIconButton from '../../../components/AppIconButton.vue';
import AdminTableFrame from '../../../components/admin/AdminTableFrame.vue';
import { sessionState } from '../../../domain/auth/session';
import { formatLocalizedDateTimeDisplay } from '../../../support/dateTimeFormat';
import { t } from '../../localization/i18nRuntime.js';

const CATEGORY_LABELS = {
  whiteboard: 'marketplace.category.whiteboard',
  avatar: 'marketplace.category.avatar',
  assistant: 'marketplace.category.assistant',
  collaboration: 'marketplace.category.collaboration',
  utility: 'marketplace.category.utility',
  other: 'marketplace.category.other',
};

defineProps({
  rows: {
    type: Array,
    default: () => [],
  },
  mutatingAppId: {
    type: Number,
    default: 0,
  },
  installingAppKey: {
    type: String,
    default: '',
  },
});

defineEmits(['install-call-app', 'edit-app', 'delete-app']);

function categoryLabel(category) {
  const normalized = String(category || '').toLowerCase();
  return t(CATEGORY_LABELS[normalized] || 'marketplace.category.other');
}

function formatDateTime(value) {
  return formatLocalizedDateTimeDisplay(value, {
    locale: sessionState.locale,
    dateFormat: sessionState.dateFormat,
    timeFormat: sessionState.timeFormat,
    fallback: t('common.not_available'),
  });
}

function catalogApp(app) {
  const catalog = app?.call_app_catalog;
  if (!catalog || typeof catalog !== 'object') return null;
  return String(catalog.app_key || '').trim() !== '' ? catalog : null;
}

function callAppKey(app) {
  return String(catalogApp(app)?.app_key || '').trim();
}

function rowKey(app) {
  const appId = Number(app?.id || 0);
  if (appId > 0) return `marketplace:${appId}`;
  const catalogKey = callAppKey(app);
  return catalogKey === '' ? `catalog:${String(app?.name || '')}` : `catalog:${catalogKey}`;
}

function isCatalogOnly(app) {
  return app?.catalog_only === true || String(app?.source || '') === 'call_app_catalog';
}

function organizationState(app) {
  const catalog = catalogApp(app);
  const organization = catalog?.organization;
  return organization && typeof organization === 'object' ? organization : {};
}

function isCatalogHealthy(app) {
  const status = String(catalogApp(app)?.health_status || '').toLowerCase();
  return status === '' || status === 'healthy' || status === 'degraded';
}

function isInstalled(app) {
  return organizationState(app).installed === true;
}

function canInstallCallApp(app) {
  return Boolean(catalogApp(app) && isCatalogHealthy(app));
}

function callAppStateLabel(app) {
  const state = organizationState(app);
  if (state.installed === true) return t('marketplace.call_app_state.installed');
  if (state.status === 'disabled') return t('marketplace.call_app_state.disabled');
  if (state.ordered === true) return t('marketplace.call_app_state.ordered');
  if (!isCatalogHealthy(app)) return t('marketplace.call_app_state.unhealthy');
  return t('marketplace.call_app_state.not_installed');
}

function installTitle(app) {
  if (isInstalled(app)) return t('marketplace.call_app_install.verify');
  if (!isCatalogHealthy(app)) return t('marketplace.call_app_install.unhealthy');
  if (organizationState(app).status === 'disabled') return t('marketplace.call_app_install.enable');
  return t('marketplace.call_app_install.install');
}
</script>

<style scoped src="./AdminMarketplaceView.css"></style>
