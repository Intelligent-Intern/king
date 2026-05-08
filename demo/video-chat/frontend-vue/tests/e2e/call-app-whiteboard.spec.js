import { expect, test } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const whiteboardPublicRoot = path.resolve(__dirname, '../../../../call-app/whiteboard/public');
const bridgeProtocol = 'king.call_app.iframe.v1';

async function readWhiteboardAssets() {
  const [html, css, js] = await Promise.all([
    readFile(path.join(whiteboardPublicRoot, 'index.html'), 'utf8'),
    readFile(path.join(whiteboardPublicRoot, 'whiteboard.css'), 'utf8'),
    readFile(path.join(whiteboardPublicRoot, 'whiteboard.js'), 'utf8'),
  ]);
  return { html, css, js };
}

async function installWhiteboardRoutes(page, assets, baseURL) {
  await page.route('**/__whiteboard_e2e/**', async (route) => {
    const url = new URL(route.request().url());
    const pathname = url.pathname;
    const headers = { 'cache-control': 'no-store' };

    if (pathname.endsWith('/host.html')) {
      await route.fulfill({
        status: 200,
        headers: { ...headers, 'content-type': 'text/html; charset=utf-8' },
        body: hostHtml(baseURL),
      });
      return;
    }

    if (pathname.endsWith('/index.html')) {
      await route.fulfill({
        status: 200,
        headers: { ...headers, 'content-type': 'text/html; charset=utf-8' },
        body: assets.html,
      });
      return;
    }

    if (pathname.endsWith('/whiteboard.css')) {
      await route.fulfill({
        status: 200,
        headers: { ...headers, 'content-type': 'text/css; charset=utf-8' },
        body: assets.css,
      });
      return;
    }

    if (pathname.endsWith('/whiteboard.js')) {
      await route.fulfill({
        status: 200,
        headers: { ...headers, 'content-type': 'text/javascript; charset=utf-8' },
        body: assets.js,
      });
      return;
    }

    await route.fulfill({ status: 404, body: 'missing whiteboard fixture' });
  });
}

function hostHtml(baseURL) {
  const entry = `${baseURL.replace(/\/+$/, '')}/__whiteboard_e2e`;
  return `<!doctype html>
    <html lang="en">
      <head>
        <meta charset="utf-8">
        <title>Whiteboard E2E Host</title>
        <style>
          body { margin: 0; background: #000010; color: #fff; font-family: sans-serif; }
          .host { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; padding: 16px; }
          iframe { width: 780px; height: 438px; border: 1px solid #03275a; background: #00052d; }
          #audit { position: fixed; left: 16px; bottom: 12px; font-size: 12px; }
        </style>
      </head>
      <body>
        <main class="host">
          <iframe
            id="ownerFrame"
            name="owner"
            title="Owner Whiteboard"
            sandbox="allow-scripts allow-forms allow-pointer-lock allow-downloads"
            src="${entry}/owner/index.html"></iframe>
          <iframe
            id="participantFrame"
            name="participant"
            title="Participant Whiteboard"
            sandbox="allow-scripts allow-forms allow-pointer-lock allow-downloads"
            src="${entry}/participant/index.html"></iframe>
        </main>
        <output id="audit">waiting</output>
        <script>
          (() => {
            const bridgeProtocol = ${JSON.stringify(bridgeProtocol)};
            const appSessionId = 'whiteboard-session-e2e';
            const callId = 'call-whiteboard-e2e';
            const documentId = 'document-whiteboard-e2e';
            const appKey = 'whiteboard';
            const participants = {
              owner: {
                alias: 'owner',
                frameId: 'ownerFrame',
                actorId: 'user_owner_e2e',
                grantState: 'allowed',
                capabilities: [
                  'call_apps.launch',
                  'call_apps.crdt.read',
                  'call_apps.crdt.append',
                  'call_apps.crdt.replay',
                  'call_apps.presence.publish',
                ],
              },
              participant: {
                alias: 'participant',
                frameId: 'participantFrame',
                actorId: 'user_participant_e2e',
                grantState: 'allowed',
                capabilities: [
                  'call_apps.launch',
                  'call_apps.crdt.read',
                  'call_apps.crdt.append',
                  'call_apps.crdt.replay',
                  'call_apps.presence.publish',
                ],
              },
            };
            const state = {
              organizationOrdered: false,
              organizationInstalled: false,
              attached: false,
              defaultParticipantAccess: 'allowed_by_default',
              ready: {},
              launchCount: {},
              logicalClock: 0,
              ops: [],
              presence: [],
              presenceDeliveries: [],
              snapshot: null,
              snapshotClock: 0,
              appendAttempts: [],
              deniedAppendCount: 0,
              sessionRemoved: false,
            };

            function audit(message) {
              document.getElementById('audit').textContent = message;
            }

            function frameWindow(alias) {
              return document.getElementById(participants[alias].frameId)?.contentWindow || null;
            }

            function participantForSource(source) {
              return Object.values(participants).find((participant) => frameWindow(participant.alias) === source) || null;
            }

            function postTo(alias, type, payload = {}) {
              const target = frameWindow(alias);
              if (!target) return;
              target.postMessage({
                type,
                bridge_protocol: bridgeProtocol,
                app_key: appKey,
                app_session_id: appSessionId,
                ...payload,
              }, '*');
            }

            function opsAfter(afterClock) {
              const replayAfter = Math.max(Number(afterClock || 0), state.snapshotClock);
              return state.ops.filter((operation) => Number(operation.logical_clock || 0) > replayAfter);
            }

            function emptySnapshot() {
              return {
                kind: 'whiteboard.snapshot.v1',
                state: {
                  strokes: [],
                  shapes: [],
                  texts: [],
                  notes: [],
                },
              };
            }

            function clone(value) {
              return JSON.parse(JSON.stringify(value));
            }

            function snapshotFromOps(ops) {
              const snapshot = emptySnapshot();
              const stateMaps = {
                strokes: new Map(),
                shapes: new Map(),
                texts: new Map(),
                notes: new Map(),
              };
              for (const operation of ops) {
                const payload = clone(operation.payload || {});
                if (operation.payload_type === 'stroke.add') stateMaps.strokes.set(payload.id, payload);
                if (operation.payload_type === 'shape.add') stateMaps.shapes.set(payload.id, payload);
                if (operation.payload_type === 'shape.update' && stateMaps.shapes.has(payload.id)) {
                  stateMaps.shapes.set(payload.id, { ...stateMaps.shapes.get(payload.id), ...payload });
                }
                if (operation.payload_type === 'text.add') stateMaps.texts.set(payload.id, payload);
                if (operation.payload_type === 'text.update' && stateMaps.texts.has(payload.id)) {
                  stateMaps.texts.set(payload.id, { ...stateMaps.texts.get(payload.id), ...payload });
                }
                if (operation.payload_type === 'sticky_note.add') stateMaps.notes.set(payload.id, payload);
                if (operation.payload_type === 'sticky_note.update' && stateMaps.notes.has(payload.id)) {
                  stateMaps.notes.set(payload.id, { ...stateMaps.notes.get(payload.id), ...payload });
                }
                if (operation.payload_type === 'shape.delete') {
                  stateMaps.strokes.delete(payload.id);
                  stateMaps.shapes.delete(payload.id);
                  stateMaps.texts.delete(payload.id);
                  stateMaps.notes.delete(payload.id);
                }
              }
              snapshot.state.strokes = [...stateMaps.strokes.values()];
              snapshot.state.shapes = [...stateMaps.shapes.values()];
              snapshot.state.texts = [...stateMaps.texts.values()];
              snapshot.state.notes = [...stateMaps.notes.values()];
              return snapshot;
            }

            function crdtResult(participant, afterClock = 0, limit = 250) {
              return {
                ok: participant.grantState === 'allowed',
                state: participant.grantState === 'allowed' ? 'listed' : 'participant_grant_denied',
                grant_state: participant.grantState,
                document: {
                  document_id: documentId,
                  schema_version: 'king.call_app.crdt.v1',
                  snapshot: state.snapshot || emptySnapshot(),
                  snapshot_clock: state.snapshotClock,
                  compacted_through_clock: state.snapshotClock,
                  op_count: state.ops.length,
                },
                ops: participant.grantState === 'allowed' ? opsAfter(afterClock).slice(0, limit) : [],
                replay_cursor: { after_clock: Math.max(0, Number(afterClock || 0)) },
              };
            }

            function broadcastOps(ops) {
              for (const participant of Object.values(participants)) {
                if (participant.grantState !== 'allowed') continue;
                postTo(participant.alias, 'call_app.crdt.ops.response', {
                  request_id: 'broadcast_' + Date.now(),
                  result: {
                    grant_state: participant.grantState,
                    ops,
                    replay_cursor: { after_clock: state.logicalClock },
                  },
                });
              }
            }

            function launch(alias) {
              const participant = participants[alias];
              state.launchCount[alias] = (state.launchCount[alias] || 0) + 1;
              postTo(alias, 'call_app.launch', {
                call_id: callId,
                app_version: '0.1.0',
                document_id: documentId,
                launch_token: 'launch_' + alias + '_' + state.launchCount[alias],
                launch_token_id: 'launch-token-' + alias,
                expires_at: '2030-01-01T00:00:00.000Z',
                capabilities: participant.grantState === 'allowed' ? participant.capabilities : ['call_apps.launch'],
                launch_context: {
                  bridge_protocol: bridgeProtocol,
                  iframe_origin_policy: 'sandbox_opaque_origin',
                  expected_message_origin: 'null',
                  grant_state: participant.grantState,
                  participant: {
                    actor_id: participant.actorId,
                    display_name: alias === 'owner' ? 'Owner' : 'Participant',
                  },
                  app: {
                    name: 'Whiteboard',
                    category: 'whiteboard',
                    crdt_protocol: 'king.call_app.crdt.v1',
                  },
                },
              });
            }

            window.whiteboardHarness = {
              get state() {
                return {
                  organizationOrdered: state.organizationOrdered,
                  organizationInstalled: state.organizationInstalled,
                  attached: state.attached,
                  ready: { ...state.ready },
                  launchCount: { ...state.launchCount },
                  logicalClock: state.logicalClock,
                  ops: state.ops.map((operation) => ({
                    logical_clock: operation.logical_clock,
                    payload_type: operation.payload_type,
                    actor_id: operation.actor_id,
                    payload: clone(operation.payload || {}),
                  })),
                  presence: state.presence.map((entry) => ({
                    alias: entry.alias,
                    actor_id: entry.actor_id,
                    payload_type: entry.payload_type,
                  })),
                  presenceDeliveries: state.presenceDeliveries.map((entry) => ({ ...entry })),
                  snapshotClock: state.snapshotClock,
                  appendAttempts: state.appendAttempts.slice(),
                  deniedAppendCount: state.deniedAppendCount,
                };
              },
              orderInstallAndAttach() {
                state.organizationOrdered = true;
                state.organizationInstalled = true;
                state.attached = true;
                audit('whiteboard ordered, installed, and attached');
              },
              launch,
              launchAll() {
                launch('owner');
                launch('participant');
              },
              revoke(alias) {
                participants[alias].grantState = 'denied';
                postTo(alias, 'call_app.crdt.error', {
                  request_id: 'grant_revoke_' + Date.now(),
                  reason: 'participant_grant_denied',
                  grant_state: 'denied',
                  message: 'Participant grant denied.',
                });
                audit(alias + ' revoked');
              },
              injectRemoteCursor(alias, cursor) {
                postTo(alias, 'call_app.presence.update', {
                  actor_id: String(cursor.actorId || ''),
                  payload_type: 'cursor.move',
                  payload: {
                    actor_id: String(cursor.actorId || ''),
                    display_name: String(cursor.label || ''),
                    label: String(cursor.label || ''),
                    x: Number(cursor.x || 0),
                    y: Number(cursor.y || 0),
                    color: String(cursor.color || '#1582bf'),
                  },
                });
                state.presenceDeliveries.push({
                  from: String(cursor.label || 'remote'),
                  to: alias,
                  actor_id: String(cursor.actorId || ''),
                  payload_type: 'cursor.move',
                  label: String(cursor.label || ''),
                });
              },
              leaveRemoteCursor(alias, actorId) {
                postTo(alias, 'call_app.presence.leave', {
                  actor_id: String(actorId || ''),
                });
                audit('remote cursor left: ' + actorId);
              },
              reload(alias) {
                state.ready[alias] = false;
                const frame = document.getElementById(participants[alias].frameId);
                frame.onload = () => setTimeout(() => launch(alias), 0);
                frame.src = '${entry}/' + alias + '/index.html?reconnect=' + Date.now();
              },
              injectReplay(alias) {
                const replay = state.ops.slice().reverse();
                if (replay[0]) replay.unshift(replay[0]);
                postTo(alias, 'call_app.crdt.ops.response', {
                  request_id: 'manual_replay_' + Date.now(),
                  result: {
                    grant_state: participants[alias].grantState,
                    ops: replay,
                    replay_cursor: { after_clock: state.logicalClock },
                  },
                });
              },
              compactSnapshot() {
                state.snapshot = snapshotFromOps(state.ops);
                state.snapshotClock = state.logicalClock;
                audit('snapshot compacted through ' + state.snapshotClock);
              },
            };

            window.addEventListener('message', (event) => {
              const message = event.data && typeof event.data === 'object' ? event.data : null;
              if (!message || message.bridge_protocol !== bridgeProtocol) return;
              const participant = participantForSource(event.source);
              if (!participant) return;

              if (message.type === 'call_app.ready') {
                state.ready[participant.alias] = true;
                audit(participant.alias + ' ready');
                return;
              }

              if (message.type === 'call_app.crdt.bootstrap.request') {
                const afterClock = Number(message.after_clock || 0);
                postTo(participant.alias, 'call_app.crdt.bootstrap.response', {
                  request_id: String(message.request_id || ''),
                  result: crdtResult(participant, afterClock, 250),
                });
                return;
              }

              if (message.type === 'call_app.crdt.ops.request') {
                const afterClock = Number(message.after_clock || 0);
                const limit = Number(message.limit || 250);
                postTo(participant.alias, 'call_app.crdt.ops.response', {
                  request_id: String(message.request_id || ''),
                  result: crdtResult(participant, afterClock, limit),
                });
                return;
              }

              if (message.type === 'call_app.crdt.op.append') {
                state.appendAttempts.push({
                  alias: participant.alias,
                  payload_type: String(message.operation?.payload_type || ''),
                  grant_state: participant.grantState,
                });

                if (participant.grantState !== 'allowed') {
                  state.deniedAppendCount += 1;
                  postTo(participant.alias, 'call_app.crdt.error', {
                    request_id: String(message.request_id || ''),
                    reason: 'participant_grant_denied',
                    grant_state: 'denied',
                    message: 'Participant grant denied.',
                  });
                  return;
                }

                state.logicalClock += 1;
                const operation = {
                  app_id: appKey,
                  app_version: '0.1.0',
                  call_id: callId,
                  app_session_id: appSessionId,
                  document_id: documentId,
                  schema_version: 'king.call_app.crdt.v1',
                  actor_id: participant.actorId,
                  operation_id: String(message.operation?.operation_id || 'op_' + state.logicalClock),
                  logical_clock: state.logicalClock,
                  causal_dependencies: Array.isArray(message.operation?.causal_dependencies)
                    ? message.operation.causal_dependencies
                    : [],
                  payload_type: String(message.operation?.payload_type || ''),
                  payload: message.operation?.payload || {},
                  server_admission_stamp: {
                    admitted_at: new Date().toISOString(),
                    source: 'whiteboard_e2e_parent_bridge',
                  },
                };
                state.ops.push(operation);
                postTo(participant.alias, 'call_app.crdt.op.appended', {
                  request_id: String(message.request_id || ''),
                  result: {
                    ok: true,
                    state: 'admitted',
                    grant_state: participant.grantState,
                    operation,
                  },
                });
                broadcastOps([operation]);
                return;
              }

              if (message.type === 'call_app.presence.publish') {
                state.presence.push({
                  alias: participant.alias,
                  actor_id: participant.actorId,
                  payload_type: String(message.payload_type || ''),
                  payload: message.payload || {},
                });
                for (const target of Object.values(participants)) {
                  if (target.alias === participant.alias || target.grantState !== 'allowed') continue;
                  postTo(target.alias, 'call_app.presence.update', {
                    request_id: String(message.request_id || ''),
                    actor_id: participant.actorId,
                    payload_type: String(message.payload_type || ''),
                    payload: message.payload || {},
                  });
                  state.presenceDeliveries.push({
                    from: participant.alias,
                    to: target.alias,
                    actor_id: participant.actorId,
                    payload_type: String(message.payload_type || ''),
                    label: String(message.payload?.label || message.payload?.display_name || ''),
                  });
                }
              }
            });
          })();
        </script>
      </body>
    </html>`;
}

async function drawStroke(page, frameSelector) {
  const canvas = page.frameLocator(frameSelector).locator('#board');
  const box = await canvas.boundingBox();
  expect(box).toBeTruthy();
  await page.mouse.move(box.x + 120, box.y + 100);
  await page.mouse.down();
  await page.mouse.move(box.x + 260, box.y + 180, { steps: 8 });
  await page.mouse.up();
}

async function nonWhitePixelCount(page, frameName) {
  const frame = page.frame({ name: frameName });
  expect(frame).toBeTruthy();
  return frame.evaluate(() => {
    const canvas = document.getElementById('board');
    const context = canvas.getContext('2d');
    const pixels = context.getImageData(0, 0, canvas.width, canvas.height).data;
    let count = 0;
    for (let index = 0; index < pixels.length; index += 400) {
      const red = pixels[index];
      const green = pixels[index + 1];
      const blue = pixels[index + 2];
      const alpha = pixels[index + 3];
      if (alpha > 0 && !(red > 245 && green > 245 && blue > 245)) count += 1;
    }
    return count;
  });
}

async function canvasRegionNonWhiteCount(page, frameName, region) {
  const frame = page.frame({ name: frameName });
  expect(frame).toBeTruthy();
  return frame.evaluate((sampleRegion) => {
    const canvas = document.getElementById('board');
    const context = canvas.getContext('2d');
    const x = Math.max(0, Math.min(canvas.width - 1, Math.floor(sampleRegion.x)));
    const y = Math.max(0, Math.min(canvas.height - 1, Math.floor(sampleRegion.y)));
    const width = Math.max(1, Math.min(canvas.width - x, Math.floor(sampleRegion.width)));
    const height = Math.max(1, Math.min(canvas.height - y, Math.floor(sampleRegion.height)));
    const pixels = context.getImageData(x, y, width, height).data;
    let count = 0;
    for (let index = 0; index < pixels.length; index += 4) {
      const red = pixels[index];
      const green = pixels[index + 1];
      const blue = pixels[index + 2];
      const alpha = pixels[index + 3];
      if (alpha > 0 && !(red > 245 && green > 245 && blue > 245)) count += 1;
    }
    return count;
  }, region);
}

test('Whiteboard Call App journey covers collaboration, presence, replay, snapshot, and revocation', async ({ page }) => {
  const assets = await readWhiteboardAssets();
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  await installWhiteboardRoutes(page, assets, baseURL);
  await page.goto(`${baseURL.replace(/\/+$/, '')}/__whiteboard_e2e/host.html`, { waitUntil: 'domcontentloaded' });

  const ownerFrame = page.frameLocator('iframe[name="owner"]');
  const participantFrame = page.frameLocator('iframe[name="participant"]');
  await expect(ownerFrame.locator('#status')).toHaveText('Waiting for Call App launch.');
  await expect(participantFrame.locator('#status')).toHaveText('Waiting for Call App launch.');
  await expect(ownerFrame.locator('#modeBadge')).toHaveText('No access');
  await expect(participantFrame.locator('#modeBadge')).toHaveText('No access');

  await page.evaluate(() => window.whiteboardHarness.orderInstallAndAttach());
  await page.evaluate(() => window.whiteboardHarness.launchAll());

  await expect(ownerFrame.locator('#modeBadge')).toHaveText('Editor');
  await expect(participantFrame.locator('#modeBadge')).toHaveText('Editor');
  await expect.poll(() => page.evaluate(() => window.whiteboardHarness.state.ready))
    .toEqual({ owner: true, participant: true });

  await drawStroke(page, 'iframe[name="owner"]');
  await participantFrame.locator('[data-tool="sticky"]').click();
  await participantFrame.locator('#board').click({ position: { x: 260, y: 160 } });
  await participantFrame.locator('#inlineText').fill('Shared note from participant');
  await participantFrame.locator('#inlineSubmit').click();

  await expect.poll(() => page.evaluate(() => window.whiteboardHarness.state.ops.map((op) => op.payload_type)))
    .toEqual(expect.arrayContaining(['stroke.add', 'sticky_note.add']));
  await expect.poll(() => nonWhitePixelCount(page, 'owner')).toBeGreaterThan(40);
  await expect.poll(() => nonWhitePixelCount(page, 'participant')).toBeGreaterThan(40);
  await expect.poll(() => page.evaluate(() => window.whiteboardHarness.state.ops.map((op) => op.payload_type)))
    .toEqual(expect.not.arrayContaining(['cursor.move', 'selection.update']));

  const ownerCanvasBox = await ownerFrame.locator('#board').boundingBox();
  expect(ownerCanvasBox).toBeTruthy();
  const cursorScreenPoint = {
    x: ownerCanvasBox.x + 520,
    y: ownerCanvasBox.y + 92,
  };
  const cursorBoardPoint = {
    x: ((cursorScreenPoint.x - ownerCanvasBox.x) / ownerCanvasBox.width) * 1600,
    y: ((cursorScreenPoint.y - ownerCanvasBox.y) / ownerCanvasBox.height) * 900,
  };
  const cursorRegion = {
    x: cursorBoardPoint.x + 20,
    y: cursorBoardPoint.y + 6,
    width: 190,
    height: 36,
  };
  const cursorRegionBefore = await canvasRegionNonWhiteCount(page, 'participant', cursorRegion);
  const presenceBeforeCursorBurst = await page.evaluate(() => window.whiteboardHarness.state.presence.length);
  await page.waitForTimeout(650);
  await page.mouse.move(cursorScreenPoint.x, cursorScreenPoint.y);
  await expect.poll(() => canvasRegionNonWhiteCount(page, 'participant', cursorRegion))
    .toBeGreaterThan(cursorRegionBefore + 40);
  await expect(participantFrame.locator('.remote-cursor-label')).toHaveText('Owner');
  const cursorRegionWithOwner = await canvasRegionNonWhiteCount(page, 'participant', cursorRegion);
  expect(cursorRegionWithOwner).toBeGreaterThan(cursorRegionBefore + 40);
  await expect.poll(() => page.evaluate(() => window.whiteboardHarness.state.presenceDeliveries))
    .toEqual(expect.arrayContaining([
      expect.objectContaining({
        from: 'owner',
        to: 'participant',
        payload_type: 'cursor.move',
        label: 'Owner',
      }),
    ]));
  await expect(participantFrame.locator('.remote-cursor-label')).toHaveText('Owner');
  const participantLaunchCountBeforeRemoteCursors = await page.evaluate(() => window.whiteboardHarness.state.launchCount.participant);
  const participantFrameSrcBeforeRemoteCursors = await page.locator('#participantFrame').evaluate((frame) => frame.src);
  await page.evaluate(() => {
    window.whiteboardHarness.injectRemoteCursor('participant', {
      actorId: 'user_reviewer_e2e',
      label: 'Reviewer',
      x: 760,
      y: 176,
      color: '#00652f',
    });
    window.whiteboardHarness.injectRemoteCursor('participant', {
      actorId: 'user_facilitator_e2e',
      label: 'Facilitator',
      x: 920,
      y: 226,
      color: '#f47221',
    });
  });
  await expect(participantFrame.locator('.remote-cursor-label')).toHaveCount(3);
  await expect.poll(() => participantFrame.locator('.remote-cursor-label').allTextContents())
    .toEqual(['Owner', 'Reviewer', 'Facilitator']);
  await page.evaluate(() => window.whiteboardHarness.leaveRemoteCursor('participant', 'user_reviewer_e2e'));
  await expect(participantFrame.locator('.remote-cursor-label')).toHaveCount(2);
  await expect.poll(() => participantFrame.locator('.remote-cursor-label').allTextContents())
    .toEqual(['Owner', 'Facilitator']);
  await expect.poll(() => page.evaluate(() => window.whiteboardHarness.state.launchCount.participant))
    .toBe(participantLaunchCountBeforeRemoteCursors);
  expect(await page.locator('#participantFrame').evaluate((frame) => frame.src)).toBe(participantFrameSrcBeforeRemoteCursors);
  for (let index = 0; index < 8; index += 1) {
    await page.mouse.move(ownerCanvasBox.x + 350 + index * 12, ownerCanvasBox.y + 120 + index * 4);
  }
  await expect.poll(() => page.evaluate(() => window.whiteboardHarness.state.presence.length))
    .toBeGreaterThanOrEqual(presenceBeforeCursorBurst + 1);
  await expect.poll(() => page.evaluate(() => window.whiteboardHarness.state.presence.length))
    .toBeLessThanOrEqual(presenceBeforeCursorBurst + 2);

  const opsBeforeMove = await page.evaluate(() => window.whiteboardHarness.state.ops.length);
  const stickyPosition = await page.evaluate(() => {
    const sticky = window.whiteboardHarness.state.ops.find((op) => op.payload_type === 'sticky_note.add');
    return { x: Number(sticky?.payload?.x || 0), y: Number(sticky?.payload?.y || 0) };
  });
  const stickyPoint = {
    x: ownerCanvasBox.x + ((stickyPosition.x + 48) / 1600) * ownerCanvasBox.width,
    y: ownerCanvasBox.y + ((stickyPosition.y + 48) / 900) * ownerCanvasBox.height,
  };
  await ownerFrame.locator('[data-tool="select"]').click();
  await page.mouse.move(stickyPoint.x, stickyPoint.y);
  await page.mouse.down();
  await page.mouse.move(stickyPoint.x + 50, stickyPoint.y + 50, { steps: 4 });
  await page.mouse.up();
  await expect.poll(() => page.evaluate(() => window.whiteboardHarness.state.ops.length))
    .toBe(opsBeforeMove + 1);
  await ownerFrame.locator('#undo').click();
  await expect.poll(() => page.evaluate(() => window.whiteboardHarness.state.ops.length))
    .toBe(opsBeforeMove + 2);
  await ownerFrame.locator('#redo').click();
  await expect.poll(() => page.evaluate(() => window.whiteboardHarness.state.ops.length))
    .toBe(opsBeforeMove + 3);
  await expect.poll(() => page.evaluate(() => window.whiteboardHarness.state.presence.map((entry) => entry.payload_type)))
    .toEqual(expect.arrayContaining(['selection.update']));
  await expect.poll(() => page.evaluate(() => window.whiteboardHarness.state.ops.filter((op) => op.payload_type === 'sticky_note.update').length))
    .toBeGreaterThanOrEqual(3);

  const opsBeforeReplayInjection = await page.evaluate(() => window.whiteboardHarness.state.ops.length);
  await page.evaluate(() => window.whiteboardHarness.injectReplay('owner'));
  await page.waitForTimeout(300);
  await expect.poll(() => page.evaluate(() => window.whiteboardHarness.state.ops.length))
    .toBe(opsBeforeReplayInjection);
  await expect.poll(() => nonWhitePixelCount(page, 'owner')).toBeGreaterThan(40);

  await page.evaluate(() => window.whiteboardHarness.compactSnapshot());
  await expect.poll(() => page.evaluate(() => window.whiteboardHarness.state.snapshotClock))
    .toBeGreaterThanOrEqual(opsBeforeReplayInjection);

  const admittedBeforeRevoke = await page.evaluate(() => window.whiteboardHarness.state.ops.length);
  await page.evaluate(() => window.whiteboardHarness.revoke('participant'));
  await expect(participantFrame.locator('#modeBadge')).toHaveText('No access');
  await expect(participantFrame.locator('[data-tool="pen"]')).toBeDisabled();
  await expect(participantFrame.locator('.remote-cursor-label')).toHaveCount(0);
  await expect.poll(() => page.evaluate(() => window.whiteboardHarness.state.launchCount.participant))
    .toBe(participantLaunchCountBeforeRemoteCursors);
  expect(await page.locator('#participantFrame').evaluate((frame) => frame.src)).toBe(participantFrameSrcBeforeRemoteCursors);
  await expect.poll(() => canvasRegionNonWhiteCount(page, 'participant', cursorRegion))
    .toBeLessThanOrEqual(cursorRegionBefore + 12);
  const participantPresenceDeliveriesAfterRevoke = await page.evaluate(() => (
    window.whiteboardHarness.state.presenceDeliveries.filter((entry) => entry.to === 'participant').length
  ));
  await page.waitForTimeout(650);
  await page.mouse.move(cursorScreenPoint.x + 64, cursorScreenPoint.y + 24);
  await page.waitForTimeout(200);
  await expect.poll(() => page.evaluate(() => (
    window.whiteboardHarness.state.presenceDeliveries.filter((entry) => entry.to === 'participant').length
  ))).toBe(participantPresenceDeliveriesAfterRevoke);
  const cursorRegionAfterRevoke = await canvasRegionNonWhiteCount(page, 'participant', cursorRegion);
  expect(cursorRegionAfterRevoke).toBeLessThanOrEqual(cursorRegionBefore + 12);

  await drawStroke(page, 'iframe[name="participant"]');
  await expect.poll(() => page.evaluate(() => window.whiteboardHarness.state.ops.length))
    .toBe(admittedBeforeRevoke);

  const launchCountBeforeReconnect = await page.evaluate(() => window.whiteboardHarness.state.launchCount.owner);
  await page.evaluate(() => window.whiteboardHarness.reload('owner'));
  await expect.poll(() => page.evaluate(() => window.whiteboardHarness.state.launchCount.owner))
    .toBe(launchCountBeforeReconnect + 1);
  await expect(ownerFrame.locator('#modeBadge')).toHaveText('Editor');
  await expect.poll(() => nonWhitePixelCount(page, 'owner')).toBeGreaterThan(40);
  await expect.poll(() => page.evaluate(() => window.whiteboardHarness.state.ops.map((op) => op.payload_type)))
    .toEqual(expect.arrayContaining(['stroke.add', 'sticky_note.add', 'sticky_note.update']));
});
