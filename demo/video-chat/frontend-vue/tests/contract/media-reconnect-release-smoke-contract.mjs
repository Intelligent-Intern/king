import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

const packageJson = JSON.parse(read('demo/video-chat/frontend-vue/package.json'));
const releaseSmokeCommand = String(packageJson.scripts?.['test:contract:media-reconnect-release-smoke'] || '');
const smoke = read('demo/video-chat/scripts/smoke.sh');

assert.match(
  releaseSmokeCommand,
  /node tests\/contract\/media-reconnect-screenshare-stability-contract\.mjs/,
  'release smoke must include the static reconnect/screenshare lifecycle contract',
);
assert.match(
  releaseSmokeCommand,
  /node tests\/contract\/media-reconnect-screenshare-browser-smoke-contract\.mjs/,
  'release smoke must include the fake-browser stale capture smoke',
);
assert.match(
  releaseSmokeCommand,
  /node tests\/contract\/media-reconnect-release-smoke-contract\.mjs/,
  'release smoke must pin this deploy-facing hook contract',
);
assert.doesNotMatch(
  releaseSmokeCommand,
  /\b(playwright|vite|dev-server|getUserMedia|getDisplayMedia)\b/,
  'release smoke must remain a deterministic node contract and not require real devices or a browser',
);
assert.match(
  smoke,
  /npm run test:contract:media-reconnect-release-smoke/,
  'main smoke gate must run the media reconnect/screenshare release smoke before deploy',
);
assert.match(
  smoke,
  /frontend contract: media reconnect screenshare release smoke/,
  'smoke output must name the media reconnect/screenshare release gate clearly',
);

process.stdout.write('[media-reconnect-release-smoke-contract] PASS\n');
