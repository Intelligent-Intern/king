import { defineConfig } from '@playwright/test';

const testPort = Number.parseInt(process.env.PLAYWRIGHT_FRONTEND_PORT || '4174', 10);
const backendOrigin = process.env.VITE_VIDEOCHAT_BACKEND_ORIGIN || 'http://127.0.0.1:18080';
const backendWebSocketOrigin = process.env.VITE_VIDEOCHAT_WS_ORIGIN || '';
const backendWebSocketPort = process.env.VITE_VIDEOCHAT_WS_PORT || process.env.VIDEOCHAT_V1_BACKEND_WS_PORT || '18081';
const backendSfuOrigin = process.env.VITE_VIDEOCHAT_SFU_ORIGIN || '';
const backendSfuPort = process.env.VITE_VIDEOCHAT_SFU_PORT || process.env.VIDEOCHAT_V1_BACKEND_SFU_PORT || '18082';
const allowInsecureWebSockets = process.env.VITE_VIDEOCHAT_ALLOW_INSECURE_WS || process.env.VIDEOCHAT_V1_ALLOW_INSECURE_WS || '';
const chromiumExecutablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || '';
const productionBrowserSmoke = process.env.PLAYWRIGHT_PRODUCTION_BROWSER_SMOKE === '1'
  || process.env.VIDEOCHAT_PRODUCTION_BROWSER_SMOKE === '1';

function withoutTrailingSlash(value) {
  return String(value || '').trim().replace(/\/+$/, '');
}

function productionDomain() {
  return String(process.env.VIDEOCHAT_DEPLOY_DOMAIN || process.env.VIDEOCHAT_V1_PUBLIC_HOST || 'kingrt.com').trim();
}

function productionOrigin({ originEnv, domainEnv, protocol, subdomain }) {
  const explicitOrigin = withoutTrailingSlash(process.env[originEnv] || '');
  if (explicitOrigin !== '') return explicitOrigin;
  const domain = String(process.env[domainEnv] || `${subdomain}.${productionDomain()}`).trim();
  return `${protocol}://${domain}`;
}

function assertNonLoopbackProductionOrigin(label, origin, expectedProtocols) {
  let parsed;
  try {
    parsed = new URL(origin);
  } catch {
    throw new Error(`[playwright-production-smoke] ${label} must be an absolute production origin: ${origin}`);
  }
  const protocols = Array.isArray(expectedProtocols) ? expectedProtocols : [expectedProtocols];
  if (!protocols.includes(parsed.protocol)) {
    throw new Error(`[playwright-production-smoke] ${label} must use ${protocols.join(' or ')}: ${origin}`);
  }
  const hostname = parsed.hostname.toLowerCase().replace(/^\[(.*)\]$/, '$1');
  if (hostname === 'localhost'
    || hostname === '127.0.0.1'
    || hostname === '0.0.0.0'
    || hostname === '::1'
    || hostname.endsWith('.localhost')) {
    throw new Error(`[playwright-production-smoke] ${label} must not use a loopback origin in production smoke: ${origin}`);
  }
}

function configureProductionOrigins() {
  const baseURL = withoutTrailingSlash(
    process.env.PLAYWRIGHT_PRODUCTION_BASE_URL
      || process.env.VIDEOCHAT_ONLINE_BASE_URL
      || process.env.VIDEOCHAT_DEPLOY_APP_ORIGIN
      || 'https://app.kingrt.com',
  );
  const origins = {
    baseURL,
    backendOrigin: productionOrigin({
      originEnv: 'VITE_VIDEOCHAT_BACKEND_ORIGIN',
      domainEnv: 'VIDEOCHAT_DEPLOY_API_DOMAIN',
      protocol: 'https',
      subdomain: 'api',
    }),
    sfuOrigin: productionOrigin({
      originEnv: 'VITE_VIDEOCHAT_SFU_ORIGIN',
      domainEnv: 'VIDEOCHAT_DEPLOY_SFU_DOMAIN',
      protocol: 'wss',
      subdomain: 'sfu',
    }),
    wsOrigin: productionOrigin({
      originEnv: 'VITE_VIDEOCHAT_WS_ORIGIN',
      domainEnv: 'VIDEOCHAT_DEPLOY_WS_DOMAIN',
      protocol: 'wss',
      subdomain: 'ws',
    }),
  };
  assertNonLoopbackProductionOrigin('baseURL', origins.baseURL, 'https:');
  assertNonLoopbackProductionOrigin('backendOrigin', origins.backendOrigin, 'https:');
  assertNonLoopbackProductionOrigin('sfuOrigin', origins.sfuOrigin, 'wss:');
  assertNonLoopbackProductionOrigin('wsOrigin', origins.wsOrigin, 'wss:');
  process.env.PLAYWRIGHT_PRODUCTION_BASE_URL ||= origins.baseURL;
  process.env.VIDEOCHAT_ONLINE_BASE_URL ||= origins.baseURL;
  process.env.VITE_VIDEOCHAT_BACKEND_ORIGIN ||= origins.backendOrigin;
  process.env.VITE_VIDEOCHAT_SFU_ORIGIN ||= origins.sfuOrigin;
  process.env.VITE_VIDEOCHAT_WS_ORIGIN ||= origins.wsOrigin;
  process.env.VITE_VIDEOCHAT_ALLOW_INSECURE_WS ||= '0';
  return origins;
}

const productionOrigins = productionBrowserSmoke ? configureProductionOrigins() : null;

const localUse = {
  baseURL: `http://127.0.0.1:${testPort}`,
  headless: true,
  screenshot: 'only-on-failure',
  trace: 'on-first-retry',
  launchOptions: chromiumExecutablePath !== '' ? {
    executablePath: chromiumExecutablePath,
  } : undefined,
};

const productionUse = {
  baseURL: productionOrigins?.baseURL || 'https://app.kingrt.com',
  headless: true,
  permissions: ['camera', 'microphone'],
  screenshot: 'only-on-failure',
  trace: 'retain-on-failure',
  video: process.env.PLAYWRIGHT_PRODUCTION_BROWSER_VIDEO === '1' ? 'on' : 'retain-on-failure',
};

const productionProjects = [
  {
    name: 'production-chromium',
    use: {
      browserName: 'chromium',
      launchOptions: {
        args: [
          '--autoplay-policy=no-user-gesture-required',
          '--use-fake-device-for-media-stream',
          '--use-fake-ui-for-media-stream',
        ],
        ...(chromiumExecutablePath !== '' ? { executablePath: chromiumExecutablePath } : {}),
      },
    },
  },
  {
    name: 'production-firefox',
    use: {
      browserName: 'firefox',
      launchOptions: {
        firefoxUserPrefs: {
          'media.autoplay.default': 0,
          'media.navigator.permission.disabled': true,
          'media.navigator.streams.fake': true,
        },
      },
    },
  },
];

const localWebServer = {
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
};

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
  use: productionBrowserSmoke ? productionUse : localUse,
  ...(productionBrowserSmoke
    ? { projects: productionProjects }
    : { webServer: localWebServer }),
});
