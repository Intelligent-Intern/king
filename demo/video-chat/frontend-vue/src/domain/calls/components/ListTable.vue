<template>
  <table class="calls-list-table" :class="{ 'calls-list-table-admin': adminMode }">
    <thead>
      <tr>
        <th class="col-title">Call</th>
        <th>Status</th>
        <th>Window</th>
        <th>Participants</th>
        <th>Owner</th>
        <th class="col-actions">Actions</th>
      </tr>
    </thead>
    <tbody v-if="calls.length > 0">
      <tr v-for="call in calls" :key="call.id">
        <td data-label="Call">
          <div class="call-title">{{ call.title || call.id }}</div>
          <div class="call-subline code">{{ call.id }}</div>
        </td>
        <td data-label="Status">
          <span class="tag" :class="statusTagClass(call.status)">
            {{ call.status || 'unknown' }}
          </span>
        </td>
        <td data-label="Window">{{ formatRange(call.starts_at, call.ends_at) }}</td>
        <td data-label="Participants">
          {{ call.participants?.total ?? 0 }}
          <span class="call-subline">
            in {{ call.participants?.internal ?? 0 }} / ex {{ call.participants?.external ?? 0 }}
          </span>
        </td>
        <td data-label="Owner">
          {{ call.owner?.display_name || 'Unknown' }}
          <span class="call-subline">{{ call.owner?.email || 'n/a' }}</span>
        </td>
        <td data-label="Actions">
          <div class="actions-inline">
            <AppIconButton
              v-if="adminMode || isEditable(call)"
              icon="/assets/orgas/kingrt/icons/gear.png"
              title="Edit call"
              :aria-label="`Edit call ${call.title || call.id}`"
              :disabled="adminMode && !isEditable(call)"
              @click="$emit('edit-call', call)"
            />
            <AppIconButton
              icon="/assets/orgas/kingrt/icons/chat.png"
              title="Open chat archive"
              :aria-label="`Open chat archive for ${call.title || call.id}`"
              @click="$emit('open-chat-archive', call)"
            />
            <AppIconButton
              v-if="adminMode || isInvitable(call)"
              icon="/assets/orgas/kingrt/icons/add_to_call.png"
              title="Enter video call"
              :aria-label="`Enter video call ${call.title || call.id}`"
              :disabled="adminMode && !isInvitable(call)"
              @click="$emit('enter-call', call)"
            />
            <AppIconButton
              v-if="adminMode"
              icon="/assets/orgas/kingrt/icons/end_call.png"
              title="Cancel call"
              :aria-label="`Cancel call ${call.title || call.id}`"
              :disabled="!isCancellable(call)"
              danger
              @click="$emit('cancel-call', call)"
            />
            <AppIconButton
              v-if="adminMode"
              icon="/assets/orgas/kingrt/icons/remove_user.png"
              title="Delete call"
              :aria-label="`Delete call ${call.title || call.id}`"
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
</script>

<style scoped src="./ListTable.css"></style>
