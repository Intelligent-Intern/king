<template>
  <section class="view-card admin-page-frame">
    <AppPageHeader class="admin-page-frame-head" :title="title">
      <template v-if="$slots.actions" #actions>
        <slot name="actions" />
      </template>
    </AppPageHeader>

    <section v-if="$slots.toolbar" class="toolbar admin-page-frame-toolbar">
      <slot name="toolbar" />
    </section>

    <slot />

    <footer v-if="$slots.footer" class="footer admin-page-frame-footer">
      <slot name="footer" />
    </footer>
  </section>
</template>

<script setup>
import AppPageHeader from '../AppPageHeader.vue';

defineProps({
  title: {
    type: String,
    required: true,
  },
});
</script>

<style scoped>
.admin-page-frame {
  height: 100%;
  min-height: 0;
  display: flex;
  flex-direction: column;
  gap: 0;
  background: transparent;
  overflow: hidden;
}

.admin-page-frame > :first-child {
  border-start-start-radius: 0;
  border-start-end-radius: 5px;
}

.admin-page-frame-head,
.admin-page-frame-toolbar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  background: transparent;
}

.admin-page-frame-head {
  gap: 10px;
  justify-content: space-between;
}

.admin-page-frame-toolbar {
  gap: 20px;
  column-gap: 20px;
  row-gap: 20px;
  justify-content: flex-end;
  padding: 0 20px 20px;
}

.admin-page-frame-toolbar :deep(.search-field-main) {
  margin-inline-start: auto;
}

.admin-page-frame-toolbar :deep(.search-field) {
  flex: 0 1 360px;
  width: min(360px, 100%);
  min-width: min(240px, 100%);
}

.admin-page-frame-toolbar :deep(.ii-select) {
  flex: 0 0 180px;
  width: 180px;
  max-width: 100%;
}

.admin-page-frame-toolbar :deep(.icon-mini-btn) {
  flex: 0 0 auto;
  margin-inline-start: 0;
}

.admin-page-frame-toolbar :deep(.settings-upload-status),
.admin-page-frame-toolbar :deep(.governance-state),
.admin-page-frame-toolbar :deep(.governance-inline-error) {
  flex: 1 0 100%;
  text-align: end;
}

.admin-page-frame-footer {
  display: flex;
  justify-content: center;
  margin-top: auto;
  padding-inline: 10px;
  background: transparent;
}

@media (max-width: 760px) {
  .admin-page-frame-toolbar {
    align-items: stretch;
    padding-inline: 10px;
  }

  .admin-page-frame-toolbar :deep(.search-field-main) {
    margin-inline-start: 0;
  }

  .admin-page-frame-toolbar :deep(.search-field),
  .admin-page-frame-toolbar :deep(.ii-select) {
    flex: 1 1 100%;
    width: 100%;
  }

  .admin-page-frame-toolbar :deep(.icon-mini-btn) {
    margin-inline-start: auto;
  }
}
</style>
