<template>
  <select
    class="ii-select"
    :value="normalizedValue"
    @change="handleChange"
  >
    <slot />
  </select>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  modelValue: {
    default: '',
  },
  modelModifiers: {
    type: Object,
    default: () => ({}),
  },
});

const emit = defineEmits(['update:modelValue', 'change']);

const normalizedValue = computed(() => {
  if (props.modelValue === null || props.modelValue === undefined) return '';
  return String(props.modelValue);
});

function castModelValue(rawValue) {
  if (props.modelModifiers?.number) {
    if (rawValue === '') return '';
    const parsed = Number(rawValue);
    return Number.isNaN(parsed) ? rawValue : parsed;
  }
  return rawValue;
}

function handleChange(event) {
  const rawValue = String(event?.target?.value ?? '');
  emit('update:modelValue', castModelValue(rawValue));
  emit('change', event);
}
</script>
