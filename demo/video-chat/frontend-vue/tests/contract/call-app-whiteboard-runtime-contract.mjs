import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(root, '../../..');

async function read(relativePath) {
  return readFile(path.join(repoRoot, relativePath), 'utf8');
}

async function readJson(relativePath) {
  return JSON.parse(await read(relativePath));
}

const [
  manifest,
  crdtSchema,
  iframeSource,
  sprintSource,
] = await Promise.all([
  readJson('demo/call-app/whiteboard/call-app.manifest.json'),
  readJson('demo/call-app/whiteboard/crdt.schema.json'),
  read('demo/call-app/whiteboard/public/index.html'),
  read('SPRINT.md'),
]);

assert.equal(
  manifest.status,
  'runtime_ready',
  'whiteboard package must move from metadata stub to runtime-ready status',
);

assert.match(
  iframeSource,
  /<canvas id="board" width="1600" height="900"><\/canvas>/,
  'whiteboard runtime must render a fixed-format canvas workspace',
);

for (const tool of ['pen', 'highlighter', 'rect', 'text', 'sticky', 'delete']) {
  assert.match(
    iframeSource,
    new RegExp(`data-tool="${tool}"`),
    `whiteboard runtime must expose ${tool} tool`,
  );
}

assert.match(
  iframeSource,
  /function canAppend\(\)[\s\S]*grantState === 'allowed'/,
  'whiteboard runtime must derive editor mode from the launch grant',
);

assert.match(
  iframeSource,
  /document\.querySelectorAll\('\[data-tool\], \.swatch, #width'\)[\s\S]*element\.disabled = !canAppend\(\)/,
  'viewer mode must disable drawing controls in the iframe',
);

assert.match(
  iframeSource,
  /call_app\.crdt\.bootstrap\.request[\s\S]*call_app\.crdt\.ops\.request[\s\S]*setInterval\(requestOps, 1500\)/,
  'whiteboard runtime must bootstrap and continuously replay CRDT ops',
);

for (const operationType of [
  'stroke.add',
  'shape.add',
  'shape.update',
  'shape.delete',
  'text.add',
  'text.update',
  'sticky_note.add',
  'sticky_note.update',
  'cursor.move',
]) {
  assert(
    crdtSchema.documents[0].operation_types.includes(operationType),
    `CRDT schema must include ${operationType}`,
  );
  assert(
    iframeSource.includes(operationType),
    `whiteboard runtime must handle or emit ${operationType}`,
  );
}

assert.match(
  iframeSource,
  /function sendCursor[\s\S]*appendOperation\('cursor\.move'/,
  'whiteboard runtime must publish participant cursor movement',
);

assert.match(
  iframeSource,
  /function applyEnvelope[\s\S]*state\.applied\.has\(envelope\.operation_id\)[\s\S]*payload_type === 'stroke\.add'[\s\S]*payload_type === 'cursor\.move'/,
  'whiteboard runtime must idempotently apply CRDT envelopes for drawing and cursor state',
);

assert.match(
  iframeSource,
  /exportCanvas\('image\/png'\)[\s\S]*kingrt-whiteboard\.png/,
  'whiteboard runtime must support PNG export',
);

assert.match(
  iframeSource,
  /function exportPdf\(\)[\s\S]*application\/pdf[\s\S]*kingrt-whiteboard\.pdf/,
  'whiteboard runtime must support PDF export',
);

assert.doesNotMatch(
  iframeSource,
  /sessionToken|Authorization|localStorage|primary_session_token_received:\s*true/,
  'whiteboard runtime must not access parent auth material',
);

assert.match(
  sprintSource,
  /- \[x\] CAP-13 Whiteboard Call App first implementation/,
  'SPRINT.md must mark CAP-13 complete',
);

console.log('[call-app-whiteboard-runtime-contract] PASS');
