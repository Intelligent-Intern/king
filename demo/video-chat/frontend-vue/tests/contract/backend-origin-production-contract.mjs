import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[backend-origin-production-contract] FAIL: ${message}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const videoChatRoot = path.resolve(frontendRoot, '..');

function read(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

function readVideoChat(relativePath) {
  return fs.readFileSync(path.resolve(videoChatRoot, relativePath), 'utf8');
}

function loadBackendOrigin(runtimeEnv = {}, windowValue = undefined) {
  const source = read('src/support/backendOrigin.ts')
    .replaceAll('import.meta.env', 'runtimeEnv')
    .replace(/\bexport\s+function\s+/g, 'function ');
  return new Function(
    'runtimeEnv',
    'window',
    `${source}; return { resolveBackendOrigin, resolveBackendOriginCandidates };`,
  )(runtimeEnv, windowValue);
}

try {
  const productionBackend = loadBackendOrigin({}, {
    location: {
      protocol: 'https:',
      hostname: 'app.kingrt.com',
    },
  });
  assert.equal(
    productionBackend.resolveBackendOrigin(),
    'https://api.app.kingrt.com',
    'production app host must use the public API origin, not the frontend origin',
  );

  const explicitBackend = loadBackendOrigin({ VITE_VIDEOCHAT_BACKEND_ORIGIN: 'https://custom-api.example.test' }, {
    location: {
      protocol: 'https:',
      hostname: 'app.kingrt.com',
    },
  });
  assert.equal(
    explicitBackend.resolveBackendOrigin(),
    'https://custom-api.example.test',
    'explicit backend origin config must override production host inference',
  );

  const localBackend = loadBackendOrigin({}, {
    location: {
      protocol: 'http:',
      hostname: '127.0.0.1',
    },
  });
  assert.deepEqual(
    localBackend.resolveBackendOriginCandidates(),
    ['http://127.0.0.1:18080', 'http://localhost:18080'],
    'local development must keep the loopback backend port fallback',
  );

  const deployScript = readVideoChat('scripts/deploy.sh');
  assert.ok(
    deployScript.includes('VIDEOCHAT_V1_BACKEND_ORIGIN=https://\\${API_DOMAIN}'),
    'generated production env must build the frontend against the API domain',
  );
  assert.ok(
    deployScript.includes('set_env_value VIDEOCHAT_V1_BACKEND_ORIGIN "https://\\${API_DOMAIN}"'),
    'production env refresh must keep the API domain backend origin',
  );
  assert.ok(
    deployScript.includes('set_env_value VIDEOCHAT_V1_BACKEND_ORIGIN "http://\\${API_DOMAIN}:18080"'),
    'public HTTP smoke mode must target the public API host and backend port',
  );

  process.stdout.write('[backend-origin-production-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
