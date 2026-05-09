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
const deploySmokePath = 'demo/video-chat/scripts/deploy-smoke.sh';
const deploySmoke = readText(deploySmokePath);
const readme = readText('README.md');

assert.match(script, /^#!\/usr\/bin\/env bash/, 'prod-debug must be a bash operator script');
assert.match(script, /mode: read-only production diagnostics/, 'prod-debug must declare its read-only mode');
assert.match(script, /no deploy, restart, DB write, DNS change, or admin action/, 'prod-debug must state forbidden production mutations');
assert.match(script, /LOCAL_ENV_FILE=.*\.env\.local/, 'prod-debug must use existing .env.local as its local source');
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

assert.match(script, /docker compose[\s\S]* ps/, 'remote probe must inspect compose container status');
assert.match(script, /\$\{COMPOSE\[@\]\}" logs --no-color --tail/, 'remote probe must collect bounded recent container logs');
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
