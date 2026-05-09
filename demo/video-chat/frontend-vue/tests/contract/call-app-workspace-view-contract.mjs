import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(root, '../../..');

async function read(relativePath) {
  return readFile(path.join(repoRoot, relativePath), 'utf8');
}

function cssBlock(source, selector) {
  const escapedSelector = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const match = source.match(new RegExp(`${escapedSelector}\\s*\\{([\\s\\S]*?)\\n\\}`, 'm'));
  assert.ok(match, `${selector} CSS block must exist`);
  return match[1];
}

function zIndex(source, selector) {
  const block = cssBlock(source, selector);
  const match = block.match(/z-index:\s*(\d+);/);
  assert.ok(match, `${selector} must declare a numeric z-index`);
  return Number(match[1]);
}

const [
  componentSource,
  stageCssSource,
  stateSource,
  templateSource,
  workspaceSource,
  strategiesSource,
  uiOptionsSource,
  roomStateSource,
  participantUiSource,
  backendLayoutSource,
  migrationsSource,
] = await Promise.all([
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/CallAppWorkspaceHost.vue'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceStage.css'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/callAppWorkspaceState.js'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.template.html'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.vue'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/layout/strategies.ts'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/layout/uiOptions.ts'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/roomState.ts'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/participantUi.ts'),
  read('demo/video-chat/backend-king-php/domain/realtime/realtime_activity_layout.php'),
  read('demo/video-chat/backend-king-php/support/database_migrations.php'),
]);

const callAppFullscreenZ = zIndex(componentSource, '.call-app-workspace-host.fullscreen');
const videoFullscreenZ = zIndex(stageCssSource, '.workspace-video-fullscreen-overlay');

assert.match(strategiesSource, /CALL_LAYOUT_MODES\s*=\s*\[[^\]]*call_app_workspace/s, 'frontend layout modes must include call_app_workspace');
assert.match(uiOptionsSource, /mode:\s*['"]call_app_workspace['"]/, 'layout controls must expose Call App workspace mode');
assert.match(backendLayoutSource, /call_app_workspace/, 'backend layout mode normalization must accept call_app_workspace');
assert.match(migrationsSource, /0050_call_app_workspace_layout_mode/, 'SQLite migrations must widen the persisted layout mode check');

assert.match(templateSource, /<CallAppWorkspaceHost[\s\S]*currentLayoutMode\s*===\s*['"]call_app_workspace['"]/s, 'workspace template must render the dedicated Call App host for call_app_workspace mode');
assert.doesNotMatch(workspaceSource, /<iframe\b/i, 'CallWorkspaceView.vue must not own Call App iframe implementation');
assert.doesNotMatch(workspaceSource, /sandbox=/i, 'CallWorkspaceView.vue must not own iframe sandbox policy');

assert.match(roomStateSource, /applyCallAppsRoomState\(payload\?\.call_apps/, 'room snapshots must apply active Call App session state');
assert.match(participantUiSource, /\['main_mini',\s*'call_app_workspace'\]\.includes\(currentLayoutMode\.value\)/, 'Call App workspace mode must provide mini video participants to the video layout');
assert.match(strategiesSource, /const limit =[\s\S]*mode === ['"]call_app_workspace['"]\s*\?\s*10[\s\S]*mode === ['"]grid['"]\s*\?\s*8[\s\S]*mode === ['"]main_only['"]\s*\?\s*1[\s\S]*5/s, 'Call App workspace layout strategy must allow ten visible mini participants before clipping');
assert.match(strategiesSource, /mode === ['"]call_app_workspace['"][\s\S]*miniUserIds = clippedVisibleIds/s, 'Call App workspace mode must keep every clipped visible participant in the mini strip');

assert.match(stateSource, /CALL_APP_WORKSPACE_MINI_LIMIT\s*=\s*10/, 'Call App workspace must cap mini participants at ten');
assert.match(componentSource, /<iframe[\s\S]*sandbox="allow-scripts allow-forms allow-pointer-lock allow-downloads"/, 'Call App iframe must be sandboxed');
assert.doesNotMatch(componentSource, /allow-same-origin/, 'Call App iframe sandbox must not include allow-same-origin');
assert.doesNotMatch(componentSource + stateSource, /sessionToken|Authorization|localStorage/, 'Call App workspace shell must not expose primary auth material to the iframe');
assert.match(componentSource, /referrerpolicy="no-referrer"/, 'Call App iframe must not leak referrer data');
assert.match(componentSource, /--call-app-workspace-mini-height:\s*112px[\s\S]*grid-template-rows:\s*var\(--call-app-workspace-mini-height\)\s*minmax\(0,\s*1fr\)[\s\S]*height:\s*var\(--call-app-workspace-mini-height\)/, 'Call App workspace must keep mini strip and iframe sizing stable');
assert.match(componentSource, /class="\{ fullscreen: isWorkspaceFullscreen \}"/, 'Call App workspace host must expose a dedicated fullscreen state');
assert.match(componentSource, /call-app-workspace-fullscreen-toggle[\s\S]*aria-pressed[\s\S]*fullscreenToggleLabel[\s\S]*@click\.stop="toggleWorkspaceFullscreen"/, 'Call App workspace must provide an iframe fullscreen toggle without using participant video fullscreen controls');
assert.match(componentSource, /call-app-workspace-fullscreen-icon[\s\S]*class="\{ active: isWorkspaceFullscreen \}"/, 'Call App workspace fullscreen toggle must render a clear stateful icon inside the button');
assert.doesNotMatch(componentSource, /\{\{\s*isWorkspaceFullscreen\s*\?\s*['"]X['"]\s*:\s*['"]\[\]['"]\s*\}\}/, 'Call App workspace fullscreen toggle must not rely on raw text glyphs');
assert.match(componentSource, /\.call-app-workspace-host\.fullscreen\s*\{[\s\S]*position:\s*fixed;[\s\S]*inset:\s*0;[\s\S]*z-index:\s*9990;[\s\S]*height:\s*100dvh;[\s\S]*grid-template-rows:\s*var\(--call-app-workspace-mini-height\)\s*minmax\(0,\s*1fr\)/, 'Call App fullscreen must escape sidebars/body clipping while preserving the mini video strip row');
assert.match(componentSource, /\.call-app-workspace-host\.fullscreen[\s\S]*\.call-app-workspace-mini-strip\s*\{[\s\S]*display:\s*flex;[\s\S]*overflow-x:\s*auto;[\s\S]*overflow-y:\s*hidden;[\s\S]*scrollbar-gutter:\s*stable;/, 'Call App fullscreen must keep the participant strip visible, horizontally scrollable, and scrollbar-stable');
assert.match(componentSource, /\.call-app-workspace-mini-strip\s*\{[\s\S]*(?:grid-auto-flow:\s*column;[\s\S]*grid-auto-columns:\s*minmax\(120px,\s*120px\)|display:\s*flex;[\s\S]*\.call-app-workspace-mini-tile[\s\S]*flex:\s*0\s+0\s+120px)/s, 'Call App mini strip must use fixed tile tracks so scrolling does not resize participants');
assert.match(componentSource, /\.call-app-workspace-mini-tile\s*\{[\s\S]*width:\s*120px;[\s\S]*height:\s*84px;[\s\S]*flex:\s*0\s+0\s+120px;/, 'Call App mini tiles must have stable width, height, and flex basis');
assert.match(componentSource, /\.call-app-workspace-mini-tile\.is-hidden\s*\{[\s\S]*display:\s*none;/, 'Call App fullscreen participant strip must expose a class for hidden participant tiles');
assert.match(componentSource, /\.call-app-workspace-mini-tile\.is-visible\s*\{[\s\S]*display:\s*(?:grid|block);/, 'Call App fullscreen participant strip must expose a class for shown participant tiles');
assert.match(componentSource, /:class="\[[\s\S]*'is-hidden':[\s\S]*'is-visible':[\s\S]*\]"/, 'Call App mini participant tiles must bind hide/show selector classes from participant visibility state');
assert.match(componentSource, /data-call-app-participant-control/, 'Call App participant controls must expose a stable selector for hide/show automation');
assert.match(componentSource, /aria-controls="\`call-app-workspace-mini-tile-\$\{participant\.userId\}\`"/, 'Call App participant controls must target the mini tile they hide or show');
assert.match(componentSource, /\.call-app-workspace-mini-video-slot :deep\(video\),\s*\.call-app-workspace-mini-video-slot :deep\(canvas\),\s*\.call-app-workspace-mini-video-slot :deep\(img\),\s*\.call-app-workspace-mini-video-slot :deep\(\.workspace-static-avatar-media\)\s*\{[\s\S]*object-fit:\s*contain !important;/, 'Call App mini videos, canvases, images, and static avatars must use contain fit so participant media is not cropped');
assert.match(componentSource, /\.call-app-workspace-frame-shell\s*\{[\s\S]*position:\s*relative;[\s\S]*z-index:\s*1;[\s\S]*overflow:\s*hidden;/, 'Call App iframe frame must stay layered below the mini video strip');
assert.ok(videoFullscreenZ > callAppFullscreenZ, 'participant video fullscreen overlay must stay above Call App workspace fullscreen');
assert.doesNotMatch(componentSource, /requestFullscreen|webkitRequestFullscreen|mozRequestFullScreen|msRequestFullscreen/, 'Call App fullscreen must remain an app workspace layout state, not a browser fullscreen API path');
assert.match(componentSource, /accessNoticeState[\s\S]*no-access[\s\S]*call_apps\.crdt\.read[\s\S]*call_apps\.crdt\.append[\s\S]*read-only/s, 'Call App workspace must show explicit no-access and read-only states from launch grant capabilities');

console.log('[call-app-workspace-view-contract] PASS');
