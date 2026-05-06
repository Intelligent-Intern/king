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

test('call goodbye is not shown without an authenticated guest session', async ({ page }) => {
  await page.goto('/call-goodbye');
  await expect(page).toHaveURL(/\/login\?redirect=(%2F|\/)call-goodbye$/);
  await expect(page.getByLabel('Email')).toBeVisible();
  await expect(page.getByText('You have left the video call.')).toHaveCount(0);
});

test('admin can click through all implemented admin routes and logout', async ({ page }) => {
  await signIn(page, {
    email: 'admin@intelligent-intern.com',
    password: 'admin123',
  });

  await expect(page).toHaveURL(/\/admin\/overview$/);
  await expect(page.locator('.view-card h3', { hasText: 'Admin Overview' })).toBeVisible();

  await page.getByRole('link', { name: 'Governance' }).click();
  await expect(page).toHaveURL(/\/admin\/governance\/users$/);
  await expect(page.getByRole('heading', { name: 'Nutzer' })).toBeVisible();

  await page.getByRole('link', { name: 'Gruppen' }).click();
  await expect(page).toHaveURL(/\/admin\/governance\/groups$/);
  await expect(page.getByRole('heading', { name: 'Gruppen' })).toBeVisible();
  await page.getByRole('button', { name: 'Create new' }).click();
  await expect(page.getByRole('dialog', { name: 'Create Gruppe' })).toBeVisible();
  await page.getByRole('button', { name: 'Maximize panel' }).click();
  await expect(page.getByRole('button', { name: 'Restore panel size' })).toBeVisible();
  await page.getByRole('button', { name: 'Close governance panel' }).click();

  await page.getByRole('link', { name: 'Video Calls' }).click();
  await expect(page).toHaveURL(/\/admin\/calls$/);
  await expect(page.locator('.view-card h3', { hasText: 'Admin Video Calls' })).toBeVisible();

  await page.goto('/workspace/call/lobby');
  await expect(page).toHaveURL(/\/workspace\/call\/lobby$/);
  await expect(page.locator('.workspace-call-head h3', { hasText: 'Call Workspace' })).toBeVisible();
  await expect(page.locator('.workspace-call-head')).toContainText('Active room');
  await expect(page.locator('.workspace-call-head')).toContainText('lobby');

  await page.goto('/call-goodbye');
  await expect(page).toHaveURL(/\/admin\/calls$/);
  await expect(page.getByText('You have left the video call.')).toHaveCount(0);

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

  await page.goto('/admin/governance/users');
  await expect(page).toHaveURL(/\/user\/dashboard$/);
  await expect(page.locator('.view-card h3', { hasText: 'User Dashboard' })).toBeVisible();

  await page.goto('/workspace/call/lobby');
  await expect(page).toHaveURL(/\/workspace\/call\/lobby$/);
  await expect(page.locator('.workspace-call-head h3', { hasText: 'Call Workspace' })).toBeVisible();

  await page.goto('/workspace/call/review-room');
  await expect(page).toHaveURL(/\/workspace\/call\/review-room$/);
  await expect(page.locator('.workspace-call-head')).toContainText('review-room');

  await page.goto('/call-goodbye');
  await expect(page).toHaveURL(/\/user\/dashboard$/);
  await expect(page.getByText('You have left the video call.')).toHaveCount(0);
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
