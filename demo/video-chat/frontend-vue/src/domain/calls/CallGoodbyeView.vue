<template>
  <main class="call-goodbye-page">
    <section class="call-goodbye-card">
      <img class="call-goodbye-logo" src="/assets/orgas/kingrt/king_logo-withslogan.svg" alt="KingRT" />
      <p class="call-goodbye-text">You have left the video call.</p>
      <p class="call-goodbye-meta">This exit page can be customized by admin settings later.</p>
      <button class="btn" type="button" @click="goToLogin">Back to login</button>
    </section>
  </main>
</template>

<script setup>
import { onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import {
  callListRouteForRole,
  isAuthenticated,
  isGuestSession,
  logoutSession,
  sessionState,
} from '../auth/session';

const router = useRouter();

function redirectAccountSession() {
  if (isAuthenticated() && !isGuestSession()) {
    router.replace(callListRouteForRole(sessionState.role));
  }
}

async function goToLogin() {
  await logoutSession();
  router.replace('/login');
}

onMounted(redirectAccountSession);
watch(
  () => [sessionState.sessionToken, sessionState.role, sessionState.accountType],
  redirectAccountSession,
);
</script>

<style scoped>
.call-goodbye-page {
  min-height: 100vh;
  display: grid;
  place-items: center;
  background: var(--color-0b1324);
  padding: 24px;
}

.call-goodbye-card {
  width: min(520px, 100%);
  background: var(--color-182c4d);
  border: 1px solid var(--color-133262);
  border-radius: 14px;
  padding: 24px;
  color: var(--color-f7f7f7);
  text-align: center;
  display: grid;
  gap: 12px;
}

.call-goodbye-logo {
  width: min(260px, 100%);
  margin: 0 auto;
}

.call-goodbye-text {
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
}

.call-goodbye-meta {
  margin: 0;
  font-size: 0.86rem;
  color: var(--color-c9d5ea);
}
</style>
