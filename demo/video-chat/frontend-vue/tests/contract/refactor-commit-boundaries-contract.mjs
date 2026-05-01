import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[refactor-commit-boundaries-contract] FAIL: ${message}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const realtimeRoot = path.resolve(frontendRoot, 'src/domain/realtime');
const workspaceViewPath = path.resolve(realtimeRoot, 'CallWorkspaceView.vue');
const workspaceHelpersRoot = path.resolve(realtimeRoot, 'workspace/callWorkspace');

function read(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

try {
  const workspaceView = read('src/domain/realtime/CallWorkspaceView.vue');
  const workspaceLines = workspaceView.split('\n').length;
  assert.ok(workspaceLines <= 2200, `CallWorkspaceView.vue must stay below the monolith cap (got ${workspaceLines})`);

  const requiredImports = [
    "createCallWorkspaceSocketHelpers",
    "createCallWorkspaceRouteResolutionHelpers",
    "createCallWorkspaceRuntimeSwitchingHelpers",
    "createCallWorkspaceParticipantUiHelpers",
    "createCallWorkspaceChatRuntimeHelpers",
    "createCallWorkspaceRoomStateHelpers",
    "createCallWorkspaceMediaSecurityRuntime",
    "createCallWorkspaceOrchestrationHelpers",
    "registerCallWorkspaceLifecycleHelpers",
    "createCallWorkspaceMediaStack",
    "createCallWorkspaceNativeStack",
  ];
  for (const name of requiredImports) {
    assert.ok(workspaceView.includes(name), `CallWorkspaceView.vue must import/use ${name}`);
  }

  const forbiddenMonolithFunctions = [
    'function handleSFUEncodedFrame(',
    'function createOrUpdateSfuRemotePeer(',
    'function startEncodingPipeline(',
    'function handleMediaSecuritySignal(',
    'function syncMediaSecurityWithParticipants(',
    'function checkRemoteVideoStalls(',
    'function switchMediaRuntimePath(',
  ];
  for (const signature of forbiddenMonolithFunctions) {
    assert.ok(!workspaceView.includes(signature), `CallWorkspaceView.vue must not re-monolithize ${signature}`);
  }

  const requiredHelperFiles = [
    'chatRuntime.js',
    'clientDiagnostics.js',
    'lifecycle.js',
    'mediaSecurityRuntime.js',
    'mediaStack.js',
    'moderationSync.js',
    'nativeStack.js',
    'orchestration.js',
    'participantUi.js',
    'roomState.js',
    'routeResolution.js',
    'runtimeHealth.js',
    'runtimeSwitching.js',
    'sfuTransport.js',
    'socketLifecycle.js',
    'videoLayout.js',
  ];
  for (const fileName of requiredHelperFiles) {
    assert.ok(fs.existsSync(path.resolve(workspaceHelpersRoot, fileName)), `workspace helper file missing: ${fileName}`);
  }

  assert.ok(!workspaceView.includes('moderationSyncQueue'), 'CallWorkspaceView.vue must not own moderation sync queues');
  assert.ok(!workspaceView.includes('let moderationSyncTimer'), 'CallWorkspaceView.vue must not own moderation sync timers');

  const participantUi = read('src/domain/realtime/workspace/callWorkspace/participantUi.js');
  assert.ok(participantUi.includes("import { createCallWorkspaceModerationSync } from './moderationSync';"), 'participant UI must delegate moderation sync queue handling');
  assert.ok(participantUi.includes('consumeQueuedModerationSyncEntries,'), 'participant UI must expose consumeQueuedModerationSyncEntries for lifecycle cleanup');

  const helperFiles = fs.readdirSync(workspaceHelpersRoot).filter((name) => name.endsWith('.js') && !name.endsWith('.extracted.js'));
  assert.ok(helperFiles.length >= 12, `workspace helper surface must remain modular (got ${helperFiles.length} helper modules)`);

  assert.ok(fs.existsSync(workspaceViewPath), 'CallWorkspaceView.vue must exist');
  process.stdout.write('[refactor-commit-boundaries-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
