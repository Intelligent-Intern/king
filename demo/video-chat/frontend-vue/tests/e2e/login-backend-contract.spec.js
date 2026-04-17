import { test, expect } from '@playwright/test';

test('login uses backend contract and returns a session token', async ({ page }) => {
  await page.goto('/login');
  await expect(page.getByLabel('Email')).toBeVisible();
  await expect(page.getByLabel('Password')).toBeVisible();

  const loginResponsePromise = page.waitForResponse((response) => {
    return response.url().includes('/api/auth/login') && response.request().method() === 'GET';
  });

  await page.getByLabel('Email').fill('admin@intelligent-intern.com');
  await page.getByLabel('Password').fill('admin123');
  await page.getByRole('button', { name: 'Sign in' }).click();

  const loginResponse = await loginResponsePromise;
  expect(loginResponse.status()).toBe(200);
  expect(loginResponse.request().method()).toBe('GET');
  expect((loginResponse.request().postData() ?? '').length).toBe(0);
  expect(loginResponse.url()).toContain('/api/auth/login?');
  expect(loginResponse.url()).toContain('email=admin%40intelligent-intern.com');

  const payload = await loginResponse.json();
  expect(payload?.status).toBe('ok');
  expect(typeof payload?.session?.token).toBe('string');
  expect(payload.session.token.length).toBeGreaterThan(0);
  expect(payload?.user?.role).toBe('admin');

  await expect(page).toHaveURL(/\/admin\/overview$/);
});
