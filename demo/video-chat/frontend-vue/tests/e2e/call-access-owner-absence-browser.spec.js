import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { test, expect } from '@playwright/test';

import {
  getSeedCall,
  getSeedUser,
} from './helpers/callAccessSeedMatrix.js';
import { createDirectJoinMatrixPage } from './helpers/callAccessSeedRuntime.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const videoChatRoot = path.resolve(frontendRoot, '..');
const ownerAbsenceSourcePath = path.join(
  videoChatRoot,
  'backend-king-php/domain/realtime/realtime_owner_absence.php',
);

function readOwnerAbsenceContractMs() {
  const source = fs.readFileSync(ownerAbsenceSourcePath, 'utf8');
  const constantMs = (name) => {
    const match = source.match(new RegExp(`const\\s+${name}\\s*=\\s*(\\d+)\\s*\\*\\s*60\\s*\\*\\s*1000\\s*;`));
    if (!match) throw new Error(`Missing owner absence contract constant ${name}`);
    return Number(match[1]) * 60 * 1000;
  };
  return {
    timerMs: constantMs('VIDEOCHAT_OWNER_ABSENCE_TIMER_MS'),
    countdownMs: constantMs('VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS'),
  };
}

const ownerAbsenceContract = readOwnerAbsenceContractMs();
const call = getSeedCall('alpha_active');
const owner = getSeedUser(call.owner_user_key);
const participant = getSeedUser('registered_guest');
const absentSinceMs = 1_778_000_000_000;

function isoFromMs(ms) {
  return new Date(ms).toISOString();
}

function participantSnapshot(user, callRole) {
  return {
    user_id: user.id,
    display_name: user.display_name,
    email: user.email,
    role: user.role,
    call_role: callRole,
    effective_call_role: callRole,
    invite_state: 'allowed',
    joined_at: isoFromMs(absentSinceMs - 60_000),
    connected_at: isoFromMs(absentSinceMs - 60_000),
  };
}

function ownerAbsenceSnapshot(status, overrides = {}) {
  const countdownStartsAtMs = absentSinceMs + ownerAbsenceContract.timerMs - ownerAbsenceContract.countdownMs;
  const endsAtMs = absentSinceMs + ownerAbsenceContract.timerMs;
  const ownerPresent = status === 'owner_present';
  const callStatus = status === 'ended' ? 'ended' : 'active';
  const participants = ownerPresent
    ? [participantSnapshot(owner, 'owner'), participantSnapshot(participant, 'participant')]
    : [participantSnapshot(participant, 'participant')];
  const remainingMs = Number(overrides.countdown_remaining_ms ?? (
    status === 'ended' ? 0 : ownerAbsenceContract.countdownMs
  ));

  return {
    participants,
    participant_count: participants.length,
    call_lifecycle: {
      status: callStatus,
      owner_absence: {
        enabled: true,
        call_id: call.id,
        room_id: call.room_id,
        call_status: callStatus,
        owner_user_id: owner.id,
        owner_present: ownerPresent,
        active_participant_count: participants.length,
        active_non_owner_count: ownerPresent ? 1 : participants.length,
        timer_ms: ownerAbsenceContract.timerMs,
        countdown_ms: ownerAbsenceContract.countdownMs,
        status,
        countdown_started: status === 'countdown' || status === 'ended',
        absent_since: isoFromMs(absentSinceMs),
        absent_since_ms: absentSinceMs,
        countdown_starts_at: isoFromMs(countdownStartsAtMs),
        countdown_starts_at_ms: countdownStartsAtMs,
        ends_at: isoFromMs(endsAtMs),
        ends_at_ms: endsAtMs,
        ...(status === 'countdown' ? { countdown_remaining_ms: remainingMs } : {}),
        ...(status === 'ended' ? {
          countdown_remaining_ms: 0,
          ended_at: isoFromMs(endsAtMs),
          ended_at_ms: endsAtMs,
          ended_reason: 'owner_absent_timeout',
          transitioned: true,
        } : {}),
        ...overrides,
      },
    },
  };
}

async function openOwnerAbsenceParticipantWorkspace(browser, baseURL) {
  const session = await createDirectJoinMatrixPage(browser, baseURL, {
    scenarioKey: 'direct_join_guest_list_user_allowed',
  });
  await session.page.goto(`/workspace/call/${call.id}`);
  await expect(session.page.locator('.workspace-call-view')).toBeVisible({ timeout: 20_000 });
  await session.page.waitForFunction(() => {
    const setup = document.querySelector('.workspace-call-view')?.__vueParentComponent?.setupState;
    return setup?.connectionState === 'online'
      && typeof window.__iamCallAccessEmitRoomSnapshot === 'function';
  }, null, { timeout: 20_000 });
  await session.page.waitForFunction(() => (
    (window.__iamCallAccessSocketFrames || []).some((frame) => frame?.type === 'room/snapshot/request')
  ), null, { timeout: 20_000 });
  return session;
}

async function emitOwnerAbsenceSnapshot(page, snapshot) {
  const sent = await page.evaluate((payload) => window.__iamCallAccessEmitRoomSnapshot(payload), snapshot);
  expect(sent, 'fake realtime socket must receive the owner-absence room snapshot').toBeGreaterThan(0);
}

async function ownerAbsenceState(page) {
  return page.evaluate(() => {
    const setup = document.querySelector('.workspace-call-view')?.__vueParentComponent?.setupState;
    return JSON.parse(JSON.stringify(setup?.ownerAbsenceState || null));
  });
}

test('e2e_journey_024_owner_absence_countdown_then_auto_end', async ({ browser }) => {
  test.setTimeout(90_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const { context, page } = await openOwnerAbsenceParticipantWorkspace(browser, baseURL);
  const banner = page.getByTestId('owner-absence-countdown');

  try {
    await expect(banner).toHaveCount(0);

    await emitOwnerAbsenceSnapshot(page, ownerAbsenceSnapshot('countdown'));
    await expect(banner).toBeVisible();
    await expect(banner).toContainText('Call owner disconnected.');
    await expect(page.getByTestId('owner-absence-remaining')).toHaveText('5:00');

    await emitOwnerAbsenceSnapshot(page, ownerAbsenceSnapshot('countdown', {
      countdown_remaining_ms: ownerAbsenceContract.countdownMs - 60_000,
    }));
    await expect(page.getByTestId('owner-absence-remaining')).toHaveText('4:00');

    await emitOwnerAbsenceSnapshot(page, ownerAbsenceSnapshot('ended'));
    await expect(banner).toBeVisible();
    await expect(banner).toContainText('Call ended because the owner did not return.');
    await expect(page.getByTestId('owner-absence-remaining')).toHaveCount(0);

    const state = await ownerAbsenceState(page);
    expect(state?.status).toBe('ended');
    expect(state?.callStatus).toBe('ended');
    expect(state?.ended_reason).toBe('owner_absent_timeout');
    expect(state?.transitioned).toBe(true);
  } finally {
    await context.close();
  }
});

test('e2e_journey_025_owner_absence_countdown_then_reconnect_cancels_end', async ({ browser }) => {
  test.setTimeout(90_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const { context, page } = await openOwnerAbsenceParticipantWorkspace(browser, baseURL);
  const banner = page.getByTestId('owner-absence-countdown');

  try {
    await emitOwnerAbsenceSnapshot(page, ownerAbsenceSnapshot('countdown'));
    await expect(banner).toBeVisible();
    await expect(page.getByTestId('owner-absence-remaining')).toHaveText('5:00');

    await emitOwnerAbsenceSnapshot(page, ownerAbsenceSnapshot('owner_present'));
    await expect(banner).toHaveCount(0);
    await expect(page.locator('.workspace-call-view')).toBeVisible();
    await expect(page).toHaveURL(new RegExp(`/workspace/call/${call.id.replaceAll('-', '\\-')}`));

    const state = await ownerAbsenceState(page);
    expect(state?.status).toBe('owner_present');
    expect(state?.callStatus).toBe('active');
    expect(state?.countdownStarted).toBe(false);
  } finally {
    await context.close();
  }
});
