import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[foreground-reconnect-contract] FAIL: ${message}`);
}

function read(root, relativePath) {
  return fs.readFileSync(path.join(root, relativePath), 'utf8');
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const root = path.resolve(__dirname, '../..');

try {
  const helper = read(root, 'src/support/foregroundReconnect.js');
  assert.match(helper, /window\.addEventListener\('blur', handleBackground\)/, 'foreground helper must track blur');
  assert.match(helper, /window\.addEventListener\('focus', handleForeground\)/, 'foreground helper must track focus');
  assert.match(helper, /window\.addEventListener\('pageshow', handleForeground\)/, 'foreground helper must track pageshow');
  assert.match(helper, /window\.addEventListener\('online', handleForeground\)/, 'foreground helper must track online');
  assert.match(helper, /document\.addEventListener\('visibilitychange', handleVisibilityChange\)/, 'foreground helper must track visibility changes');

  const joinView = read(root, 'src/domain/calls/access/JoinView.vue');
  assert.match(joinView, /attachForegroundReconnectHandlers/, 'call access join view must use foreground reconnect helper');
  assert.match(joinView, /function reconnectAdmissionAfterForeground\(\)/, 'call access join view must define foreground reconnect');
  assert.match(joinView, /admissionReconnectAfterForeground = true;/, 'call access join view must mark reconnect pending');
  assert.match(joinView, /connectAdmissionSocket\(accessId\)/, 'call access join view must reconnect the admission socket');

  const dashboard = read(root, 'src/domain/calls/dashboard/UserDashboardView.vue');
  assert.match(dashboard, /attachForegroundReconnectHandlers/, 'user dashboard must use foreground reconnect helper');
  assert.match(dashboard, /function reconnectEnterAdmissionAfterForeground\(\)/, 'user dashboard must define modal foreground reconnect');
  assert.match(dashboard, /enterAdmissionReconnectAfterForeground = true;/, 'user dashboard must mark reconnect pending');
  assert.match(dashboard, /connectEnterAdmissionSocket\(\)/, 'user dashboard must reconnect the enter-call admission socket');

  const workspace = read(root, 'src/domain/realtime/CallWorkspaceView.vue');
  assert.match(workspace, /attachForegroundReconnectHandlers/, 'workspace must use foreground reconnect helper');
  assert.match(workspace, /function reconnectWorkspaceAfterForeground\(\)/, 'workspace must define foreground reconnect');
  assert.match(workspace, /workspaceReconnectAfterForeground = true;/, 'workspace must mark reconnect pending');
  assert.match(workspace, /if \(!hasLiveLocalMedia\(\) && \(controlState\.cameraEnabled !== false \|\| controlState\.micEnabled !== false\)\) \{\s*void publishLocalTracks\(\);/m, 'workspace foreground reconnect must reacquire local media when preview/tracks are gone');
  assert.match(workspace, /void connectSocket\(\);/, 'workspace foreground reconnect must reconnect the realtime socket');
  assert.match(workspace, /sfuClientRef\.value\.leave\(\);/, 'workspace foreground reconnect must recycle stale SFU state');
  assert.match(workspace, /await publishLocalTracks\(\);\s*\n\s*if \(shouldConnectSfu\.value && sessionState\.sessionToken && sessionState\.userId\) \{\s*\n\s*initSFU\(\);/m, 'workspace mount must start local media before SFU connect');

  process.stdout.write('[foreground-reconnect-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
