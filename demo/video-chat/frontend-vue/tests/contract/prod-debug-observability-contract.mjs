import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const videoChatRoot = path.resolve(frontendRoot, '..');
const repoRoot = path.resolve(videoChatRoot, '../..');

function readText(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

const scriptPath = 'demo/video-chat/scripts/prod-debug.sh';
const script = readText(scriptPath);
const readme = readText('README.md');

assert.match(script, /^#!\/usr\/bin\/env bash/, 'prod-debug must be a bash operator script');
assert.match(script, /mode: read-only production diagnostics/, 'prod-debug must declare its read-only mode');
assert.match(script, /no deploy, restart, DB write, DNS change, or admin action/, 'prod-debug must state forbidden production mutations');
assert.match(script, /LOCAL_ENV_FILE=.*\.env\.local/, 'prod-debug must use existing .env.local as its local source');
assert.match(script, /redact_stream\(\)/, 'prod-debug must redact output');
assert.match(script, /TOKEN\|SECRET\|PASSWORD\|PASS\|KEY\|CREDENTIAL\|COOKIE\|SESSION/, 'prod-debug redaction must cover token/password-like values');
assert.match(script, /REDACTED_MEDIA_PAYLOAD/, 'prod-debug must redact media payload-like fields before printing logs');
assert.match(script, /VIDEOCHAT_PROD_DEBUG_DRY_RUN/, 'prod-debug must expose a dry-run path for local proof without network or SSH');

for (const endpoint of [
  '/api/runtime',
  '/api/version',
  '/api/marketplace/call-apps',
]) {
  assert.ok(script.includes(endpoint), `prod-debug must inspect ${endpoint}`);
}

for (const label of [
  'lobby websocket',
  'sfu websocket',
  'marketplace apps',
  'call-app host',
  'filtered recent logs',
  'media reconnect',
  'screen-share reconnect exhaustion',
  'stale local media capture discard',
  'audio/video track loss',
  'SFU reconnect and websocket transport',
  'Call App frame and CSP errors',
]) {
  assert.ok(script.includes(label), `prod-debug must include ${label}`);
}

assert.match(script, /docker compose[\s\S]* ps/, 'remote probe must inspect compose container status');
assert.match(script, /\$\{COMPOSE\[@\]\}" logs --no-color --tail/, 'remote probe must collect bounded recent container logs');
assert.match(script, /filter_recent_logs\(\)/, 'remote log filtering must label each investigation category');
assert.match(script, /stale_local_media_capture_discarded/, 'remote log scan must include stale local media capture discard diagnostics');
assert.match(script, /local_screen_share_sfu_reconnect_exhausted/, 'remote log scan must include screen-share SFU reconnect exhaustion diagnostics');
assert.match(script, /\(audio\|video\).*track/, 'remote log scan must include audio/video track-loss terms');
assert.match(script, /Content-Security-Policy\|Allow-CSP-From\|frame-ancestors\|postMessage/, 'remote log scan must include Call App frame and CSP diagnostics');

const forbiddenPatterns = [
  /\bcurl\b[^\n]*\s-X\s*(POST|PUT|PATCH|DELETE)\b/i,
  /\bdocker\s+compose\b[^\n]*(\bup\b|\bdown\b|\brestart\b|\brm\b|\bkill\b|\bpull\b|\bpush\b|\bexec\b)/i,
  /\bdocker\b[^\n]*(\brun\b|\brestart\b|\brm\b|\bkill\b|\bpull\b|\bpush\b|\bexec\b)/i,
  /\b(rsync|scp)\b/,
  /\b(certbot|hcloud|terraform|kubectl)\b/,
  /\bdeploy\.sh\b/,
  /\bsqlite3\b[^\n]*(INSERT|UPDATE|DELETE|REPLACE|DROP|CREATE|ALTER|VACUUM)/i,
];

for (const pattern of forbiddenPatterns) {
  assert.doesNotMatch(script, pattern, `prod-debug must remain read-only; forbidden pattern ${pattern}`);
}

assert.match(
  readme,
  /demo\/video-chat\/scripts\/prod-debug\.sh/,
  'README must expose the production debug command',
);
assert.match(
  readme,
  /read-only[\s\S]*media reconnect[\s\S]*screen-share[\s\S]*stale local media capture[\s\S]*audio\/video track loss[\s\S]*SFU reconnect[\s\S]*Call App frame\/CSP[\s\S]*prod-debug\.sh[\s\S]*does not deploy,\s*restart,\s*write DB data,\s*change DNS,\s*or use admin actions/i,
  'README must document prod-debug as read-only and non-mutating',
);

process.stdout.write('[prod-debug-observability-contract] PASS\n');
