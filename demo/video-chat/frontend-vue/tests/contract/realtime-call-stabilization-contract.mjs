import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[realtime-call-stabilization-contract] FAIL: ${message}`);
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
const repoRoot = path.resolve(root, '../../..');

const packageJson = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8'));
const strategies = fs.readFileSync(path.join(root, 'src/domain/realtime/layout/strategies.ts'), 'utf8');
const videoLayout = fs.readFileSync(path.join(root, 'src/domain/realtime/workspace/callWorkspace/videoLayout.ts'), 'utf8');
const stageCss = fs.readFileSync(path.join(root, 'src/domain/realtime/CallWorkspaceStage.css'), 'utf8');
const harness = fs.readFileSync(path.join(root, 'tests/standalone/realtime-call-stabilization-harness.html'), 'utf8');
const harnessTs = fs.readFileSync(path.join(root, 'tests/standalone/realtime-call-stabilization-harness.ts'), 'utf8');
const activityLayout = fs.readFileSync(path.join(repoRoot, 'demo/video-chat/backend-king-php/domain/realtime/realtime_activity_layout.php'), 'utf8');
const migrations = fs.readFileSync(path.join(repoRoot, 'demo/video-chat/backend-king-php/support/database_migrations.php'), 'utf8');

try {
  assert.equal(
    packageJson.scripts?.['test:contract:realtime-call-stabilization'],
    'node tests/contract/realtime-call-stabilization-contract.mjs',
    'package script must keep the standalone stabilization contract discoverable'
  );
  assert.equal(
    packageJson.scripts?.['dev:call-stabilization-harness'],
    'vite --config ./vite.config.js --host 127.0.0.1 --port 8765',
    'browser harness must run through Vite/TypeScript instead of a raw file server'
  );

  assert.match(strategies, /export function rollingActivityScore\(entry\)/, 'frontend must expose rolling activity scoring');
  assert.match(strategies, /add\(score2s,\s*0\.5\);[\s\S]*add\(score5s,\s*0\.3\);[\s\S]*add\(score15s,\s*0\.2\);/, 'frontend speaker score must blend 2s, 5s, and 15s windows');
  assert.match(strategies, /ACTIVE_SPEAKER_MIN_SPEAKING_MS = 2000/, 'frontend active speaker takeover must require sustained speech');
  assert.match(strategies, /ACTIVE_SPEAKER_RELEASE_PAUSE_MS = 2000/, 'frontend active speaker handoff must wait for a release pause');
  assert.match(strategies, /canSpeakerTakeOver/, 'frontend active speaker selection must gate takeover through speaker state');

  assert.match(activityLayout, /sample_history_json TEXT NOT NULL DEFAULT '\[\]'/, 'backend activity table must persist bounded sample history');
  assert.match(activityLayout, /function videochat_activity_topk_rolling_score/, 'backend must compute rolling top-k speaker scores');
  assert.match(activityLayout, /array_slice\(\$decayed,\s*0,\s*3\)/, 'backend rolling score must average the top three recent samples');
  assert.match(activityLayout, /'topk_score_2s' => \$score2s/, 'backend payload must expose explicit top-k 2s score');
  assert.match(activityLayout, /\$activityByUserId\[\$userId\] = \(float\) \(\$row\['topk_score_2s'\]/, 'backend layout ranking must prefer top-k scores');
  assert.match(migrations, /0044_call_activity_sample_history/, 'sample history schema change must be a contracted migration');

  assert.doesNotMatch(videoLayout, /role === REMOTE_RENDER_SURFACE_ROLES\.MINI\)\s*return 1/, 'mini surface aspect must come from its stable slot, not a forced square');
  const rootVars = cssBlock(stageCss, '.workspace-call-view');
  assert.match(rootVars, /--mini-story-width:\s*180px;/, 'mini tile width must be stable and rectangular');
  assert.match(rootVars, /--mini-story-height:\s*112px;/, 'mini tile height must be stable and rectangular');
  assert.match(stageCss, /\.workspace-mini-tile\s*\{[\s\S]*aspect-ratio:\s*16\s*\/\s*10;/, 'mini tiles must avoid square-to-rectangle transitions');
  const decodedBlock = cssBlock(stageCss, '.video-container.decoded');
  assert.match(decodedBlock, /opacity:\s*0;/, 'decoded fallback container must not visibly flash unmatched media');
  assert.match(decodedBlock, /visibility:\s*hidden;/, 'decoded fallback container must stay hidden from the visible call stage');
  assert.match(stageCss, /\.workspace-mini-video-slot :deep\(video\),[\s\S]*position:\s*absolute;[\s\S]*inset:\s*0;/, 'mini media children must absolute-fill their stable slot');

  assert.match(harness, /Three-Speaker Stabilization Harness/, 'watchable browser harness must stay present');
  assert.match(harness, /<script type="module" src="\.\/realtime-call-stabilization-harness\.ts"><\/script>/, 'watchable harness must load TypeScript through Vite');
  assert.doesNotMatch(harness, /<script type="module">\s*import/, 'watchable harness must not keep inline JavaScript logic');
  assert.match(harnessTs, /import \{[\s\S]*selectCallLayoutParticipants,[\s\S]*\} from '\.\.\/\.\.\/src\/domain\/realtime\/layout\/strategies\.ts';/, 'browser harness must import the real KingRT layout strategy module');
  assert.match(harnessTs, /return kingRollingActivityScore\(kingActivityEntry\(person, now\)\);/, 'browser harness displayed scores must use real frontend rollingActivityScore');
  assert.match(harnessTs, /const selection = selectCallLayoutParticipants\(\{/, 'browser harness speaker choice must use real frontend selectCallLayoutParticipants');
  assert.match(harnessTs, /const TOP_K_SAMPLE_COUNT = 3;/, 'browser harness must use the same top-k sample count');
  assert.match(harnessTs, /const SAMPLE_INTERVAL_MS = 250;/, 'browser harness must publish emulated samples at a realistic cadence');
  assert.match(harnessTs, /const people: Speaker\[\] = \[[\s\S]*Speaker A Sustained[\s\S]*Speaker B Spike[\s\S]*Speaker C Alternating[\s\S]*\];/, 'browser harness must emulate exactly three speakers');
  assert.doesNotMatch(harnessTs, /Quiet Listener|Motion Only|Owner'/, 'browser harness must stay focused on three speakers');

  process.stdout.write('[realtime-call-stabilization-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
