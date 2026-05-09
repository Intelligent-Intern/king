import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
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

function extractBashFunction(source, name) {
  const match = source.match(new RegExp(`^${name}\\(\\) \\{[\\s\\S]*?^\\}`, 'm'));
  assert.ok(match, `${name} must be defined`);
  return match[0];
}

function runBash(input) {
  const run = spawnSync('bash', ['-s'], { input, encoding: 'utf8' });
  assert.equal(run.status, 0, run.stderr);
  return run.stdout;
}

const scriptPath = 'demo/video-chat/scripts/prod-debug.sh';
const script = readText(scriptPath);
const deploySmokePath = 'demo/video-chat/scripts/deploy-smoke.sh';
const deploySmoke = readText(deploySmokePath);
const readme = readText('README.md');

assert.match(script, /^#!\/usr\/bin\/env bash/, 'prod-debug must be a bash operator script');
assert.match(script, /mode: read-only production diagnostics/, 'prod-debug must declare its read-only mode');
assert.match(script, /no deploy, restart, DB write, DNS change, or admin action/, 'prod-debug must state forbidden production mutations');
assert.match(script, /LOCAL_ENV_FILE=.*\.env\.local/, 'prod-debug must use existing .env.local as its local env file');
assert.doesNotMatch(script, /^\s*(?:source|\.)\s+["']?\$\{LOCAL_ENV_FILE\}["']?/m, 'prod-debug must not source or dot-load .env.local');
assert.doesNotMatch(script, /^\s*set\s+-a\b/m, 'prod-debug must not auto-export by sourcing .env.local');
assert.doesNotMatch(script, /\beval\b/, 'prod-debug must not evaluate .env.local contents');
assert.match(script, /parse_local_env_value\(\)[\s\S]*while IFS= read -r line[\s\S]*allowed_env_names/, 'prod-debug must parse .env.local inertly through an allowlist');
assert.match(script, /VIDEOCHAT_DEPLOY_DOMAIN DEPLOY_DOMAIN[\s\S]*VIDEOCHAT_DEPLOY_SSH_KEY[\s\S]*VIDEOCHAT_PROD_DEBUG_DRY_RUN VIDEOCHAT_PROD_DEBUG_SKIP_REMOTE/, 'prod-debug .env.local allowlist must stay scoped to deploy/debug diagnostics names');
assert.doesNotMatch(script, /VIDEOCHAT_DEPLOY_ADMIN_PASSWORD|VIDEOCHAT_DEPLOY_TURN_SECRET|VIDEOCHAT_DEPLOY_HCLOUD_TOKEN/, 'prod-debug .env.local parser must not import deploy secrets or provider tokens');
assert.match(script, /redact_stream\(\)/, 'prod-debug must redact output');
assert.match(script, /TOKEN\|SECRET\|PASSWORD\|PASS\|KEY\|CREDENTIAL\|COOKIE\|SESSION/, 'prod-debug redaction must cover token/password-like values');
assert.match(script, /REDACTED_MEDIA_PAYLOAD/, 'prod-debug must redact media payload-like fields before printing logs');
assert.match(script, /VIDEOCHAT_PROD_DEBUG_DRY_RUN/, 'prod-debug must expose a dry-run path for local proof without network or SSH');
assert.match(script, /DEPLOY_TURN_DOMAIN/, 'prod-debug must include the TURN service domain in production diagnostics');
assert.match(script, /assert_domain_contract\(\)/, 'prod-debug must validate the production domain contract before probing');
assert.match(script, /no nested \.app\.kingrt\.com domains/, 'prod-debug must reject nested app-domain service origins');
assert.match(
  script,
  /app\/api\/ws\/sfu\/cdn\/turn\/registry\/whiteboard rooted at \$\{DEPLOY_DOMAIN\}/,
  'prod-debug must report the app/api/ws/sfu/cdn/turn/registry/whiteboard domain set',
);

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
  'BGF-07 browser proof: background init',
  'BGF-07 browser proof: matte rejected',
  'BGF-07 browser proof: replacement unavailable',
  'BGF-07 browser proof: modal choice',
  'BGF-07 browser proof: media/screen reconnect',
  'media reconnect',
  'screen-share reconnect exhaustion',
  'stale local media capture discard',
  'background fallback transitions',
  'audio/video track loss',
  'SFU reconnect and websocket transport',
  'Call App frame and CSP errors',
  'cdn mediapipe model',
  'cdn tasks vision',
  'cdn tasks wasm',
  'background modal icon',
  'background avatar asset',
]) {
  assert.ok(script.includes(label), `prod-debug must include ${label}`);
}

assert.match(
  script,
  /Content-Security-Policy[\s\S]*Allow-CSP-From[\s\S]*X-Frame-Options[\s\S]*nested \*\.\$\{DEPLOY_APP_DOMAIN\} service origins/,
  'prod-debug must prove Whiteboard Call App CSP, Embedded-CSP, frame-option absence, and nested-origin absence',
);

const parserFunctions = [
  extractBashFunction(script, 'trim_env_value'),
  extractBashFunction(script, 'parse_local_env_value'),
  extractBashFunction(script, 'local_env_group'),
  extractBashFunction(script, 'local_env_service_domain_group'),
  extractBashFunction(script, 'load_local_env'),
].join('\n');
const parserTempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'prod-debug-env-'));
const parserEnvPath = path.join(parserTempDir, '.env.local');
const parserMarkerPath = path.join(parserTempDir, 'should-not-exist');
fs.writeFileSync(parserEnvPath, [
  '# ignored comment',
  'VIDEOCHAT_DEPLOY_DOMAIN=local.test',
  'export VIDEOCHAT_DEPLOY_APP_DOMAIN="app.local.test"',
  "DEPLOY_API_DOMAIN='api.local.test'",
  'VIDEOCHAT_PROD_DEBUG_DRY_RUN=1',
  `VIDEOCHAT_DEPLOY_SSH_KEY="$(touch ${parserMarkerPath})"`,
  'VIDEOCHAT_DEPLOY_ADMIN_PASSWORD=should-not-load',
].join('\n'));
const parserOut = runBash(`${parserFunctions}
LOCAL_ENV_FILE=${JSON.stringify(parserEnvPath)}
load_local_env
printf 'domain=%s\n' "\${VIDEOCHAT_DEPLOY_DOMAIN-unset}"
printf 'app=%s\n' "\${VIDEOCHAT_DEPLOY_APP_DOMAIN-unset}"
printf 'api=%s\n' "\${DEPLOY_API_DOMAIN-unset}"
printf 'dry_run=%s\n' "\${VIDEOCHAT_PROD_DEBUG_DRY_RUN-unset}"
printf 'ssh_key=%s\n' "\${VIDEOCHAT_DEPLOY_SSH_KEY-unset}"
printf 'admin=%s\n' "\${VIDEOCHAT_DEPLOY_ADMIN_PASSWORD-unset}"
`);
assert.match(parserOut, /domain=local\.test/, 'prod-debug parser must load simple KEY=VALUE entries');
assert.match(parserOut, /app=app\.local\.test/, 'prod-debug parser must load double-quoted values');
assert.match(parserOut, /api=api\.local\.test/, 'prod-debug parser must load single-quoted values');
assert.match(parserOut, /dry_run=1/, 'prod-debug parser must load whitelisted debug flags');
assert.match(parserOut, /\$\(touch /, 'prod-debug parser must keep command substitutions inert as literal text');
assert.match(parserOut, /admin=unset/, 'prod-debug parser must ignore non-allowlisted deploy secrets');
assert.equal(fs.existsSync(parserMarkerPath), false, 'prod-debug parser must not execute .env.local command substitutions');
const parserOverrideOut = runBash(`${parserFunctions}
LOCAL_ENV_FILE=${JSON.stringify(parserEnvPath)}
export DEPLOY_DOMAIN=explicit.test
load_local_env
printf 'deploy_domain=%s\n' "\${DEPLOY_DOMAIN-unset}"
printf 'videochat_domain=%s\n' "\${VIDEOCHAT_DEPLOY_DOMAIN-unset}"
printf 'app=%s\n' "\${VIDEOCHAT_DEPLOY_APP_DOMAIN-unset}"
printf 'api=%s\n' "\${DEPLOY_API_DOMAIN-unset}"
`);
assert.match(parserOverrideOut, /deploy_domain=explicit\.test/, 'prod-debug parser must preserve explicit root domain overrides');
assert.match(parserOverrideOut, /videochat_domain=unset/, 'prod-debug parser must not let .env.local root aliases override explicit root domains');
assert.match(parserOverrideOut, /app=unset/, 'prod-debug parser must not mix .env.local service domains under an explicit root domain');
assert.match(parserOverrideOut, /api=unset/, 'prod-debug parser must not mix .env.local service aliases under an explicit root domain');
fs.rmSync(parserTempDir, { recursive: true, force: true });

const redactFunction = extractBashFunction(script, 'redact_stream');
const redactSample = [
  'authorization: bearer raw.jwt.value',
  'SESSION_TOKEN=raw-session-secret',
  '{"frame_data":"raw-frame-bytes","token":"raw-json-token"}',
  'https://example.test/call?session=raw-query-session&ok=1',
  'data:image/png;base64,rawbase64payload',
].join('\n');
const redactOut = runBash(`${redactFunction}\nredact_stream <<'EOF'\n${redactSample}\nEOF\n`);
assert.doesNotMatch(redactOut, /raw\.jwt\.value|raw-session-secret|raw-frame-bytes|raw-json-token|raw-query-session|rawbase64payload/, 'redact_stream must remove sample secrets and media payloads');
assert.match(redactOut, /\[REDACTED\]/, 'redact_stream must emit secret redaction markers');
assert.match(redactOut, /\[REDACTED_MEDIA_PAYLOAD\]/, 'redact_stream must emit media redaction markers');

const callAppCspDiagnostics = [
  extractBashFunction(script, 'dump_call_app_headers'),
  extractBashFunction(script, 'dump_call_app_body_sample'),
  extractBashFunction(script, 'call_app_frame_header_probe'),
].join('\n');
assert.match(callAppCspDiagnostics, /redact_stream < "\$\{headers\}" >&2 \|\| true/, 'Call-App CSP header dumps must redact before stderr');
assert.match(callAppCspDiagnostics, /head -c 2000 "\$\{body\}" \| redact_stream >&2 \|\| true/, 'Call-App CSP body dumps must redact before stderr');
assert.match(callAppCspDiagnostics, /grep -Eia "\$\{nested_pattern\}" "\$\{headers\}" "\$\{body\}" \| redact_stream >&2 \|\| true/, 'Call-App CSP nested-origin matches must redact before stderr');
for (const line of callAppCspDiagnostics.split('\n')) {
  if (line.includes('>&2') && (line.includes('${headers}') || line.includes('${body}') || line.includes('${nested_pattern}'))) {
    assert.match(line, /redact_stream/, `Call-App CSP stderr dump must use redact_stream: ${line.trim()}`);
  }
}
assert.doesNotMatch(callAppCspDiagnostics, /\bcat\s+"\$\{headers\}"\s+>&2/, 'Call-App CSP failures must not cat raw headers to stderr');
assert.doesNotMatch(callAppCspDiagnostics, /\bhead\s+-c\s+2000\s+"\$\{body\}"\s+>&2/, 'Call-App CSP failures must not head raw body bytes to stderr');
assert.doesNotMatch(callAppCspDiagnostics, /\bgrep\s+-Eia\s+"\$\{nested_pattern\}"\s+"\$\{headers\}"\s+"\$\{body\}"\s+>&2/, 'Call-App CSP failures must not grep raw nested matches to stderr');

assert.match(script, /docker compose[\s\S]* ps/, 'remote probe must inspect compose container status');
assert.match(script, /\$\{COMPOSE\[@\]\}" logs --no-color --tail/, 'remote probe must collect bounded recent container logs');
assert.doesNotMatch(script, /--env-file\s+\.env\.local/, 'remote read-only compose diagnostics must not consume full .env.local');
assert.match(script, /SANITIZED_ENV_FILE="\\\$\(mktemp\)"/, 'remote read-only compose diagnostics must use a sanitized temporary env file');
assert.match(script, /\*TOKEN\*\|\*SECRET\*\|\*PASSWORD\*\|\*PASS\*\|\*KEY\*\|\*CREDENTIAL\*\|\*COOKIE\*\|\*SESSION\*\|\*HCLOUD\*/, 'remote sanitized compose env must exclude secrets and provider tokens');
assert.match(script, /COMPOSE=\(docker compose --env-file \.env --env-file "\\\$\{SANITIZED_ENV_FILE\}"/, 'remote compose must use sanitized env allowlist after base .env');
assert.match(script, /filter_recent_logs\(\)/, 'remote log filtering must label each investigation category');
assert.match(script, /stale_local_media_capture_discarded/, 'remote log scan must include stale local media capture discard diagnostics');
assert.match(script, /local_background_\(backend_init\|matte_rejected\|replacement_unavailable\|replacement_modal_choice\)/, 'remote log scan must include BGF fallback transition event types');
assert.match(script, /BGF-07 browser proof: background init[\s\S]*local_background_backend_init[\s\S]*background_backend_init_failed[\s\S]*segmentation_backend_init_failed/, 'remote log scan must expose a read-only background-init browser proof bucket');
assert.match(script, /BGF-07 browser proof: matte rejected[\s\S]*local_background_matte_rejected[\s\S]*background_matte_rejected[\s\S]*production_category_mask_unavailable/, 'remote log scan must expose a read-only matte-rejected browser proof bucket');
assert.match(script, /BGF-07 browser proof: replacement unavailable[\s\S]*local_background_replacement_unavailable[\s\S]*background_replacement_requires_user_choice[\s\S]*fallback_reason/, 'remote log scan must expose a read-only replacement-unavailable browser proof bucket');
assert.match(script, /BGF-07 browser proof: modal choice[\s\S]*local_background_replacement_modal_choice[\s\S]*background_modal_choice[\s\S]*user_choice_required/, 'remote log scan must expose a read-only modal-choice browser proof bucket');
assert.match(script, /BGF-07 browser proof: media\/screen reconnect[\s\S]*media\[_ -\]\?reconnect[\s\S]*screen\[_ -\]\?share[\s\S]*local_screen_share_sfu_reconnect/, 'remote log scan must expose a read-only media/screen reconnect browser proof bucket');
assert.match(script, /failed_backend\|fallback_reason\|user_choice_required/, 'remote log scan must include BGF fallback diagnostic payload fields');
assert.match(script, /production_category_mask_unavailable\|worker_segment_errors_repeated/, 'remote log scan must include BGF terminal worker failure reasons');
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

assert.match(deploySmoke, /^#!\/usr\/bin\/env bash/, 'deploy-smoke must be a bash operator script');
assert.match(deploySmoke, /assert_domain_contract\(\)/, 'deploy-smoke must validate production domains before smoke probes');
assert.match(deploySmoke, /expect_dns_resolves\(\)/, 'deploy-smoke must DNS-check production service domains');
assert.match(
  deploySmoke,
  /app:DEPLOY_APP_DOMAIN[\s\S]*api:DEPLOY_API_DOMAIN[\s\S]*ws:DEPLOY_WS_DOMAIN[\s\S]*sfu:DEPLOY_SFU_DOMAIN[\s\S]*turn:DEPLOY_TURN_DOMAIN[\s\S]*cdn:DEPLOY_CDN_DOMAIN[\s\S]*registry:DEPLOY_REGISTRY_DOMAIN[\s\S]*whiteboard:DEPLOY_CALL_APP_DOMAIN/,
  'deploy-smoke must cover app/api/ws/sfu/cdn/turn/registry/whiteboard domains',
);
assert.match(deploySmoke, /no nested \.app\.kingrt\.com domains/, 'deploy-smoke must reject nested app-domain service origins');
assert.match(
  deploySmoke,
  /kingrt\.com[\s\S]*app\.kingrt\.com[\s\S]*api\.kingrt\.com[\s\S]*ws\.kingrt\.com[\s\S]*sfu\.kingrt\.com[\s\S]*turn\.kingrt\.com[\s\S]*cdn\.kingrt\.com[\s\S]*registry\.kingrt\.com[\s\S]*whiteboard\.kingrt\.com/,
  'deploy-smoke must pin exact production KingRT service domains when the root is kingrt.com',
);

for (const smokeLabel of [
  'cdn-mediapipe-tasks-model',
  'cdn-mediapipe-tasks-vision',
  'cdn-mediapipe-tasks-wasm-loader',
  'cdn-mediapipe-wasm-loader',
  'background-modal-icon',
  'background-avatar-placeholder',
  'call-app-whiteboard-host',
  'call-app-whiteboard-path',
  'registry-host',
  'lobby websocket host',
  'sfu websocket host',
]) {
  assert.ok(deploySmoke.includes(smokeLabel), `deploy-smoke must include ${smokeLabel}`);
}

assert.match(
  deploySmoke,
  /BGF-07 proof commands:[^\n]*npm run test:predeploy:background[^\n]*prod-debug-observability-contract\.mjs[^\n]*npm run build[^\n]*demo\/video-chat\/scripts\/deploy\.sh[^\n]*routine post-deploy diagnostics[^\n]*VIDEOCHAT_PROD_DEBUG_SKIP_REMOTE=1[^\n]*optional domain\/certificate smoke[^\n]*DNS\/certbot validation is explicitly required[^\n]*Chrome\/Chromium[^\n]*Firefox[^\n]*background-unavailable modal[^\n]*background fallback transition logs/,
  'deploy-smoke must record focused background contract, build, deploy, routine prod debug, optional domain/certificate smoke, and browser-smoke proof commands',
);

const deploySmokeWithoutProofLog = deploySmoke.replace(/log "BGF-07 proof commands:[^\n]+"/, '');
assert.doesNotMatch(
  deploySmokeWithoutProofLog,
  /\bdemo\/video-chat\/scripts\/deploy\.sh\b|\bscripts\/deploy\.sh\b/,
  'deploy-smoke may record the deploy command but must not invoke deploy.sh',
);

assert.match(
  readme,
  /demo\/video-chat\/scripts\/prod-debug\.sh/,
  'README must expose the production debug command',
);
assert.match(
  readme,
  /read-only[\s\S]*BGF-07 browser proof buckets[\s\S]*background init[\s\S]*matte rejected[\s\S]*replacement unavailable[\s\S]*modal choice[\s\S]*media\/screen reconnect[\s\S]*stale local media capture[\s\S]*audio\/video track\s+loss[\s\S]*SFU reconnect[\s\S]*Call App frame\/CSP[\s\S]*do not deploy,\s*restart,\s*write DB data,\s*change DNS,\s*or use admin actions/i,
  'README must document BGF-07 prod-debug buckets as read-only and non-mutating',
);

assert.match(
  readme,
  /Whiteboard Call App CSP\/`Allow-CSP-From` frame headers[\s\S]*\/public\/index\.html[\s\S]*\/call-app\/whiteboard\/public\/index\.html[\s\S]*absence[\s\S]*of `X-Frame-Options`[\s\S]*absence of nested `\*\.app\.kingrt\.com` service origins/s,
  'README must document the read-only Call App frame-header proof',
);

process.stdout.write('[prod-debug-observability-contract] PASS\n');
