import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';

import {
  SCREEN_SHARE_TRACK_LABEL,
  isScreenShareUserId,
  resolveScreenSharePeerIdentity,
  screenShareOwnerOrUserId,
  screenShareUserIdForOwner,
} from '../../src/domain/realtime/screenShareIdentity.js';
import { selectCallLayoutParticipants } from '../../src/domain/realtime/layout/strategies.js';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8');

const ownerUserId = 42;
const screenUserId = screenShareUserIdForOwner(ownerUserId);
assert.ok(screenUserId > ownerUserId, 'screen share participant uses a synthetic positive user id');
assert.ok(isScreenShareUserId(screenUserId), 'synthetic screen share user id is recognized');
assert.equal(screenShareOwnerOrUserId(screenUserId), ownerUserId, 'synthetic screen share ids resolve back to the real owner id');
assert.equal(screenShareOwnerOrUserId(ownerUserId), ownerUserId, 'real user ids remain unchanged for signaling targets');

const identity = resolveScreenSharePeerIdentity({
  publisherUserId: ownerUserId,
  publisherName: 'Alex',
  tracks: [{ id: 'screen-track', kind: 'video', label: SCREEN_SHARE_TRACK_LABEL }],
});
assert.equal(identity.isScreenShare, true, 'screen share track labels create screen share peer identity');
assert.equal(identity.ownerUserId, ownerUserId, 'screen share identity preserves owner user id');
assert.equal(identity.userId, screenUserId, 'screen share identity maps to synthetic participant id');

const alreadySyntheticIdentity = resolveScreenSharePeerIdentity({
  publisherUserId: screenUserId,
  publisherName: 'Alex screen',
  mediaSource: 'screen_share',
});
assert.equal(alreadySyntheticIdentity.isScreenShare, true, 'already synthetic screen-share publisher ids stay screen-share identities');
assert.equal(alreadySyntheticIdentity.ownerUserId, ownerUserId, 'already synthetic screen-share publisher ids resolve to the real owner');
assert.equal(alreadySyntheticIdentity.userId, screenUserId, 'already synthetic screen-share publisher ids must not be offset twice');

const nowMs = 1_000_000;
const participants = [
  { userId: 1, displayName: 'Speaker', role: 'user', callRole: 'participant' },
  { userId: screenUserId, displayName: 'Alex screen', role: 'user', callRole: 'participant', mediaSource: 'screen_share' },
  ...Array.from({ length: 8 }, (_, index) => ({
    userId: index + 2,
    displayName: `User ${index + 2}`,
    role: 'user',
    callRole: 'participant',
  })),
];
const selection = selectCallLayoutParticipants({
  participants,
  currentUserId: 1,
  activityByUserId: {
    1: { score2s: 100, isSpeaking: true, speakingStartedAtMs: nowMs - 3000, speakingLastAtMs: nowMs },
  },
  layoutState: { mode: 'main_mini', strategy: 'round_robin_active' },
  nowMs,
  selectionState: {},
});
assert.notEqual(selection.mainUserId, screenUserId, 'screen share does not steal active-speaker main video');
assert.ok(
  selection.miniParticipants.some((row) => row.userId === screenUserId),
  'screen share participant is kept visible in mini videos',
);

const pinnedScreenSelection = selectCallLayoutParticipants({
  participants,
  currentUserId: 1,
  pinnedUsers: { [screenUserId]: true },
  activityByUserId: {
    1: { score2s: 100, isSpeaking: true, speakingStartedAtMs: nowMs - 3000, speakingLastAtMs: nowMs },
  },
  layoutState: { mode: 'main_mini', strategy: 'round_robin_active', main_user_id: 1 },
  nowMs,
  selectionState: {},
});
assert.equal(pinnedScreenSelection.mainUserId, screenUserId, 'pinned screen share takes the main video');
assert.ok(
  pinnedScreenSelection.miniParticipants.some((row) => row.userId === 1),
  'previous main participant moves into mini videos when screen share is pinned',
);

const localScreenSelection = selectCallLayoutParticipants({
  participants: [
    { userId: ownerUserId, displayName: 'Alex', role: 'user', callRole: 'participant' },
    { userId: screenUserId, displayName: 'Alex screen', role: 'user', callRole: 'participant', mediaSource: 'screen_share' },
  ],
  currentUserId: ownerUserId,
  activityByUserId: {
    [screenUserId]: { activityDeltaCount: 100, score2s: 100 },
  },
  layoutState: { mode: 'main_mini', strategy: 'manual_pinned' },
  nowMs,
  selectionState: {},
});
assert.equal(localScreenSelection.mainUserId, ownerUserId, 'local screen share does not replace the sharer main video');
assert.deepEqual(
  localScreenSelection.miniParticipants.map((row) => row.userId),
  [screenUserId],
  'local screen share gets its own mini tile next to the sharer',
);

const pinnedLocalScreenSelection = selectCallLayoutParticipants({
  participants: [
    { userId: ownerUserId, displayName: 'Alex', role: 'user', callRole: 'participant' },
    { userId: screenUserId, displayName: 'Alex screen', role: 'user', callRole: 'participant', mediaSource: 'screen_share' },
  ],
  currentUserId: ownerUserId,
  pinnedUsers: { [screenUserId]: true },
  activityByUserId: {
    [ownerUserId]: { score2s: 100, isSpeaking: true, speakingStartedAtMs: nowMs - 3000, speakingLastAtMs: nowMs },
  },
  layoutState: { mode: 'main_mini', strategy: 'manual_pinned', main_user_id: ownerUserId },
  nowMs,
  selectionState: {},
});
assert.equal(pinnedLocalScreenSelection.mainUserId, screenUserId, 'local screen-share pin replaces the sharer main video');
assert.deepEqual(
  pinnedLocalScreenSelection.miniParticipants.map((row) => row.userId),
  [ownerUserId],
  'sharer moves to mini video while their screen-share participant is pinned',
);

const automatedScreenSelection = selectCallLayoutParticipants({
  participants: [
    { userId: 1, displayName: 'Speaker', role: 'user', callRole: 'participant' },
    { userId: screenUserId, displayName: 'Alex screen', role: 'user', callRole: 'participant', mediaSource: 'screen_share' },
    { userId: 2, displayName: 'Remote', role: 'user', callRole: 'participant' },
  ],
  currentUserId: 1,
  activityByUserId: {
    [screenUserId]: { activityDeltaCount: 999, score2s: 999 },
    2: { activityDeltaCount: 3, score2s: 3 },
  },
  layoutState: { mode: 'main_mini', strategy: 'most_active_window' },
  nowMs,
  selectionState: { topActivityEnteredAtMsByUserId: { 2: nowMs - 30_000 } },
});
assert.equal(automatedScreenSelection.mainUserId, 2, 'automatic main selection ignores screen-share activity');
assert.ok(
  automatedScreenSelection.miniParticipants.some((row) => row.userId === screenUserId),
  'automatic layout still keeps screen share in its own tile',
);

const screenSharePublisher = read('src/domain/realtime/local/screenSharePublisher.js');
assert.match(screenSharePublisher, /publishTracks\?\.\(\[\{[\s\S]*label: SCREEN_SHARE_TRACK_LABEL/, 'screen publisher announces a screen-share video track');
assert.match(screenSharePublisher, /publisher_media_source: SCREEN_SHARE_MEDIA_SOURCE/, 'screen publisher tags outbound frames with media source');
assert.match(screenSharePublisher, /autoSubscribe: false/, 'screen publisher does not subscribe to other call media');
assert.ok(
  screenSharePublisher.indexOf('nextStream = await acquireScreenShareStream()')
    < screenSharePublisher.indexOf('Screen sharing needs the SFU media runtime.'),
  'browser screen-share prompt happens before SFU runtime routing validation',
);

const mediaOrchestration = read('src/domain/realtime/local/mediaOrchestration.js');
assert.match(mediaOrchestration, /startScreenShareParticipant/, 'screen button delegates to participant publisher');
assert.match(mediaOrchestration, /hasScreenShareParticipantPublisher/, 'screen share participant path bypasses camera replacement');

const remotePeers = read('src/domain/realtime/sfu/remotePeers.js');
assert.match(remotePeers, /resolveScreenSharePeerIdentity/, 'remote peers map screen-share publishers to synthetic participants');
assert.match(
  remotePeers,
  /canvas\.dataset\.userId = String\(peerUserId\);[\s\S]*canvas\.dataset\.publisherUserId = String\(publisherUserId\);/,
  'screen-share media surfaces expose synthetic participant id separately from real publisher owner id',
);
assert.match(
  remotePeers,
  /function isLocalScreenShareIdentity[\s\S]*identity\?\.isScreenShare[\s\S]*identity\.ownerUserId[\s\S]*currentUserId\(\)[\s\S]*localScreenSharePreview === true/,
  'local sharers keep their own screen visible through the local preview instead of subscribing to their own SFU loopback',
);
assert.match(
  remotePeers,
  /matchedBy: 'local_screen_share_pending'/,
  'own screen-share SFU loopback frames are ignored even before the local preview has finished registering',
);
assert.match(
  remotePeers,
  /deleteDecodedLoopbackPeer\(publisherId, exactPeer\)[\s\S]*return null;/,
  'own screen-share track announcements delete accidental decoded loopback peers instead of creating decoders',
);

const mediaStack = read('src/domain/realtime/workspace/callWorkspace/mediaStack.js');
assert.match(mediaStack, /registerLocalScreenSharePeer/, 'local sharer registers their own screen as a visible media participant');
assert.match(mediaStack, /local_screen_share:\$\{normalizedOwnerUserId\}/, 'local screen-share preview uses a local publisher id');
assert.match(mediaStack, /localScreenSharePreview: true/, 'local screen-share preview is tagged as local-only UI media');
assert.match(
  mediaStack,
  /function removeLocalScreenShareLoopbackPeers[\s\S]*deleteSfuRemotePeer\(publisherId\)/,
  'registering the local screen preview removes already-created SFU loopback decoder peers',
);
assert.match(
  mediaStack,
  /isScreenShareUserId\(payloadPublisherUserId\)[\s\S]*screenShareOwnerOrUserId[\s\S]*target_user_id: targetUserId/,
  'screen-share recovery signaling targets the real owner user id even when only the payload has a synthetic publisher id',
);
assert.match(mediaStack, /unregisterLocalScreenSharePeer/, 'stopping screen share removes the local screen media participant');

const runtimeHealth = read('src/domain/realtime/workspace/callWorkspace/runtimeHealth.js');
assert.match(runtimeHealth, /screenShareOwnerOrUserId/, 'runtime health can recover a real owner id from synthetic screen-share ids');
assert.match(
  runtimeHealth,
  /isScreenShareUserId\(payloadPublisherUserId\)[\s\S]*target_user_id: targetUserId/,
  'runtime stall recovery never sends socket fallback recovery directly to the synthetic screen-share participant',
);
assert.match(
  runtimeHealth,
  /peerIsScreenShare[\s\S]*lastFrameAtMs > 0[\s\S]*setRemoteVideoStatus\(peer, 'live', '', nowMs\)/,
  'screen-share peers that have already rendered are not treated as frozen camera video when the shared screen is static',
);

const nativePeerFactory = read('src/domain/realtime/native/peerFactory.js');
assert.match(nativePeerFactory, /import \{ isScreenShareUserId \}/, 'native peer factory knows screen-share synthetic ids');
assert.match(
  nativePeerFactory,
  /if \(isScreenShareUserId\(normalizedTargetUserId\)\) return null;/,
  'native WebRTC offers are never opened directly to synthetic screen-share participants',
);
assert.match(
  nativePeerFactory,
  /if \(isScreenShareUserId\(userId\)\) continue;/,
  'native roster sync ignores synthetic screen-share participants',
);

const screenSharePublisherStart = screenSharePublisher.indexOf('async function start()');
const registerLocalPreview = screenSharePublisher.indexOf('callbacks.registerLocalScreenSharePeer?.(', screenSharePublisherStart);
const waitForSfuConnected = screenSharePublisher.indexOf('await waitForScreenSfuConnected();', screenSharePublisherStart);
const startDiagnostic = screenSharePublisher.indexOf('local_screen_share_participant_started', screenSharePublisherStart);
assert.ok(
  screenSharePublisherStart >= 0
    && registerLocalPreview > screenSharePublisherStart
    && registerLocalPreview < waitForSfuConnected
    && registerLocalPreview < startDiagnostic,
  'local screen-share preview is registered before the SFU loopback can create a decoder',
);
assert.match(screenSharePublisher, /ensureLocalScreenSharePreviewVideo/, 'screen-share publisher creates a local preview node before publishing frames');
assert.match(screenSharePublisher, /callbacks\.unregisterLocalScreenSharePeer\?\.\(\{ reason \}\);/, 'local screen-share preview unregisters during stop cleanup');

const participantUi = read('src/domain/realtime/workspace/callWorkspace/participantUi.js');
const callWorkspaceTemplate = read('src/domain/realtime/CallWorkspaceView.template.html');
const videoLayout = read('src/domain/realtime/workspace/callWorkspace/videoLayout.js');
const screenSharePan = read('src/domain/realtime/workspace/callWorkspace/screenSharePan.js');
const callWorkspaceStageCss = read('src/domain/realtime/CallWorkspaceStage.css');
assert.match(participantUi, /function toggleVideoFullscreenForEvent/, 'fullscreen toggle can resolve the concrete media surface from the click event');
assert.match(participantUi, /dataset\?\.callVideoSurfaceUserId[\s\S]*dataset\?\.userId/, 'fullscreen event resolution can select screen media surfaces by dataset user id');
assert.match(
  callWorkspaceTemplate,
  /@dblclick\.stop="toggleVideoFullscreenForEvent\(participant\.userId, \$event\)"/,
  'video tiles pass the double-click event to fullscreen media-surface resolution',
);
assert.match(participantUi, /function replaceLocalPinsWithScreenShare/, 'screen-share start replaces local pins through a dedicated helper');
assert.match(
  participantUi,
  /for \(const key of Object\.keys\(pinnedUsers\)\)[\s\S]*delete pinnedUsers\[key\];[\s\S]*pinnedUsers\[normalizedUserId\] = true/,
  'screen-share pin removes all previous local pins before pinning the screen-share participant',
);
assert.match(
  participantUi,
  /screenShareUserIdForOwner\(senderUserId\)[\s\S]*pinScreenShareParticipant/,
  'remote screen-share control state pins the synthetic screen-share participant locally',
);
assert.match(
  participantUi,
  /const localScreenShareUserId = screenShareUserIdForOwner\(currentUserId\.value\)[\s\S]*pinScreenShareParticipant\(localScreenShareUserId\)/,
  'local screen-share start pins the local synthetic screen-share participant',
);
assert.match(
  videoLayout,
  /if \(isScreenShareUserId\(userId\) \|\| isScreenShareMediaSource\(peer\?\.mediaSource \|\| peer\?\.media_source\)\) return;/,
  'unassigned screen-share media must not fall back into the decoded overlay container over main video',
);
assert.match(videoLayout, /applyScreenSharePanSurface\(node, target, \{ userId \}\)/, 'mounted screen-share media surfaces are wired for drag panning');
assert.match(screenSharePan, /isScreenShareUserId[\s\S]*isScreenShareMediaSource/, 'screen-share pan is gated to screen-share media only');
assert.match(screenSharePan, /addEventListener\('pointerdown'[\s\S]*addEventListener\('pointermove'/, 'screen-share pan uses pointer drag events');
assert.match(screenSharePan, /node\.dataset\.callScreenSharePanEnabled = '1'/, 'screen-share pan marks only enabled screen-share surfaces');
assert.match(callWorkspaceStageCss, /\[data-call-screen-share-pan-enabled="1"\][\s\S]*object-fit: cover !important/, 'screen-share pan surfaces use a movable cropped fit');

const frameDecode = read('src/domain/realtime/sfu/frameDecode.js');
const screenShareFrameIdentity = read('src/domain/realtime/sfu/screenShareFrameIdentity.js');
const decodeStart = frameDecode.indexOf('async function decodeSfuFrameForPeer');
const publisherUserIdDeclaration = frameDecode.indexOf('const publisherUserId = Number(frame?.publisherUserId || 0);', decodeStart);
const securityPublisherUserIdDeclaration = frameDecode.indexOf('const securityPublisherUserId = mediaSecurityPublisherUserIdForFrame(peer, frame, publisherUserId);', decodeStart);
const mediaSecurityUse = frameDecode.indexOf('shouldWaitForMediaSecurityBeforeProtectedDecrypt(publisherId, peer, frame, securityPublisherUserId)', decodeStart);
assert.ok(
  decodeStart >= 0
    && publisherUserIdDeclaration > decodeStart
    && securityPublisherUserIdDeclaration > publisherUserIdDeclaration
    && securityPublisherUserIdDeclaration < mediaSecurityUse,
  'SFU frame decode resolves the real media-security publisher before protected frame handling',
);
assert.match(
  screenShareFrameIdentity,
  /function mediaSecurityPublisherUserIdForFrame[\s\S]*screenShareOwnerOrUserId/,
  'screen-share media security uses the real owner user id instead of the synthetic screen-share participant id',
);
assert.match(
  frameDecode,
  /matchedBy === 'local_screen_share_preview'[\s\S]*matchedBy === 'local_screen_share_pending'[\s\S]*return;/,
  'own screen-share SFU loopback frames are ignored in favor of the local preview surface',
);

console.log('[call-screenshare-participant-contract] PASS');
