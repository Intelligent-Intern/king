import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '..', '..');
const repoRoot = path.resolve(frontendRoot, '..', '..', '..');

function readText(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

const evidence = readText('documentation/iam-sprint-05-integration-classification.md');

assert.match(
  evidence,
  /Classification: `cleanup anchor`/,
  'integration branch must be classified as cleanup anchor',
);
assert.match(
  evidence,
  /not a merge candidate/,
  'evidence must reject wholesale integration-branch merge',
);
assert.match(
  evidence,
  /Base HEAD \| `prod-kingrt-do-not-push-to-github` at `7a593d46`/,
  'evidence must preserve base branch and inspected HEAD',
);
assert.match(
  evidence,
  /Source HEAD \| `iam-e2e-integration` at `b5649e4c`/,
  'evidence must preserve source branch and inspected HEAD',
);
assert.match(
  evidence,
  /Source-only commits \| 251 commits/,
  'evidence must record source-only commit count',
);
assert.match(
  evidence,
  /Prod-only commits \| 383 commits/,
  'evidence must record prod-only commit count',
);
assert.match(
  evidence,
  /512 files changed, 53465 insertions\(\+\), 37206 deletions\(-\)/,
  'evidence must record the broad diff size',
);
assert.match(
  evidence,
  /Parked Background\/Gossip\/SFU\/MediaSecurity paths \| 39/,
  'evidence must record parked media path merge blocker count',
);
assert.match(
  evidence,
  /Sprint 03\/04 IAM evidence docs deleted by source branch \| 14/,
  'evidence must record Sprint 03/04 evidence-doc deletion risk',
);

for (const requiredSource of [
  'd6197c02',
  '1d31357f',
  '2b34babd',
  '4f8159fd',
  '6cd09066',
  '755da3df',
  'bb4331ef',
  '5101367b',
  '47bf14a1',
]) {
  assert.ok(
    evidence.includes(requiredSource),
    `classification evidence must retain source proof commit ${requiredSource}`,
  );
}

for (const forbiddenArea of ['Background', 'Gossip', 'SFU', 'MediaSecurity', 'BTGF']) {
  assert.ok(
    evidence.includes(forbiddenArea),
    `classification evidence must preserve ${forbiddenArea} boundary`,
  );
}

assert.match(
  evidence,
  /do not carry over stale branch wiring/,
  'classification must require focused extraction instead of stale branch wiring',
);
assert.match(
  evidence,
  /Do not delete `iam-e2e-integration` yet/,
  'classification must block cleanup until later contained-head review',
);

process.stdout.write('[iam-sprint-05-integration-classification-contract] PASS\n');
