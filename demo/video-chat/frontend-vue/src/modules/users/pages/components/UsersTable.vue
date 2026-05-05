<template>
  <section class="table-wrap users-table-wrap">
    <table class="users-table">
      <thead>
        <tr>
          <th>{{ t('users.name') }}</th>
          <th>{{ t('users.email') }}</th>
          <th>{{ t('users.role') }}</th>
          <th>{{ t('users.status') }}</th>
          <th>{{ t('users.updated') }}</th>
          <th>{{ t('users.actions') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="user in rows" :key="user.id">
          <td :data-label="t('users.name')">
            <div class="users-name">{{ user.display_name }}</div>
            <div class="users-subline code">#{{ user.id }}</div>
          </td>
          <td :data-label="t('users.email')">
            <div>{{ user.email }}</div>
            <div class="users-subline">{{ user.time_format }} · {{ user.theme }}</div>
          </td>
          <td :data-label="t('users.role')"><span class="tag" :class="roleTagClass(user.role)">{{ roleLabel(user.role) }}</span></td>
          <td :data-label="t('users.status')"><span class="tag" :class="statusTagClass(user.status)">{{ statusLabel(user.status) }}</span></td>
          <td :data-label="t('users.updated')">{{ formatDateTime(user.updated_at) }}</td>
          <td :data-label="t('users.actions')">
            <div class="actions-inline">
              <AppIconButton
                icon="/assets/orgas/kingrt/icons/gear.png"
                :title="t('users.edit_user')"
                :aria-label="t('users.edit_user')"
                @click="$emit('edit-user', user)"
              />
              <AppIconButton
                v-if="canToggleStatus(user)"
                :icon="statusToggleIcon(user)"
                :disabled="mutatingUserId === user.id"
                @click="$emit('toggle-user-status', user)"
              />
              <AppIconButton
                v-if="canDeleteUser(user)"
                icon="/assets/orgas/kingrt/icons/remove_user.png"
                :title="t('users.delete_user')"
                :aria-label="t('users.delete_user')"
                :disabled="mutatingUserId === user.id"
                danger
                @click="$emit('delete-user', user)"
              />
            </div>
          </td>
        </tr>
        <tr v-if="rows.length === 0">
          <td colspan="6" class="users-empty-cell">{{ t('users.empty_filter') }}</td>
        </tr>
      </tbody>
    </table>
  </section>
</template>

<script setup>
import AppIconButton from '../../../../components/AppIconButton.vue';
import { sessionState } from '../../../../domain/auth/session';
import { formatLocalizedDateTimeDisplay } from '../../../../support/dateTimeFormat';
import { t } from '../../../localization/i18nRuntime.js';

defineProps({
  rows: {
    type: Array,
    default: () => [],
  },
  mutatingUserId: {
    type: Number,
    default: 0,
  },
  canToggleStatus: {
    type: Function,
    required: true,
  },
  canDeleteUser: {
    type: Function,
    required: true,
  },
});

defineEmits(['edit-user', 'toggle-user-status', 'delete-user']);

function formatDateTime(value) {
  return formatLocalizedDateTimeDisplay(value, {
    locale: sessionState.locale,
    dateFormat: sessionState.dateFormat,
    timeFormat: sessionState.timeFormat,
    fallback: t('common.not_available'),
  });
}

function roleLabel(role) {
  const normalized = String(role || '').toLowerCase();
  if (normalized === 'admin') return t('users.role_admin');
  if (normalized === 'user') return t('users.role_user');
  return String(role || '');
}

function roleTagClass(role) {
  const normalized = String(role || '').toLowerCase();
  return normalized === 'admin' ? 'ok' : 'warn';
}

function statusLabel(status) {
  const normalized = String(status || '').toLowerCase();
  if (normalized === 'active') return t('users.status_active');
  if (normalized === 'disabled') return t('users.status_disabled');
  return String(status || '');
}

function statusTagClass(status) {
  const normalized = String(status || '').toLowerCase();
  return normalized === 'active' ? 'ok' : 'warn';
}

function statusToggleIcon(user) {
  return String(user?.status || '').toLowerCase() === 'disabled'
    ? '/assets/orgas/kingrt/icons/adminon.png'
    : '/assets/orgas/kingrt/icons/adminoff.png';
}
</script>

<style scoped src="../admin/UsersView.css"></style>
