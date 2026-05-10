import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '..', '..');
const monitorPath = path.join(root, '..', 'scripts', 'live-chat-monitor.sh');
const source = fs.readFileSync(monitorPath, 'utf8');

for (const command of ['start)', 'stop)', 'status)', 'watchdog)', 'once)', 'post)', 'run)']) {
  assert.ok(source.includes(command), `live chat monitor must expose ${command}`);
}

assert.match(source, /PID_FILE=.*monitor\.pid/, 'monitor must use a local pid file');
assert.match(source, /LOG_FILE=.*monitor\.log/, 'monitor must use a local log file');
assert.match(source, /HEARTBEAT_FILE=.*heartbeat/, 'monitor must write a heartbeat');
assert.match(source, /POLL_SECONDS=.*60/, 'default chat poll cadence must be 60 seconds');
assert.match(source, /WATCHDOG_STALE_SECONDS=.*900/, 'watchdog staleness must default to 15 minutes');
assert.match(source, /setsid/, 'monitor start must detach from the invoking shell process group when available');
assert.match(source, /chat-archive\?/, 'monitor must read chat through the authenticated HTTP DB-tail endpoint');
assert.match(source, /Authorization.*Bearer/, 'monitor HTTP reads must authenticate without a public diagnostics API');
assert.match(source, /chat\/send/, 'status posts must use the normal chat/send websocket path');
assert.match(source, /chat\/ack/, 'status posts must wait for chat/ack');
assert.match(source, /reader_session_sql/, 'monitor may resolve a reader session internally without logging tokens');
assert.doesNotMatch(source, /message_json\s+FROM call_chat_messages/, 'monitor must not tail full message_json payloads');
assert.doesNotMatch(source, /FROM call_chat_messages/, 'monitor must not bypass the DB-tail API for chat reads');

for (const forbidden of ['raw ICE', 'raw SDP', 'full websocket URL']) {
  assert.doesNotMatch(source, new RegExp(forbidden, 'i'), `monitor source must not instruct logging ${forbidden}`);
}

assert.match(source, /redact_stream\(\)/, 'monitor must define redaction');
assert.match(source, /REDACTED_MEDIA_PAYLOAD/, 'monitor must redact media payloads');
assert.match(source, /DRY-RUN would read latest/, 'dry-run once must not open remote connections');

console.log('live chat monitor contract passed');
