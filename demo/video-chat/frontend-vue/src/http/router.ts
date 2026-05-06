import { createRouter, createWebHistory } from 'vue-router';
import {
  callListRouteForRole,
  defaultRouteForRole,
  ensureSessionRecovery,
  isAuthenticated,
  isGuestSession,
  sessionState,
} from '../domain/auth/session';

const routes = [
  {
    path: '/join/:accessId',
    name: 'call-access-join',
    component: () => import('../domain/calls/access/JoinView.vue'),
    meta: { public: true },
  },
  {
    path: '/book/:ownerId',
    name: 'appointment-booking',
    component: () => import('../domain/calls/appointment/AppointmentBookingView.vue'),
    meta: { public: true },
  },
  {
    path: '/call-goodbye',
    name: 'call-goodbye',
    component: () => import('../domain/calls/access/GoodbyeView.vue'),
    meta: { requiresAuth: true, roles: ['user'] },
  },
  {
    path: '/login',
    name: 'login',
    component: () => import('../domain/auth/LoginView.vue'),
    meta: { public: true },
  },
  {
    path: '/',
    component: () => import('../layouts/WorkspaceShell.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        redirect: () => defaultRouteForRole(sessionState.role),
      },
      {
        path: 'admin/overview',
        name: 'admin-overview',
        component: () => import('../domain/users/overview/OverviewView.vue'),
        meta: { requiresAuth: true, roles: ['admin'] },
      },
      {
        path: 'admin/users',
        name: 'admin-users',
        component: () => import('../domain/users/admin/UsersView.vue'),
        meta: { requiresAuth: true, roles: ['admin'] },
      },
      {
        path: 'admin/marketplace',
        name: 'admin-marketplace',
        component: () => import('../domain/marketplace/AdminMarketplaceView.vue'),
        meta: { requiresAuth: true, roles: ['admin'] },
      },
      {
        path: 'admin/calls',
        name: 'admin-calls',
        component: () => import('../domain/calls/admin/CallsView.vue'),
        meta: { requiresAuth: true, roles: ['admin'] },
      },
      {
        path: 'user/dashboard',
        name: 'user-dashboard',
        component: () => import('../domain/calls/dashboard/UserDashboardView.vue'),
        meta: { requiresAuth: true, roles: ['user'] },
      },
      {
        path: 'workspace/call/:callRef?',
        name: 'call-workspace',
        component: () => import('../domain/realtime/CallWorkspaceView.vue'),
        meta: { requiresAuth: true, roles: ['admin', 'user'] },
      },
    ],
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: () => defaultRouteForRole(sessionState.role),
  },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
});

function allowedRolesForRoute(route) {
  return route.matched.filter((record) => Array.isArray(record.meta?.roles) && record.meta.roles.length > 0);
}

export function routeAllowsRole(route, role) {
  if (!role) return false;

  const roleBoundRecords = allowedRolesForRoute(route);
  if (roleBoundRecords.length === 0) return true;

  return roleBoundRecords.every((record) => record.meta.roles.includes(role));
}

export function resolveAuthorizedRedirect(target, role, routerInstance = router) {
  const fallback = defaultRouteForRole(role);
  const value = String(target || '').trim();
  if (value === '' || !value.startsWith('/') || value.startsWith('//')) {
    return fallback;
  }

  if (value.startsWith('/login')) {
    return fallback;
  }

  const resolved = routerInstance.resolve(value);
  if (!resolved.matched.length) {
    return fallback;
  }

  return routeAllowsRole(resolved, role) ? resolved.fullPath : fallback;
}

router.beforeEach(async (to) => {
  if (sessionState.sessionToken) {
    await ensureSessionRecovery();
  } else if (!sessionState.recovered) {
    await ensureSessionRecovery();
  }

  const loggedIn = isAuthenticated();
  const requiresAuth = to.matched.some((record) => record.meta?.requiresAuth);

  if (to.path === '/login' && loggedIn) {
    return defaultRouteForRole(sessionState.role);
  }

  if (to.name === 'call-goodbye' && loggedIn && !isGuestSession()) {
    return callListRouteForRole(sessionState.role);
  }

  if (requiresAuth && !loggedIn) {
    return {
      path: '/login',
      query: to.fullPath !== '/' ? { redirect: to.fullPath } : undefined,
    };
  }

  if (loggedIn && !routeAllowsRole(to, sessionState.role)) {
    return defaultRouteForRole(sessionState.role);
  }

  return true;
});

export default router;
