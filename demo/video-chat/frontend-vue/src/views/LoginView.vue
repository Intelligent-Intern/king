<template>
  <main class="login-screen">
    <section class="login-card">
      <h1>Video Control Workspace</h1>
      <p>Sign in to continue.</p>

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
        <button type="submit">Sign in</button>
      </form>
    </section>
  </main>
</template>

<script setup>
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { defaultRouteForRole, signInAs } from '../stores/session';

const router = useRouter();
const route = useRoute();

const displayName = ref('');
const email = ref('');
const role = ref('admin');
const error = ref('');

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
