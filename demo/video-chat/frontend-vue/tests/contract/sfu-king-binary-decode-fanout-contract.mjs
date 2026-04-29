import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-king-binary-decode-fanout-contract] FAIL: ${message}`);
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

function read(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

try {
  const helper = read('../backend-king-php/domain/realtime/realtime_sfu_binary_payload.php');
  const subscriberBudget = read('../backend-king-php/domain/realtime/realtime_sfu_subscriber_budget.php');
  const store = read('../backend-king-php/domain/realtime/realtime_sfu_store.php');
  const gateway = read('../backend-king-php/domain/realtime/realtime_sfu_gateway.php');

  requireContains(store, "require_once __DIR__ . '/realtime_sfu_binary_payload.php';", 'SFU store loads binary payload helpers');
  requireContains(helper, 'function videochat_sfu_base64url_encoded_length(int $byteLength): int', 'payload char metrics are computed without base64 allocation');
  requireContains(helper, 'function videochat_sfu_frame_data_binary(array $frame): string', 'raw binary payload accessor');
  requireContains(helper, 'function videochat_sfu_transport_payload_chars(string $dataBase64, string $dataBinary): int', 'raw binary payload char helper');
  requireContains(helper, 'function videochat_sfu_frame_json_safe_for_live_relay(array $frame): array', 'JSON relay safety adapter');
  requireContains(helper, "$frame['data_base64'] = videochat_sfu_base64url_encode($dataBinary);", 'raw binary is converted only for JSON relay');

  requireContains(store, '$dataBinary = videochat_sfu_frame_data_binary($frame);', 'binary envelope encoder accepts raw payloads');
  requireContains(store, '} elseif ($dataBinary !== \'\') {', 'binary envelope encoder routes raw payloads before base64 decode');
  requireContains(store, '$payloadBytes = $dataBinary;', 'binary envelope encoder reuses raw payload bytes');
  requireContains(store, "'payload_chars' => videochat_sfu_base64url_encoded_length($payloadByteLength),", 'binary decoder projects payload chars without encoding');
  requireContains(store, "$payload['data_binary'] = $payloadBytes;", 'binary decoder keeps transport-only payload raw');
  assert.equal(
    store.includes('$payloadBase64 = videochat_sfu_base64url_encode($payloadBytes);'),
    false,
    'King binary decode must not convert transport-only media payloads to base64 before fanout',
  );

  requireContains(gateway, '$dataBinary = videochat_sfu_frame_data_binary($msg);', 'gateway reads raw binary payloads');
  requireContains(gateway, "$outboundFrame['data_binary'] = $dataBinary;", 'direct fanout frame keeps raw binary payload');
  requireContains(gateway, '$relayFrame = videochat_sfu_frame_json_safe_for_live_relay($outboundFrame);', 'live relay receives a JSON-safe copy');
  requireContains(gateway, 'videochat_sfu_live_frame_relay_publish($roomId, (string) $clientId, $relayFrame)', 'live relay publishes the JSON-safe frame');
  requireContains(gateway, 'videochat_sfu_direct_fanout_frame(', 'gateway delegates direct fanout without mutating relay frame');
  requireContains(subscriberBudget, '$frameForSubscriber = $outboundFrame;', 'direct fanout helper preserves the raw outbound frame per subscriber');
  requireContains(subscriberBudget, "videochat_sfu_send_outbound_message($subClient['websocket'], $frameForSubscriber", 'direct fanout sends the raw outbound frame');
  assert.ok(
    gateway.indexOf('$relayFrame = videochat_sfu_frame_json_safe_for_live_relay($outboundFrame);')
      < gateway.indexOf('videochat_sfu_direct_fanout_frame('),
    'JSON relay conversion must not replace the raw direct-fanout frame',
  );

  process.stdout.write('[sfu-king-binary-decode-fanout-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
