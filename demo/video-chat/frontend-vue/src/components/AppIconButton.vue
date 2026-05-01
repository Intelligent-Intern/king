<template>
  <button
    v-bind="rootAttrs"
    :class="rootClass"
    :type="type"
    :title="title || undefined"
    :aria-label="ariaLabel || title || undefined"
    :disabled="disabled"
    @click="$emit('click', $event)"
  >
    <img v-if="icon" :src="icon" alt="" />
    <slot />
  </button>
</template>

<script setup>
import { computed, useAttrs } from 'vue';

defineOptions({ inheritAttrs: false });

const props = defineProps({
  icon: {
    type: String,
    default: '',
  },
  title: {
    type: String,
    default: '',
  },
  ariaLabel: {
    type: String,
    default: '',
  },
  type: {
    type: String,
    default: 'button',
  },
  disabled: {
    type: Boolean,
    default: false,
  },
  danger: {
    type: Boolean,
    default: false,
  },
});

defineEmits(['click']);

const attrs = useAttrs();
const rootAttrs = computed(() => {
  const { class: _class, ...rest } = attrs;
  return rest;
});
const rootClass = computed(() => ['icon-mini-btn', { danger: props.danger }, attrs.class]);
</script>
