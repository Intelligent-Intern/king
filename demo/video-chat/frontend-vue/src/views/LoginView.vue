<template>
  <div class="ii-auth">
    <div class="ii-authCard">
      <div class="ii-authSplit">
        <div class="ii-authSplit__brand">
          <div class="ii-authSplit__brandInner">
            <img
              class="ii-authSplit__logo"
              src="/assets/orgas/intelligent-intern/logo.svg"
              alt="logo"
            />
          </div>
        </div>

        <div class="ii-authSplit__form">
          <form class="ii-form" novalidate @submit.prevent="handleSubmit">
            <div>
              <label class="ii-fieldLabel" for="email">Email</label>
              <input
                id="email"
                v-model.trim="email"
                class="ii-input ii-input-email"
                type="email"
                inputmode="email"
                autocomplete="username"
                aria-label="Email"
                placeholder="name@company.com"
              />
              <div class="ii-fieldError" :hidden="!emailError">{{ emailError }}</div>
            </div>

            <div>
              <label class="ii-fieldLabel" for="password">Password</label>
              <input
                id="password"
                v-model="password"
                class="ii-input ii-input-password"
                type="password"
                autocomplete="current-password"
                aria-label="Password"
                placeholder="••••••••••"
              />
              <div class="ii-fieldError" :hidden="!passwordError">{{ passwordError }}</div>
            </div>

            <button class="ii-btn ii-btn--primary ii-authBtn ii-btn--block" type="submit">
              <span class="ii-btn__label">Sign in</span>
            </button>

            <div class="ii-error" :hidden="!authError">{{ authError }}</div>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { defaultRouteForRole, loginWithPassword } from '../stores/session';

const router = useRouter();
const route = useRoute();

const email = ref('');
const password = ref('');
const emailError = ref('');
const passwordError = ref('');
const authError = ref('');
const submitting = ref(false);

async function handleSubmit() {
  if (submitting.value) return;
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

  submitting.value = true;
  try {
    const result = await loginWithPassword(emailValue, passwordValue);
    if (!result.ok) {
      authError.value = result.message || 'Invalid email or password.';
      return;
    }

    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '';
    router.replace(redirect || defaultRouteForRole(result.role));
  } finally {
    submitting.value = false;
  }
}
</script>
