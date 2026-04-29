import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[call-layout-sidebar-controls-contract] FAIL: ${message}`);
}

function balancedBlock(source, marker) {
  const start = source.indexOf(marker);
  assert.notEqual(start, -1, `missing marker ${marker}`);
  const open = source.indexOf('{', start);
  assert.notEqual(open, -1, `missing block for ${marker}`);

  let depth = 0;
  for (let index = open; index < source.length; index += 1) {
    const char = source[index];
    if (char === '{') depth += 1;
    if (char === '}') {
      depth -= 1;
      if (depth === 0) {
        return source.slice(open + 1, index);
      }
    }
  }
  fail(`unterminated block for ${marker}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const root = path.resolve(__dirname, '../..');
const shell = fs.readFileSync(path.join(root, 'src/layouts/WorkspaceShell.vue'), 'utf8');
const workspace = fs.readFileSync(path.join(root, 'src/domain/realtime/CallWorkspaceView.vue'), 'utf8');
const participantUi = fs.readFileSync(path.join(root, 'src/domain/realtime/workspace/callWorkspace/participantUi.js'), 'utf8');
const preferences = fs.readFileSync(path.join(root, 'src/domain/realtime/media/preferences.js'), 'utf8');
const workspaceConfig = fs.readFileSync(path.join(root, 'src/domain/realtime/workspace/config.js'), 'utf8');
const lifecycle = fs.readFileSync(path.join(root, 'src/domain/realtime/workspace/callWorkspace/lifecycle.js'), 'utf8');
const mediaOrchestration = fs.readFileSync(path.join(root, 'src/domain/realtime/local/mediaOrchestration.js'), 'utf8');
const publisherPipeline = fs.readFileSync(path.join(root, 'src/domain/realtime/local/publisherPipeline.js'), 'utf8');
const runtimeSwitching = fs.readFileSync(path.join(root, 'src/domain/realtime/workspace/callWorkspace/runtimeSwitching.js'), 'utf8');
const settingsCss = fs.readFileSync(path.join(root, 'src/styles/call-settings.css'), 'utf8');
const responsiveCss = fs.readFileSync(path.join(root, 'src/styles/responsive.css'), 'utf8');
const adminCallsResponsiveCss = fs.readFileSync(path.join(root, 'src/domain/calls/admin/CallsViewResponsive.css'), 'utf8');
const userDashboardCss = fs.readFileSync(path.join(root, 'src/domain/calls/dashboard/UserDashboardView.css'), 'utf8');

try {
  const callSettingsStart = shell.indexOf('class="call-left-settings-block call-left-owner-edit-block"');
  assert.notEqual(callSettingsStart, -1, 'call settings sidebar block must exist');
  const nextSettingsBlock = shell.indexOf('<div v-if="callMediaPrefs.error"', callSettingsStart);
  assert.notEqual(nextSettingsBlock, -1, 'call settings block must end before media error block');
  const callSettingsMarkup = shell.slice(callSettingsStart, nextSettingsBlock);

  assert.match(settingsCss, /\.shell\.call-workspace-mode \.sidebar-content\.left\.left-call-content \.call-left-settings-block\s*\{[\s\S]*?width:\s*100%;[\s\S]*?margin:\s*0;/, 'call workspace settings blocks must share one width rule');
  assert.doesNotMatch(settingsCss, /\.shell\.call-workspace-mode[^{]*\.call-left-owner-edit-block\s*\{/, 'call settings must not use owner-specific width rules');
  assert.match(callSettingsMarkup, /<label for="call-left-layout-mode">Video layout<\/label>[\s\S]*<AppSelect[\s\S]*id="call-left-layout-mode"/, 'layout mode must use existing AppSelect styling');
  assert.match(callSettingsMarkup, /<label for="call-left-layout-strategy">Activity strategy<\/label>[\s\S]*<AppSelect[\s\S]*id="call-left-layout-strategy"/, 'activity strategy must use existing AppSelect styling');
  assert.match(callSettingsMarkup, /class="call-left-settings-field"[\s\S]*id="call-left-layout-mode"/, 'layout mode must use the existing call-left settings field wrapper');
  assert.match(callSettingsMarkup, /class="call-left-settings-field"[\s\S]*id="call-left-layout-strategy"/, 'activity strategy must use the existing call-left settings field wrapper');
  assert.doesNotMatch(callSettingsMarkup, /class="call-left-layout-controls"/, 'layout controls must not use a bespoke styling wrapper');
  assert.doesNotMatch(callSettingsMarkup, /aria-label="Call settings video layout"/, 'layout controls must not introduce a bespoke nested panel');
  assert.doesNotMatch(shell, /call-left-video-quality/, 'video quality must not be user-selectable in the sidebar');
  assert.doesNotMatch(shell, /callVideoQualityOptions/, 'sidebar must not expose SFU quality options');
  assert.doesNotMatch(shell, /SFU_VIDEO_QUALITY_PROFILE_OPTIONS/, 'sidebar must not import SFU quality options');
  assert.doesNotMatch(shell, /@update:model-value="setCallOutgoingVideoQualityProfile"/, 'sidebar must not persist user-selected video quality');
  assert.match(preferences, /export function setCallOutgoingVideoQualityProfile\(profile\) \{[\s\S]*?toOutgoingVideoQualityProfile\(profile\)[\s\S]*?persistCallMediaPrefs\(\);[\s\S]*?\}/, 'video quality setter must normalize and persist the automatic profile');
  assert.match(lifecycle, /\(\) => callMediaPrefs\.outgoingVideoQualityProfile,[\s\S]*?void reconfigureLocalTracksFromSelectedDevices\(\);/, 'video quality changes must reconfigure local tracks in active calls');
  assert.match(runtimeSwitching, /return resolveSfuVideoQualityProfile\(refs\.callMediaPrefs\.outgoingVideoQualityProfile\);/, 'runtime must resolve the active SFU profile from automatic state');
  assert.match(mediaOrchestration, /frameRate:\s*\{\s*ideal:\s*videoProfile\.captureFrameRate,\s*max:\s*30\s*\}/, 'local capture constraints must use the automatic SFU profile framerate');
  assert.match(publisherPipeline, /const nextQuality = Math\.max\(1, Math\.floor\(Number\(videoProfile\.frameQuality/, 'WLVC encoder quality must use the automatic SFU profile quality');
  assert.match(publisherPipeline, /setTimeout\(runWlvcEncodeTick,\s*Math\.max\(0,\s*Math\.round\(delayMs\)\)\)/, 'WLVC encode loop must use the automatic SFU profile encode interval');
  assert.doesNotMatch(workspaceConfig, /SFU_VIDEO_QUALITY_PROFILE_OPTIONS/, 'workspace config must not export user-facing SFU quality select options');
  assert.match(workspaceConfig, /quality:\s*Object\.freeze\(\{[\s\S]*?label:\s*'Quality'/, 'quality profile must remain available for automatic profile control');

  const controlsState = balancedBlock(shell, 'const callLayoutSidebarState = reactive');
  assert.match(controlsState, /currentMode:\s*'main_mini'/, 'sidebar controls must own current layout mode state');
  assert.match(controlsState, /currentStrategy:\s*'manual_pinned'/, 'sidebar controls must own current strategy state');
  assert.match(controlsState, /setMode:\s*null/, 'sidebar controls must delegate mode changes through injected callbacks');
  assert.match(controlsState, /setStrategy:\s*null/, 'sidebar controls must delegate strategy changes through injected callbacks');

  assert.match(shell, /callLayoutControls:\s*callLayoutSidebarState/, 'workspace shell must provide layout controls to call workspace');
  assert.match(workspace, /workspaceSidebarState,/, 'call workspace must pass sidebar state into participant UI helpers');
  assert.match(participantUi, /const controls = workspaceSidebarState\?\.callLayoutControls;/, 'call workspace must write layout controls through sidebar state');
  assert.match(participantUi, /controls\.setMode = setCallLayoutMode;/, 'call workspace must route mode changes through sidebar callbacks');
  assert.match(participantUi, /controls\.setStrategy = setCallLayoutStrategy;/, 'call workspace must route strategy changes through sidebar callbacks');
  assert.doesNotMatch(workspace, /Activity strategy/, 'activity strategy UI must not render inside the workspace stage overlay');
  assert.doesNotMatch(workspace, /call-layout-controls/, 'workspace stage must not own layout control markup');
  assert.doesNotMatch(shell, /call-left-layout-controls/, 'sidebar layout controls must not use bespoke CSS hooks');
  assert.doesNotMatch(settingsCss, /\.call-left-layout-controls\b/, 'sidebar layout controls must not add ad-hoc CSS');
  assert.match(
    responsiveCss,
    /\.shell\.call-workspace-mode\.mobile-mode \.sidebar\s*\{[\s\S]*?z-index:\s*75;/,
    'mobile call sidebars must layer above the bottom action controls',
  );

  for (const [label, source] of [
    ['admin calls', adminCallsResponsiveCss],
    ['user dashboard', userDashboardCss],
  ]) {
    assert.match(
      source,
      /\.calls-enter-right-settings \.call-left-settings\s*\{[\s\S]*?overflow-y:\s*auto;/,
      `${label} mobile enter-call settings must scroll to background blur controls`,
    );
    assert.match(
      source,
      /\.calls-enter-right-settings \.call-left-settings\s*\{[\s\S]*?overscroll-behavior:\s*contain;/,
      `${label} mobile enter-call settings must contain touch scroll`,
    );
    assert.doesNotMatch(
      source,
      /\.calls-enter-right-settings \.call-left-settings\s*\{[\s\S]*?overflow-y:\s*hidden;/,
      `${label} mobile enter-call settings must not clip lower controls`,
    );
    assert.match(
      source,
      /grid-template-rows:\s*minmax\(112px,\s*34%\) minmax\(0,\s*1fr\);/,
      `${label} mobile enter-call layout must leave more space for settings controls`,
    );
  }

  process.stdout.write('[call-layout-sidebar-controls-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
