import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[mobile-call-media-devices-contract] FAIL: ${message}`);
}

function readFrontend(relativePath) {
  const __filename = fileURLToPath(import.meta.url);
  const __dirname = path.dirname(__filename);
  return fs.readFileSync(path.resolve(__dirname, '../..', relativePath), 'utf8');
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

try {
  const cameraConstraints = readFrontend('src/domain/realtime/media/cameraCaptureConstraints.ts');
  const speakerRouting = readFrontend('src/domain/realtime/media/speakerOutputRouting.ts');
  const mediaPreferences = readFrontend('src/domain/realtime/media/preferences.ts');
  const mediaOrchestration = readFrontend('src/domain/realtime/local/mediaOrchestration.ts');
  const audioBridgeRecovery = readFrontend('src/domain/realtime/native/audioBridgeRecovery.ts');
  const accessPreview = readFrontend('src/domain/calls/access/joinPreview.ts');
  const adminEnterCall = readFrontend('src/domain/calls/admin/enterCall.ts');
  const dashboardEnterCall = readFrontend('src/domain/calls/dashboard/enterCall.ts');
  const workspaceShell = readFrontend('src/layouts/WorkspaceShell.vue');
  const workspaceMicLevelMonitor = readFrontend('src/layouts/useWorkspaceMicLevelMonitor.js');

  requireContains(cameraConstraints, 'constraints.deviceId = { ideal: normalizedDeviceId };', 'selected mobile camera ids are hints instead of hard exact constraints');
  requireContains(cameraConstraints, "facingMode: { ideal: 'user' }", 'camera fallback prefers front camera on phones');
  requireContains(cameraConstraints, 'capturePreviewMediaWithCameraFallback', 'shared preview capture retries stale mobile camera ids');
  assert.equal(
    `${accessPreview}\n${adminEnterCall}\n${dashboardEnterCall}`.includes('deviceId: { exact: cameraDeviceId }'),
    false,
    'enter-call previews must not fail just because Android rotated a persisted camera device id',
  );
  requireContains(accessPreview, 'capturePreviewMediaWithCameraFallback({', 'public join preview uses stale-camera fallback');
  requireContains(adminEnterCall, 'capturePreviewMediaWithCameraFallback({', 'admin enter-call preview uses stale-camera fallback');
  requireContains(dashboardEnterCall, 'capturePreviewMediaWithCameraFallback({', 'dashboard enter-call preview uses stale-camera fallback');
  requireContains(mediaOrchestration, 'buildCallCameraVideoConstraints(cameraDeviceId, profileVideoConstraints())', 'live call capture uses non-exact selected camera constraints');
  requireContains(mediaOrchestration, 'buildFallbackCallCameraVideoConstraints({', 'live call loose retry keeps front-camera fallback');

  requireContains(speakerRouting, "CALL_PHONE_SPEAKER_DEVICE_ID = '__kingrt_phone_speaker__'", 'mobile phones have an explicit speaker route option');
  requireContains(speakerRouting, 'createMediaStreamSource(stream)', 'mobile phone speaker route uses WebAudio when sink routing is unavailable');
  requireContains(speakerRouting, 'node.muted = true;', 'speakerphone route suppresses duplicate element playback');
  requireContains(speakerRouting, 'normalizeSpeakerSinkDeviceId', 'real output devices still use setSinkId when available');
  requireContains(mediaPreferences, 'phoneSpeakerFallbackRows(enumeratedSpeakers).concat(enumeratedSpeakers)', 'device enumeration keeps phone speaker visible when Android exposes no audiooutput rows');
  requireContains(mediaOrchestration, 'applyCallSpeakerOutputToMediaElement(node, { selectedSpeakerId: speakerDeviceId, volume });', 'call audio output preferences route remote audio through the shared speaker router');
  requireContains(audioBridgeRecovery, 'applyCallOutputPreferences();', 'newly arriving native remote audio applies output routing before playback');
  requireContains(accessPreview, 'playCallSpeakerTestSound(callMediaPrefs)', 'public join test tone uses shared speaker routing');
  requireContains(adminEnterCall, 'playCallSpeakerTestSound(callMediaPrefs)', 'admin test tone uses shared speaker routing');
  requireContains(dashboardEnterCall, 'playCallSpeakerTestSound(callMediaPrefs)', 'dashboard test tone uses shared speaker routing');
  requireContains(workspaceShell, 'playCallSpeakerTestSound(callMediaPrefs)', 'in-call sidebar test tone uses shared speaker routing');
  requireContains(mediaPreferences, 'if (isLikelyMobileAudioDevice()) {\n    return;\n  }', 'mobile device refresh does not open camera and mic just to unlock labels');
  requireContains(mediaPreferences, 'waitForCallMediaDeviceRelease', 'mobile call entry waits for hardware release between preview and live call');
  requireContains(accessPreview, 'releasePreviewForCallEntry', 'public join preview exposes a media-release transition');
  requireContains(accessPreview, 'await waitForCallMediaDeviceRelease();', 'public join preview releases mobile capture before entering call');
  requireContains(adminEnterCall, 'await releaseEnterCallPreviewForWorkspace();', 'admin call entry releases preview before opening workspace');
  requireContains(dashboardEnterCall, 'await releaseEnterCallPreviewForWorkspace();', 'dashboard call entry releases preview before opening workspace');
  requireContains(workspaceShell, 'setMicLevelMonitorStream: attachMicLevelStream', 'call workspace sidebar can reuse the live call microphone stream for the mic meter');
  requireContains(workspaceMicLevelMonitor, 'if (!isCallWorkspace.value || isMobileViewport.value) return;', 'mobile call workspace must not open a second microphone stream for the sidebar meter');
  requireContains(workspaceMicLevelMonitor, 'micLevelMonitorOwnsStream', 'borrowed live streams are not stopped by sidebar mic meter cleanup');
  requireContains(workspaceMicLevelMonitor, "typeof MediaStream !== 'undefined'", 'sidebar mic meter checks MediaStream before cleanup');
  requireContains(workspaceShell, '() => [isCallWorkspace.value, callMediaPrefs.selectedMicrophoneId, isMobileViewport.value]', 'sidebar mic meter reacts to mobile viewport changes');
  requireContains(readFrontend('src/domain/realtime/workspace/callWorkspace/lifecycle.ts'), 'setMicLevelMonitorStream(mediaStream);', 'call workspace binds the live local stream into the sidebar mic meter');

  process.stdout.write('[mobile-call-media-devices-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
