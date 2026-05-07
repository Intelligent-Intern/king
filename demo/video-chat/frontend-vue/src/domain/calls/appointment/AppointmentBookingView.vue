<template>
  <main class="appointment-booking-page">
    <AppointmentBookingModal :open="true" :calendar-id="calendarId" @close="close" />
  </main>
</template>

<script setup>
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AppointmentBookingModal from './AppointmentBookingModal.vue';

const route = useRoute();
const router = useRouter();
const calendarId = computed(() => String(route.params.calendarId || '').trim());

function close() {
  router.replace('/login');
}
</script>

<style scoped>
.appointment-booking-page {
  min-height: 100dvh;
  background: var(--bg-ui-chrome);
}
</style>
