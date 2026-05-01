import { test, expect } from '@playwright/test';

const backendOrigin = process.env.VITE_VIDEOCHAT_BACKEND_ORIGIN || 'http://127.0.0.1:18080';
const sessionStorageKey = 'ii_videocall_v1_session';

function corsHeaders() {
  return {
    'access-control-allow-origin': '*',
    'access-control-allow-credentials': 'true',
    'access-control-allow-headers': 'content-type, authorization, x-session-id',
    'access-control-allow-methods': 'GET, POST, PATCH, DELETE, OPTIONS',
    'access-control-max-age': '86400',
  };
}

async function seedAdminSession(page) {
  await page.addInitScript(
    ({ key }) => {
      localStorage.setItem(key, JSON.stringify({
        sessionId: 'sess_admin_ops',
        sessionToken: 'sess_admin_ops',
        expiresAt: '2030-01-01T00:00:00.000Z',
      }));
    },
    { key: sessionStorageKey },
  );
}

async function installOperationsRoutes(page, counters) {
  await page.route(`${backendOrigin}/api/auth/session-state`, async (route) => {
    await route.fulfill({
      status: 200,
      headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
      json: {
        status: 'ok',
        result: { state: 'authenticated' },
        session: {
          id: 'sess_admin_ops',
          token: 'sess_admin_ops',
          expires_at: '2030-01-01T00:00:00.000Z',
        },
        user: {
          id: 1,
          email: 'admin@intelligent-intern.com',
          display_name: 'Platform Admin',
          role: 'admin',
          status: 'active',
          account_type: 'account',
          is_guest: false,
          time_format: '24h',
          date_format: 'dmy_dot',
          theme: 'dark',
        },
      },
    });
  });

  await page.route(`${backendOrigin}/api/admin/infrastructure`, async (route) => {
    await route.fulfill({
      status: 200,
      headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
      json: {
        status: 'ok',
        deployment: {
          id: 'fixture-deploy',
          name: 'Fixture Deploy',
          public_domain: 'ops.example.test',
          inventory_mode: 'fixture',
        },
        providers: [],
        nodes: [],
        services: [],
        telemetry: { open_telemetry: { enabled: false } },
        scaling: { modes: [], strategy: 'manual' },
        time: '2026-04-22T10:00:00.000Z',
      },
    });
  });

  await page.route(`${backendOrigin}/api/admin/video-operations`, async (route) => {
    counters.videoOperations += 1;
    await route.fulfill({
      status: 200,
      headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
      json: {
        status: 'ok',
        metrics: {
          live_calls: 1,
          concurrent_participants: 2,
        },
        running_calls: [
          {
            id: 'call-live-backend-001',
            room_id: 'room-live-backend-001',
            title: 'Live Backend Call',
            status: 'live',
            call_status: 'active',
            host: 'Backend Host',
            owner: {
              user_id: 1,
              email: 'admin@intelligent-intern.com',
              display_name: 'Backend Host',
            },
            live_participants: {
              total: 2,
              internal: 2,
              external: 0,
            },
            assigned_participants: {
              total: 4,
              internal: 3,
              external: 1,
            },
            running_since: '2026-04-22T09:58:30.000Z',
            uptime_seconds: 90,
            starts_at: '2026-04-22T09:55:00.000Z',
            ends_at: '2026-04-22T10:30:00.000Z',
          },
        ],
        time: '2026-04-22T10:00:00.000Z',
      },
    });
  });
}

test('admin video operations table is sourced from backend live data, not static samples', async ({ page }) => {
  const counters = { videoOperations: 0 };
  await installOperationsRoutes(page, counters);
  await seedAdminSession(page);

  await page.goto('/admin/overview');

  await expect(page.locator('.metric', { hasText: 'Live Calls' })).toContainText('1');
  await expect(page.locator('.metric', { hasText: 'Concurrent Participants' })).toContainText('2');
  await expect(page.getByRole('heading', { name: 'Running Calls' })).toBeVisible();

  const liveRow = page.getByRole('row', { name: /Live Backend Call/ });
  await expect(liveRow).toContainText('Backend Host');
  await expect(liveRow).toContainText('2');
  await expect(liveRow).toContainText('00:01:30');
  await expect(liveRow).toContainText('live');

  await expect(page.getByText('Sales Standup')).toHaveCount(0);
  await expect(page.getByText('Incident Bridge')).toHaveCount(0);
  await expect(page.getByText('Quarterly Sync')).toHaveCount(0);
  expect(counters.videoOperations).toBeGreaterThan(0);
});
