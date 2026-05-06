import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
  ACTIVE_SPEAKER_MIN_SPEAKING_MS,
  ACTIVITY_TOP_POOL_MIN_TENURE_MS,
  ROUND_ROBIN_REFRESH_MS,
  selectCallLayoutParticipants,
} from '../../src/domain/realtime/layout/strategies.js';
import { createParticipantActivityState } from '../../src/domain/realtime/workspace/callWorkspace/participantActivityState.js';

function fail(message) {
  throw new Error(`[call-layout-active-strategy-contract] FAIL: ${message}`);
}

function participants(count) {
  return Array.from({ length: count }, (_, index) => ({
    userId: index + 1,
    displayName: `User ${index + 1}`,
    role: index === 0 ? 'admin' : 'user',
    callRole: index === 0 ? 'owner' : 'participant',
  }));
}

try {
  const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
  const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8');
  const nowMs = 1_777_000_000_000;

  {
    const activityByUserId = {};
    const activityState = createParticipantActivityState({
      participantActivityByUserId: activityByUserId,
      participantActivityWeight: (source) => (source === 'speaking' ? 1 : 0.5),
      participantActivityWindowMs: 15_000,
    });
    activityState.markParticipantActivity(2, 'speaking', nowMs);
    activityState.markParticipantActivity(2, 'media_frame', nowMs + 3000);

    assert.equal(activityByUserId[2].isSpeaking, false, 'remote media-frame deltas must not extend stale speech ownership');
    assert.equal(activityByUserId[2].activityDeltaCount, 2, 'activity deltas still count for main activity ranking');
  }

  {
    const rows = participants(3);
    const state = { activeSpeakerUserId: 2, topActivityEnteredAtMsByUserId: {} };
    const result = selectCallLayoutParticipants({
      participants: rows,
      currentUserId: 1,
      activityByUserId: {
        2: {
          score2s: 61,
          isSpeaking: true,
          speakingStartedAtMs: nowMs - 4000,
          speakingLastAtMs: nowMs - 200,
        },
        3: {
          score2s: 99,
          audioLevel: 1,
          isSpeaking: true,
          speakingStartedAtMs: nowMs - 1000,
          speakingLastAtMs: nowMs - 100,
        },
      },
      layoutState: {
        mode: 'main_mini',
        strategy: 'active_speaker_main',
        main_user_id: 2,
      },
      selectionState: state,
      nowMs,
    });

    assert.equal(result.mainUserId, 2, 'louder one-second speaker must not steal main video');
  }

  {
    const rows = participants(3);
    const state = { activeSpeakerUserId: 2, topActivityEnteredAtMsByUserId: {} };
    const result = selectCallLayoutParticipants({
      participants: rows,
      currentUserId: 1,
      activityByUserId: {
        2: {
          score2s: 10,
          isSpeaking: false,
          speakingStartedAtMs: 0,
          speakingLastAtMs: nowMs - ACTIVE_SPEAKER_MIN_SPEAKING_MS - 200,
        },
        3: {
          score2s: 88,
          audioLevel: 0.7,
          isSpeaking: true,
          speakingStartedAtMs: nowMs - ACTIVE_SPEAKER_MIN_SPEAKING_MS - 100,
          speakingLastAtMs: nowMs - 100,
        },
      },
      layoutState: {
        mode: 'main_mini',
        strategy: 'active_speaker_main',
        main_user_id: 2,
      },
      selectionState: state,
      nowMs,
    });

    assert.equal(result.mainUserId, 3, 'stable speaker should take main after previous speaker pause');
  }

  {
    const rows = participants(6);
    const result = selectCallLayoutParticipants({
      participants: rows,
      currentUserId: 1,
      activityByUserId: {
        2: { activityDeltaCount: 3, score2s: 99 },
        3: { activityDeltaCount: 18, score2s: 20 },
        4: { activityDeltaCount: 9, score2s: 60 },
      },
      layoutState: {
        mode: 'main_mini',
        strategy: 'most_active_window',
      },
      selectionState: { activeSpeakerUserId: 0, topActivityEnteredAtMsByUserId: {} },
      nowMs,
    });

    assert.equal(result.mainUserId, 3, 'most active main must sort by exchanged delta count before score');
  }

  {
    const rows = participants(4);
    const result = selectCallLayoutParticipants({
      participants: rows,
      currentUserId: 1,
      pinnedUsers: { 2: true },
      activityByUserId: {
        3: {
          activityDeltaCount: 200,
          score2s: 200,
          isSpeaking: true,
          speakingStartedAtMs: nowMs - 5000,
          speakingLastAtMs: nowMs - 100,
        },
      },
      layoutState: {
        mode: 'main_mini',
        strategy: 'active_speaker_main',
        pinned_user_ids: [3],
        main_user_id: 3,
      },
      selectionState: { activeSpeakerUserId: 3, topActivityEnteredAtMsByUserId: {} },
      nowMs,
    });

    assert.equal(result.mainUserId, 2, 'local pin must override speaker, activity, and server layout selection');
    assert.ok(result.miniParticipants.some((row) => row.userId === 3), 'previous automated main remains visible as a mini participant');
  }

  {
    const rows = participants(3);
    const result = selectCallLayoutParticipants({
      participants: rows,
      currentUserId: 1,
      pinnedUsers: { 1: true },
      activityByUserId: {
        2: {
          activityDeltaCount: 100,
          score2s: 100,
          isSpeaking: true,
          speakingStartedAtMs: nowMs - 5000,
          speakingLastAtMs: nowMs - 100,
        },
      },
      layoutState: {
        mode: 'main_mini',
        strategy: 'active_speaker_main',
        main_user_id: 2,
      },
      selectionState: { activeSpeakerUserId: 2, topActivityEnteredAtMsByUserId: {} },
      nowMs,
    });

    assert.equal(result.mainUserId, 1, 'viewer must be able to pin themself into their own main video');
  }

  {
    const participantUi = read('src/domain/realtime/workspace/callWorkspace/participantUi.js');
    const moderationSync = read('src/domain/realtime/workspace/callWorkspace/moderationSync.js');
    const template = read('src/domain/realtime/CallWorkspaceView.template.html');
    const togglePinnedMatch = /function togglePinned\(userId\) \{[\s\S]*?\n\}\n\nfunction currentPinnedUserIds/.exec(participantUi);
    assert.ok(togglePinnedMatch, 'togglePinned function must exist');
    const togglePinnedBody = togglePinnedMatch[0];
    assert.match(togglePinnedBody, /for \(const key of Object\.keys\(pinnedUsers\)\)/, 'local pin selection must be single-owner for the viewer');
    assert.equal(togglePinnedBody.includes('normalizedUserId === currentUserId.value'), false, 'self pin must not be blocked in local pin handler');
    assert.equal(togglePinnedBody.includes('queueModerationSync'), false, 'local pin must not broadcast moderation state');
    assert.equal(togglePinnedBody.includes('publishLayoutSelectionState'), false, 'local pin must not publish shared layout selection');
    assert.equal(moderationSync.includes('pinnedUsers'), false, 'moderation sync must not read local pins');
    assert.equal(moderationSync.includes('pinned:'), false, 'moderation sync must not serialize local pins');
    assert.match(
      template,
      /:disabled="!row\.isRoomMember"/,
      'pin button must be available to every room member, including the viewer',
    );
  }

  {
    const rows = participants(25);
    const state = { activeSpeakerUserId: 0, topActivityEnteredAtMsByUserId: {} };
    const activityByUserId = Object.fromEntries(rows.map((row) => [
      row.userId,
      { activityDeltaCount: row.userId, score2s: row.userId },
    ]));

    const first = selectCallLayoutParticipants({
      participants: rows,
      currentUserId: 1,
      activityByUserId,
      layoutState: { mode: 'main_mini', strategy: 'most_active_window' },
      selectionState: state,
      nowMs,
    });
    assert.equal(first.mainUserId, 25, 'most active main is not blocked by top-20 tenure');
    assert.deepEqual(first.miniParticipants.map((row) => row.userId), [], 'mini strip waits for top-20 tenure');

    const mature = selectCallLayoutParticipants({
      participants: rows,
      currentUserId: 1,
      activityByUserId,
      layoutState: { mode: 'main_mini', strategy: 'most_active_window' },
      selectionState: state,
      nowMs: nowMs + ACTIVITY_TOP_POOL_MIN_TENURE_MS + 100,
    });
    const miniIds = mature.miniParticipants.map((row) => row.userId);
    assert.ok(miniIds.length > 0, 'mature top-20 participants should be eligible for mini strip');
    assert.ok(miniIds.every((id) => id >= 6 && id <= 24), 'mini strip must only sample from mature top-20 rest pool');
    assert.equal(mature.mainUserId, 25, 'main remains #1 even when mini strip is random');
  }

  {
    const rows = participants(8);
    const roundRobinNowMs = Math.floor(nowMs / ROUND_ROBIN_REFRESH_MS) * ROUND_ROBIN_REFRESH_MS + 1000;
    const state = { activeSpeakerUserId: 2, topActivityEnteredAtMsByUserId: {} };
    const activityByUserId = {
      2: {
        isSpeaking: true,
        speakingStartedAtMs: roundRobinNowMs - 5000,
        speakingLastAtMs: roundRobinNowMs - 100,
        score2s: 80,
      },
    };
    const base = {
      participants: rows,
      currentUserId: 1,
      activityByUserId,
      layoutState: { mode: 'main_mini', strategy: 'round_robin_active', main_user_id: 2 },
      selectionState: state,
    };

    const first = selectCallLayoutParticipants({ ...base, nowMs: roundRobinNowMs });
    const sameBucket = selectCallLayoutParticipants({ ...base, nowMs: roundRobinNowMs + ROUND_ROBIN_REFRESH_MS - 2000 });
    const nextBucket = selectCallLayoutParticipants({ ...base, nowMs: roundRobinNowMs + ROUND_ROBIN_REFRESH_MS + 1000 });

    assert.equal(first.mainUserId, 2, 'round robin keeps stable speaker in main');
    assert.deepEqual(
      sameBucket.miniParticipants.map((row) => row.userId),
      first.miniParticipants.map((row) => row.userId),
      'round robin mini selection stays stable inside the 30s bucket',
    );
    assert.notDeepEqual(
      nextBucket.miniParticipants.map((row) => row.userId),
      first.miniParticipants.map((row) => row.userId),
      'round robin mini selection refreshes after the 30s bucket',
    );
    assert.ok(nextBucket.miniParticipants.every((row) => row.userId !== 2), 'round robin mini pool excludes the speaker');
  }

  process.stdout.write('[call-layout-active-strategy-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) fail(error.message);
  fail(String(error));
}
