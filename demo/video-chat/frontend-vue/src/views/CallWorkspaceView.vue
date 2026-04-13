<template>
  <section class="view-card">
    <h3>Call Workspace</h3>
    <p>Scaffold route resolved for room: <strong>{{ roomId }}</strong></p>
    <p>Realtime room, lobby, users, chat, and media controls land in follow-up leaves.</p>
  </section>
</template>

<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';

const route = useRoute();
const roomId = computed(() => (typeof route.params.roomId === 'string' && route.params.roomId !== ''
  ? route.params.roomId
  : 'lobby'));
</script>
