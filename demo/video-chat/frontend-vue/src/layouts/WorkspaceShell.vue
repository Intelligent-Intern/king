<template>
  <main class="app">
    <div class="shell no-right-sidebar" :class="{ 'left-collapsed': leftSidebarCollapsed }">
      <aside class="sidebar sidebar-left" :class="{ collapsed: leftSidebarCollapsed }">
        <div class="sidebar-content left">
          <div class="brand-strip">
            <img src="/assets/orgas/intelligent-intern/logo.svg" alt="Intelligent Intern" />
            <button
              class="sidebar-toggle-btn"
              type="button"
              title="Hide sidebar"
              aria-label="Hide sidebar"
              @click="leftSidebarCollapsed = true"
            >
              <img class="arrow-icon-image" src="/assets/orgas/intelligent-intern/icons/backward.png" alt="" />
            </button>
          </div>

          <nav class="nav" aria-label="Main navigation">
            <RouterLink
              v-for="item in navItems"
              :key="item.to"
              :to="item.to"
              class="nav-link"
              :class="{ active: route.path === item.to }"
            >
              <img :src="item.icon" alt="" />
              <span>{{ item.label }}</span>
            </RouterLink>
          </nav>

          <section class="sidebar-profile avatar-only">
            <img
              class="sidebar-avatar-image"
              src="/assets/orgas/intelligent-intern/avatar-placeholder.svg"
              alt="Profile avatar"
            />
          </section>

          <div class="logout-wrap">
            <button class="btn full" type="button" @click="handleSignOut">Log out</button>
          </div>
        </div>
      </aside>

      <section class="main">
        <div class="workspace">
          <section class="section">
            <div class="section-head">
              <div class="section-head-left">
                <button
                  class="show-sidebar-overlay show-sidebar-inline show-left-sidebar-overlay"
                  type="button"
                  title="Show sidebar"
                  aria-label="Show sidebar"
                  @click="leftSidebarCollapsed = false"
                >
                  <img class="arrow-icon-image" src="/assets/orgas/intelligent-intern/icons/forward.png" alt="" />
                </button>
                <div>
                  <h1 class="title">{{ pageTitle }}</h1>
                  <p class="subtitle">{{ runtimeSummary }}</p>
                </div>
              </div>
              <div class="actions">
              </div>
            </div>
          </section>

          <section class="panel-grid">
            <RouterView />
          </section>
        </div>
      </section>
    </div>
  </main>
</template>

<script setup>
import { computed, ref } from 'vue';
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router';
import { defaultRouteForRole, logoutSession, sessionState } from '../stores/session';
import { backendRuntimeState } from '../stores/runtime';

const router = useRouter();
const route = useRoute();
const leftSidebarCollapsed = ref(false);

const navItems = computed(() => {
  if (sessionState.role === 'admin') {
    return [
      { to: '/admin/overview', label: 'Overview', icon: '/assets/orgas/intelligent-intern/icons/users.png' },
      { to: '/admin/users', label: 'User Management', icon: '/assets/orgas/intelligent-intern/icons/user.png' },
      { to: '/admin/calls', label: 'Video Calls', icon: '/assets/orgas/intelligent-intern/icons/lobby.png' },
      { to: '/workspace/call/lobby', label: 'Call Workspace', icon: '/assets/orgas/intelligent-intern/icons/chat.png' },
    ];
  }

  return [
    { to: '/user/dashboard', label: 'Dashboard', icon: '/assets/orgas/intelligent-intern/icons/users.png' },
    { to: '/workspace/call/lobby', label: 'Call Workspace', icon: '/assets/orgas/intelligent-intern/icons/lobby.png' },
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

async function handleSignOut() {
  await logoutSession();
  router.replace('/login');
}

if (!route.path || route.path === '/') {
  router.replace(defaultRouteForRole(sessionState.role));
}
</script>
