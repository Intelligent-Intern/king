<template>
  <button
    class="btn btn-cyan admin-side-panel-submit"
    :type="type"
    :form="effectiveForm"
    :disabled="saving || disabled"
    @click="$emit('submit')"
  >
    {{ saving ? savingLabel : label }}
  </button>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  label: {
    type: String,
    required: true,
  },
  savingLabel: {
    type: String,
    required: true,
  },
  saving: {
    type: Boolean,
    default: false,
  },
  disabled: {
    type: Boolean,
    default: false,
  },
  form: {
    type: String,
    default: '',
  },
  type: {
    type: String,
    default: 'submit',
    validator: (value) => ['button', 'submit', 'reset'].includes(value),
  },
});

defineEmits(['submit']);

const effectiveForm = computed(() => {
  const value = String(props.form || '').trim();
  return value !== '' ? value : undefined;
});
</script>

<style scoped>
.admin-side-panel-submit {
  min-height: 40px;
  margin-inline-start: auto;
}
</style>
