<template>
  <section class="table-wrap marketplace-table-wrap">
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
        <tr v-for="app in rows" :key="app.id">
          <td :data-label="t('marketplace.name')">
            <div class="marketplace-name">{{ app.name }}</div>
            <div class="marketplace-subline">{{ categoryLabel(app.category) }}</div>
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
                icon="/assets/orgas/kingrt/icons/gear.png"
                :title="t('marketplace.edit_app')"
                @click="$emit('edit-app', app)"
              />
              <AppIconButton
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
  </section>
</template>

<script setup>
import AppIconButton from '../../../components/AppIconButton.vue';
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
});

defineEmits(['edit-app', 'delete-app']);

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
</script>

<style scoped src="./AdminMarketplaceView.css"></style>
