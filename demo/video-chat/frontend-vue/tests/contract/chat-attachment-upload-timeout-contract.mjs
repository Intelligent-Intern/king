import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');

function read(relPath) {
  return fs.readFileSync(path.join(frontendRoot, relPath), 'utf8');
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `[chat-attachment-upload-timeout-contract] missing ${label}`);
}

const backendFetch = read('src/support/backendFetch.js');
const workspaceApi = read('src/domain/realtime/workspace/api.js');
const workspaceView = read('src/domain/realtime/CallWorkspaceView.vue');

requireContains(backendFetch, 'controller.abort(buildBackendTimeoutError(timeoutMs))', 'timeout abort reason');
requireContains(backendFetch, "error.name = 'TimeoutError';", 'named timeout error');
requireContains(workspaceApi, 'timeoutMs = undefined', 'apiRequest timeout option');
requireContains(workspaceApi, 'timeoutMs,', 'apiRequest forwards timeout to fetchBackend');
requireContains(workspaceView, 'const CHAT_ATTACHMENT_UPLOAD_TIMEOUT_MIN_MS = 30_000;', 'attachment upload timeout lower bound');
requireContains(workspaceView, 'const timeoutMs = chatAttachmentUploadTimeoutMs(draft);', 'attachment upload computes custom timeout');
requireContains(workspaceView, 'timeoutMs,', 'attachment upload passes custom timeout');
requireContains(workspaceView, 'Chat attachment upload timed out after', 'attachment timeout user message');

process.stdout.write('[chat-attachment-upload-timeout-contract] PASS\n');
