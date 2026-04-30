import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-no-frame-persistence-regression-contract] FAIL: ${message}`);
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

function requireNotContains(source, needle, label) {
  assert.equal(source.includes(needle), false, `${label} must not contain: ${needle}`);
}

function read(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

function listFiles(root, predicate = () => true) {
  const entries = fs.readdirSync(root, { withFileTypes: true });
  const files = [];
  for (const entry of entries) {
    const fullPath = path.join(root, entry.name);
    if (entry.isDirectory()) {
      files.push(...listFiles(fullPath, predicate));
      continue;
    }
    if (entry.isFile() && predicate(fullPath)) {
      files.push(fullPath);
    }
  }
  return files;
}

function collectSource(files) {
  return files
    .map((filePath) => ({
      filePath,
      source: fs.readFileSync(filePath, 'utf8'),
    }));
}

function lineForOffset(source, offset) {
  const start = source.lastIndexOf('\n', offset) + 1;
  const end = source.indexOf('\n', offset);
  return source.slice(start, end === -1 ? source.length : end);
}

function assertNoDisallowedMatches(sources, pattern, label, allow = () => false) {
  for (const { filePath, source } of sources) {
    pattern.lastIndex = 0;
    let match;
    while ((match = pattern.exec(source)) !== null) {
      const line = lineForOffset(source, match.index);
      if (!allow({ filePath, source, match, line })) {
        const relativePath = path.relative(repoRoot, filePath);
        fail(`${label} in ${relativePath}: ${line.trim()}`);
      }
      if (match.index === pattern.lastIndex) {
        pattern.lastIndex += 1;
      }
    }
  }
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '..', '..');
const backendRoot = path.resolve(frontendRoot, '../backend-king-php');
const backendRealtimeRoot = path.join(backendRoot, 'domain/realtime');
const migrationPath = path.join(backendRoot, 'support/database_migrations.php');

try {
  const store = read('../backend-king-php/domain/realtime/realtime_sfu_store.php');
  const frameBuffer = read('../backend-king-php/domain/realtime/realtime_sfu_frame_buffer.php');
  const gateway = read('../backend-king-php/domain/realtime/realtime_sfu_gateway.php');
  const relay = read('../backend-king-php/domain/realtime/realtime_sfu_broker_replay.php');
  const subscriberBudget = read('../backend-king-php/domain/realtime/realtime_sfu_subscriber_budget.php');
  const migrations = read('../backend-king-php/support/database_migrations.php');
  const compose = read('../docker-compose.v1.yml');
  const sfuClient = read('src/lib/sfu/sfuClient.ts');
  const mediaTransport = read('src/lib/sfu/mediaTransport.ts');
  const publisherPipeline = read('src/domain/realtime/local/publisherPipeline.js');

  requireContains(store, 'CREATE TABLE IF NOT EXISTS sfu_publishers', 'SFU bootstrap persists publisher metadata');
  requireContains(store, 'CREATE TABLE IF NOT EXISTS sfu_tracks', 'SFU bootstrap persists track metadata');
  requireContains(store, 'CREATE TABLE IF NOT EXISTS sfu_frames', 'SFU bootstrap creates bounded frame buffer table');
  requireContains(store, 'frame_row_id INTEGER PRIMARY KEY AUTOINCREMENT', 'SFU frame buffer has monotonic cursor rows');
  requireContains(store, 'payload_json TEXT NOT NULL', 'SFU frame buffer stores JSON-safe frame payloads');
  requireContains(store, "require_once __DIR__ . '/realtime_sfu_frame_buffer.php';", 'SFU store loads focused frame-buffer helper');
  requireContains(frameBuffer, 'function videochat_sfu_frame_buffer_ttl_ms(): int', 'SFU frame buffer has a short TTL');
  requireContains(frameBuffer, 'return 2500;', 'SFU frame buffer keeps frame records short-lived');
  requireContains(frameBuffer, 'function videochat_sfu_frame_buffer_max_rows_per_room(): int', 'SFU frame buffer has a room row cap');
  requireContains(frameBuffer, 'function videochat_sfu_frame_buffer_max_room_bytes(): int', 'SFU frame buffer has a room byte cap');
  requireContains(frameBuffer, 'function videochat_sfu_frame_buffer_select_age_biased_eviction_rows(', 'SFU frame buffer has age-biased eviction');
  requireContains(frameBuffer, 'sfu_frame_buffer_age_biased_eviction', 'SFU frame buffer reports eviction diagnostics');
  requireContains(store, 'function videochat_sfu_decode_stored_frame_payload', 'SFU frame buffer decodes stored payloads before replay');
  requireContains(frameBuffer, 'function videochat_sfu_insert_frame', 'SFU frame buffer has one insert helper');
  requireContains(frameBuffer, 'function videochat_sfu_fetch_buffered_frames', 'SFU frame buffer has one replay helper');
  requireContains(migrations, "'name' => '0020_drop_legacy_sfu_frame_persistence'", 'migration keeps legacy frame persistence removal');
  requireContains(migrations, "'DROP TABLE IF EXISTS sfu_frames'", 'migration drops legacy sfu_frames table');
  requireNotContains(store, 'videochat_sfu_encode_stored_frame_payload', 'legacy stored frame helper');

  requireContains(store, "if ($type === 'sfu/frame' || $type === 'sfu/frame-chunk') {", 'JSON media command rejection gate');
  requireContains(store, "'error' => 'binary_media_required'", 'JSON media command rejection reason');
  requireContains(store, 'king_websocket_send($socket, $binaryPayload, true)', 'outbound media frame uses binary WebSocket send');
  requireContains(store, "'binary_media_required' => true", 'outbound media frame is marked binary-required');
  requireContains(gateway, "case 'sfu/frame':", 'gateway accepts decoded binary media frames');
  requireContains(gateway, '$processFramePayload($stampKingReceiveMetrics($msg));', 'gateway routes decoded frames through live processor');
  requireContains(gateway, "case 'sfu/frame-chunk':", 'gateway rejects JSON frame chunks');
  requireContains(gateway, "'error' => 'binary_media_required'", 'gateway JSON frame chunk rejection reason');
  requireContains(compose, 'VIDEOCHAT_KING_DB_PATH: /data/video-chat.sqlite', 'persistent application database stays on /data');
  requireContains(compose, 'VIDEOCHAT_KING_SFU_BROKER_DB_PATH: "${VIDEOCHAT_KING_SFU_BROKER_DB_PATH:-/sfu-buffer/video-chat-sfu-broker.sqlite}"', 'SFU broker defaults to tmpfs path');
  requireContains(compose, 'tmpfs:', 'SFU service mounts tmpfs for broker replay');
  requireContains(compose, '/sfu-buffer:rw,noexec,nosuid,nodev,size=${VIDEOCHAT_V1_SFU_BROKER_TMPFS_SIZE:-256m},mode=1777', 'SFU broker tmpfs mount is bounded and non-executable');
  requireContains(gateway, 'function videochat_sfu_broker_storage_class', 'SFU broker exposes storage class diagnostics');
  requireContains(gateway, "'broker_storage_class' => videochat_sfu_broker_storage_class", 'SFU broker logs storage class diagnostics');
  requireContains(gateway, "return 'ram_tmpfs';", 'SFU broker recognizes RAM-backed tmpfs paths');
  requireNotContains(gateway, 'sqlite::memory:', 'SFU broker must not use per-connection sqlite memory DB');

  const processFrameStart = gateway.indexOf('$processFramePayload = static function');
  const processFrameEnd = gateway.indexOf('while (true)', processFrameStart);
  assert.ok(processFrameStart >= 0 && processFrameEnd > processFrameStart, 'gateway frame hotpath closure must be locatable');
  const frameHotPath = gateway.slice(processFrameStart, processFrameEnd);
  requireContains(frameHotPath, 'videochat_sfu_upsert_publisher(', 'frame hotpath only refreshes publisher presence');
  requireContains(frameHotPath, 'videochat_sfu_touch_track(', 'frame hotpath only refreshes track presence');
  requireContains(frameHotPath, '$relayFrame = videochat_sfu_frame_json_safe_for_live_relay($outboundFrame);', 'frame hotpath uses JSON-safe relay copy');
  requireContains(frameHotPath, 'videochat_sfu_insert_frame($activeSfuDatabase, $roomId, (string) $clientId, $relayFrame)', 'frame hotpath writes JSON-safe frame copy to bounded SQLite buffer');
  requireContains(frameHotPath, 'videochat_sfu_live_frame_relay_publish($roomId, (string) $clientId, $relayFrame)', 'frame hotpath publishes to live relay');
  requireContains(frameHotPath, 'videochat_sfu_direct_fanout_frame(', 'frame hotpath keeps direct live fanout');
  assert.equal(/\$activeSfuDatabase->(?:prepare|exec|query)\s*\(/.test(frameHotPath), false, 'frame hotpath must not write frame payload SQL directly');

  requireContains(relay, 'function videochat_sfu_live_frame_relay_ttl_ms(): int', 'live relay has transient TTL');
  requireContains(relay, 'return 2500;', 'live relay keeps frame records short-lived');
  requireContains(relay, 'function videochat_sfu_live_frame_relay_max_room_bytes(): int', 'live relay bounds room bytes');
  requireContains(relay, 'function videochat_sfu_live_frame_relay_cleanup_room(string $roomId, ?int $nowMs = null): void', 'live relay cleanup exists');
  requireContains(relay, "@unlink($file);", 'live relay deletes expired transient files');
  requireContains(relay, "return '/dev/shm/king-videochat-sfu-live-relay';", 'live relay prefers memory-backed transient storage');
  requireContains(relay, 'function videochat_sfu_sqlite_frame_buffer_poll(', 'broker replay polls the bounded SQLite frame buffer');
  requireContains(relay, "videochat_sfu_send_replay_frames_to_subscriber(", 'broker replay uses bounded subscriber pacing');
  requireContains(relay, "'sqlite_frame_buffer_poll'", 'SQLite frame-buffer replay labels its send path');
  requireContains(subscriberBudget, 'videochat_sfu_send_outbound_message($subClient[\'websocket\'], $frameForSubscriber', 'direct fanout sends live binary frames');

  requireContains(sfuClient, 'async sendEncodedFrame(frame: SFUEncodedFrame): Promise<boolean>', 'client sends encoded frames live');
  requireContains(sfuClient, 'this.mediaTransport.sendBinaryFrame(encoded)', 'client sends binary envelope through media transport abstraction');
  requireContains(mediaTransport, 'socket.send(payload)', 'WebSocket fallback media transport performs the actual binary send');
  requireContains(sfuClient, 'binary_media_required: true', 'client marks binary-required media');
  requireContains(sfuClient, 'direct legacy JSON/base64 fallback has been removed', 'client has no legacy JSON media fallback');
  requireContains(publisherPipeline, 'const frameSent = await sendClient.sendEncodedFrame(outgoingFrame);', 'publisher pipeline sends frames to live SFU client');

  const backendSources = collectSource([
    ...listFiles(backendRealtimeRoot, (filePath) => filePath.endsWith('.php')),
    migrationPath,
  ]);
  const frontendSfuSources = collectSource([
    ...listFiles(path.resolve(frontendRoot, 'src/lib/sfu'), (filePath) => /\.(ts|js)$/.test(filePath)),
    ...listFiles(path.resolve(frontendRoot, 'src/domain/realtime/sfu'), (filePath) => /\.js$/.test(filePath)),
    ...listFiles(path.resolve(frontendRoot, 'src/domain/realtime/local'), (filePath) => /\.js$/.test(filePath)),
  ]);

  assertNoDisallowedMatches(
    backendSources,
    /\bvideochat_sfu_(?:upsert|store|persist|encode_stored)_frame\b/gi,
    'legacy backend frame persistence helper',
  );
  assertNoDisallowedMatches(
    frontendSfuSources,
    /\b(indexedDB|localStorage|sessionStorage|openDatabase|sqlite|sfu_frames)\b/gi,
    'frontend SFU frame persistence API',
  );

  process.stdout.write('[sfu-no-frame-persistence-regression-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
