<template>
  <table class="calls-list-table" :class="{ 'calls-list-table-admin': adminMode }">
    <thead>
      <tr>
        <th class="col-title">{{ t('calls.table.call') }}</th>
        <th>{{ t('calls.table.status') }}</th>
        <th>{{ t('calls.table.window') }}</th>
        <th>{{ t('calls.table.participants') }}</th>
        <th>{{ t('calls.table.owner') }}</th>
        <th class="col-actions">{{ t('calls.table.actions') }}</th>
      </tr>
    </thead>
    <tbody v-if="calls.length > 0">
      <tr v-for="call in calls" :key="call.id">
        <td :data-label="t('calls.table.call')">
          <div class="call-title">{{ call.title || call.id }}</div>
          <div class="call-subline code">{{ call.id }}</div>
        </td>
        <td :data-label="t('calls.table.status')">
          <span class="tag" :class="statusTagClass(call.status)">
            {{ call.status || t('calls.status_unknown') }}
          </span>
        </td>
        <td :data-label="t('calls.table.window')">{{ formatRange(call.starts_at, call.ends_at) }}</td>
        <td :data-label="t('calls.table.participants')">
          {{ call.participants?.total ?? 0 }}
          <span class="call-subline">
            {{ t('calls.table.participant_breakdown', { internal: call.participants?.internal ?? 0, external: call.participants?.external ?? 0 }) }}
          </span>
        </td>
        <td :data-label="t('calls.table.owner')">
          {{ call.owner?.display_name || t('calls.owner_unknown') }}
          <span class="call-subline">{{ call.owner?.email || t('common.not_available') }}</span>
        </td>
        <td :data-label="t('calls.table.actions')">
          <div class="actions-inline">
            <AppIconButton
              v-if="adminMode || isEditable(call)"
              icon="/assets/orgas/kingrt/icons/gear.png"
              :title="t('calls.actions.edit')"
              :aria-label="t('calls.actions.edit_call', { call: callLabel(call) })"
              :disabled="adminMode && !isEditable(call)"
              @click="$emit('edit-call', call)"
            />
            <AppIconButton
              icon="/assets/orgas/kingrt/icons/chat.png"
              :title="t('calls.actions.open_chat_archive')"
              :aria-label="t('calls.actions.open_chat_archive_for', { call: callLabel(call) })"
              @click="$emit('open-chat-archive', call)"
            />
            <AppIconButton
              v-if="adminMode || isInvitable(call)"
              icon="/assets/orgas/kingrt/icons/add_to_call.png"
              :title="t('calls.actions.enter')"
              :aria-label="t('calls.actions.enter_call', { call: callLabel(call) })"
              :disabled="adminMode && !isInvitable(call)"
              @click="$emit('enter-call', call)"
            />
            <AppIconButton
              v-if="adminMode"
              icon="/assets/orgas/kingrt/icons/end_call.png"
              :title="t('calls.actions.cancel')"
              :aria-label="t('calls.actions.cancel_call', { call: callLabel(call) })"
              :disabled="!isCancellable(call)"
              danger
              @click="$emit('cancel-call', call)"
            />
            <AppIconButton
              v-if="adminMode"
              icon="/assets/orgas/kingrt/icons/remove_user.png"
              :title="t('calls.actions.delete')"
              :aria-label="t('calls.actions.delete_call', { call: callLabel(call) })"
              :disabled="!isDeletable(call)"
              danger
              @click="$emit('delete-call', call)"
            />
          </div>
        </td>
      </tr>
    </tbody>
  </table>
</template>

<script setup>
import AppIconButton from '../../../components/AppIconButton.vue';
import { t } from '../../../modules/localization/i18nRuntime.js';

defineProps({
  calls: {
    type: Array,
    default: () => [],
  },
  adminMode: {
    type: Boolean,
    default: false,
  },
  formatRange: {
    type: Function,
    required: true,
  },
  statusTagClass: {
    type: Function,
    required: true,
  },
  isEditable: {
    type: Function,
    required: true,
  },
  isInvitable: {
    type: Function,
    required: true,
  },
  isCancellable: {
    type: Function,
    default: () => false,
  },
  isDeletable: {
    type: Function,
    default: () => false,
  },
});

defineEmits([
  'edit-call',
  'open-chat-archive',
  'enter-call',
  'cancel-call',
  'delete-call',
]);

function callLabel(call) {
  return String(call?.title || call?.id || '').trim() || t('calls.this_call');
}
</script>

<style scoped src="./ListTable.css"></style>
