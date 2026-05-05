<template>
  <section class="view-card governance-placeholder-view">
    <AppPageHeader class="section governance-placeholder-head" :title="title" />
    <section class="governance-empty section">
      <p>{{ emptyText }}</p>
    </section>
  </section>
</template>

<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import AppPageHeader from '../../components/AppPageHeader.vue';

const route = useRoute();

const title = computed(() => {
  const value = typeof route.meta?.pageTitle === 'string' ? route.meta.pageTitle.trim() : '';
  return value || 'Governance';
});

const emptyText = computed(() => {
  const value = typeof route.meta?.emptyText === 'string' ? route.meta.emptyText.trim() : '';
  return value || 'Keine Daten vorhanden.';
});
</script>

<style scoped>
.governance-placeholder-view {
  min-height: 100%;
  display: flex;
  flex-direction: column;
  background: transparent;
}

.governance-placeholder-head {
  background: var(--bg-ui-chrome);
}

.governance-empty {
  flex: 1 1 auto;
  min-height: 260px;
  display: grid;
  align-content: center;
  justify-items: center;
  gap: 8px;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: var(--bg-surface);
  text-align: center;
}

.governance-empty p {
  margin: 0;
  color: var(--text-muted);
}
</style>
