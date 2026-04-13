import { test, expect } from '@playwright/test';

async function signIn(page, { displayName, email, role }) {
  await page.goto('/login');
  await expect(page.getByRole('heading', { name: 'Video Control Workspace' })).toBeVisible();

  await page.getByLabel('Display name').fill(displayName);
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Role').selectOption(role);
  await page.getByRole('button', { name: 'Sign in' }).click();
}

test('route guard redirects unauthenticated users to /login', async ({ page }) => {
  await page.goto('/admin/overview');
  await expect(page).toHaveURL(/\/login(\?.*)?$/);
  await expect(page.getByRole('heading', { name: 'Video Control Workspace' })).toBeVisible();
});

test('admin can click through all implemented admin routes and logout', async ({ page }) => {
  await signIn(page, {
    displayName: 'Platform Admin',
    email: 'admin@intelligent-intern.com',
    role: 'admin',
  });

  await expect(page).toHaveURL(/\/admin\/overview$/);
  await expect(page.locator('.view-card h3', { hasText: 'Admin Overview' })).toBeVisible();

  await page.getByRole('link', { name: 'User Management' }).click();
  await expect(page).toHaveURL(/\/admin\/users$/);
  await expect(page.locator('.view-card h3', { hasText: 'Admin User Management' })).toBeVisible();

  await page.getByRole('link', { name: 'Video Calls' }).click();
  await expect(page).toHaveURL(/\/admin\/calls$/);
  await expect(page.locator('.view-card h3', { hasText: 'Admin Video Calls' })).toBeVisible();

  await page.getByRole('link', { name: 'Call Workspace' }).click();
  await expect(page).toHaveURL(/\/workspace\/call\/lobby$/);
  await expect(page.locator('.view-card h3', { hasText: 'Call Workspace' })).toBeVisible();
  await expect(page.getByText('Scaffold route resolved for room:')).toBeVisible();
  await expect(page.locator('.view-card strong', { hasText: 'lobby' })).toBeVisible();

  await page.getByRole('button', { name: 'Log out' }).click();
  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByRole('heading', { name: 'Video Control Workspace' })).toBeVisible();
});

test('user role is constrained by RBAC and can still open call workspace', async ({ page }) => {
  await signIn(page, {
    displayName: 'Call User',
    email: 'user@intelligent-intern.com',
    role: 'user',
  });

  await expect(page).toHaveURL(/\/user\/dashboard$/);
  await expect(page.locator('.view-card h3', { hasText: 'User Dashboard' })).toBeVisible();

  await page.goto('/admin/users');
  await expect(page).toHaveURL(/\/user\/dashboard$/);
  await expect(page.locator('.view-card h3', { hasText: 'User Dashboard' })).toBeVisible();

  await page.getByRole('link', { name: 'Call Workspace' }).click();
  await expect(page).toHaveURL(/\/workspace\/call\/lobby$/);
  await expect(page.locator('.view-card h3', { hasText: 'Call Workspace' })).toBeVisible();

  await page.goto('/workspace/call/review-room');
  await expect(page).toHaveURL(/\/workspace\/call\/review-room$/);
  await expect(page.getByText('review-room')).toBeVisible();
});

test('session state survives hard reload and /login redirects for authenticated user', async ({ page }) => {
  await signIn(page, {
    displayName: 'Session User',
    email: 'session@intelligent-intern.com',
    role: 'user',
  });

  await expect(page).toHaveURL(/\/user\/dashboard$/);
  await page.reload();
  await expect(page).toHaveURL(/\/user\/dashboard$/);
  await expect(page.getByText('Session User')).toBeVisible();

  await page.goto('/login');
  await expect(page).toHaveURL(/\/user\/dashboard$/);
});
