<template>
  <div class="shell-root">
    <aside class="shell-sidebar">
      <h1>King Video</h1>
      <p class="shell-user">{{ sessionState.displayName }}</p>
      <p class="shell-role">{{ sessionState.role }}</p>

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

function handleSignOut() {
  signOut();
  router.replace('/login');
}

if (!route.path || route.path === '/') {
  router.replace(defaultRouteForRole(sessionState.role));
}
</script>
