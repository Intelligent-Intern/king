import { defineConfig } from '@playwright/test';

const testPort = Number.parseInt(process.env.PLAYWRIGHT_FRONTEND_PORT || '4174', 10);
const backendOrigin = process.env.VITE_VIDEOCHAT_BACKEND_ORIGIN || 'http://127.0.0.1:18080';

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 30_000,
  expect: {
    timeout: 5_000,
  },
  fullyParallel: true,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 2 : undefined,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: `http://127.0.0.1:${testPort}`,
    headless: true,
    screenshot: 'only-on-failure',
    trace: 'on-first-retry',
  },
  webServer: {
    command: `npm run dev -- --host 127.0.0.1 --port ${testPort} --strictPort`,
    port: testPort,
    timeout: 120_000,
    reuseExistingServer: !process.env.CI,
    env: {
      ...process.env,
      VITE_VIDEOCHAT_BACKEND_ORIGIN: backendOrigin,
    },
  },
});
