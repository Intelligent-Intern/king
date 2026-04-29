import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const SCRIPT_DIR = path.dirname(fileURLToPath(import.meta.url));
const FRONTEND_DIR = path.resolve(SCRIPT_DIR, '../..');
const VIDEOCHAT_DIR = path.resolve(FRONTEND_DIR, '..');
const LOCAL_ENV_FILE = path.join(VIDEOCHAT_DIR, '.env.local');

const CONTINUATION_THRESHOLD_BYTES = 65_535;
const QUALITY_MAX_PAYLOAD_BYTES = 1280 * 1024;
const QUALITY_MAX_BUFFERED_BYTES = 1536 * 1024;
const CRITICAL_BUFFERED_BYTES = 5 * 1024 * 1024;
const DRAIN_LOW_WATER_BYTES = 64 * 1024;
const DEFAULT_TIMEOUT_MS = Math.max(5_000, Number.parseInt(process.env.VIDEOCHAT_PRODUCTION_PROXY_BUDGET_TIMEOUT_MS || '20000', 10));
const FRAME_SIZES = [
  32 * 1024,
  CONTINUATION_THRESHOLD_BYTES - 128,
  CONTINUATION_THRESHOLD_BYTES + 512,
  180 * 1024,
  360 * 1024,
  720 * 1024,
  QUALITY_MAX_PAYLOAD_BYTES,
];

function unquoteEnvValue(value) {
  const trimmed = String(value || '').trim();
  if (trimmed.length >= 2 && trimmed.startsWith('"') && trimmed.endsWith('"')) {
    return trimmed.slice(1, -1).replace(/\\n/g, '\n').replace(/\\"/g, '"').replace(/\\\\/g, '\\');
  }
  if (trimmed.length >= 2 && trimmed.startsWith("'") && trimmed.endsWith("'")) {
    return trimmed.slice(1, -1);
  }
  return trimmed;
}

function loadLocalEnv(filePath) {
  if (!fs.existsSync(filePath)) return;
  const raw = fs.readFileSync(filePath, 'utf8');
  for (const line of raw.split(/\r?\n/)) {
    const trimmed = line.trim();
    if (trimmed === '' || trimmed.startsWith('#')) continue;
    const match = /^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)=(.*)$/.exec(trimmed);
    if (!match) continue;
    const [, key, value] = match;
    if (process.env[key] === undefined) {
      process.env[key] = unquoteEnvValue(value);
    }
  }
}

function configureProductionOrigins() {
  loadLocalEnv(LOCAL_ENV_FILE);
  const domain = String(process.env.VIDEOCHAT_DEPLOY_DOMAIN || process.env.VIDEOCHAT_V1_PUBLIC_HOST || 'kingrt.com').trim();
  const apiDomain = String(process.env.VIDEOCHAT_DEPLOY_API_DOMAIN || `api.${domain}`).trim();
  const sfuDomain = String(process.env.VIDEOCHAT_DEPLOY_SFU_DOMAIN || `sfu.${domain}`).trim();
  return {
    apiOrigin: String(process.env.VITE_VIDEOCHAT_BACKEND_ORIGIN || `https://${apiDomain}`).replace(/\/+$/, ''),
    sfuOrigin: String(process.env.VITE_VIDEOCHAT_SFU_ORIGIN || `wss://${sfuDomain}`).replace(/\/+$/, ''),
  };
}

function credentialsFromEnv() {
  return {
    email: String(process.env.VIDEOCHAT_E2E_ADMIN_EMAIL || 'admin@intelligent-intern.com').trim(),
    password: String(process.env.VIDEOCHAT_E2E_ADMIN_PASSWORD || process.env.VIDEOCHAT_DEPLOY_ADMIN_PASSWORD || '').trim(),
  };
}

async function login(apiOrigin) {
  const credentials = credentialsFromEnv();
  if (credentials.email === '' || credentials.password === '') {
    throw new Error('Missing production admin credentials for proxy-budget probe.');
  }
  const response = await fetch(`${apiOrigin}/api/auth/login`, {
    method: 'POST',
    headers: { accept: 'application/json', 'content-type': 'application/json' },
    body: JSON.stringify(credentials),
  });
  const payload = await response.json().catch(() => null);
  if (!response.ok || payload?.status !== 'ok') {
    throw new Error(payload?.error?.message || `Login failed (${response.status}).`);
  }
  const sessionToken = String(payload?.session?.token || payload?.session?.id || '').trim();
  const userId = String(payload?.user?.id || '').trim();
  const displayName = String(payload?.user?.display_name || payload?.user?.email || 'Production Proxy Probe').trim();
  if (sessionToken === '' || userId === '') {
    throw new Error('Login payload did not include a session token and user id.');
  }
  return { sessionToken, userId, displayName };
}

function sleep(ms) {
  return new Promise((resolve) => {
    setTimeout(resolve, ms);
  });
}

function normalizeSocketData(data) {
  if (data instanceof ArrayBuffer) return Promise.resolve(data);
  if (ArrayBuffer.isView(data)) {
    return Promise.resolve(data.buffer.slice(data.byteOffset, data.byteOffset + data.byteLength));
  }
  if (typeof Blob !== 'undefined' && data instanceof Blob) {
    return data.arrayBuffer();
  }
  if (typeof data === 'string') return Promise.resolve(data);
  return Promise.resolve(data);
}

async function connectSfuSocket({ sfuOrigin, roomId, session, role }) {
  const url = new URL('/sfu', sfuOrigin);
  url.searchParams.set('room_id', roomId);
  url.searchParams.set('room', roomId);
  url.searchParams.set('token', session.sessionToken);
  url.searchParams.set('userId', session.userId);
  url.searchParams.set('name', session.displayName);
  url.searchParams.set('role', role);

  const ws = new WebSocket(url);
  ws.binaryType = 'arraybuffer';
  const state = {
    role,
    messages: [],
    binaryFrames: [],
    errors: [],
    close: null,
  };

  ws.addEventListener('message', (event) => {
    void normalizeSocketData(event.data).then((data) => {
      if (data instanceof ArrayBuffer) {
        state.binaryFrames.push(decodeBinaryEnvelope(data));
        return;
      }
      if (typeof data !== 'string') return;
      try {
        state.messages.push(JSON.parse(data));
      } catch {
        state.messages.push({ type: '__text__', text: data.slice(0, 240) });
      }
    });
  });
  ws.addEventListener('error', () => {
    state.errors.push({ type: 'error', at: Date.now() });
  });
  ws.addEventListener('close', (event) => {
    state.close = {
      code: Number(event.code || 0),
      reason: String(event.reason || ''),
      wasClean: Boolean(event.wasClean),
      at: Date.now(),
    };
  });

  await waitUntil(`${role} SFU socket open`, DEFAULT_TIMEOUT_MS, () => ws.readyState === WebSocket.OPEN);
  ws.send(JSON.stringify({ type: 'sfu/join', room_id: roomId, role }));
  await waitForMessage(state, (message) => String(message.type || '') === 'sfu/welcome', `${role} SFU welcome`);
  return { ws, state };
}

async function waitUntil(label, timeoutMs, probe) {
  const startedAt = Date.now();
  let lastValue = null;
  while ((Date.now() - startedAt) < timeoutMs) {
    lastValue = await probe();
    if (lastValue) return lastValue;
    await sleep(25);
  }
  throw new Error(`${label} timed out; last=${JSON.stringify(lastValue)}`);
}

function waitForMessage(state, predicate, label, timeoutMs = DEFAULT_TIMEOUT_MS) {
  return waitUntil(label, timeoutMs, () => state.messages.find(predicate) || null);
}

function waitForBinaryFrame(state, predicate, label, timeoutMs = DEFAULT_TIMEOUT_MS) {
  return waitUntil(label, timeoutMs, () => state.binaryFrames.find(predicate) || null);
}

async function waitForBufferedDrain(ws, lowWaterBytes, timeoutMs) {
  const startedAt = performance.now();
  while (ws.readyState === WebSocket.OPEN && Number(ws.bufferedAmount || 0) > lowWaterBytes) {
    if ((performance.now() - startedAt) >= timeoutMs) break;
    await sleep(16);
  }
  return {
    drainMs: Number((performance.now() - startedAt).toFixed(3)),
    bufferedAmount: Math.max(0, Number(ws.bufferedAmount || 0)),
  };
}

function encodeUtf8(value) {
  return new TextEncoder().encode(String(value || ''));
}

function writeUint64(view, offset, value) {
  const normalized = BigInt(Math.max(0, Math.floor(Number(value || 0))));
  view.setUint32(offset, Number(normalized & 0xffffffffn), true);
  view.setUint32(offset + 4, Number((normalized >> 32n) & 0xffffffffn), true);
}

function readUint64(view, offset) {
  const low = BigInt(view.getUint32(offset, true));
  const high = BigInt(view.getUint32(offset + 4, true));
  return Number((high << 32n) | low);
}

function encodeBinaryEnvelope({ publisherId, publisherUserId, trackId, frameSequence, frameType, payloadBytes, senderSentAtMs }) {
  const publisherIdBytes = encodeUtf8(publisherId);
  const publisherUserIdBytes = encodeUtf8(publisherUserId);
  const trackIdBytes = encodeUtf8(trackId);
  const frameIdBytes = encodeUtf8(`proxy_probe_${frameSequence}`);
  const metadataJsonBytes = encodeUtf8(JSON.stringify({
    codec_id: 'wlvc_wasm',
    runtime_id: 'wlvc_sfu',
    outgoing_video_quality_profile: 'quality',
    budget_max_encoded_bytes_per_frame: QUALITY_MAX_PAYLOAD_BYTES,
    budget_max_keyframe_bytes_per_frame: 1536 * 1024,
    budget_max_wire_bytes_per_second: 2200 * 1024,
    budget_max_buffered_bytes: QUALITY_MAX_BUFFERED_BYTES,
    binary_continuation_threshold_bytes: CONTINUATION_THRESHOLD_BYTES,
    layout_mode: 'full_frame',
    layer_id: 'full',
    cache_epoch: 1,
  }));
  const headerByteLength = 46;
  const totalByteLength = headerByteLength
    + publisherIdBytes.byteLength
    + publisherUserIdBytes.byteLength
    + trackIdBytes.byteLength
    + frameIdBytes.byteLength
    + metadataJsonBytes.byteLength
    + payloadBytes.byteLength;
  const out = new ArrayBuffer(totalByteLength);
  const view = new DataView(out);
  const bytes = new Uint8Array(out);

  bytes.set(Uint8Array.from([75, 83, 70, 66]), 0);
  view.setUint8(4, 2);
  view.setUint8(5, 1);
  view.setUint8(6, frameType === 'keyframe' ? 1 : 0);
  view.setUint8(7, 0);
  view.setUint16(8, 2, true);
  view.setUint16(10, publisherIdBytes.byteLength, true);
  view.setUint16(12, publisherUserIdBytes.byteLength, true);
  view.setUint16(14, trackIdBytes.byteLength, true);
  view.setUint16(16, frameIdBytes.byteLength, true);
  view.setUint32(18, metadataJsonBytes.byteLength, true);
  writeUint64(view, 22, Date.now());
  view.setUint32(30, frameSequence, true);
  writeUint64(view, 34, senderSentAtMs);
  view.setUint32(42, payloadBytes.byteLength, true);

  let offset = headerByteLength;
  bytes.set(publisherIdBytes, offset);
  offset += publisherIdBytes.byteLength;
  bytes.set(publisherUserIdBytes, offset);
  offset += publisherUserIdBytes.byteLength;
  bytes.set(trackIdBytes, offset);
  offset += trackIdBytes.byteLength;
  bytes.set(frameIdBytes, offset);
  offset += frameIdBytes.byteLength;
  bytes.set(metadataJsonBytes, offset);
  offset += metadataJsonBytes.byteLength;
  bytes.set(payloadBytes, offset);
  return out;
}

function decodeBinaryEnvelope(buffer) {
  const fallback = { valid: false, byteLength: Number(buffer?.byteLength || 0) };
  if (!(buffer instanceof ArrayBuffer) || buffer.byteLength < 46) return fallback;
  const view = new DataView(buffer);
  const bytes = new Uint8Array(buffer);
  if (bytes[0] !== 75 || bytes[1] !== 83 || bytes[2] !== 70 || bytes[3] !== 66) return fallback;
  const version = view.getUint8(4);
  if (version !== 1 && version !== 2) return fallback;
  const headerByteLength = version === 2 ? 46 : 42;
  const publisherIdLength = view.getUint16(10, true);
  const publisherUserIdLength = view.getUint16(12, true);
  const trackIdLength = view.getUint16(14, true);
  const frameIdLength = view.getUint16(16, true);
  const metadataLength = version === 2 ? view.getUint32(18, true) : 0;
  const timestamp = version === 2 ? readUint64(view, 22) : readUint64(view, 18);
  const frameSequence = version === 2 ? view.getUint32(30, true) : view.getUint32(26, true);
  const senderSentAtMs = version === 2 ? readUint64(view, 34) : readUint64(view, 30);
  const payloadBytes = version === 2 ? view.getUint32(42, true) : view.getUint32(38, true);
  const expected = headerByteLength + publisherIdLength + publisherUserIdLength + trackIdLength + frameIdLength + metadataLength + payloadBytes;
  if (expected !== buffer.byteLength) return fallback;
  let offset = headerByteLength;
  const publisherId = new TextDecoder().decode(bytes.subarray(offset, offset + publisherIdLength));
  offset += publisherIdLength + publisherUserIdLength;
  const trackId = new TextDecoder().decode(bytes.subarray(offset, offset + trackIdLength));
  return {
    valid: true,
    byteLength: buffer.byteLength,
    payloadBytes,
    publisherId,
    trackId,
    frameSequence,
    timestamp,
    senderSentAtMs,
    binaryContinuationRequired: buffer.byteLength > CONTINUATION_THRESHOLD_BYTES,
  };
}

function makePayload(size, seed) {
  const bytes = new Uint8Array(size);
  for (let index = 0; index < bytes.byteLength; index += 1) {
    bytes[index] = (index + (seed * 31)) & 0xff;
  }
  return bytes;
}

function assertNoSocketFailure(label, state) {
  const errors = state.messages.filter((message) => String(message.type || '') === 'sfu/error');
  if (errors.length > 0) {
    throw new Error(`${label} reported SFU errors: ${JSON.stringify(errors.slice(0, 3))}`);
  }
  if (state.errors.length > 0) {
    throw new Error(`${label} emitted websocket errors.`);
  }
  if (state.close) {
    throw new Error(`${label} websocket closed unexpectedly: ${JSON.stringify(state.close)}`);
  }
}

async function closeSocket(ws) {
  if (ws.readyState === WebSocket.CLOSED) return;
  await new Promise((resolve) => {
    ws.addEventListener('close', resolve, { once: true });
    if (ws.readyState === WebSocket.OPEN) {
      try {
        ws.send(JSON.stringify({ type: 'sfu/leave' }));
      } catch {}
    }
    ws.close(1000, 'proxy_budget_probe_done');
    setTimeout(resolve, 1000);
  });
}

async function main() {
  const origins = configureProductionOrigins();
  const session = await login(origins.apiOrigin);
  const roomId = `proxy-budget-${Date.now().toString(36)}`;
  const trackId = `proxy-track-${Date.now().toString(36)}`;
  const publisher = await connectSfuSocket({ sfuOrigin: origins.sfuOrigin, roomId, session, role: 'publisher' });
  const subscriber = await connectSfuSocket({ sfuOrigin: origins.sfuOrigin, roomId, session, role: 'subscriber' });

  try {
    publisher.ws.send(JSON.stringify({ type: 'sfu/publish', track_id: trackId, kind: 'video', label: 'Production proxy budget probe' }));
    await waitForMessage(publisher.state, (message) => String(message.type || '') === 'sfu/published', 'publisher track acknowledgement');
    await waitForMessage(subscriber.state, (message) => ['sfu/joined', 'sfu/tracks'].includes(String(message.type || '')), 'subscriber sees publisher', 5000)
      .catch(() => null);

    const samples = [];
    for (let index = 0; index < FRAME_SIZES.length; index += 1) {
      const payloadBytes = makePayload(FRAME_SIZES[index], index + 1);
      const frameSequence = index + 1;
      const senderSentAtMs = Date.now();
      const envelope = encodeBinaryEnvelope({
        publisherId: session.userId,
        publisherUserId: session.userId,
        trackId,
        frameSequence,
        frameType: index === 0 ? 'keyframe' : 'delta',
        payloadBytes,
        senderSentAtMs,
      });
      const bufferedBefore = Math.max(0, Number(publisher.ws.bufferedAmount || 0));
      const sentAt = performance.now();
      publisher.ws.send(envelope);
      const bufferedAfterSend = Math.max(0, Number(publisher.ws.bufferedAmount || 0));
      const drain = await waitForBufferedDrain(publisher.ws, DRAIN_LOW_WATER_BYTES, 4000);
      const received = await waitForBinaryFrame(
        subscriber.state,
        (frame) => frame.valid && frame.frameSequence === frameSequence && frame.payloadBytes === payloadBytes.byteLength,
        `subscriber receives sequence ${frameSequence}`,
      );
      const roundTripMs = Number((performance.now() - sentAt).toFixed(3));
      samples.push({
        frameSequence,
        payloadBytes: payloadBytes.byteLength,
        wireBytes: envelope.byteLength,
        continuationRequired: envelope.byteLength > CONTINUATION_THRESHOLD_BYTES,
        bufferedBefore,
        bufferedAfterSend,
        bufferedAfterDrain: drain.bufferedAmount,
        drainMs: drain.drainMs,
        roundTripMs,
        receivedWireBytes: received.byteLength,
        receivedPublisherId: received.publisherId,
      });
      assertNoSocketFailure('publisher', publisher.state);
      assertNoSocketFailure('subscriber', subscriber.state);
      if (bufferedAfterSend > CRITICAL_BUFFERED_BYTES) {
        throw new Error(`publisher bufferedAmount reached critical pressure: ${bufferedAfterSend}`);
      }
      if (drain.bufferedAmount > QUALITY_MAX_BUFFERED_BYTES) {
        throw new Error(`publisher bufferedAmount stayed above quality budget after drain: ${drain.bufferedAmount}`);
      }
    }

    console.log('[production-socket-proxy-budget] PASS');
    console.log(JSON.stringify({
      apiOrigin: origins.apiOrigin,
      sfuOrigin: origins.sfuOrigin,
      roomId,
      continuationThresholdBytes: CONTINUATION_THRESHOLD_BYTES,
      qualityMaxPayloadBytes: QUALITY_MAX_PAYLOAD_BYTES,
      qualityMaxBufferedBytes: QUALITY_MAX_BUFFERED_BYTES,
      samples,
    }, null, 2));
  } finally {
    await Promise.allSettled([
      closeSocket(publisher.ws),
      closeSocket(subscriber.ws),
    ]);
  }
}

main().then(() => {
  process.exit(0);
}).catch((error) => {
  console.error('[production-socket-proxy-budget] FAIL');
  console.error(error?.stack || error?.message || error);
  process.exit(1);
});
