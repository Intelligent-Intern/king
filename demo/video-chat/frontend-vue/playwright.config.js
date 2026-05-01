import { defineConfig } from '@playwright/test';

const testPort = Number.parseInt(process.env.PLAYWRIGHT_FRONTEND_PORT || '4174', 10);
const backendOrigin = process.env.VITE_VIDEOCHAT_BACKEND_ORIGIN || 'http://127.0.0.1:18080';
const backendWebSocketOrigin = process.env.VITE_VIDEOCHAT_WS_ORIGIN || '';
const backendWebSocketPort = process.env.VITE_VIDEOCHAT_WS_PORT || process.env.VIDEOCHAT_V1_BACKEND_WS_PORT || '18081';
const backendSfuOrigin = process.env.VITE_VIDEOCHAT_SFU_ORIGIN || '';
const backendSfuPort = process.env.VITE_VIDEOCHAT_SFU_PORT || process.env.VIDEOCHAT_V1_BACKEND_SFU_PORT || '18082';
const allowInsecureWebSockets = process.env.VITE_VIDEOCHAT_ALLOW_INSECURE_WS || process.env.VIDEOCHAT_V1_ALLOW_INSECURE_WS || '';
const chromiumExecutablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || '';

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
    launchOptions: chromiumExecutablePath !== '' ? {
      executablePath: chromiumExecutablePath,
    } : undefined,
  },
  webServer: {
    command: `npm run dev -- --host 127.0.0.1 --port ${testPort} --strictPort`,
    port: testPort,
    timeout: 120_000,
    reuseExistingServer: !process.env.CI,
    env: {
      ...process.env,
      VITE_VIDEOCHAT_BACKEND_ORIGIN: backendOrigin,
      VITE_VIDEOCHAT_WS_ORIGIN: backendWebSocketOrigin,
      VITE_VIDEOCHAT_WS_PORT: backendWebSocketPort,
      VITE_VIDEOCHAT_SFU_ORIGIN: backendSfuOrigin,
      VITE_VIDEOCHAT_SFU_PORT: backendSfuPort,
      VITE_VIDEOCHAT_ALLOW_INSECURE_WS: allowInsecureWebSockets,
    },
  },
});
