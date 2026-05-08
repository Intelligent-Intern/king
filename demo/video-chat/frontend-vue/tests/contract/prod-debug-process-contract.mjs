import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(root, '../../..');

async function read(relativePath) {
  return readFile(path.join(repoRoot, relativePath), 'utf8');
}

const [script, packageJsonRaw, readme] = await Promise.all([
  read('demo/video-chat/scripts/prod-debug.sh'),
  read('demo/video-chat/frontend-vue/package.json'),
  read('README.md'),
]);

const packageJson = JSON.parse(packageJsonRaw);

assert.match(script, /^#!/, 'prod-debug.sh must be directly executable as a documented process');
assert.match(script, /VIDEOCHAT_PROD_DEBUG_ENV_FILE/, 'prod-debug must support an explicit env-file override for worktree debugging');
assert.match(script, /VIDEOCHAT_PROD_DEBUG_SKIP_SSH/, 'prod-debug must allow SSH diagnostics to be skipped');
assert.match(script, /redact_stream[\s\S]*(TOKEN|SECRET|PASSWORD|AUTH|COOKIE|SESSION)/, 'prod-debug must redact secret-like output');
assert.match(script, /curl_code[\s\S]*\/health[\s\S]*\/api\/version/s, 'prod-debug must inspect public API health and version');
assert.match(script, /websocket_handshake_probe[\s\S]*DEPLOY_WS_DOMAIN[\s\S]*DEPLOY_SFU_DOMAIN/s, 'prod-debug must inspect WS and SFU reachability');
assert.match(script, /call_app[\s\S]*MOTHERNODE/s, 'prod-debug must include Call App and Mothernode domain visibility');
assert.match(script, /SQLITE3_OPEN_READONLY/, 'marketplace and Call App database checks must use a read-only SQLite open');
assert.match(script, /docker compose[\s\S]* ps[\s\S]*logs --no-color --tail/s, 'remote container diagnostics must be limited to status and recent logs');

const activeScript = script
  .split('\n')
  .filter((line) => {
    const trimmed = line.trim();
    return trimmed !== '' && !trimmed.startsWith('#') && !trimmed.startsWith('echo ') && !trimmed.startsWith('cat <<');
  })
  .join('\n');

for (const forbidden of [
  /\bcurl\b[^\n]*(?:-X|--request)\s*(?:POST|PUT|PATCH|DELETE)\b/i,
  /\bdocker\s+compose\b[^\n]*\b(?:up|down|start|stop|restart|kill|pull|build|rm|run)\b/i,
  /\bdocker\b[^\n]*\b(?:run|start|stop|restart|kill|rm|rmi|pull|build|push)\b/i,
  /\b(?:rsync|scp|certbot|hcloud|ssh-keygen)\b/i,
  /\b(?:INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|REPLACE|VACUUM|PRAGMA)\b/i,
]) {
  assert.doesNotMatch(activeScript, forbidden, `prod-debug.sh must remain read-only: ${forbidden}`);
}

assert.equal(
  packageJson.scripts?.['prod:debug'],
  '../scripts/prod-debug.sh',
  'package.json must expose the production debug command',
);
assert.equal(
  packageJson.scripts?.['test:contract:prod-debug'],
  'node tests/contract/prod-debug-process-contract.mjs',
  'package.json must expose the prod-debug contract',
);

assert.match(
  readme,
  /Production Debug Process[\s\S]*demo\/video-chat\/scripts\/prod-debug\.sh[\s\S]*read-only[\s\S]*does not deploy,\s+restart,\s+write\s+database\s+data,\s+change\s+DNS,\s+or\s+use\s+admin\s+actions/i,
  'README.md must document the read-only production debug process and safety boundary',
);

console.log('[prod-debug-process-contract] PASS');
