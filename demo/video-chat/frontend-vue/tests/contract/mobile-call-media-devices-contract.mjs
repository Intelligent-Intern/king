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

  process.stdout.write('[mobile-call-media-devices-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
