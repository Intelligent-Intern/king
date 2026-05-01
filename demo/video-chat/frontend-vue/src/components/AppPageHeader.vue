<template>
  <header v-bind="rootAttrs" :class="rootClass">
    <div class="app-page-header-main">
      <slot name="before-title" />
      <h1>{{ title }}</h1>
      <p v-if="subtitle">{{ subtitle }}</p>
      <slot />
    </div>
    <div v-if="$slots.actions" class="actions app-page-header-actions">
      <slot name="actions" />
    </div>
  </header>
</template>

<script setup>
import { computed, useAttrs } from 'vue';

defineOptions({ inheritAttrs: false });

const props = defineProps({
  title: {
    type: String,
    required: true,
  },
  subtitle: {
    type: String,
    default: '',
  },
});

const attrs = useAttrs();
const rootAttrs = computed(() => {
  const { class: _class, ...rest } = attrs;
  return rest;
});
const rootClass = computed(() => ['app-page-header', attrs.class]);
</script>

<style scoped>
.app-page-header h1 {
  margin: 0;
  font-size: 22px;
  font-weight: 700;
}

.app-page-header p {
  margin: 4px 0 0;
  color: var(--text-muted);
}
</style>
