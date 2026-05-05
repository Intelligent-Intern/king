import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[call-mini-strip-responsive-contract] FAIL: ${message}`);
}

function cssBlock(source, selector) {
  const start = source.indexOf(selector);
  assert.notEqual(start, -1, `missing CSS selector ${selector}`);
  const open = source.indexOf('{', start);
  assert.notEqual(open, -1, `missing CSS block for ${selector}`);
  const close = source.indexOf('}', open);
  assert.notEqual(close, -1, `unterminated CSS block for ${selector}`);
  return source.slice(open + 1, close);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const root = path.resolve(__dirname, '../..');
const view = fs.readFileSync(path.join(root, 'src/domain/realtime/CallWorkspaceView.vue'), 'utf8');
const template = fs.readFileSync(path.join(root, 'src/domain/realtime/CallWorkspaceView.template.html'), 'utf8');
const participantUi = fs.readFileSync(path.join(root, 'src/domain/realtime/workspace/callWorkspace/participantUi.ts'), 'utf8');
const viewSurface = `${view}\n${template}\n${participantUi}`;
const stageCss = fs.readFileSync(path.join(root, 'src/domain/realtime/CallWorkspaceStage.css'), 'utf8');
const panelsCss = fs.readFileSync(path.join(root, 'src/domain/realtime/CallWorkspacePanels.css'), 'utf8');

try {
  assert.match(viewSurface, /'mini-strip-above': isCompactMiniStripAbove/, 'mini strip placement class must use compact viewport state');
  assert.match(viewSurface, /v-if="showCompactMiniStripToggle"/, 'compact mini strip toggle must render in compact layouts');
  assert.match(viewSurface, /@click="toggleCompactMiniStripPlacement"/, 'compact mini strip toggle must switch placement');
  assert.match(
    viewSurface,
    /const isCompactMiniStripAbove = computed\(\(\) => \(\s*isCompactLayoutViewport\.value\s*&& compactMiniStripPlacement\.value === 'above'\s*\)\);/,
    'above placement must apply to tablet and mobile compact layouts'
  );
  assert.match(
    viewSurface,
    /const showCompactMiniStripToggle = computed\(\(\) => \(\s*isCompactLayoutViewport\.value\s*&& showMiniParticipantStrip\.value\s*\)\);/,
    'placement toggle must be available on tablet and mobile compact layouts'
  );

  const rootVars = cssBlock(stageCss, '.workspace-call-view');
  const miniTileBlock = cssBlock(stageCss, '.workspace-mini-tile,');
  const width = Number((rootVars.match(/--mini-story-width:\s*(\d+)px/) || [])[1] || 0);
  const height = Number((rootVars.match(/--mini-story-height:\s*(\d+)px/) || [])[1] || 0);
  const stripHeight = Number((rootVars.match(/--mini-strip-height:\s*(\d+)px/) || [])[1] || 0);
  assert.equal(width, 150, 'mini story width must match the larger square tile target');
  assert.equal(height, 150, 'mini story height must be roughly ten percent taller than the old 136px tile');
  assert.equal(width, height, 'mini story dimensions must stay square');
  assert.equal(stripHeight, 170, 'mini strip height must fit the larger square tile plus vertical padding');
  assert.match(miniTileBlock, /border-radius:\s*0;/, 'mini tiles must have square corners');
  assert.match(stageCss, /\.workspace-mini-tile\s*\{[\s\S]*aspect-ratio:\s*1\s*\/\s*1;/, 'mini tiles must declare a square aspect ratio');

  assert.match(panelsCss, /@media \(max-width: 1180px\) \{[\s\S]*\.workspace-stage\.compact\.has-mini-strip\.mini-strip-above/, 'tablet layout must support mini strip above main video');
  assert.match(panelsCss, /@media \(max-width: 1180px\) \{[\s\S]*\.workspace-mini-placement-toggle\s*\{[\s\S]*display:\s*grid;/, 'tablet layout must show mini strip placement toggle');
  assert.match(panelsCss, /@media \(max-width: 760px\) and \(orientation: landscape\) \{[\s\S]*--mini-strip-height:\s*121px;[\s\S]*--mini-story-width:\s*101px;[\s\S]*--mini-story-height:\s*101px;/, 'mobile landscape mini strip must use larger square tiles');

  process.stdout.write('[call-mini-strip-responsive-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
