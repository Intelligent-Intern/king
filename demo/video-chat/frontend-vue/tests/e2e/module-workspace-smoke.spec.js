import { test, expect } from '@playwright/test';

const adminSessionPayload = {
  status: 'ok',
  result: { state: 'authenticated' },
  session: {
    id: 'module-smoke-session',
    token: 'module-smoke-session',
    expires_at: '2099-01-01T00:00:00Z',
  },
  user: {
    id: 1,
    email: 'admin@intelligent-intern.com',
    display_name: 'Admin',
    role: 'admin',
    status: 'active',
    time_format: '24h',
    date_format: 'dmy_dot',
    theme: 'dark',
    can_edit_themes: true,
    avatar_path: '',
    account_type: 'account',
    is_guest: false,
    tenant: {
      id: 1,
      uuid: 'module-smoke-tenant',
      label: 'Module Smoke',
      role: 'owner',
      permissions: {
        platform_admin: true,
        tenant_admin: true,
        manage_users: true,
        manage_groups: true,
        manage_organizations: true,
        manage_permission_grants: true,
        edit_themes: true,
      },
    },
  },
  tenant: {
    id: 1,
    uuid: 'module-smoke-tenant',
    label: 'Module Smoke',
    role: 'owner',
    permissions: {
      platform_admin: true,
      tenant_admin: true,
      manage_users: true,
      manage_groups: true,
      manage_organizations: true,
      manage_permission_grants: true,
      edit_themes: true,
    },
  },
};

async function seedAuthenticatedAdmin(page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('ii_videocall_v1_session', JSON.stringify({
      sessionId: 'module-smoke-session',
      sessionToken: 'module-smoke-session',
      expiresAt: '2099-01-01T00:00:00Z',
    }));
  });

  await page.route('**/api/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === '/api/auth/session-state') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(adminSessionPayload) });
      return;
    }
    if (url.pathname === '/api/workspace/appearance') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'ok',
          result: {
            sidebar_logo_path: '/assets/orgas/kingrt/logo.svg',
            modal_logo_path: '/assets/orgas/kingrt/logo.svg',
            themes: [{ id: 'dark', label: 'Dark', colors: {}, is_system: true }],
          },
        }),
      });
      return;
    }
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ status: 'ok', result: {} }) });
  });
}

test('module-backed workspace navigation renders without backend fixtures', async ({ page }) => {
  await seedAuthenticatedAdmin(page);

  await page.goto('/admin/governance/modules');
  await expect(page).toHaveURL(/\/admin\/governance\/modules$/);
  await expect(page.getByRole('heading', { name: 'Module' })).toBeVisible();
  await expect(page.getByRole('cell', { name: 'governance', exact: true })).toBeVisible();
  await expect(page.getByRole('row', { name: /governance\.read/ })).toBeVisible();

  await page.getByRole('link', { name: 'Administration' }).click();
  await expect(page).toHaveURL(/\/admin\/administration\/marketplace$/);
  await expect(page.getByRole('heading', { name: 'Marketplace' })).toBeVisible();

  await page.getByRole('link', { name: 'Theme Editor' }).click();
  await expect(page).toHaveURL(/\/admin\/administration\/theme-editor$/);
  await expect(page.getByRole('heading', { name: 'Theme Editor' })).toBeVisible();

  await page.getByLabel('Open settings').click();
  await expect(page.getByRole('button', { name: 'About Me' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Credentials + E-Mail' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Theme', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Localization', exact: true })).toBeVisible();
});
