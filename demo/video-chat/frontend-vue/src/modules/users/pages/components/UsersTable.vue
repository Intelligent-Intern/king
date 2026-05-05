<template>
  <section class="table-wrap users-table-wrap">
    <table class="users-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Email</th>
          <th>Role</th>
          <th>Status</th>
          <th>Updated</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="user in rows" :key="user.id">
          <td data-label="Name">
            <div class="users-name">{{ user.display_name }}</div>
            <div class="users-subline code">#{{ user.id }}</div>
          </td>
          <td data-label="Email">
            <div>{{ user.email }}</div>
            <div class="users-subline">{{ user.time_format }} · {{ user.theme }}</div>
          </td>
          <td data-label="Role"><span class="tag" :class="roleTagClass(user.role)">{{ user.role }}</span></td>
          <td data-label="Status"><span class="tag" :class="statusTagClass(user.status)">{{ user.status }}</span></td>
          <td data-label="Updated">{{ formatDateTime(user.updated_at) }}</td>
          <td data-label="Actions">
            <div class="actions-inline">
              <AppIconButton
                icon="/assets/orgas/kingrt/icons/gear.png"
                title="Edit user"
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
                title="Delete user"
                :disabled="mutatingUserId === user.id"
                danger
                @click="$emit('delete-user', user)"
              />
            </div>
          </td>
        </tr>
        <tr v-if="rows.length === 0">
          <td colspan="6" class="users-empty-cell">No users match the current filter.</td>
        </tr>
      </tbody>
    </table>
  </section>
</template>

<script setup>
import AppIconButton from '../../../../components/AppIconButton.vue';

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
  const text = typeof value === 'string' ? value.trim() : '';
  if (text === '') return 'n/a';
  const date = new Date(text);
  if (Number.isNaN(date.getTime())) return text;
  return new Intl.DateTimeFormat('en-GB', {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date);
}

function roleTagClass(role) {
  const normalized = String(role || '').toLowerCase();
  return normalized === 'admin' ? 'ok' : 'warn';
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
