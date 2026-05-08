import { test, expect } from '@playwright/test';

import {
  getSeedCall,
  getSeedTenant,
  getSeedUser,
  installCallAccessSeedRoutes,
  sessionStorageKey,
  tenantSnapshotForSeedUser,
} from './helpers/callAccessSeedMatrix.js';
import {
  installCallAccessFakeRealtime,
  installCallAccessMediaDeviceShim,
} from './helpers/callAccessSeedRuntime.js';

const directAccessId = '10000000-0000-4000-8000-000000000107';
const manipulatedAccessId = '10000000-0000-4000-8000-000000000108';
const temporaryGuestSessionToken = 'sess_iam_seed_temporary_personalized_guest';

function escapeRegExp(input) {
  return String(input).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function parseJsonPostData(request) {
  const raw = request.postData();
  if (!raw) return null;
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

function participantPayload(user, callRole = 'participant', inviteState = 'allowed') {
  return {
    user_id: user.id,
    display_name: user.display_name,
    email: user.email,
    call_role: callRole,
    invite_state: inviteState,
    joined_at: null,
    connected_at: null,
  };
}

function callPayload(call, viewer) {
  const owner = getSeedUser(call.owner_user_key);
  const guests = (Array.isArray(call.guest_list_user_keys) ? call.guest_list_user_keys : [])
    .map((userKey) => getSeedUser(userKey));
  const internal = [
    participantPayload(owner, 'owner', 'allowed'),
    ...guests.map((guest) => participantPayload(guest, 'participant', 'allowed')),
  ];
  return {
    id: call.id,
    room_id: call.room_id,
    title: call.title,
    status: call.status,
    starts_at: call.starts_at,
    ends_at: call.ends_at,
    owner: {
      user_id: owner.id,
      display_name: owner.display_name,
      email: owner.email,
    },
    participants: {
      total: internal.length,
      internal,
      external: [],
    },
    my_participation: {
      call_role: Number(viewer.id) === Number(owner.id) ? 'owner' : 'participant',
      invite_state: 'allowed',
    },
  };
}

function userPayload(user, tenant) {
  return {
    id: user.id,
    email: user.email,
    display_name: user.display_name,
    role: user.role,
    status: user.status || 'active',
    time_format: '24h',
    date_format: 'dmy_dot',
    theme: 'dark',
    locale: 'en',
    direction: 'ltr',
    supported_locales: ['en'],
    avatar_path: null,
    post_logout_landing_url: '',
    account_type: user.account_type,
    is_guest: Boolean(user.is_guest),
    tenant,
  };
}

function accessLinkPayload({ accessId, call, tenant, participant }) {
  return {
    id: accessId,
    call_id: call.id,
    room_id: call.room_id,
    tenant_id: tenant.id,
    link_kind: 'personal',
    participant_user_id: participant.id,
    participant_email: participant.email,
    created_by_user_id: getSeedUser(call.owner_user_key).id,
    created_at: '2026-05-08T10:00:00.000Z',
    expires_at: '2030-01-01T00:00:00.000Z',
    consumed_at: null,
    last_used_at: null,
  };
}

async function createSeededJoinPage(browser, baseURL) {
  const context = await browser.newContext({ baseURL, permissions: ['camera', 'microphone'] });
  await installCallAccessSeedRoutes(context);
  await installCallAccessMediaDeviceShim(context);
  await installCallAccessFakeRealtime(context, {
    callKey: 'alpha_temp_guest_list_active',
    userKey: 'temporary_personalized_guest',
    requiresAdmission: false,
  });
  const page = await context.newPage();
  return { context, page };
}

test('e2e_personalized_logged_out_003_temp_guest_on_guest_list_direct_join: temporary personalized guest on guest list enters directly', async ({ browser }) => {
  test.setTimeout(60_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const call = getSeedCall('alpha_temp_guest_list_active');
  const tenant = getSeedTenant(call.tenant_key);
  const participant = getSeedUser('temporary_personalized_guest');
  const tenantSnapshot = tenantSnapshotForSeedUser(participant.key, call.key);
  const accessLink = accessLinkPayload({ accessId: directAccessId, call, tenant, participant });
  const callForParticipant = callPayload(call, participant);
  const participantResponse = userPayload(participant, tenantSnapshot);
  const { context, page } = await createSeededJoinPage(browser, baseURL);
  let joinGetCount = 0;
  let sessionPostCount = 0;
  let sessionAuthorization = '';
  let sessionBody = undefined;

  try {
    await page.route((url) => url.pathname === `/api/call-access/${directAccessId}/join`, async (route) => {
      joinGetCount += 1;
      await route.fulfill({
        status: 200,
        json: {
          status: 'ok',
          result: {
            state: 'resolved',
            access_link: accessLink,
            link_kind: 'personal',
            call: callForParticipant,
            target_user: participantResponse,
            target_hint: { participant_email: participant.email },
            join_path: `/join/${directAccessId}`,
          },
          time: '2026-05-08T10:00:00.000Z',
        },
      });
    });
    await page.route((url) => url.pathname === `/api/call-access/${directAccessId}/session`, async (route) => {
      sessionPostCount += 1;
      sessionAuthorization = route.request().headers().authorization || '';
      sessionBody = parseJsonPostData(route.request());
      await route.fulfill({
        status: 200,
        json: {
          status: 'ok',
          result: {
            state: 'session_started',
            session: {
              id: temporaryGuestSessionToken,
              token: temporaryGuestSessionToken,
              token_type: 'session_id',
              issued_at: '2026-05-08T10:00:00.000Z',
              expires_at: '2030-01-01T00:00:00.000Z',
              expires_in_seconds: 43200,
            },
            user: participantResponse,
            tenant: tenantSnapshot,
            access_link: accessLink,
            link_kind: 'personal',
            call: callForParticipant,
            join_path: `/join/${directAccessId}`,
          },
          time: '2026-05-08T10:00:00.000Z',
        },
      });
    });

    const joinResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${directAccessId}/join`)
      && response.request().method() === 'GET'
    ));
    await page.goto(`/join/${directAccessId}?participant_user_id=6102&call_id=20000000-0000-4000-8000-000000000102`);
    const joinResponse = await joinResponsePromise;
    expect(joinResponse.status()).toBe(200);

    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog).toContainText(call.title);
    await expect(joinDialog).toContainText('Personalized link');

    const sessionResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${directAccessId}/session`)
      && response.request().method() === 'POST'
    ));
    await joinDialog.getByRole('button', { name: /^Join call$/ }).click();
    const sessionResponse = await sessionResponsePromise;
    expect(sessionResponse.status()).toBe(200);
    const sessionPayload = await sessionResponse.json();
    expect(sessionPayload?.result?.user?.id).toBe(participant.id);
    expect(sessionPayload?.result?.user?.account_type).toBe('guest');
    expect(sessionPayload?.result?.user?.is_guest).toBe(true);
    expect(sessionPayload?.result?.call?.id).toBe(call.id);
    expect(sessionPayload?.result?.call?.my_participation?.invite_state).toBe('allowed');
    expect(sessionPayload?.result?.tenant?.membership_id ?? 0).toBe(0);
    expect(sessionPayload?.result?.tenant?.permissions?.tenant_admin ?? false).toBe(false);
    expect(sessionPayload?.result?.tenant?.permissions?.manage_lobby ?? false).toBe(false);
    expect(sessionAuthorization).toBe('');
    expect(sessionBody).toBeNull();

    await page.waitForURL(new RegExp(`/workspace/call/${escapeRegExp(call.id)}(?:[/?#].*)?$`), { timeout: 30_000 });
    await expect(page.locator('.workspace-call-view')).toBeVisible({ timeout: 20_000 });

    const socketFrames = await page.evaluate(() => window.__iamCallAccessSocketFrames || []);
    expect(socketFrames.some((frame) => frame?.type === 'lobby/queue/join')).toBe(false);
    expect(socketFrames.some((frame) => frame?.type === 'room/join' || frame?.type === 'room/snapshot/request')).toBe(true);

    const storedSession = await page.evaluate((key) => {
      try {
        return JSON.parse(localStorage.getItem(key) || '{}');
      } catch {
        return {};
      }
    }, sessionStorageKey);
    expect(storedSession.sessionId).toBe(temporaryGuestSessionToken);
    expect(storedSession.sessionToken).toBe(temporaryGuestSessionToken);
    expect(joinGetCount).toBe(1);
    expect(sessionPostCount).toBe(1);
  } finally {
    await context.close();
  }
});

test('e2e_personalized_logged_out_007_manipulated_link_rejected: mutated temporary personalized link fails without session issuance', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const call = getSeedCall('alpha_temp_guest_list_active');
  const participant = getSeedUser('temporary_personalized_guest');
  const { context, page } = await createSeededJoinPage(browser, baseURL);
  let sessionRequests = 0;

  try {
    page.on('request', (request) => {
      if (request.url().includes(`/api/call-access/${manipulatedAccessId}/session`)) {
        sessionRequests += 1;
      }
    });

    const joinResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${manipulatedAccessId}/join`)
      && response.request().method() === 'GET'
    ));
    await page.goto(`/join/${manipulatedAccessId}`);
    const joinResponse = await joinResponsePromise;
    expect(joinResponse.status()).toBe(404);
    const responseText = await joinResponse.text();
    expect(responseText).not.toContain(call.title);
    expect(responseText).not.toContain(participant.email);
    expect(responseText).not.toContain(participant.display_name);

    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog).toContainText(/call link is invalid|call access id is invalid|does not exist/i);
    await expect(joinDialog).not.toContainText(call.title);
    await expect(joinDialog).not.toContainText(participant.email);
    await expect(joinDialog.getByRole('button', { name: /^Join call$/ })).toHaveCount(0);
    expect(sessionRequests).toBe(0);
    expect(page.url()).not.toContain('/workspace/call');
  } finally {
    await context.close();
  }
});
