<template>
  <label class="search-field search-field-main" :aria-label="searchLabel">
    <input
      :value="modelValue"
      class="input"
      type="search"
      :placeholder="searchPlaceholder || searchLabel"
      @input="$emit('update:modelValue', $event.target.value)"
      @keydown.enter.prevent="$emit('submit')"
    />
  </label>

  <slot />

  <AppIconButton
    class="admin-search-toolbar-submit"
    icon="/assets/orgas/kingrt/icons/send.png"
    :title="searchLabel"
    :aria-label="searchLabel"
    @click="$emit('submit')"
  />
</template>

<script setup>
import AppIconButton from '../AppIconButton.vue';

defineProps({
  modelValue: {
    type: String,
    default: '',
  },
  searchLabel: {
    type: String,
    required: true,
  },
  searchPlaceholder: {
    type: String,
    default: '',
  },
});

defineEmits(['update:modelValue', 'submit']);
</script>

<style scoped>
.search-field {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 20px;
  flex: 0 1 360px;
  width: min(360px, 100%);
  min-width: min(240px, 100%);
  margin-inline-start: 0;
}

.admin-search-toolbar-submit {
  width: 40px;
  height: 40px;
}

.admin-search-toolbar-submit :deep(img) {
  width: 18px;
  height: 18px;
}
</style>
