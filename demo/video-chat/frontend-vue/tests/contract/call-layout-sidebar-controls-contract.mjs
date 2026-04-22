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
const settingsCss = fs.readFileSync(path.join(root, 'src/styles/call-settings.css'), 'utf8');

try {
  const callSettingsStart = shell.indexOf('class="call-left-settings-block call-left-owner-edit-block"');
  assert.notEqual(callSettingsStart, -1, 'call settings sidebar block must exist');
  const nextSettingsBlock = shell.indexOf('<div v-if="callMediaPrefs.error"', callSettingsStart);
  assert.notEqual(nextSettingsBlock, -1, 'call settings block must end before media error block');
  const callSettingsMarkup = shell.slice(callSettingsStart, nextSettingsBlock);

  assert.match(callSettingsMarkup, /<label for="call-left-layout-mode">Video layout<\/label>[\s\S]*<AppSelect[\s\S]*id="call-left-layout-mode"/, 'layout mode must use existing AppSelect styling');
  assert.match(callSettingsMarkup, /<label for="call-left-layout-strategy">Activity strategy<\/label>[\s\S]*<AppSelect[\s\S]*id="call-left-layout-strategy"/, 'activity strategy must use existing AppSelect styling');
  assert.match(callSettingsMarkup, /class="call-left-settings-field"[\s\S]*id="call-left-layout-mode"/, 'layout mode must use the existing call-left settings field wrapper');
  assert.match(callSettingsMarkup, /class="call-left-settings-field"[\s\S]*id="call-left-layout-strategy"/, 'activity strategy must use the existing call-left settings field wrapper');
  assert.doesNotMatch(callSettingsMarkup, /class="call-left-layout-controls"/, 'layout controls must not use a bespoke styling wrapper');
  assert.doesNotMatch(callSettingsMarkup, /aria-label="Call settings video layout"/, 'layout controls must not introduce a bespoke nested panel');

  const controlsState = balancedBlock(shell, 'const callLayoutSidebarState = reactive');
  assert.match(controlsState, /currentMode:\s*'main_mini'/, 'sidebar controls must own current layout mode state');
  assert.match(controlsState, /currentStrategy:\s*'manual_pinned'/, 'sidebar controls must own current strategy state');
  assert.match(controlsState, /setMode:\s*null/, 'sidebar controls must delegate mode changes through injected callbacks');
  assert.match(controlsState, /setStrategy:\s*null/, 'sidebar controls must delegate strategy changes through injected callbacks');

  assert.match(shell, /callLayoutControls:\s*callLayoutSidebarState/, 'workspace shell must provide layout controls to call workspace');
  assert.match(workspace, /const controls = workspaceSidebarState\?\.callLayoutControls;/, 'call workspace must write layout controls through sidebar state');
  assert.match(workspace, /controls\.setMode = setCallLayoutMode;/, 'call workspace must route mode changes through sidebar callbacks');
  assert.match(workspace, /controls\.setStrategy = setCallLayoutStrategy;/, 'call workspace must route strategy changes through sidebar callbacks');
  assert.doesNotMatch(workspace, /Activity strategy/, 'activity strategy UI must not render inside the workspace stage overlay');
  assert.doesNotMatch(workspace, /call-layout-controls/, 'workspace stage must not own layout control markup');
  assert.doesNotMatch(shell, /call-left-layout-controls/, 'sidebar layout controls must not use bespoke CSS hooks');
  assert.doesNotMatch(settingsCss, /\.call-left-layout-controls\b/, 'sidebar layout controls must not add ad-hoc CSS');

  process.stdout.write('[call-layout-sidebar-controls-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
