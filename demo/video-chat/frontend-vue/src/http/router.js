import { createRouter, createWebHistory } from 'vue-router';
import {
  callListRouteForRole,
  defaultRouteForRole,
  ensureSessionRecovery,
  isAuthenticated,
  isGuestSession,
  sessionState,
} from '../domain/auth/session';
import { workspaceModuleRouteRecords } from '../modules/index.js';
import {
  DEFAULT_I18N_NAMESPACES,
  ensureI18nResources,
} from '../modules/localization/i18nRuntime.js';
import { applyPublicRouteLocale } from '../modules/localization/publicLocale.js';
import {
  routeAllowsRole,
  routeAllowsSessionAccess,
} from './routeAccess.js';

const routes = [
  {
    path: '/join/:accessId',
    name: 'call-access-join',
    component: () => import('../domain/calls/access/JoinView.vue'),
    meta: { public: true, i18nNamespaces: ['public'] },
  },
  {
    path: '/book/:calendarId',
    name: 'appointment-booking',
    component: () => import('../domain/calls/appointment/AppointmentBookingView.vue'),
    meta: { public: true, i18nNamespaces: ['public'] },
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
        path: 'admin/administration',
        redirect: '/admin/administration/marketplace',
      },
      {
        path: 'admin/governance',
        redirect: '/admin/governance/users',
      },
      {
        path: 'admin/users',
        redirect: '/admin/governance/users',
      },
      ...workspaceModuleRouteRecords,
      {
        path: 'admin/marketplace',
        redirect: '/admin/administration/marketplace',
      },
      {
        path: 'admin/calls',
        name: 'admin-calls',
        component: () => import('../domain/calls/admin/CallsView.vue'),
        meta: { requiresAuth: true, roles: ['admin'] },
      },
      {
        path: 'admin/tenancy',
        redirect: '/admin/governance/organizations',
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

export { routeAllowsRole, routeAllowsSessionAccess };

function routeI18nNamespaces(route) {
  const namespaces = [...DEFAULT_I18N_NAMESPACES];
  for (const record of route.matched || []) {
    const routeNamespaces = Array.isArray(record.meta?.i18nNamespaces) ? record.meta.i18nNamespaces : [];
    namespaces.push(...routeNamespaces);
  }
  return [...new Set(namespaces)].sort();
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

  return routeAllowsSessionAccess(resolved, { ...sessionState, role }) ? resolved.fullPath : fallback;
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

  if (loggedIn && !routeAllowsSessionAccess(to, sessionState)) {
    return defaultRouteForRole(sessionState.role);
  }

  if (!requiresAuth) {
    const publicLocale = applyPublicRouteLocale(to);
    await ensureI18nResources({
      locale: publicLocale.locale,
      namespaces: routeI18nNamespaces(to),
      public: true,
    });
  }

  if (requiresAuth && loggedIn) {
    await ensureI18nResources({
      locale: sessionState.locale,
      namespaces: routeI18nNamespaces(to),
    });
  }

  return true;
});

export default router;
