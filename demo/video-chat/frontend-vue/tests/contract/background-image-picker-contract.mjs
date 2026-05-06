import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

const picker = read('demo/video-chat/frontend-vue/src/domain/realtime/background/CallBackgroundImagePicker.vue');
const uploadModal = read('demo/video-chat/frontend-vue/src/modules/administration/components/BackgroundImageUploadModal.vue');
const appConfigBackgrounds = read('demo/video-chat/frontend-vue/src/modules/administration/components/AppConfigurationBackgroundImagesTab.vue');
const shell = read('demo/video-chat/frontend-vue/src/layouts/WorkspaceShell.vue');
const adminCalls = read('demo/video-chat/frontend-vue/src/domain/calls/admin/CallsView.template.html');
const adminCallsScript = read('demo/video-chat/frontend-vue/src/domain/calls/admin/CallsView.vue');
const userCalls = read('demo/video-chat/frontend-vue/src/domain/calls/dashboard/UserDashboardView.template.html');
const userEnterCall = read('demo/video-chat/frontend-vue/src/domain/calls/dashboard/enterCall.ts');
const joinView = read('demo/video-chat/frontend-vue/src/domain/calls/access/JoinView.vue');
const joinViewCss = read('demo/video-chat/frontend-vue/src/domain/calls/access/JoinView.css');
const joinPreview = read('demo/video-chat/frontend-vue/src/domain/calls/access/joinPreview.ts');
const workspaceTemplate = read('demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.template.html');
const workspaceScript = read('demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.vue');
const workspaceModule = read('demo/video-chat/backend-king-php/http/module_workspace_administration.php');
const router = read('demo/video-chat/backend-king-php/http/router.php');

assert.match(
  picker,
  /grid-template-columns:\s*repeat\(4,\s*minmax\(0,\s*1fr\)\)/,
  'call background image picker must render four landscape thumbnails per row',
);
assert.match(
  picker,
  /listPublicWorkspaceBackgroundImages/,
  'call background picker must load the public workspace background image list',
);
assert.match(
  picker,
  /setCallBackgroundReplacementImageUrl\(path\)/,
  'call background picker must activate the selected image as the outgoing replacement background',
);
assert.match(
  picker,
  /hideWhenEmpty/,
  'call background picker must be able to disappear when no workspace background images exist',
);
assert.match(
  uploadModal,
  /OUTPUT_WIDTH\s*=\s*1600/,
  'background crop modal must emit a normalized landscape image width',
);
assert.match(
  uploadModal,
  /OUTPUT_HEIGHT\s*=\s*900/,
  'background crop modal must emit a normalized landscape image height',
);
assert.match(
  uploadModal,
  /canvas\.toDataURL\('image\/jpeg',\s*JPEG_QUALITY\)/,
  'background crop modal must upload a compressed cropped image instead of the raw file',
);
assert.equal(
  (uploadModal.match(/key:\s*'/g) || []).length,
  8,
  'background crop modal must expose eight image filters',
);
assert.match(
  appConfigBackgrounds,
  /<BackgroundImageUploadModal/,
  'background image administration must crop uploads before sending them',
);
for (const [label, source] of [
  ['call sidebar', shell],
  ['admin enter lobby', adminCalls],
  ['user enter lobby', userCalls],
  ['public join lobby', joinView],
]) {
  assert.match(source, /<CallBackgroundImagePicker/, `${label} must expose the background image picker`);
}
assert.doesNotMatch(adminCalls, /BackgroundPipelineDebugPanel|Preview pipeline/, 'admin lobby must not render the preview pipeline mask');
assert.doesNotMatch(adminCallsScript, /BackgroundPipelineDebugPanel|activeBackgroundPreset/, 'admin lobby script must not wire the preview pipeline mask');
for (const [label, source] of [
  ['admin enter lobby', adminCalls],
  ['user enter lobby', userCalls],
  ['public join lobby', joinView],
]) {
  assert.match(source, /call-left-blur-controls/, `${label} must keep the existing blur controls next to background images`);
}
assert.match(joinView, /call-access-join-guest-name[\s\S]*calls-enter-preview-frame/, 'public guest join name entry must sit above the camera preview');
assert.match(joinView, /hide-when-empty/, 'public join must hide background image picker when no images are configured');
assert.match(joinView, /role="meter"[\s\S]*state\.micLevelPercent/, 'public join must expose a live microphone level meter');
assert.match(joinViewCss, /@media \(max-width: 900px\)[\s\S]*\.calls-enter-layout\s*\{[\s\S]*display:\s*flex;[\s\S]*flex-direction:\s*column;/, 'mobile public join must stack name, half-screen preview, effects, audio controls, and footer');
assert.match(joinViewCss, /\.calls-enter-preview-frame\s*\{[\s\S]*height:\s*min\(50dvh,\s*420px\);/, 'mobile public join preview must take roughly half the viewport');
assert.match(joinPreview, /requestedMode === 'replace'[\s\S]*backgroundImageUrl:/, 'public join preview must render selected background replacement images');
assert.match(joinPreview, /startMicLevelMonitor\(rawStream\)/, 'public join microphone meter must use the active preview audio stream');
assert.match(userEnterCall, /requestedMode === 'replace'[\s\S]*backgroundImageUrl:/, 'user enter lobby preview must render selected background replacement images');
assert.doesNotMatch(workspaceTemplate, /BackgroundPipelineDebugPanel|workspace-local-pipeline-panel/, 'call workspace must not render local pipeline controls over video');
assert.doesNotMatch(workspaceScript, /BackgroundPipelineDebugPanel|activeWorkspaceBackgroundPreset|backgroundPipelineDebug/, 'call workspace script must not keep the removed local pipeline panel state');
assert.match(
  router,
  /requestPath === '\/api\/workspace\/background-images'/,
  'public background image listing must be reachable without admin-only routing',
);
assert.match(
  workspaceModule,
  /if \(\$path === '\/api\/workspace\/background-images'\)/,
  'workspace administration module must serve a public background image listing endpoint',
);

console.log('[background-image-picker-contract] PASS');
