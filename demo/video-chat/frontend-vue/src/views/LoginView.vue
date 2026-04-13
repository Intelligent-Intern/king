<template>
  <main class="ii-auth">
    <section class="ii-authCard">
      <div class="ii-authSplit">
        <div class="ii-authSplit__brand">
          <div class="ii-authSplit__brandInner">
            <img
              class="ii-authSplit__logo"
              src="/assets/orgas/intelligent-intern/logo.svg"
              alt="Intelligent Intern"
            />
          </div>
        </div>

        <div class="ii-authSplit__form">
          <form class="ii-form" @submit.prevent="handleSubmit">
            <div>
              <label class="ii-fieldLabel" for="email">Email</label>
              <input
                id="email"
                v-model.trim="email"
                class="ii-input"
                type="email"
                inputmode="email"
                autocomplete="username"
                placeholder="name@company.com"
              />
              <p v-if="emailError" class="ii-fieldError">{{ emailError }}</p>
            </div>

            <div>
              <label class="ii-fieldLabel" for="password">Password</label>
              <input
                id="password"
                v-model="password"
                class="ii-input"
                type="password"
                autocomplete="current-password"
                placeholder="••••••••••"
              />
              <p v-if="passwordError" class="ii-fieldError">{{ passwordError }}</p>
            </div>

            <button
              v-if="backendRuntimeState.status === 'error'"
              class="ii-btn ii-btn--secondary"
              type="button"
              @click="handleRuntimeRetry"
            >
              Retry backend preflight
            </button>

            <button class="ii-btn ii-btn--primary ii-authBtn" type="submit">Sign in</button>
            <p v-if="authError" class="ii-error">{{ authError }}</p>
            <p class="runtime-preflight" :class="`runtime-preflight-${backendRuntimeState.status}`">
              {{ runtimeLabel }}
            </p>
          </form>
        </div>
      </div>
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

const email = ref('');
const password = ref('');
const emailError = ref('');
const passwordError = ref('');
const authError = ref('');

const ACCOUNTS = Object.freeze({
  'admin@intelligent-intern.com': {
    password: 'admin123',
    role: 'admin',
    displayName: 'Platform Admin',
  },
  'user@intelligent-intern.com': {
    password: 'user123',
    role: 'user',
    displayName: 'Call User',
  },
});

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
  emailError.value = '';
  passwordError.value = '';
  authError.value = '';

  const emailValue = email.value.trim().toLowerCase();
  const passwordValue = password.value;
  let hasError = false;

  if (emailValue === '') {
    emailError.value = 'Email is required.';
    hasError = true;
  } else if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(emailValue)) {
    emailError.value = 'Email is invalid.';
    hasError = true;
  }

  if (passwordValue === '') {
    passwordError.value = 'Password is required.';
    hasError = true;
  }

  if (hasError) {
    return;
  }

  const account = ACCOUNTS[emailValue];
  if (!account || account.password !== passwordValue) {
    authError.value = 'Invalid email or password.';
    return;
  }

  signInAs(account.role, account.displayName, emailValue);

  const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '';
  router.replace(redirect || defaultRouteForRole(account.role));
}
</script>
