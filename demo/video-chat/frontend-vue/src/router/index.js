import { createRouter, createWebHistory } from 'vue-router';
import {
  defaultRouteForRole,
  ensureSessionRecovery,
  isAuthenticated,
  sessionState,
} from '../stores/session';

const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('../views/LoginView.vue'),
    meta: { public: true },
  },
  {
    path: '/',
    component: () => import('../layouts/WorkspaceShell.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        redirect: () => {
          if (!isAuthenticated()) return '/login';
          return defaultRouteForRole(sessionState.role);
        },
      },
      {
        path: 'admin/overview',
        name: 'admin-overview',
        component: () => import('../views/AdminOverviewView.vue'),
        meta: { requiresAuth: true, roles: ['admin'] },
      },
      {
        path: 'admin/users',
        name: 'admin-users',
        component: () => import('../views/AdminUsersView.vue'),
        meta: { requiresAuth: true, roles: ['admin'] },
      },
      {
        path: 'admin/calls',
        name: 'admin-calls',
        component: () => import('../views/AdminCallsView.vue'),
        meta: { requiresAuth: true, roles: ['admin'] },
      },
      {
        path: 'user/dashboard',
        name: 'user-dashboard',
        component: () => import('../views/UserDashboardView.vue'),
        meta: { requiresAuth: true, roles: ['moderator', 'user'] },
      },
      {
        path: 'workspace/call/:roomId?',
        name: 'call-workspace',
        component: () => import('../views/CallWorkspaceView.vue'),
        meta: { requiresAuth: true, roles: ['admin', 'moderator', 'user'] },
      },
    ],
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: () => {
      if (!isAuthenticated()) return '/login';
      return defaultRouteForRole(sessionState.role);
    },
  },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
});

router.beforeEach(async (to) => {
  await ensureSessionRecovery();

  const loggedIn = isAuthenticated();
  const requiresAuth = to.matched.some((record) => record.meta?.requiresAuth);

  if (to.path === '/login' && loggedIn) {
    return defaultRouteForRole(sessionState.role);
  }

  if (requiresAuth && !loggedIn) {
    return {
      path: '/login',
      query: to.fullPath !== '/' ? { redirect: to.fullPath } : undefined,
    };
  }

  const allowedRoles = to.matched
    .flatMap((record) => (Array.isArray(record.meta?.roles) ? record.meta.roles : []));

  if (allowedRoles.length > 0 && !allowedRoles.includes(sessionState.role)) {
    return loggedIn ? defaultRouteForRole(sessionState.role) : '/login';
  }

  return true;
});

export default router;
