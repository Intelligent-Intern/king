export const STT_DEFAULT_CHUNK_MS = 1800;
export const STT_DEFAULT_MIN_RMS = 0.018;
export const STT_DEFAULT_MIN_SPEECH_RATIO = 0.16;

function clampNumber(value, fallback, min, max) {
  const number = Number(value);
  if (!Number.isFinite(number)) return fallback;
  return Math.max(min, Math.min(max, number));
}

function firstString(...values) {
  for (const value of values) {
    const text = String(value || '').trim();
    if (text !== '') return text;
  }
  return '';
}

function normalizeTemplateEndpoint(endpoint, { callId = '', roomId = '' } = {}) {
  const text = String(endpoint || '').trim();
  if (text === '') return '';
  return text
    .replaceAll('{call_id}', encodeURIComponent(String(callId || '').trim()))
    .replaceAll('{callId}', encodeURIComponent(String(callId || '').trim()))
    .replaceAll('{room_id}', encodeURIComponent(String(roomId || '').trim()))
    .replaceAll('{roomId}', encodeURIComponent(String(roomId || '').trim()));
}

export function normalizeSttRuntimeConfig(payload, context = {}) {
  const source = payload && typeof payload === 'object' ? payload : {};
  const candidates = [
    source.stt,
    source.speech_to_text,
    source.speechToText,
    source.calls?.stt,
    source.calls?.speech_to_text,
    source.runtime?.stt,
  ].filter((entry) => entry && typeof entry === 'object');
  const config = candidates[0] || {};

  return {
    active: Boolean(config.active ?? config.enabled ?? config.upload_enabled ?? config.uploadEnabled),
    endpoint: normalizeTemplateEndpoint(firstString(
      config.chunk_upload_endpoint,
      config.chunkUploadEndpoint,
      config.upload_endpoint,
      config.uploadEndpoint,
      source.calls?.stt_chunk_upload_endpoint,
      source.calls?.sttChunkUploadEndpoint,
    ), context),
    controlEndpoint: normalizeTemplateEndpoint(firstString(
      config.control_endpoint,
      config.controlEndpoint,
      config.settings_endpoint,
      config.settingsEndpoint,
      source.calls?.stt_control_endpoint,
      source.calls?.sttControlEndpoint,
    ), context),
    chunkMs: clampNumber(config.chunk_ms ?? config.chunkMs, STT_DEFAULT_CHUNK_MS, 800, 8000),
    minRms: clampNumber(config.min_rms ?? config.minRms, STT_DEFAULT_MIN_RMS, 0.001, 0.2),
    minSpeechRatio: clampNumber(config.min_speech_ratio ?? config.minSpeechRatio, STT_DEFAULT_MIN_SPEECH_RATIO, 0.02, 1),
    mimeType: firstString(config.mime_type, config.mimeType),
  };
}

export function normalizeTranscriptMessages(payload, fallback = {}) {
  const source = payload && typeof payload === 'object' ? payload : {};
  const result = source.result && typeof source.result === 'object' ? source.result : {};
  const rows = [];
  if (Array.isArray(source.messages)) rows.push(...source.messages);
  if (Array.isArray(source.transcript_messages)) rows.push(...source.transcript_messages);
  if (source.message && typeof source.message === 'object') rows.push(source.message);
  if (source.transcript?.message && typeof source.transcript.message === 'object') rows.push(source.transcript.message);
  if (result.message && typeof result.message === 'object') rows.push(result.message);

  const roomId = firstString(source.room_id, source.roomId, result.room_id, result.roomId, fallback.roomId, 'lobby');
  const time = firstString(source.time, new Date().toISOString());
  const normalized = rows
    .map((message) => ({
      type: 'chat/message',
      room_id: firstString(message.room_id, message.roomId, roomId),
      message,
      time,
    }))
    .filter((row) => firstString(row.message?.text) !== '');

  const text = firstString(
    source.text,
    source.transcript_text,
    source.transcriptText,
    source.transcript?.text,
    result.text,
    result.transcript_text,
    result.transcript?.text,
  );
  if (normalized.length === 0 && text !== '') {
    normalized.push({
      type: 'chat/message',
      room_id: roomId,
      message: {
        id: firstString(source.id, `stt_${Date.now()}_${Math.random().toString(16).slice(2, 8)}`),
        text,
        sender: {
          user_id: Number(fallback.userId || 0) || 0,
          display_name: firstString(fallback.displayName, 'Unknown user'),
          role: firstString(fallback.role, 'user'),
        },
        server_time: time,
        client_message_id: null,
      },
      time,
    });
  }

  return normalized;
}

export function shouldUploadSttChunk({ blobSize = 0, speechSamples = 0, totalSamples = 0, maxRms = 0 }, config = {}) {
  if (Number(blobSize || 0) <= 0) return false;
  if (Number(totalSamples || 0) <= 0) return false;
  const minRms = clampNumber(config.minRms, STT_DEFAULT_MIN_RMS, 0.001, 0.2);
  const minSpeechRatio = clampNumber(config.minSpeechRatio, STT_DEFAULT_MIN_SPEECH_RATIO, 0.02, 1);
  return Number(maxRms || 0) >= minRms
    && (Number(speechSamples || 0) / Number(totalSamples || 1)) >= minSpeechRatio;
}

function pickSupportedMimeType(MediaRecorderCtor, preferred) {
  const candidates = [
    preferred,
    'audio/webm;codecs=opus',
    'audio/webm',
    'audio/ogg;codecs=opus',
    'audio/mp4',
  ].map((value) => String(value || '').trim()).filter(Boolean);

  for (const candidate of candidates) {
    if (typeof MediaRecorderCtor.isTypeSupported !== 'function' || MediaRecorderCtor.isTypeSupported(candidate)) {
      return candidate;
    }
  }
  return '';
}

export class CallWorkspaceSttUploader {
  constructor(options = {}) {
    this.options = options;
    this.recorder = null;
    this.audioContext = null;
    this.source = null;
    this.analyser = null;
    this.sampleTimer = null;
    this.uploading = false;
    this.started = false;
    this.stats = { speechSamples: 0, totalSamples: 0, maxRms: 0 };
  }

  emit(code, message, details = {}) {
    if (typeof this.options.onDiagnostic === 'function') {
      this.options.onDiagnostic({ code, message, details, at: new Date().toISOString() });
    }
  }

  start(stream, config) {
    this.stop();
    if (!(stream instanceof MediaStream) || stream.getAudioTracks().length === 0) {
      this.emit('recorder_unsupported', 'No local microphone stream is available.');
      return false;
    }
    if (typeof MediaRecorder === 'undefined') {
      this.emit('recorder_unsupported', 'MediaRecorder is not available in this browser.');
      return false;
    }

    const mimeType = pickSupportedMimeType(MediaRecorder, config?.mimeType);
    try {
      this.attachAnalyser(stream, config);
      this.recorder = new MediaRecorder(stream, mimeType !== '' ? { mimeType } : {});
      this.recorder.addEventListener('dataavailable', (event) => {
        void this.handleChunk(event?.data || null, config);
      });
      this.recorder.addEventListener('error', () => {
        this.emit('recorder_unsupported', 'MediaRecorder failed while capturing local microphone chunks.');
      });
      this.recorder.start(clampNumber(config?.chunkMs, STT_DEFAULT_CHUNK_MS, 800, 8000));
      this.started = true;
      this.emit('recorder_started', 'STT mic uploader started.');
      return true;
    } catch {
      this.stop();
      this.emit('recorder_unsupported', 'Could not start local microphone recorder.');
      return false;
    }
  }

  attachAnalyser(stream, config = {}) {
    const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextCtor) return;
    this.audioContext = new AudioContextCtor({ latencyHint: 'interactive' });
    this.source = this.audioContext.createMediaStreamSource(stream);
    this.analyser = this.audioContext.createAnalyser();
    this.analyser.fftSize = 512;
    this.source.connect(this.analyser);
    const data = new Uint8Array(this.analyser.fftSize);
    const minRms = clampNumber(config.minRms, STT_DEFAULT_MIN_RMS, 0.001, 0.2);
    this.sampleTimer = setInterval(() => {
      if (!this.analyser) return;
      this.analyser.getByteTimeDomainData(data);
      let energy = 0;
      for (let index = 0; index < data.length; index += 1) {
        const centered = (data[index] - 128) / 128;
        energy += centered * centered;
      }
      const rms = Math.sqrt(energy / data.length);
      this.stats.totalSamples += 1;
      if (rms >= minRms) this.stats.speechSamples += 1;
      if (rms > this.stats.maxRms) this.stats.maxRms = rms;
    }, 120);
  }

  async handleChunk(blob, config = {}) {
    const snapshot = { ...this.stats, blobSize: Number(blob?.size || 0) };
    this.stats = { speechSamples: 0, totalSamples: 0, maxRms: 0 };
    if (!(blob instanceof Blob) || !this.started) return;
    if (!shouldUploadSttChunk(snapshot, config)) {
      this.emit('transcript_empty', 'Skipped silent local microphone chunk.', snapshot);
      return;
    }
    if (this.uploading) {
      this.emit('upload_skipped_busy', 'Skipped STT chunk because the previous upload is still running.');
      return;
    }

    this.uploading = true;
    try {
      const payload = await this.options.uploadChunk(blob, snapshot);
      const messages = normalizeTranscriptMessages(payload, this.options.fallbackMessage || {});
      if (messages.length === 0) {
        this.emit('transcript_empty', 'STT backend accepted audio but returned no transcript.');
      } else {
        for (const message of messages) this.options.onTranscript?.(message);
        this.emit('transcript_accepted', 'STT transcript accepted.', { count: messages.length });
      }
    } catch (error) {
      this.emit('upload_failed', error instanceof Error ? error.message : 'STT upload failed.');
    } finally {
      this.uploading = false;
    }
  }

  stop() {
    this.started = false;
    if (this.sampleTimer !== null) clearInterval(this.sampleTimer);
    this.sampleTimer = null;
    if (this.recorder && this.recorder.state !== 'inactive') {
      try {
        this.recorder.stop();
      } catch {
        // ignore recorder shutdown races
      }
    }
    this.recorder = null;
    if (this.source?.disconnect) this.source.disconnect();
    if (this.analyser?.disconnect) this.analyser.disconnect();
    this.source = null;
    this.analyser = null;
    if (this.audioContext?.close) this.audioContext.close().catch(() => {});
    this.audioContext = null;
    this.uploading = false;
    this.stats = { speechSamples: 0, totalSamples: 0, maxRms: 0 };
  }
}
