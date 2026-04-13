<template>
  <RouterView />
</template>

<script setup>
import { onMounted } from 'vue';
import { RouterView } from 'vue-router';
import { probeBackendRuntime } from './stores/runtime';

onMounted(() => {
  void probeBackendRuntime();
});
</script>
