<template>
  <main class="account-update-confirmation-page">
    <section class="account-update-confirmation-panel" aria-live="polite">
      <p class="account-update-confirmation-eyebrow">Account update</p>
      <h1>{{ heading }}</h1>
      <p class="account-update-confirmation-message">{{ message }}</p>
      <p v-if="confirmedDisplayName" class="account-update-confirmation-user">
        {{ confirmedDisplayName }}
      </p>
      <RouterLink
        v-if="state === 'confirmed'"
        class="account-update-confirmation-action"
        :to="continueTarget"
      >
        Continue
      </RouterLink>
      <RouterLink
        v-else-if="state === 'failed'"
        class="account-update-confirmation-action secondary"
        :to="continueTarget"
      >
        Back
      </RouterLink>
    </section>
  </main>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { defaultRouteForRole, sessionState } from '../../auth/session';
import { fetchBackend } from '../../../support/backendFetch';

const route = useRoute();
const state = ref('confirming');
const errorText = ref('');
const confirmedDisplayName = ref('');

const continueTarget = computed(() => defaultRouteForRole(sessionState.role));
const heading = computed(() => {
  if (state.value === 'confirmed') return 'Account update confirmed';
  if (state.value === 'failed') return 'Account update could not be confirmed';
  return 'Confirming account update';
});
const message = computed(() => {
  if (state.value === 'confirmed') return 'Your manually entered account data has been applied.';
  if (state.value === 'failed') return errorText.value || 'The confirmation link is invalid, expired, or no longer current.';
  return 'Please wait while the confirmation link is checked.';
});

function confirmationTokenFromRoute() {
  const token = typeof route.query.call_access_account_update_confirmation_token === 'string'
    ? route.query.call_access_account_update_confirmation_token.trim()
    : '';
  return token;
}

async function readResponsePayload(response) {
  try {
    const payload = await response.json();
    return payload && typeof payload === 'object' ? payload : {};
  } catch {
    return {};
  }
}

function responseErrorMessage(payload, fallback) {
  const message = typeof payload?.error?.message === 'string' ? payload.error.message.trim() : '';
  return message || fallback;
}

async function confirmAccountUpdate() {
  const token = confirmationTokenFromRoute();
  if (token === '') {
    state.value = 'failed';
    errorText.value = 'Confirmation token is missing.';
    return;
  }
  if (!sessionState.sessionToken) {
    state.value = 'failed';
    errorText.value = 'A valid signed-in session is required.';
    return;
  }

  try {
    const { response } = await fetchBackend(
      `/api/call-access/account-update-confirmations/${encodeURIComponent(token)}/confirm`,
      {
        method: 'POST',
        headers: {
          accept: 'application/json',
          authorization: `Bearer ${sessionState.sessionToken}`,
        },
      },
    );
    const payload = await readResponsePayload(response);
    if (!response.ok || payload?.status !== 'ok' || payload?.result?.state !== 'confirmed') {
      state.value = 'failed';
      errorText.value = responseErrorMessage(payload, 'Could not confirm account update.');
      return;
    }

    const displayName = typeof payload?.result?.user?.display_name === 'string'
      ? payload.result.user.display_name.trim()
      : '';
    confirmedDisplayName.value = displayName;
    state.value = 'confirmed';
  } catch {
    state.value = 'failed';
    errorText.value = 'Could not reach the backend.';
  }
}

onMounted(() => {
  void confirmAccountUpdate();
});
</script>

<style scoped>
.account-update-confirmation-page {
  min-height: 100vh;
  display: grid;
  place-items: center;
  padding: 32px;
  background: #f6f7f9;
  color: #17202a;
}

.account-update-confirmation-panel {
  width: min(100%, 440px);
  padding: 28px;
  border: 1px solid #d8dde6;
  border-radius: 8px;
  background: #ffffff;
  box-shadow: 0 12px 30px rgba(19, 33, 48, 0.08);
}

.account-update-confirmation-eyebrow {
  margin: 0 0 8px;
  color: #5d6b7a;
  font-size: 0.78rem;
  font-weight: 700;
  text-transform: uppercase;
}

.account-update-confirmation-panel h1 {
  margin: 0;
  font-size: 1.45rem;
  line-height: 1.2;
}

.account-update-confirmation-message,
.account-update-confirmation-user {
  margin: 14px 0 0;
  line-height: 1.5;
}

.account-update-confirmation-user {
  font-weight: 700;
}

.account-update-confirmation-action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 40px;
  margin-top: 22px;
  padding: 0 16px;
  border-radius: 8px;
  background: #1f6feb;
  color: #ffffff;
  font-weight: 700;
  text-decoration: none;
}

.account-update-confirmation-action.secondary {
  background: #425466;
}
</style>
