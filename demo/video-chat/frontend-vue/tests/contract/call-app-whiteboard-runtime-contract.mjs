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
  stylesheetSource,
  runtimeSource,
  e2eSource,
  packageJsonSource,
  sprintSource,
] = await Promise.all([
  readJson('demo/call-app/whiteboard/call-app.manifest.json'),
  readJson('demo/call-app/whiteboard/crdt.schema.json'),
  read('demo/call-app/whiteboard/public/index.html'),
  read('demo/call-app/whiteboard/public/whiteboard.css'),
  read('demo/call-app/whiteboard/public/whiteboard.js'),
  read('demo/video-chat/frontend-vue/tests/e2e/call-app-whiteboard.spec.js'),
  read('demo/video-chat/frontend-vue/package.json'),
  read('SPRINT.md'),
]);

const whiteboardSource = `${iframeSource}\n${stylesheetSource}\n${runtimeSource}`;
const packageJson = JSON.parse(packageJsonSource);

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

assert.match(
  whiteboardSource,
  /id="cursorOverlay" class="cursor-overlay"[\s\S]*\.remote-cursor-label[\s\S]*function syncCursorOverlay[\s\S]*label\.textContent = displayNameLabel\(cursor\.label \|\| cursor\.display_name\)/,
  'whiteboard runtime must render remote cursor display names in an accessible overlay tied to presence labels',
);

assert.match(
  whiteboardSource,
  /function removePresenceForActor[\s\S]*message\.type === 'call_app\.presence\.leave'/,
  'whiteboard runtime must support remote presence leave cleanup without iframe reload',
);

for (const tool of ['select', 'pen', 'highlighter', 'line', 'rect', 'ellipse', 'text', 'sticky', 'delete']) {
  assert.match(
    iframeSource,
    new RegExp(`data-tool="${tool}"`),
    `whiteboard runtime must expose ${tool} tool`,
  );
}

for (const control of ['id="undo"', 'id="redo"']) {
  assert.match(
    iframeSource,
    new RegExp(control),
    `whiteboard runtime must expose ${control} control`,
  );
}

assert.match(
  whiteboardSource,
  /function canAppend\(\)[\s\S]*grantState === 'allowed'/,
  'whiteboard runtime must derive editor mode from the launch grant',
);

assert.match(
  whiteboardSource,
  /const message = cloneBridgePayload\(\{[\s\S]*window\.parent\.postMessage\(message, parentOrigin\)/,
  'whiteboard runtime must emit cloneable postMessage payloads only',
);

assert.match(
  whiteboardSource,
  /document\.querySelectorAll\('\[data-tool\], \.swatch, #width, #undo, #redo'\)[\s\S]*element\.disabled = !canAppend\(\)/,
  'viewer mode must disable drawing controls in the iframe',
);

assert.match(
  whiteboardSource,
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
]) {
  assert(
    crdtSchema.documents[0].operation_types.includes(operationType),
    `CRDT schema must include ${operationType}`,
  );
  assert(
    whiteboardSource.includes(operationType),
    `whiteboard runtime must handle or emit ${operationType}`,
  );
}

for (const presenceType of ['cursor.move', 'selection.update', 'tool.preview']) {
  assert(
    !crdtSchema.documents[0].operation_types.includes(presenceType),
    `${presenceType} must not be a persisted CRDT document operation`,
  );
  assert(
    crdtSchema.presence?.types?.includes(presenceType),
    `CRDT schema must advertise ${presenceType} as non-persistent presence`,
  );
}

assert.equal(
  crdtSchema.presence?.persisted,
  false,
  'whiteboard presence must stay non-authoritative and non-persistent',
);

assert.match(
  whiteboardSource,
  /function publishPresence[\s\S]*call_app\.presence\.publish[\s\S]*payload_type/,
  'whiteboard runtime must publish cursor and selection state through the presence lane',
);

assert.match(
  whiteboardSource,
  /function sendCursor[\s\S]*display_name:\s*participantLabel[\s\S]*label:\s*participantLabel/,
  'whiteboard runtime must label cursor presence with the participant display name from the launch context',
);
assert.match(
  whiteboardSource,
  /participantLabel\s*=\s*displayNameLabel\(context\.participant\?\.display_name/,
  'whiteboard runtime must derive the local cursor label from the launch participant display name',
);

assert.doesNotMatch(
  whiteboardSource,
  /appendOperation\('(cursor\.move|selection\.update)'/,
  'whiteboard runtime must not persist cursor or selection presence as CRDT ops',
);

assert.match(
  whiteboardSource,
  /function updateObjectPosition[\s\S]*shape\.update[\s\S]*text\.update[\s\S]*sticky_note\.update/,
  'whiteboard runtime must move selected shapes, text, and sticky notes through CRDT update operations',
);

assert.match(
  whiteboardSource,
  /function undoLast\(\)[\s\S]*action\.before[\s\S]*function redoLast\(\)[\s\S]*action\.after/,
  'whiteboard runtime must expose actor-local undo and redo handlers, including CRDT-safe move updates',
);

assert.match(
  whiteboardSource,
  /function applyEnvelope[\s\S]*state\.applied\.has\(envelope\.operation_id\)[\s\S]*payload_type === 'stroke\.add'[\s\S]*payload_type === 'sticky_note\.update'/,
  'whiteboard runtime must idempotently apply CRDT envelopes for drawing and object state',
);

assert.match(
  whiteboardSource,
  /exportCanvas\('image\/png'\)[\s\S]*kingrt-whiteboard\.png/,
  'whiteboard runtime must support PNG export',
);

assert.match(
  whiteboardSource,
  /function exportPdf\(\)[\s\S]*application\/pdf[\s\S]*kingrt-whiteboard\.pdf/,
  'whiteboard runtime must support PDF export',
);

assert.doesNotMatch(
  whiteboardSource,
  /sessionToken|Authorization|localStorage|primary_session_token_received:\s*true/,
  'whiteboard runtime must not access parent auth material',
);

assert.match(
  iframeSource,
  /<link rel="stylesheet" href="\.\/whiteboard\.css">[\s\S]*<script src="\.\/whiteboard\.js"><\/script>/,
  'whiteboard entrypoint must be a thin sandbox shell that loads extracted runtime assets',
);

assert.match(
  e2eSource,
  /orderInstallAndAttach[\s\S]*launchAll[\s\S]*stroke\.add[\s\S]*sticky_note\.add[\s\S]*injectReplay\('owner'\)[\s\S]*compactSnapshot\(\)[\s\S]*revoke\('participant'\)[\s\S]*reload\('owner'\)/,
  'whiteboard browser E2E must cover attach, drawing, duplicate replay, snapshot compaction, revoke, and reconnect replay',
);

assert.match(
  e2eSource,
  /expect\.not\.arrayContaining\(\['cursor\.move', 'selection\.update'\]\)[\s\S]*presenceBeforeCursorBurst[\s\S]*toBeLessThanOrEqual[\s\S]*selection\.update/,
  'whiteboard browser E2E must prove throttled non-persistent cursor and selection presence',
);

assert.match(
  packageJson.scripts['test:e2e:call-app-whiteboard'] || '',
  /^playwright test(?: .*)?tests\/e2e\/call-app-whiteboard\.spec\.js(?: .*)?$/,
  'package scripts must expose the Whiteboard Call App browser E2E proof',
);

assert.match(
  sprintSource,
  /- \[x\] WCA-02 Whiteboard runtime tool completeness first pass/,
  'SPRINT.md must keep the active Whiteboard runtime ticket closed after implementation proof',
);

console.log('[call-app-whiteboard-runtime-contract] PASS');
