import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
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
  const iconPath = path.join(frontendRoot, 'public/assets/orgas/kingrt/icons/solid.png');
  const preferences = readUtf8('src/domain/realtime/media/preferences.ts');
  const callSettingsCss = readUtf8('src/styles/call-settings.css');
  const blurControls = readUtf8('src/domain/realtime/background/CallBackgroundBlurControls.vue');
  const templates = [
    ['join preview', readUtf8('src/domain/calls/access/JoinView.vue')],
    ['dashboard preview', readUtf8('src/domain/calls/dashboard/UserDashboardView.template.html')],
    ['admin preview', readUtf8('src/domain/calls/admin/CallsView.template.html')],
    ['workspace shell', readUtf8('src/layouts/WorkspaceShell.vue')],
  ];

  assert.ok(fs.existsSync(iconPath), 'solid background selector PNG icon must exist');
  const iconBytes = fs.readFileSync(iconPath);
  assert.deepEqual([...iconBytes.subarray(0, 8)], [137, 80, 78, 71, 13, 10, 26, 10], 'solid background selector icon must be a PNG asset');
  assert.ok(iconBytes.length > 1024, 'solid background selector PNG must be a real rendered icon, not an empty placeholder');
  assert.equal(
    spawnSync('git', ['check-ignore', '--quiet', 'public/assets/orgas/kingrt/icons/solid.png'], { cwd: frontendRoot }).status,
    1,
    'solid background selector PNG must not require a gitignore exception',
  );
  assert.ok(callSettingsCss.includes('grid-template-columns: repeat(3, minmax(0, 1fr));'), 'background selector controls must reserve stable space for three buttons');
  assert.ok(callSettingsCss.includes('.call-left-solid-background-icon'), 'blue background selector must not inherit the white-invert blur icon filter');

  assert.ok(preferences.includes("if (value === 'exclusion') return 'exclusion';"), 'preferences must persist the exclusion backdrop mode');
  assert.ok(preferences.includes("if (preset === 'exclusion')"), 'preferences must recognize the exclusion preset');
  assert.ok(preferences.includes("setCallBackgroundBackdropMode('exclusion');"), 'exclusion preset must select the exclusion backdrop');
  assert.ok(preferences.includes("setCallBackgroundQualityProfile('quality');"), 'exclusion preset must use the quality mask path');
  assert.ok(
    preferences.includes("if (preset !== 'light' && preset !== 'strong' && preset !== 'exclusion')"),
    'exclusion preset must not be disabled by the unknown-preset off branch',
  );

  assert.ok(blurControls.includes("isCallBackgroundPresetActive('exclusion')"), 'shared blur controls must expose active state for the blue background selector');
  assert.ok(blurControls.includes("applyCallBackgroundPreset('exclusion')"), 'shared blur controls must apply the blue background selector');
  assert.ok(blurControls.includes('/assets/orgas/kingrt/icons/solid.png'), 'shared blur controls must render the solid background PNG icon image');
  assert.ok(blurControls.includes('call-left-solid-background-icon'), 'shared blur controls must opt the blue icon out of the blur icon invert filter');
  assert.ok(blurControls.includes('aria-label="Blue background"'), 'shared blur controls must label the blue background control');

  for (const [label, source] of templates) {
    assert.ok(source.includes('<CallBackgroundControls'), `${label} must render the shared background controls`);
  }

  console.log('[background-blue-selector-contract] PASS');
} catch (error) {
  console.error(`[background-blue-selector-contract] FAIL: ${error.message}`);
  process.exit(1);
}
