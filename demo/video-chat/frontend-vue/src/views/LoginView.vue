<template>
  <main class="login-screen">
    <section class="login-card">
      <h1>Video Control Workspace</h1>
      <p>Sign in to continue.</p>
      <p class="runtime-preflight" :class="`runtime-preflight-${backendRuntimeState.status}`">
        {{ runtimeLabel }}
      </p>

      <form class="login-form" @submit.prevent="handleSubmit">
        <label>
          Display name
          <input v-model.trim="displayName" type="text" required maxlength="80" />
        </label>

        <label>
          Email
          <input v-model.trim="email" type="email" required maxlength="160" />
        </label>

        <label>
          Role
          <select v-model="role">
            <option value="admin">Admin</option>
            <option value="user">User</option>
          </select>
        </label>

        <p v-if="error" class="form-error">{{ error }}</p>
        <button v-if="backendRuntimeState.status === 'error'" type="button" @click="handleRuntimeRetry">
          Retry backend preflight
        </button>
        <button type="submit">Sign in</button>
      </form>
    </section>
  </main>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { defaultRouteForRole, signInAs } from '../stores/session';
import { backendRuntimeState, probeBackendRuntime } from '../stores/runtime';

const router = useRouter();
const route = useRoute();

const displayName = ref('');
const email = ref('');
const role = ref('admin');
const error = ref('');

const runtimeLabel = computed(() => {
  if (backendRuntimeState.status === 'probing') {
    return `Backend runtime preflight in progress (${backendRuntimeState.backendOrigin})…`;
  }

  if (backendRuntimeState.status === 'error') {
    return `Backend runtime preflight failed: ${backendRuntimeState.error}`;
  }

  if (backendRuntimeState.status === 'ready' && backendRuntimeState.data) {
    const appVersion = backendRuntimeState.data?.app?.version || 'n/a';
    const kingVersion = backendRuntimeState.data?.runtime?.king_version || 'n/a';
    return `Backend ${appVersion} · King ${kingVersion}`;
  }

  return `Backend runtime preflight pending (${backendRuntimeState.backendOrigin})`;
});

function handleRuntimeRetry() {
  void probeBackendRuntime();
}

function handleSubmit() {
  error.value = '';
  if (displayName.value.trim() === '') {
    error.value = 'Display name is required.';
    return;
  }
  if (email.value.trim() === '') {
    error.value = 'Email is required.';
    return;
  }

  signInAs(role.value, displayName.value, email.value);

  const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '';
  router.replace(redirect || defaultRouteForRole(role.value));
}
</script>
