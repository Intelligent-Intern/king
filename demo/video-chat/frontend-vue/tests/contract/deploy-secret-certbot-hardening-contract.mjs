import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const frontendRoot = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(frontendRoot, '../../..');

async function read(relativePath) {
  return readFile(path.join(repoRoot, relativePath), 'utf8');
}

function assertOrder(source, markers, message) {
  let cursor = -1;
  for (const marker of markers) {
    const index = source.indexOf(marker, cursor + 1);
    assert.notEqual(index, -1, `${message}: missing ${marker}`);
    assert.ok(index > cursor, `${message}: ${marker} is out of order`);
    cursor = index;
  }
}

const [deploy, idempotency] = await Promise.all([
  read('demo/video-chat/scripts/deploy.sh'),
  read('demo/video-chat/scripts/check-deploy-idempotency.sh'),
]);

const certbotStart = deploy.indexOf('certbot_standalone() {');
const runtimeStart = deploy.indexOf('write_remote_runtime_files() {');
assert.notEqual(certbotStart, -1, 'deploy script must define certbot_standalone');
assert.notEqual(runtimeStart, -1, 'deploy script must define write_remote_runtime_files');
assert.ok(runtimeStart > certbotStart, 'runtime writer should remain after certbot helper definition');
const certbotBlock = deploy.slice(certbotStart, runtimeStart);

assert.match(
  deploy,
  /--exclude 'demo\/video-chat\/secrets\/'/,
  'rsync --delete must preserve remote runtime secrets until the writer can refresh them',
);

const prepareMatch = deploy.match(/prepare\(\) \{\n([\s\S]*?)\n\}/);
assert.ok(prepareMatch, 'deploy script must define prepare()');
assertOrder(
  prepareMatch[1],
  ['bootstrap_remote', 'sync_checkout', 'write_remote_runtime_files', 'certbot_standalone', 'sync_remote_secrets_to_local'],
  'prepare must write remote secrets before certbot can stop or restore services',
);

assert.match(
  deploy,
  /certonly\)[\s\S]*bootstrap_remote[\s\S]*write_remote_runtime_files[\s\S]*certbot_standalone/s,
  'certonly must also refresh remote runtime files before certbot can stop compose services',
);

assertOrder(
  certbotBlock,
  ['trap restore_certbot_stopped_services EXIT', 'stop videochat-edge-v1'],
  'certbot trap must be installed before the edge service can be stopped',
);
assert.match(
  certbotBlock,
  /if \[ "\\\$\{EDGE_WAS_RUNNING\}" = "1" \]; then[\s\S]*up -d --no-deps videochat-edge-v1/s,
  'certbot trap must restart the edge service when it was running before the challenge',
);
assert.match(
  certbotBlock,
  /EDGE_WAS_RUNNING=1[\s\S]*stop videochat-edge-v1/s,
  'certbot helper must remember that the edge service was running before stopping it',
);
assert.match(
  certbotBlock,
  /if \[ "\\\$\{FRONTEND_WAS_RUNNING\}" = "1" \]; then[\s\S]*up -d --no-deps videochat-frontend-v1/s,
  'certbot trap must restart the frontend service when it was running before the challenge',
);
assert.match(
  certbotBlock,
  /FRONTEND_WAS_RUNNING=1[\s\S]*stop videochat-frontend-v1/s,
  'certbot helper must remember that the frontend service was running before stopping it',
);

for (const marker of [
  'existing_certificate_has_required_sans',
  'openssl x509 -checkend 0',
  '-noout -ext subjectAltName',
  'grep -Fx "DNS:\\${required_domain}"',
  'certbot_status=0',
  'certbot_status=\\$?',
  'certbot failed, but existing certificate is still valid for all required SANs; continuing deploy.',
]) {
  assert.ok(certbotBlock.includes(marker), `certbot fallback must include ${marker}`);
  assert.ok(idempotency.includes(marker), `deploy idempotency guard must pin ${marker}`);
}

assert.match(
  certbotBlock,
  /if \[ "\\\$\{certbot_status\}" -ne 0 \]; then[\s\S]*if existing_certificate_has_required_sans; then[\s\S]*continuing deploy\.[\s\S]*else[\s\S]*exit "\\\$\{certbot_status\}"/s,
  'certbot failure must continue only with an existing valid SAN certificate and otherwise fail through the restore trap',
);

console.log('[deploy-secret-certbot-hardening-contract] PASS');
