import { test, expect } from '@playwright/test';

test('login uses backend contract and returns a session token', async ({ page }) => {
  await page.goto('/login');
  await expect(page.getByLabel('Email')).toBeVisible();
  await expect(page.getByLabel('Password')).toBeVisible();

  const loginResponsePromise = page.waitForResponse((response) => {
    return response.url().includes('/api/auth/login') && response.request().method() === 'POST';
  });

  await page.getByLabel('Email').fill('admin@intelligent-intern.com');
  await page.getByLabel('Password').fill('admin123');
  await page.getByRole('button', { name: 'Sign in' }).click();

  const loginResponse = await loginResponsePromise;
  expect(loginResponse.status()).toBe(200);
  expect(loginResponse.request().method()).toBe('POST');
  expect(loginResponse.url()).toMatch(/\/api\/auth\/login$/);

  const requestBody = JSON.parse(loginResponse.request().postData() || '{}');
  expect(requestBody).toEqual({
    email: 'admin@intelligent-intern.com',
    password: 'admin123',
  });

  const payload = await loginResponse.json();
  expect(payload?.status).toBe('ok');
  expect(typeof payload?.session?.token).toBe('string');
  expect(payload.session.token.length).toBeGreaterThan(0);
  expect(payload?.user?.role).toBe('admin');

  await expect(page).toHaveURL(/\/admin\/overview$/);
});
