<template>
  <section class="table-wrap marketplace-table-wrap">
    <table class="marketplace-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Manufacturer</th>
          <th>Website</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="app in rows" :key="app.id">
          <td data-label="Name">
            <div class="marketplace-name">{{ app.name }}</div>
            <div class="marketplace-subline">{{ categoryLabel(app.category) }}</div>
          </td>
          <td data-label="Manufacturer">
            <div>{{ app.manufacturer || 'n/a' }}</div>
            <div class="marketplace-subline">Updated {{ formatDateTime(app.updated_at) }}</div>
          </td>
          <td data-label="Website">
            <a v-if="app.website" class="marketplace-link" :href="app.website" target="_blank" rel="noreferrer noopener">
              {{ app.website }}
            </a>
            <span v-else class="marketplace-subline">No website</span>
          </td>
          <td data-label="Actions">
            <div class="actions-inline">
              <AppIconButton
                icon="/assets/orgas/kingrt/icons/gear.png"
                title="Edit app"
                @click="$emit('edit-app', app)"
              />
              <AppIconButton
                icon="/assets/orgas/kingrt/icons/remove_user.png"
                title="Delete app"
                :disabled="mutatingAppId === app.id"
                danger
                @click="$emit('delete-app', app)"
              />
            </div>
          </td>
        </tr>
        <tr v-if="rows.length === 0">
          <td colspan="4" class="marketplace-empty-cell">No marketplace apps match the current filter.</td>
        </tr>
      </tbody>
    </table>
  </section>
</template>

<script setup>
import AppIconButton from '../../../components/AppIconButton.vue';

const CATEGORY_LABELS = {
  whiteboard: 'Whiteboard',
  avatar: 'Avatar',
  assistant: 'Assistant',
  collaboration: 'Collaboration',
  utility: 'Utility',
  other: 'Other',
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
  return CATEGORY_LABELS[normalized] || 'Other';
}

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
</script>

<style scoped src="./AdminMarketplaceView.css"></style>
