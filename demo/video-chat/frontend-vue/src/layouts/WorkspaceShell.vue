<template>
  <div class="shell-root">
    <aside class="shell-sidebar">
      <div class="shell-brand-strip">
        <img src="/assets/orgas/intelligent-intern/logo.svg" alt="Intelligent Intern" />
      </div>
      <p class="shell-user">{{ sessionState.displayName }}</p>
      <p class="shell-email" v-if="sessionState.email">{{ sessionState.email }}</p>

      <nav class="shell-nav">
        <RouterLink v-for="item in navItems" :key="item.to" :to="item.to" class="shell-link">
          {{ item.label }}
        </RouterLink>
      </nav>

      <button class="shell-logout" type="button" @click="handleSignOut">Log out</button>
    </aside>

    <main class="shell-main">
      <header class="shell-header">
        <h2>{{ pageTitle }}</h2>
        <p class="shell-runtime" :class="`shell-runtime-${backendRuntimeState.status}`">
          {{ runtimeSummary }}
        </p>
      </header>
      <section class="shell-content">
        <RouterView />
      </section>
    </main>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router';
import { defaultRouteForRole, sessionState, signOut } from '../stores/session';
import { backendRuntimeState } from '../stores/runtime';

const router = useRouter();
const route = useRoute();

const navItems = computed(() => {
  if (sessionState.role === 'admin') {
    return [
      { to: '/admin/overview', label: 'Overview' },
      { to: '/admin/users', label: 'User Management' },
      { to: '/admin/calls', label: 'Video Calls' },
      { to: '/workspace/call/lobby', label: 'Call Workspace' },
    ];
  }

  return [
    { to: '/user/dashboard', label: 'Dashboard' },
    { to: '/workspace/call/lobby', label: 'Call Workspace' },
  ];
});

const pageTitle = computed(() => {
  const mapping = {
    '/admin/overview': 'Admin Overview',
    '/admin/users': 'User Management',
    '/admin/calls': 'Video Calls',
    '/user/dashboard': 'User Dashboard',
  };

  if (route.path.startsWith('/workspace/call')) return 'Call Workspace';
  return mapping[route.path] || 'Workspace';
});

const runtimeSummary = computed(() => {
  if (backendRuntimeState.status === 'probing') {
    return `Backend runtime preflight (${backendRuntimeState.backendOrigin}) …`;
  }

  if (backendRuntimeState.status === 'error') {
    return `Backend runtime preflight failed: ${backendRuntimeState.error}`;
  }

  if (backendRuntimeState.status === 'ready' && backendRuntimeState.data) {
    const appVersion = backendRuntimeState.data?.app?.version || 'n/a';
    const kingVersion = backendRuntimeState.data?.runtime?.king_version || 'n/a';
    const moduleStatus = backendRuntimeState.data?.runtime?.health?.module_status || 'unknown';
    const systemStatus = backendRuntimeState.data?.runtime?.health?.system_status || 'unknown';
    return `Backend ${appVersion} · King ${kingVersion} · module ${moduleStatus} · system ${systemStatus}`;
  }

  return `Backend runtime preflight pending (${backendRuntimeState.backendOrigin})`;
});

function handleSignOut() {
  signOut();
  router.replace('/login');
}

if (!route.path || route.path === '/') {
  router.replace(defaultRouteForRole(sessionState.role));
}
</script>
