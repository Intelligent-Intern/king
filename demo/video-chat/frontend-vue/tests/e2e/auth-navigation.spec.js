import { test, expect } from '@playwright/test';

async function signIn(page, { email, password }) {
  await page.goto('/login');
  await expect(page.getByLabel('Email')).toBeVisible();
  await expect(page.getByLabel('Password')).toBeVisible();

  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
}

test('route guard redirects unauthenticated users to /login', async ({ page }) => {
  await page.goto('/admin/overview');
  await expect(page).toHaveURL(/\/login(\?.*)?$/);
  await expect(page.getByLabel('Email')).toBeVisible();
  await expect(page.getByLabel('Password')).toBeVisible();
});

test('admin can click through all implemented admin routes and logout', async ({ page }) => {
  await signIn(page, {
    email: 'admin@intelligent-intern.com',
    password: 'admin123',
  });

  await expect(page).toHaveURL(/\/admin\/overview$/);
  await expect(page.locator('.view-card h3', { hasText: 'Admin Overview' })).toBeVisible();

  await page.getByRole('link', { name: 'User Management' }).click();
  await expect(page).toHaveURL(/\/admin\/users$/);
  await expect(page.locator('.view-card h3', { hasText: 'Admin User Management' })).toBeVisible();

  await page.getByRole('link', { name: 'Video Calls' }).click();
  await expect(page).toHaveURL(/\/admin\/calls$/);
  await expect(page.locator('.view-card h3', { hasText: 'Admin Video Calls' })).toBeVisible();

  await page.goto('/workspace/call/lobby');
  await expect(page).toHaveURL(/\/workspace\/call\/lobby$/);
  await expect(page.locator('.workspace-call-head h3', { hasText: 'Call Workspace' })).toBeVisible();
  await expect(page.locator('.workspace-call-head')).toContainText('Active room');
  await expect(page.locator('.workspace-call-head')).toContainText('lobby');

  await page.getByRole('button', { name: 'Log out' }).click();
  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByLabel('Email')).toBeVisible();
});

test('user role is constrained by RBAC and can still open call workspace', async ({ page }) => {
  await signIn(page, {
    email: 'user@intelligent-intern.com',
    password: 'user123',
  });

  await expect(page).toHaveURL(/\/user\/dashboard$/);
  await expect(page.locator('.view-card h3', { hasText: 'User Dashboard' })).toBeVisible();

  await page.goto('/admin/users');
  await expect(page).toHaveURL(/\/user\/dashboard$/);
  await expect(page.locator('.view-card h3', { hasText: 'User Dashboard' })).toBeVisible();

  await page.goto('/workspace/call/lobby');
  await expect(page).toHaveURL(/\/workspace\/call\/lobby$/);
  await expect(page.locator('.workspace-call-head h3', { hasText: 'Call Workspace' })).toBeVisible();

  await page.goto('/workspace/call/review-room');
  await expect(page).toHaveURL(/\/workspace\/call\/review-room$/);
  await expect(page.locator('.workspace-call-head')).toContainText('review-room');
});

test('session state survives hard reload and /login redirects for authenticated user', async ({ page }) => {
  await signIn(page, {
    email: 'user@intelligent-intern.com',
    password: 'user123',
  });

  await expect(page).toHaveURL(/\/user\/dashboard$/);
  await page.reload();
  await expect(page).toHaveURL(/\/user\/dashboard$/);
  await expect(page.getByText('User Dashboard')).toBeVisible();

  await page.goto('/login');
  await expect(page).toHaveURL(/\/user\/dashboard$/);
});
