import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const videoChatRoot = path.resolve(__dirname, '../../..');

const callAccessContract = fs.readFileSync(
  path.join(videoChatRoot, 'backend-king-php/domain/calls/call_access_contract.php'),
  'utf8',
);

assert.match(
  callAccessContract,
  /\$hostVerifiedSelect = videochat_tenant_table_has_column\(\$pdo, 'call_access_sessions', 'host_verified_at'\)[\s\S]*call_access_sessions\.host_verified_at AS host_verified_at[\s\S]*NULL AS host_verified_at[\s\S]*\{\$hostVerifiedSelect\},/,
  'call-access binding validation must always define the optional host_verified_at SELECT fragment before preparing SQL',
);

process.stdout.write('[call-access-binding-host-verified-select-contract] PASS\n');
