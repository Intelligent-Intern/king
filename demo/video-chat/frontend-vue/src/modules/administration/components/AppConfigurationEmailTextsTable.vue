<template>
  <AdminTableFrame class="app-config-table-wrap">
    <table class="app-config-table">
      <thead>
        <tr>
          <th>{{ t('administration.email_text_label') }}</th>
          <th>{{ t('administration.email_text_key') }}</th>
          <th>{{ t('administration.subject_template') }}</th>
          <th>{{ t('administration.email_text_updated') }}</th>
          <th>{{ t('governance.actions') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in rows" :key="row.id">
          <td :data-label="t('administration.email_text_label')">
            <strong>{{ row.label }}</strong>
            <span v-if="row.is_system" class="tag">{{ t('administration.email_text_system') }}</span>
          </td>
          <td :data-label="t('administration.email_text_key')">{{ row.template_key }}</td>
          <td :data-label="t('administration.subject_template')">{{ row.subject_template }}</td>
          <td :data-label="t('administration.email_text_updated')">{{ formatDate(row.updated_at) }}</td>
          <td :data-label="t('governance.actions')">
            <div class="actions-inline">
              <AppIconButton
                icon="/assets/orgas/kingrt/icons/gear.png"
                :title="t('administration.email_text_edit')"
                :aria-label="t('administration.email_text_edit')"
                @click="$emit('edit-row', row)"
              />
              <AppIconButton
                icon="/assets/orgas/kingrt/icons/remove_user.png"
                :title="t('administration.email_text_delete')"
                :aria-label="t('administration.email_text_delete')"
                :disabled="row.is_system"
                danger
                @click="$emit('delete-row', row)"
              />
            </div>
          </td>
        </tr>
        <tr v-if="!loading && rows.length === 0" class="table-empty-row">
          <td colspan="5" class="app-config-empty">{{ t('administration.email_text_empty') }}</td>
        </tr>
      </tbody>
    </table>
  </AdminTableFrame>
</template>

<script setup>
import AppIconButton from '../../../components/AppIconButton.vue';
import AdminTableFrame from '../../../components/admin/AdminTableFrame.vue';
import { formatLocalizedDateTimeDisplay } from '../../../support/dateTimeFormat';
import { t } from '../../localization/i18nRuntime.js';

defineProps({
  rows: {
    type: Array,
    default: () => [],
  },
  loading: {
    type: Boolean,
    default: false,
  },
});

defineEmits(['edit-row', 'delete-row']);

function formatDate(value) {
  return formatLocalizedDateTimeDisplay(value) || t('common.not_available');
}
</script>

<style scoped>
.app-config-table-wrap {
  padding-inline: 0;
}

.app-config-table {
  width: 100%;
  border-collapse: collapse;
}

.app-config-table th,
.app-config-table td {
  border-bottom: 1px solid var(--color-border);
  padding: 12px 14px;
  text-align: left;
  vertical-align: middle;
}

.app-config-table th {
  color: var(--text-main);
  background: var(--color-surface-navy);
}

.app-config-table td {
  color: var(--text-primary);
}

.app-config-table td strong {
  display: block;
  margin-bottom: 4px;
}

.app-config-empty {
  min-height: 180px;
  text-align: center;
  color: var(--text-muted);
}
</style>
