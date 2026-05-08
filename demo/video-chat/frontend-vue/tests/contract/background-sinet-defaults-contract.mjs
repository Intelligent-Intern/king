import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

function readUtf8(relativePath) {
  return fs.readFileSync(path.join(frontendRoot, relativePath), 'utf8');
}

try {
  const preferences = readUtf8('src/domain/realtime/media/preferences.ts');
  const orchestration = readUtf8('src/domain/realtime/local/mediaOrchestration.ts');
  const avatarFallback = readUtf8('src/domain/realtime/background/avatarFallbackSignal.ts');
  const staticAvatarRender = readUtf8('src/domain/realtime/background/staticAvatarRender.ts');
  const modal = readUtf8('src/domain/realtime/background/BackgroundReplacementUnavailableModal.vue');
  const stream = readUtf8('src/domain/realtime/background/stream.ts');

  assert.ok(preferences.includes("export const DEFAULT_BACKGROUND_FALLBACK_AVATAR_URL = '/assets/orgas/kingrt/avatar-placeholder.svg';"), 'standard avatar fallback must be explicit');
  assert.ok(preferences.includes("backgroundFallbackVideoMode: 'none'"), 'avatar fallback must not be enabled silently');
  assert.ok(preferences.includes("backgroundReplacementUnavailablePromptOpen: false"), 'unavailable modal must default closed');
  assert.ok(preferences.includes('useCallBackgroundFallbackAvatar(imageUrl = DEFAULT_BACKGROUND_FALLBACK_AVATAR_URL)'), 'standard avatar action must use the default avatar');
  assert.ok(preferences.includes('clearCallBackgroundFallbackVideo()'), 'unfiltered action must clear avatar fallback');

  assert.ok(orchestration.includes("String(callMediaPrefs.backgroundFallbackVideoMode || 'none') === 'avatar'"), 'local media must honor avatar fallback mode');
  assert.ok(orchestration.includes('backgroundFilterController.dispose();'), 'avatar fallback must release background filter pipeline');
  assert.ok(orchestration.includes("resetBackgroundRuntimeMetrics('avatar_placeholder');"), 'avatar fallback must expose runtime state');
  assert.ok(orchestration.includes('syncBackgroundFallbackControlState(true)'), 'avatar fallback must publish static control-state once');
  assert.ok(orchestration.includes('createBackgroundFallbackAudioOnlyStream(rawStream)'), 'avatar fallback must not create a fake video stream');
  assert.ok(avatarFallback.includes('for (const audioTrack of sourceStream.getAudioTracks())'), 'avatar fallback must preserve audio tracks');
  assert.ok(!avatarFallback.includes('captureStream'), 'avatar fallback must not stream avatar video frames');
  assert.ok(staticAvatarRender.includes("node.dataset.callStaticAvatar = '1';"), 'avatar fallback must render as static tile media');

  assert.ok(modal.includes('accept="image/png,image/jpeg,image/webp"'), 'avatar upload must restrict image types');
  assert.ok(modal.includes('useCallBackgroundFallbackAvatar(DEFAULT_BACKGROUND_FALLBACK_AVATAR_URL)'), 'modal standard avatar button must use default avatar');
  assert.ok(modal.includes('clearCallBackgroundFallbackVideo();'), 'modal unfiltered button must disable replacement');

  assert.ok(stream.includes("requested: 'worker-segmenter'"), 'production backend remains Pierre worker segmenter');
  assert.ok(!stream.includes('sinet_wasm'), 'production stream must not default to SINet WASM');

  console.log('[background-sinet-defaults-contract] PASS avatar/unfiltered alternative defaults');
} catch (error) {
  console.error(`[background-sinet-defaults-contract] FAIL: ${error.message}`);
  process.exit(1);
}
