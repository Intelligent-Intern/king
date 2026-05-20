import assert from 'node:assert/strict';

import {
  normalizeSttRuntimeConfig,
  normalizeTranscriptMessages,
  shouldUploadSttChunk,
} from '../../src/domain/realtime/workspace/callWorkspace/sttUploader.js';

const config = normalizeSttRuntimeConfig({
  calls: {
    stt: {
      enabled: true,
      upload_endpoint: '/api/calls/{call_id}/stt/chunks?room={room_id}',
      control_endpoint: '/api/calls/{call_id}/stt',
      chunk_ms: 1400,
      min_rms: 0.02,
      min_speech_ratio: 0.25,
    },
  },
}, {
  callId: 'call 1',
  roomId: 'main room',
});

assert.equal(config.active, true, 'backend STT config should activate uploader availability');
assert.equal(config.endpoint, '/api/calls/call%201/stt/chunks?room=main%20room');
assert.equal(config.controlEndpoint, '/api/calls/call%201/stt');
assert.equal(config.chunkMs, 1400);
assert.equal(config.minRms, 0.02);
assert.equal(config.minSpeechRatio, 0.25);

assert.equal(
  shouldUploadSttChunk({ blobSize: 100, totalSamples: 20, speechSamples: 8, maxRms: 0.04 }, config),
  true,
  'speech-like chunk should upload',
);
assert.equal(
  shouldUploadSttChunk({ blobSize: 100, totalSamples: 20, speechSamples: 1, maxRms: 0.04 }, config),
  false,
  'speech pauses should be filtered',
);
assert.equal(
  shouldUploadSttChunk({ blobSize: 100, totalSamples: 20, speechSamples: 8, maxRms: 0.004 }, config),
  false,
  'low RMS silence should be filtered',
);

const directMessages = normalizeTranscriptMessages({
  room_id: 'main',
  messages: [{
    id: 'chat_stt_1',
    text: 'hello from stt',
    sender: { user_id: 7, display_name: 'Pat', role: 'user' },
    server_time: '2026-05-10T12:00:00Z',
  }],
});
assert.equal(directMessages.length, 1);
assert.equal(directMessages[0].type, 'chat/message');
assert.equal(directMessages[0].message.text, 'hello from stt');

const synthesizedMessages = normalizeTranscriptMessages({
  transcript: { text: 'fallback transcript' },
}, {
  roomId: 'room-a',
  userId: 5,
  displayName: 'Current User',
  role: 'admin',
});
assert.equal(synthesizedMessages.length, 1);
assert.equal(synthesizedMessages[0].room_id, 'room-a');
assert.equal(synthesizedMessages[0].message.sender.user_id, 5);
assert.equal(synthesizedMessages[0].message.text, 'fallback transcript');

const backendResultMessages = normalizeTranscriptMessages({
  status: 'ok',
  result: {
    state: 'archived',
    message: {
      id: 'chat_stt_2',
      text: 'backend archived transcript',
      sender: { user_id: 9, display_name: 'Lee', role: 'user' },
      server_time: '2026-05-10T12:01:00Z',
    },
  },
  time: '2026-05-10T12:01:00Z',
}, { roomId: 'main' });
assert.equal(backendResultMessages.length, 1);
assert.equal(backendResultMessages[0].message.text, 'backend archived transcript');

process.stdout.write('[call-stt-uploader-contract] PASS\n');
