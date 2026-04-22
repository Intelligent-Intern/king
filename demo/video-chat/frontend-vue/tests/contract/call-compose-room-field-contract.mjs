import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[call-compose-room-field-contract] FAIL: ${message}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const files = [
  '../../src/domain/calls/AdminCallsView.vue',
  '../../src/domain/calls/UserDashboardView.vue',
  '../../src/layouts/WorkspaceShell.vue',
].map((file) => path.resolve(__dirname, file));

try {
  for (const file of files) {
    const source = fs.readFileSync(file, 'utf8');
    const relative = path.relative(path.resolve(__dirname, '../..'), file);

    assert.doesNotMatch(source, /Room name/i, `${relative} must not render a Room name field`);
    assert.doesNotMatch(source, /composeState\.roomId/, `${relative} must not keep obsolete compose room state`);
    assert.doesNotMatch(source, /callOwnerEditState\.roomId/, `${relative} must not keep obsolete owner edit room state`);
  }

  process.stdout.write('[call-compose-room-field-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
