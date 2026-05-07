<template>
  <main class="call-goodbye-page">
    <section class="call-goodbye-card">
      <img class="call-goodbye-logo" src="/assets/orgas/kingrt/king_logo-withslogan.svg" alt="KingRT" />
      <p class="call-goodbye-text">{{ t('public.goodbye.left_call') }}</p>
      <p class="call-goodbye-meta">{{ t('public.goodbye.customizable_hint') }}</p>
      <button class="btn" type="button" @click="goToLogin">{{ t('public.goodbye.back_to_login') }}</button>
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
  postLogoutRedirectTarget,
  sessionState,
} from '../../auth/session';
import { t } from '../../../modules/localization/i18nRuntime.js';

const router = useRouter();

function redirectAccountSession() {
  if (isAuthenticated() && !isGuestSession()) {
    router.replace(callListRouteForRole(sessionState.role));
  }
}

async function goToLogin() {
  const logoutResult = await logoutSession();
  router.replace(postLogoutRedirectTarget(logoutResult, '/login'));
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
  background: var(--color-surface-navy);
  padding: 24px;
}

.call-goodbye-card {
  width: min(520px, 100%);
  background: var(--color-border);
  border: 1px solid var(--color-border);
  border-radius: 14px;
  padding: 24px;
  color: var(--color-text-primary);
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
  color: var(--color-heading);
}
</style>
