<template>
  <section class="governance-empty-state">
    <div class="governance-empty-icon" aria-hidden="true"></div>
    <h2>{{ title }}</h2>
    <p>{{ body }}</p>
    <button v-if="showCreate" class="btn btn-cyan" type="button" @click="$emit('create')">
      {{ createLabel }}
    </button>
  </section>
</template>

<script setup>
defineProps({
  title: {
    type: String,
    required: true,
  },
  body: {
    type: String,
    required: true,
  },
  createLabel: {
    type: String,
    default: '',
  },
  showCreate: {
    type: Boolean,
    default: false,
  },
});

defineEmits(['create']);
</script>
