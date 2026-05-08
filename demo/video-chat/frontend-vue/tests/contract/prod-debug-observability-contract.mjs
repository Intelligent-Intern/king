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

for (const endpoint of [
  '/api/runtime',
  '/api/version',
  '/api/marketplace/call-apps',
  '/public/index.html',
  '/call-app/whiteboard/public/index.html',
]) {
  assert.ok(script.includes(endpoint), `prod-debug must inspect ${endpoint}`);
}

for (const label of [
  'lobby websocket',
  'sfu websocket',
  'marketplace apps',
  'call-app host',
  'Call-App CSP Header Proof',
  'call-app whiteboard host CSP',
  'call-app whiteboard path CSP',
  'filtered recent logs',
]) {
  assert.ok(script.includes(label), `prod-debug must include ${label}`);
}

assert.match(
  script,
  /Content-Security-Policy[\s\S]*Allow-CSP-From[\s\S]*X-Frame-Options[\s\S]*nested \*\.\$\{DEPLOY_APP_DOMAIN\} service origins/,
  'prod-debug must prove Whiteboard Call App CSP, Embedded-CSP, frame-option absence, and nested-origin absence',
);

assert.match(script, /docker compose[\s\S]* ps/, 'remote probe must inspect compose container status');
assert.match(script, /docker compose[\s\S]* logs --no-color --tail/, 'remote probe must collect bounded recent container logs');
assert.match(script, /grep -Eai 'call\|reconnect\|media\|sfu/, 'remote log scan must include call health, reconnect, media, and SFU terms');
assert.match(script, /call\[_ -\]\?app\|marketplace\|whiteboard/, 'remote log scan must include call-app status terms');

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
  /read-only[\s\S]*prod-debug\.sh[\s\S]*does not deploy,\s*restart,\s*write DB data,\s*change DNS,\s*or use admin actions/i,
  'README must document prod-debug as read-only and non-mutating',
);

assert.match(
  readme,
  /Whiteboard Call App CSP\/`Allow-CSP-From` frame headers[\s\S]*\/public\/index\.html[\s\S]*\/call-app\/whiteboard\/public\/index\.html[\s\S]*absence[\s\S]*of `X-Frame-Options`[\s\S]*absence of nested `\*\.app\.kingrt\.com` service origins/s,
  'README must document the read-only Call App frame-header proof',
);

process.stdout.write('[prod-debug-observability-contract] PASS\n');
