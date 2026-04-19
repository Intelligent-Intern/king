import { test, expect } from '@playwright/test';

const backendOrigin = process.env.VITE_VIDEOCHAT_BACKEND_ORIGIN || 'http://127.0.0.1:18080';
const sessionStorageKey = 'ii_videocall_v1_session';

const adminCredentials = {
  email: 'admin@intelligent-intern.com',
  password: 'admin123',
};

const userCredentials = {
  email: 'user@intelligent-intern.com',
  password: 'user123',
};

function escapeRegExp(value) {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function toLocalDateTimeInputValue(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  const hours = String(date.getHours()).padStart(2, '0');
  const minutes = String(date.getMinutes()).padStart(2, '0');
  return `${year}-${month}-${day}T${hours}:${minutes}`;
}

function buildStoredSession(payload) {
  const session = payload?.session || {};
  const user = payload?.user || {};

  return {
    role: String(user.role || '').trim(),
    displayName: String(user.display_name || '').trim(),
    email: String(user.email || '').trim(),
    userId: Number.isInteger(user.id) ? user.id : 0,
    avatarPath: typeof user.avatar_path === 'string' && user.avatar_path.trim() !== '' ? user.avatar_path.trim() : null,
    timeFormat: typeof user.time_format === 'string' && user.time_format.trim() !== '' ? user.time_format.trim() : '24h',
    theme: typeof user.theme === 'string' && user.theme.trim() !== '' ? user.theme.trim() : 'dark',
    status: typeof user.status === 'string' ? user.status.trim() : '',
    sessionId: String(session.id || session.token || '').trim(),
    sessionToken: String(session.token || session.id || '').trim(),
    expiresAt: typeof session.expires_at === 'string' ? session.expires_at.trim() : '',
  };
}

async function fetchStoredSession(email, password) {
  const params = new URLSearchParams({ email, password });
  let lastError = new Error('Login failed.');

  for (let attempt = 0; attempt < 4; attempt += 1) {
    try {
      const response = await fetch(`${backendOrigin}/api/auth/login?${params.toString()}`, {
        method: 'GET',
        headers: {
          accept: 'application/json',
        },
      });

      let payload = null;
      try {
        payload = await response.json();
      } catch {
        payload = null;
      }

      if (response.ok && payload && payload.status === 'ok') {
        return buildStoredSession(payload);
      }

      const message = payload?.error?.message || `Login failed (${response.status}).`;
      lastError = new Error(message);
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Login request failed.';
      lastError = new Error(message);
    }

    await new Promise((resolve) => setTimeout(resolve, 800 * (attempt + 1)));
  }

  throw lastError;
}

async function createAuthenticatedPage(browser, baseURL, { email, password }) {
  const storedSession = await fetchStoredSession(email, password);
  const context = await browser.newContext({
    baseURL,
    permissions: ['camera', 'microphone'],
  });
  await context.addInitScript(
    ({ key, value }) => {
      try {
        localStorage.setItem(key, value);
      } catch {
        // ignore
      }
    },
    { key: sessionStorageKey, value: JSON.stringify(storedSession) },
  );
  const page = await context.newPage();
  return { context, page, storedSession };
}

async function waitForCallRow(page, callTitle) {
  const searchCalls = async () => {
    await page.getByPlaceholder('Search call title').fill(callTitle);
    await page.getByRole('button', { name: 'Search' }).first().click();
  };

  await searchCalls();
  for (let attempt = 0; attempt < 10; attempt += 1) {
    const row = page.locator('tbody tr', { hasText: callTitle }).first();
    if ((await row.count()) > 0) {
      await expect(row).toBeVisible();
      return row;
    }
    await page.waitForTimeout(1000);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await searchCalls();
  }

  throw new Error(`Could not find call row for title: ${callTitle}`);
}

async function createPersonalAccessJoinPath({ callId, sessionToken, participantUserId }) {
  const response = await fetch(`${backendOrigin}/api/calls/${encodeURIComponent(callId)}/access-link`, {
    method: 'POST',
    headers: {
      accept: 'application/json',
      'content-type': 'application/json',
      authorization: `Bearer ${sessionToken}`,
    },
    body: JSON.stringify({
      link_kind: 'personal',
      participant_user_id: participantUserId,
    }),
  });
  const payload = await response.json().catch(() => null);

  if (!response.ok || !payload || payload.status !== 'ok') {
    const message = payload?.error?.message || `Access-link creation failed (${response.status}).`;
    throw new Error(message);
  }

  const rawJoinPath = String(payload?.result?.join_path || '').trim();
  if (rawJoinPath !== '') return rawJoinPath;

  const accessId = String(payload?.result?.access_link?.id || '').trim();
  if (accessId !== '') return `/join/${accessId}`;

  throw new Error('Access-link payload is missing join_path and access id.');
}

test('admin creates/invites and admits user from lobby, mini strip shows participant', async ({ browser }) => {
  test.setTimeout(180_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';

  const { context: adminContext, page: adminPage, storedSession: adminStoredSession } = await createAuthenticatedPage(browser, baseURL, adminCredentials);
  const { context: userContext, page: userPage } = await createAuthenticatedPage(browser, baseURL, userCredentials);
  const callTitle = `E2E Lobby Admit ${Date.now()}`;

  try {
    await adminPage.goto('/admin/calls');
    await expect(adminPage).toHaveURL(/\/admin\/calls$/);

    await adminPage.getByRole('tab', { name: 'Calendar' }).click();
    await adminPage.getByRole('button', { name: /Schedule video call/i }).click();
    const composeModal = adminPage.getByRole('dialog', { name: 'Call compose modal' });
    await expect(composeModal).toBeVisible();

    await composeModal.locator('input[placeholder="Weekly Product Sync"]').fill(callTitle);
    await composeModal.locator('input[placeholder="lobby"]').fill('lobby');
    const startsAt = new Date(Date.now() - 60_000);
    const endsAt = new Date(Date.now() + (59 * 60_000));
    await composeModal.getByLabel('Call starts at').fill(toLocalDateTimeInputValue(startsAt));
    await composeModal.getByLabel('Call ends at').fill(toLocalDateTimeInputValue(endsAt));
    await composeModal.locator('label[aria-label="Participant search"] input[placeholder="Search users"]').fill(userCredentials.email);
    await composeModal.locator('label[aria-label="Participant search"] button', { hasText: 'Search' }).click();

    const invitedUserRow = composeModal.locator('.calls-participant-row', { hasText: userCredentials.email }).first();
    await expect(invitedUserRow).toBeVisible({ timeout: 15_000 });
    await invitedUserRow.locator('input[type="checkbox"]').check();

    const removeExternalButtons = composeModal.locator('button[title="Remove external participant"]');
    for (let index = await removeExternalButtons.count(); index > 0; index -= 1) {
      await removeExternalButtons.first().click();
    }

    await composeModal.getByRole('button', { name: /Schedule call|Start now/i }).click();
    await expect(composeModal).toBeHidden({ timeout: 20_000 });

    await adminPage.getByRole('tab', { name: 'Calls' }).click();
    const adminCallRow = await waitForCallRow(adminPage, callTitle);
    const callId = ((await adminCallRow.locator('.call-subline.code').first().textContent()) || '').trim();
    expect(callId.length).toBeGreaterThan(0);

    const userJoinPath = await createPersonalAccessJoinPath({
      callId,
      sessionToken: adminStoredSession.sessionToken,
      participantUserId: 2,
    });

    await adminCallRow.locator('button[title="Enter video call"]').click();

    const adminEnterCallModal = adminPage.getByRole('dialog', { name: 'Enter video call' });
    await expect(adminEnterCallModal).toBeVisible();
    await adminEnterCallModal.getByRole('button', { name: /Open call|Join call/i }).click();

    await adminPage.waitForURL(/\/workspace\/call\/[^/]+$/, { timeout: 30_000 });
    await expect(adminPage.locator('.workspace-main-video')).toBeVisible({ timeout: 12_000 });

    const callRef = decodeURIComponent((adminPage.url().split('/workspace/call/')[1] || '').split(/[?#]/)[0] || '');
    expect(callRef.length).toBeGreaterThan(0);

    await userPage.goto(userJoinPath);
    const joinCallModal = userPage.getByRole('dialog', { name: 'Join video call' });
    await expect(joinCallModal).toBeVisible({ timeout: 15_000 });
    await joinCallModal.getByRole('button', { name: /Join call/i }).click();

    await userPage.waitForURL(new RegExp(`/workspace/call/${escapeRegExp(callRef)}(?:[/?#].*)?$|/workspace/call/[^/]+$`), { timeout: 30_000 });
    await expect(userPage.locator('.workspace-main-video')).toBeVisible({ timeout: 12_000 });

    const lobbyBadge = adminPage.locator('.tab-lobby .tab-notice-badge');
    await expect(lobbyBadge).toBeVisible({ timeout: 30_000 });

    await adminPage.locator('button.tab-lobby').click();
    const lobbyPanel = adminPage.locator('.panel-lobby.active');
    await expect(lobbyPanel).toBeVisible({ timeout: 10_000 });

    const allowUserButton = lobbyPanel.locator('button[title="Allow user"]').first();
    await expect(allowUserButton).toBeVisible({ timeout: 20_000 });
    await allowUserButton.click();

    const miniStrip = adminPage.locator('.workspace-mini-strip');
    await expect(miniStrip).toBeVisible({ timeout: 30_000 });
    await expect(miniStrip.locator('.workspace-mini-tile').first()).toBeVisible({ timeout: 30_000 });
  } finally {
    await Promise.allSettled([
      adminContext.close(),
      userContext.close(),
    ]);
  }
});
