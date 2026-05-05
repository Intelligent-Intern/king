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
  gap: 10px;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  background: transparent;
}

.admin-page-frame-toolbar {
  padding-bottom: 25px;
}

.admin-page-frame-footer {
  display: flex;
  justify-content: center;
  margin-top: auto;
  padding-inline: 10px;
  background: transparent;
}
</style>
