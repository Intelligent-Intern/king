import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = resolve(__dirname, '../..');
const joinView = readFileSync(resolve(root, 'src/domain/calls/access/JoinView.vue'), 'utf8');

assert.match(
  joinView,
  /import \{ loginWithCallAccess, requestCallAccessAccountUpdateConfirmation \} from '\.\/callAccessSession';/,
  'JoinView must import requestCallAccessAccountUpdateConfirmation used by strong mismatch flow setup',
);
assert.match(
  joinView,
  /createJoinStrongMismatchFlow\([\s\S]*requestCallAccessAccountUpdateConfirmation[\s\S]*startAdmissionWait/,
  'JoinView must pass the imported account update confirmation requester into the strong mismatch flow',
);

console.log('[join-view-account-confirmation-import-contract] PASS');
