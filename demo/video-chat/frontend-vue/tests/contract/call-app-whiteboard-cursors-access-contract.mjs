import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import {
  callAppPresenceUserAuthorizedForSession,
  normalizeCallAppPresenceParticipantRows,
  normalizeCallAppPresencePayload,
} from '../../src/domain/realtime/callApps/callAppPresenceRelay.js';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(root, '../../..');

async function read(relativePath) {
  return readFile(path.join(repoRoot, relativePath), 'utf8');
}

const [bridgeSource, relaySource, whiteboardHtml, whiteboardCss, whiteboardRuntime, e2eSource] = await Promise.all([
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/useCallAppCrdtBridge.js'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/callAppPresenceRelay.js'),
  read('demo/call-app/whiteboard/public/index.html'),
  read('demo/call-app/whiteboard/public/whiteboard.css'),
  read('demo/call-app/whiteboard/public/whiteboard.js'),
  read('demo/video-chat/frontend-vue/tests/e2e/call-app-whiteboard.spec.js'),
]);
const whiteboardSource = `${whiteboardHtml}\n${whiteboardCss}\n${whiteboardRuntime}`;

const session = {
  id: 'whiteboard-session-contract',
  app_key: 'whiteboard',
  default_app_policy: 'blocked_by_default',
  grants: [
    { subject_type: 'user', user_id: 10, grant_state: 'allowed' },
    { subject_type: 'user', user_id: 20, grant_state: 'allowed' },
    { subject_type: 'user', user_id: 30, grant_state: 'denied' },
  ],
};

assert.equal(callAppPresenceUserAuthorizedForSession(session, 10), true, 'allowed user should be authorized for Call App presence');
assert.equal(callAppPresenceUserAuthorizedForSession(session, 30), false, 'denied user should not be authorized for Call App presence');
assert.equal(callAppPresenceUserAuthorizedForSession(session, 0), false, 'anonymous zero user id should not be authorized for user presence');

assert.deepEqual(
  normalizeCallAppPresenceParticipantRows([
    { userId: 10, displayName: 'Owner' },
    { userId: 20, displayName: 'Participant' },
    { userId: 30, displayName: 'Revoked' },
    { userId: 40, displayName: 'Disconnected', isRoomMember: false },
  ], 10, session),
  [{ userId: 20, displayName: 'Participant' }],
  'presence relay should target only other connected users with allowed Call App grants',
);

assert.equal(
  normalizeCallAppPresencePayload('cursor.move', { x: 12, y: 34 }, { actorId: 'user_owner', displayName: 'Owner Name' })?.label,
  'Owner Name',
  'cursor payloads must carry the sender display name as their label',
);

assert.match(
  relaySource,
  /function defaultGrantStateForSession[\s\S]*export function callAppPresenceUserAuthorizedForSession[\s\S]*grant_state/s,
  'presence relay must resolve explicit and default participant grant state before targeting peers',
);

assert.match(
  bridgeSource,
  /callAppPresenceUserAuthorizedForSession\(session,\s*Number\(unrefValue\(currentUserId\)[\s\S]*state:\s*senderAuthorized \? \(accepted \? 'accepted' : 'ignored'\) : 'participant_grant_denied'/s,
  'parent bridge must reject presence publishes from users without an allowed Call App grant',
);

assert.match(
  bridgeSource,
  /normalizeCallAppPresenceParticipantRows\([\s\S]*unrefValue\(participants\)[\s\S]*Number\(unrefValue\(currentUserId\)[\s\S]*session/s,
  'parent bridge must filter cursor fanout targets through active session grants',
);

assert.match(
  bridgeSource,
  /handleRemotePresence[\s\S]*callAppPresenceUserAuthorizedForSession\(session,\s*Number\(unrefValue\(currentUserId\)[\s\S]*return/s,
  'parent bridge must not post remote cursors into an iframe after the local participant grant is revoked',
);

assert.match(
  whiteboardSource,
  /function applyAccessState[\s\S]*state\.cursors\.clear\(\)[\s\S]*state\.selections\.clear\(\)/,
  'whiteboard runtime must clear remote cursors and selections when read access is lost',
);

assert.match(
  whiteboardSource,
  /function applyPresence[\s\S]*if \(!canRead\(\)\) return;[\s\S]*label:\s*displayNameLabel\(payload\.label \|\| payload\.display_name/s,
  'whiteboard runtime must ignore remote cursor updates without read access and render authorized cursors with display-name labels',
);

assert.match(
  whiteboardSource,
  /const cursorOverlay = document\.getElementById\('cursorOverlay'\)[\s\S]*function syncCursorOverlay[\s\S]*remote-cursor-label[\s\S]*label\.textContent = displayNameLabel\(cursor\.label \|\| cursor\.display_name\)[\s\S]*cursorOverlay\.replaceChildren\(\.\.\.labels\)/s,
  'whiteboard runtime must render authorized remote cursor names into the DOM overlay, not only into canvas pixels',
);

assert.match(
  whiteboardSource,
  /function removePresenceForActor[\s\S]*state\.cursors\.delete\(normalizedActorId\)[\s\S]*state\.selections\.delete\(normalizedActorId\)[\s\S]*message\.type === 'call_app\.presence\.leave'/s,
  'whiteboard runtime must remove stale remote cursor and selection presence when a remote participant leaves',
);

assert.match(
  e2eSource,
  /injectRemoteCursor\('participant'[\s\S]*label: 'Reviewer'[\s\S]*injectRemoteCursor\('participant'[\s\S]*label: 'Facilitator'[\s\S]*remote-cursor-label'\)\)\.toHaveCount\(3\)[\s\S]*leaveRemoteCursor\('participant', 'user_reviewer_e2e'\)[\s\S]*\['Owner', 'Facilitator'\]/s,
  'whiteboard E2E must prove multiple named remote cursors render and a remote leave removes only the leaving cursor',
);

assert.match(
  e2eSource,
  /participantLaunchCountBeforeRemoteCursors[\s\S]*launchCount\.participant[\s\S]*participantFrameSrcBeforeRemoteCursors[\s\S]*revoke\('participant'\)[\s\S]*remote-cursor-label'\)\)\.toHaveCount\(0\)[\s\S]*toBe\(participantLaunchCountBeforeRemoteCursors\)[\s\S]*participantFrameSrcBeforeRemoteCursors/s,
  'whiteboard E2E must prove cursor cleanup after revoke does not reload the iframe',
);

console.log('[call-app-whiteboard-cursors-access-contract] PASS');
