import { reactive } from 'vue';
import { arrayBufferToBase64Url } from '../../../lib/sfu/framePayload';
import { GOSSIP_DATA_LANE_CONFIG, VIDEOCHAT_MEDIA_CARRIER_CONFIG } from '../../../lib/gossipmesh/featureFlags';
import { GossipController, type GossipDelivery, type GossipFrameMessage } from '../../../lib/gossipmesh/gossipController';
import { GossipDirectTransport } from '../../../lib/gossipmesh/directGossipTransport';
import { createLocalPublisherPipelineHelpers } from '../local/publisherPipeline';
import {
  resolveSfuVideoQualityProfile,
  WLVC_ENCODE_ERROR_LOG_COOLDOWN_MS,
  WLVC_ENCODE_FAILURE_THRESHOLD,
  WLVC_ENCODE_FAILURE_WINDOW_MS,
  WLVC_ENCODE_WARMUP_MS,
} from '../workspace/config';
import {
  SFU_BACKGROUND_SNAPSHOT_DIFF_THRESHOLD,
  SFU_BACKGROUND_SNAPSHOT_MAX_CHANGED_RATIO,
  SFU_BACKGROUND_SNAPSHOT_MAX_PATCH_AREA_RATIO,
  SFU_BACKGROUND_SNAPSHOT_MIN_CHANGED_RATIO,
  SFU_BACKGROUND_SNAPSHOT_MIN_INTERVAL_MS,
  SFU_BACKGROUND_SNAPSHOT_SAMPLE_STRIDE,
  SFU_BACKGROUND_SNAPSHOT_TILE_HEIGHT,
  SFU_BACKGROUND_SNAPSHOT_TILE_WIDTH,
  SFU_SELECTIVE_TILE_DIFF_THRESHOLD,
  SFU_SELECTIVE_TILE_HEIGHT,
  SFU_SELECTIVE_TILE_MAX_CHANGED_RATIO,
  SFU_SELECTIVE_TILE_MAX_PATCH_AREA_RATIO,
  SFU_SELECTIVE_TILE_SAMPLE_STRIDE,
  SFU_SELECTIVE_TILE_WIDTH,
  SFU_WLVC_MAX_DELTA_FRAME_BYTES,
  SFU_WLVC_MAX_KEYFRAME_FRAME_BYTES,
} from '../workspace/callWorkspace/runtimeConfig';
import { applyGossipTopologyFromRoomStatePayload } from '../workspace/callWorkspace/roomStateTopology';

const SPUTNIK_USER_ID_BASE = 880000;
const SPUTNIK_FRAME_WIDTH = 320;
const SPUTNIK_FRAME_HEIGHT = 180;
const SPUTNIK_FRAME_RATE = 10;
const SPUTNIK_MEDIA_GENERATION = 1;
const SPUTNIK_TELEMETRY_INTERVAL_MS = 5000;
const SPUTNIK_KEYFRAME_INTERVAL = 1;
const SPUTNIK_AUDIO_CHUNK_MS = 1000;
const SPUTNIK_MAX_FAKE_PEERS = 10;
const ALICE_RESPONSE_TONE_HZ = 659;
const ALICE_RESPONSE_AUDIO_LEVEL_THRESHOLD = 0.05;
const ALICE_RESPONSE_DELAY_MS = 450;
const SPUTNIK_AUDIO_PING_GAIN = 0.14;
const ALICE_AUDIO_PING_GAIN = 0.24;

type MutableRef<T> = { value: T };

interface SputnikPeerDefinition {
  logicalPeerId: string;
  peerId: string;
  userId: number;
  displayName: string;
  color: string;
  toneHz: number;
  sessionToken?: string;
}

interface SputnikRuntimeOptions {
  enabled: boolean;
  getRoomId: () => string;
  getCallId: () => string;
  getSocketOrigins: () => string[];
  buildSocketUrl: (roomId: string, socketOrigin: string, callId: string) => string;
  getIceServers: () => RTCIceServer[];
  preparePeerSessions?: (input: {
    callId: string;
    roomId: string;
    peers: SputnikPeerDefinition[];
  }) => Promise<SputnikPeerDefinition[]>;
  captureClientDiagnostic?: (event: unknown) => void;
  captureClientDiagnosticError?: (eventType: string, error: unknown, payload?: Record<string, unknown>, options?: Record<string, unknown>) => void;
}

interface SyntheticMediaRuntime {
  canvas: HTMLCanvasElement;
  stream: MediaStream;
  videoStream: MediaStream;
  audioContext: AudioContext | null;
  noteTimer: number;
  renderFrame: number;
  hasRecentNote: (withinMs: number) => boolean;
  playResponseNote: () => void;
  stop: () => void;
}

interface SputnikPeerRuntime {
  definition: SputnikPeerDefinition;
  socket: WebSocket | null;
  syntheticMedia: SyntheticMediaRuntime | null;
  controller: GossipController | null;
  dataTransport: GossipDirectTransport | null;
  audioTransport: GossipDirectTransport | null;
  nativeAudioPeers: Map<string, NativeAudioPeerRuntime>;
  audioRecorder: MediaRecorder | null;
  audioRecorderTrackId: string;
  audioMimeType: string;
  audioMediaGeneration: number;
  assignedNeighborIds: Set<string>;
  sequenceByTrack: Map<string, number>;
  responseTimer: number;
  telemetryTimer: number;
  pingTimer: number;
  localRefs: ReturnType<typeof createPublisherRefs>;
  publisherState: ReturnType<typeof createPublisherState>;
  publisher: ReturnType<typeof createLocalPublisherPipelineHelpers> | null;
  lastTopologyAtMs: number;
  lastAudioResponseAtMs: number;
}

interface NativeAudioPeerRuntime {
  pc: RTCPeerConnection;
  pendingIce: RTCIceCandidateInit[];
  offerOperation: Promise<void>;
}

function mutableRef<T>(value: T): MutableRef<T> {
  return { value };
}

function createPublisherRefs(peerId: string, stream: MediaStream): {
  currentUserId: () => string;
  encodeIntervalRef: MutableRef<ReturnType<typeof setTimeout> | null>;
  localFilteredStreamRef: MutableRef<MediaStream | null>;
  localRawStreamRef: MutableRef<MediaStream | null>;
  localStreamRef: MutableRef<MediaStream | null>;
  localTracksRef: MutableRef<MediaStreamTrack[]>;
  localVideoElement: MutableRef<HTMLVideoElement | null>;
  mediaRuntimeCapabilitiesRef: MutableRef<Record<string, unknown>>;
  mediaRuntimePathRef: MutableRef<string>;
  sfuClientRef: MutableRef<null>;
  sfuTransportState: Record<string, number>;
  videoEncoderRef: MutableRef<unknown>;
  videoPatchEncoderHeight: MutableRef<number>;
  videoPatchEncoderQuality: MutableRef<number>;
  videoPatchEncoderRef: MutableRef<unknown>;
  videoPatchEncoderWidth: MutableRef<number>;
} {
  return {
    currentUserId: () => peerId,
    encodeIntervalRef: mutableRef(null),
    localFilteredStreamRef: mutableRef(stream),
    localRawStreamRef: mutableRef(stream),
    localStreamRef: mutableRef(stream),
    localTracksRef: mutableRef(stream.getTracks()),
    localVideoElement: mutableRef(null),
    mediaRuntimeCapabilitiesRef: mutableRef({ syntheticSputnik: true }),
    mediaRuntimePathRef: mutableRef('wlvc_wasm'),
    sfuClientRef: mutableRef(null),
    sfuTransportState: {
      wlvcBackpressurePauseUntilMs: 0,
      wlvcBackpressureSkipCount: 0,
      wlvcFrameSendFailureCount: 0,
      wlvcRemoteKeyframeRequestUntilMs: 0,
    },
    videoEncoderRef: mutableRef(null),
    videoPatchEncoderHeight: mutableRef(0),
    videoPatchEncoderQuality: mutableRef(0),
    videoPatchEncoderRef: mutableRef(null),
    videoPatchEncoderWidth: mutableRef(0),
  };
}

function createPublisherState() {
  return {
    backgroundBaselineCaptured: false,
    backgroundRuntimeToken: 0,
    localPublisherTeardownInProgress: false,
    localTrackRecoveryAttempts: 0,
    localTrackRecoveryTimer: null as ReturnType<typeof setTimeout> | null,
    localTracksPublishedToSfu: false,
    wlvcEncodeFailureCount: 0,
    wlvcEncodeFirstFailureAtMs: 0,
    wlvcEncodeInFlight: false,
    wlvcEncodeLastErrorLogAtMs: 0,
    wlvcEncodeWarmupUntilMs: 0,
  };
}

function buildPeerDefinitions(count: number, includeAlice: boolean): SputnikPeerDefinition[] {
  const peers: SputnikPeerDefinition[] = [];
  if (includeAlice) {
    peers.push({
      logicalPeerId: 'alice',
      peerId: String(SPUTNIK_USER_ID_BASE),
      userId: SPUTNIK_USER_ID_BASE,
      displayName: 'Alice',
      color: '#16a34a',
      toneHz: 330,
    });
  }

  const colors = ['#0ea5e9', '#8b5cf6', '#f59e0b', '#ef4444', '#84cc16', '#06b6d4', '#ec4899', '#64748b', '#f97316', '#14b8a6'];
  const tones = [349, 440, 554, 587, 659, 698, 784, 880, 988, 1047];
  const normalizedCount = Math.max(0, Math.min(SPUTNIK_MAX_FAKE_PEERS, Math.floor(Number(count) || 0)));
  for (let index = 1; index <= normalizedCount; index += 1) {
    peers.push({
      logicalPeerId: `sputnik-${index}`,
      peerId: String(SPUTNIK_USER_ID_BASE + index),
      userId: SPUTNIK_USER_ID_BASE + index,
      displayName: `Sputnik ${index}`,
      color: colors[index - 1] || '#0ea5e9',
      toneHz: tones[index - 1] || 349,
    });
  }

  return peers;
}

function createSyntheticMedia(definition: SputnikPeerDefinition, soundEnabled: boolean): SyntheticMediaRuntime {
  const canvas = document.createElement('canvas');
  canvas.width = SPUTNIK_FRAME_WIDTH;
  canvas.height = SPUTNIK_FRAME_HEIGHT;
  const context = canvas.getContext('2d');
  if (!context) throw new Error('Sputnik canvas context is unavailable.');
  if (typeof canvas.captureStream !== 'function') {
    throw new Error('Sputnik synthetic video requires canvas.captureStream.');
  }

  const startedAt = performance.now();
  let renderFrame = 0;
  const draw = (now: number) => {
    const elapsed = (now - startedAt) / 1000;
    const cx = SPUTNIK_FRAME_WIDTH / 2;
    const cy = SPUTNIK_FRAME_HEIGHT / 2;
    context.fillStyle = definition.color;
    context.fillRect(0, 0, SPUTNIK_FRAME_WIDTH, SPUTNIK_FRAME_HEIGHT);
    context.fillStyle = 'rgba(0, 0, 0, 0.18)';
    context.fillRect(0, 0, SPUTNIK_FRAME_WIDTH, 48);
    context.fillStyle = '#ffffff';
    context.font = '700 22px ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
    context.fillText(definition.displayName, 16, 32);
    context.save();
    context.translate(cx, cy + 20);
    context.rotate(elapsed * 1.8);
    context.fillStyle = '#ffffff';
    context.beginPath();
    context.arc(0, -42, 24, 0, Math.PI * 2);
    context.fill();
    context.fillStyle = 'rgba(255, 255, 255, 0.42)';
    context.beginPath();
    context.arc(0, 0, 58, 0, Math.PI * 2);
    context.fill();
    context.restore();
    renderFrame = requestAnimationFrame(draw);
  };
  renderFrame = requestAnimationFrame(draw);

  const videoStream = canvas.captureStream(SPUTNIK_FRAME_RATE);
  let audioContext: AudioContext | null = null;
  let lastNoteAtMs = 0;
  let noteStartTimer = 0;
  let noteTimer = 0;
  let playResponseNote: () => void = () => undefined;
  const tracks: MediaStreamTrack[] = [...videoStream.getVideoTracks()];
  if (soundEnabled) {
    const AudioContextCtor = window.AudioContext || (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
    if (AudioContextCtor) {
      audioContext = new AudioContextCtor();
      const oscillator = audioContext.createOscillator();
      const gain = audioContext.createGain();
      const destination = audioContext.createMediaStreamDestination();
      const noteEveryMs = definition.logicalPeerId === 'sputnik-1' ? 2500 : (definition.logicalPeerId === 'sputnik-2' ? 5000 : 7500);
      const playNote = (frequencyHz = definition.toneHz, peakGain = 0.18, durationSeconds = 0.3) => {
        const context = audioContext;
        if (!context) return;
        const schedule = () => {
          if (!audioContext) return;
          const now = context.currentTime;
          lastNoteAtMs = Date.now();
          oscillator.frequency.setValueAtTime(frequencyHz, now);
          gain.gain.cancelScheduledValues(now);
          gain.gain.setValueAtTime(0.0001, now);
          gain.gain.exponentialRampToValueAtTime(peakGain, now + 0.025);
          gain.gain.exponentialRampToValueAtTime(0.0001, now + durationSeconds);
        };
        if (context.state === 'suspended' && typeof context.resume === 'function') {
          void context.resume().then(schedule).catch(schedule);
          return;
        }
        schedule();
      };
      oscillator.type = 'sine';
      oscillator.frequency.value = definition.toneHz;
      gain.gain.value = 0.0001;
      oscillator.connect(gain).connect(destination);
      oscillator.start();
      void audioContext.resume?.().catch(() => undefined);
      playResponseNote = () => playNote(ALICE_RESPONSE_TONE_HZ, 0.24, 0.36);
      if (definition.logicalPeerId !== 'alice') {
        noteStartTimer = window.setTimeout(playNote, 650);
        noteTimer = window.setInterval(playNote, noteEveryMs);
      }
      tracks.push(...destination.stream.getAudioTracks());
    }
  }

  const stream = new MediaStream(tracks);
  return {
    canvas,
    stream,
    videoStream,
    audioContext,
    noteTimer,
      renderFrame,
      hasRecentNote: (withinMs: number) => lastNoteAtMs > 0 && Date.now() - lastNoteAtMs <= Math.max(1, Number(withinMs || 0)),
      playResponseNote,
    stop: () => {
      cancelAnimationFrame(renderFrame);
      if (noteStartTimer) window.clearTimeout(noteStartTimer);
      if (noteTimer) window.clearInterval(noteTimer);
      for (const track of stream.getTracks()) track.stop();
      for (const track of videoStream.getTracks()) track.stop();
      void audioContext?.close?.();
      audioContext = null;
    },
  };
}

function appendSputnikQuery(socketUrl: string, definition: SputnikPeerDefinition): string {
  const url = new URL(socketUrl);
  if (String(definition.sessionToken || '').trim() !== '') {
    url.searchParams.set('session', String(definition.sessionToken || '').trim());
  }
  return url.toString();
}

function normalizeTopologyHintPayload(payload: unknown): Record<string, unknown> | null {
  const source = payload && typeof payload === 'object' ? payload as Record<string, unknown> : null;
  if (!source) return null;
  const wrapperType = String(source.type || '').trim().toLowerCase();
  const payloadBody = source.payload && typeof source.payload === 'object' ? source.payload as Record<string, unknown> : null;
  const candidate = wrapperType === 'topology_hint' ? source : payloadBody;
  if (!candidate || typeof candidate !== 'object') return null;
  const kind = String(candidate.kind || candidate.type || '').trim().toLowerCase();
  if (
    kind !== 'topology_hint'
    && kind !== 'gossip_topology'
    && kind !== 'gossip-topology'
    && wrapperType !== 'call/gossip-topology'
  ) return null;
  return {
    lane: 'ops',
    ...candidate,
    type: 'topology_hint',
  };
}

function topologyNeighborUsesRtcDataChannel(topologyHint: Record<string, unknown>, peerId: string): boolean {
  const normalizedPeerId = String(peerId || '').trim();
  if (normalizedPeerId === '') return false;
  const neighbors = Array.isArray(topologyHint.neighbors) ? topologyHint.neighbors : [];
  return neighbors.some((neighbor) => {
    const row = neighbor && typeof neighbor === 'object' ? neighbor as Record<string, unknown> : {};
    return String(row.peer_id || '').trim() === normalizedPeerId
      && String(row.transport || '').trim().toLowerCase() === 'rtc_datachannel';
  });
}

function dataBufferFromFrame(frame: Record<string, unknown>): ArrayBuffer {
  const data = frame.data;
  if (data instanceof ArrayBuffer) return data;
  if (ArrayBuffer.isView(data)) {
    return data.buffer.slice(data.byteOffset, data.byteOffset + data.byteLength);
  }
  return new ArrayBuffer(0);
}

function supportedGossipAudioMimeType(): string {
  if (typeof MediaRecorder === 'undefined' || typeof MediaRecorder.isTypeSupported !== 'function') return '';
  return [
    'audio/webm;codecs=opus',
    'audio/webm',
    'audio/ogg;codecs=opus',
  ].find((entry) => MediaRecorder.isTypeSupported(entry)) || '';
}

export function createSputnikPeerRuntime(options: SputnikRuntimeOptions) {
  const state = reactive({
    enabled: Boolean(options.enabled),
    running: false,
    status: 'idle',
    activePeerCount: 0,
    connectedSockets: 0,
    publishedFrames: 0,
    receivedFrames: 0,
    topologyPeerCount: 0,
    lastError: '',
  });
  const peers = new Map<string, SputnikPeerRuntime>();

  function capture(event: Record<string, unknown>): void {
    options.captureClientDiagnostic?.({
      category: 'media',
      level: event.level || 'info',
      eventType: event.eventType || 'sputnik_peer_runtime',
      code: event.code || event.eventType || 'sputnik_peer_runtime',
      message: event.message || 'Sputnik peer runtime event.',
      payload: {
        dev_runtime: 'sputnik_peer_runtime',
        ...((event.payload && typeof event.payload === 'object') ? event.payload as Record<string, unknown> : {}),
      },
    });
  }

  function peerRoomId(): string {
    return String(options.getRoomId() || '').trim() || 'lobby';
  }

  function peerCallId(): string {
    return String(options.getCallId() || '').trim();
  }

  function sendSocketFrame(peer: SputnikPeerRuntime, payload: Record<string, unknown>): boolean {
    const socket = peer.socket;
    if (!(socket instanceof WebSocket) || socket.readyState !== WebSocket.OPEN) return false;
    try {
      socket.send(JSON.stringify(payload));
      return true;
    } catch {
      return false;
    }
  }

  function isNativeWebRtcPayload(payload: Record<string, unknown>): boolean {
    const kind = String(payload?.kind || '').trim().toLowerCase();
    const runtimePath = String(payload?.runtime_path || '').trim().toLowerCase();
    return runtimePath === 'webrtc_native' || kind === 'webrtc_offer' || kind === 'webrtc_answer' || kind === 'webrtc_ice';
  }

  function normalizeNativeSdp(value: unknown): RTCSessionDescriptionInit | null {
    const source = value && typeof value === 'object' ? value as Record<string, unknown> : null;
    const type = String(source?.type || '').trim().toLowerCase();
    const rawSdp = String(source?.sdp || '').trim();
    if ((type !== 'offer' && type !== 'answer') || rawSdp === '') return null;
    const normalizedSdp = rawSdp.replace(/\r?\n/g, '\r\n');
    return {
      type: type as RTCSdpType,
      sdp: normalizedSdp.endsWith('\r\n') ? normalizedSdp : `${normalizedSdp}\r\n`,
    };
  }

  function ensureNativeAudioPeer(peer: SputnikPeerRuntime, remotePeerId: string): NativeAudioPeerRuntime | null {
    const normalizedPeerId = String(remotePeerId || '').trim();
    if (normalizedPeerId === '' || normalizedPeerId === peer.definition.peerId) return null;
    const existing = peer.nativeAudioPeers.get(normalizedPeerId);
    if (existing) return existing;

    const pc = new RTCPeerConnection({ iceServers: options.getIceServers() });
    const runtime: NativeAudioPeerRuntime = { pc, pendingIce: [], offerOperation: Promise.resolve() };
    const sourceStream = peer.syntheticMedia?.stream || null;
    const audioTracks = sourceStream instanceof MediaStream ? sourceStream.getAudioTracks() : [];
    for (const track of audioTracks) {
      pc.addTrack(track, sourceStream as MediaStream);
    }
    if (audioTracks.length === 0) {
      pc.addTransceiver('audio', { direction: 'recvonly' });
    }
    pc.addEventListener('icecandidate', (event) => {
      if (!event?.candidate) return;
      sendSocketFrame(peer, {
        type: 'call/ice',
        target_user_id: Number(normalizedPeerId),
        payload: {
          kind: 'webrtc_ice',
          runtime_path: 'webrtc_native',
          room_id: peerRoomId(),
          candidate: event.candidate.toJSON(),
        },
      });
    });
    pc.addEventListener('connectionstatechange', () => {
      const connectionState = String(pc.connectionState || '').trim().toLowerCase();
      capture({
        eventType: 'sputnik_native_audio_connection_state',
        message: 'Sputnik native audio connection state changed.',
        payload: {
          peer_id: peer.definition.peerId,
          remote_peer_id: normalizedPeerId,
          connection_state: connectionState,
        },
      });
      if (connectionState === 'closed' || connectionState === 'failed') {
        peer.nativeAudioPeers.delete(normalizedPeerId);
      }
    });
    peer.nativeAudioPeers.set(normalizedPeerId, runtime);
    return runtime;
  }

  async function flushNativeAudioPendingIce(runtime: NativeAudioPeerRuntime): Promise<void> {
    if (!runtime.pc.remoteDescription?.type) return;
    const pending = runtime.pendingIce.splice(0);
    for (const candidate of pending) {
      try {
        await runtime.pc.addIceCandidate(new RTCIceCandidate(candidate));
      } catch {}
    }
  }

  async function answerNativeAudioOffer(
    peer: SputnikPeerRuntime,
    remotePeerId: string,
    runtime: NativeAudioPeerRuntime,
    remote: RTCSessionDescriptionInit,
  ): Promise<void> {
    const signalingState = String(runtime.pc.signalingState || '').trim().toLowerCase();
    if (signalingState === 'have-local-offer') {
      try {
        await runtime.pc.setLocalDescription({ type: 'rollback' });
      } catch {}
    } else if (signalingState !== 'stable' && signalingState !== '') {
      runtime.pc.close();
      peer.nativeAudioPeers.delete(remotePeerId);
      return;
    }
    await runtime.pc.setRemoteDescription(new RTCSessionDescription(remote));
    await flushNativeAudioPendingIce(runtime);
    const readyForAnswer = String(runtime.pc.signalingState || '').trim().toLowerCase();
    if (readyForAnswer !== 'have-remote-offer' && readyForAnswer !== 'have-local-pranswer') return;
    const answer = await runtime.pc.createAnswer();
    const stillReadyForAnswer = String(runtime.pc.signalingState || '').trim().toLowerCase();
    if (stillReadyForAnswer !== 'have-remote-offer' && stillReadyForAnswer !== 'have-local-pranswer') return;
    await runtime.pc.setLocalDescription(answer);
    const local = runtime.pc.localDescription;
    if (!local?.sdp) return;
    sendSocketFrame(peer, {
      type: 'call/answer',
      target_user_id: Number(remotePeerId),
      payload: {
        kind: 'webrtc_answer',
        runtime_path: 'webrtc_native',
        room_id: peerRoomId(),
        sdp: {
          type: local.type,
          sdp: local.sdp,
        },
      },
    });
    capture({
      eventType: 'sputnik_native_audio_answer_sent',
      message: 'Sputnik answered a native audio offer.',
      payload: {
        peer_id: peer.definition.peerId,
        remote_peer_id: remotePeerId,
        audio_track_count: peer.syntheticMedia?.stream.getAudioTracks().length || 0,
      },
    });
  }

  async function handleNativeAudioSignal(
    peer: SputnikPeerRuntime,
    type: string,
    senderPeerId: string,
    payload: Record<string, unknown>,
  ): Promise<boolean> {
    if (!isNativeWebRtcPayload(payload)) return false;
    const normalizedType = String(type || '').trim().toLowerCase();
    const normalizedPeerId = String(senderPeerId || '').trim();
    const runtime = ensureNativeAudioPeer(peer, normalizedPeerId);
    if (!runtime) return true;

    if (normalizedType === 'call/ice') {
      const candidate = payload.candidate && typeof payload.candidate === 'object' ? payload.candidate as RTCIceCandidateInit : null;
      if (!candidate) return true;
      if (!runtime.pc.remoteDescription?.type) {
        runtime.pendingIce.push(candidate);
        return true;
      }
      try {
        await runtime.pc.addIceCandidate(new RTCIceCandidate(candidate));
      } catch {}
      return true;
    }

    if (normalizedType !== 'call/offer') return true;
    const remote = normalizeNativeSdp(payload.sdp);
    if (!remote || remote.type !== 'offer') return true;

    runtime.offerOperation = runtime.offerOperation
      .catch(() => undefined)
      .then(() => answerNativeAudioOffer(peer, normalizedPeerId, runtime, remote));
    try {
      await runtime.offerOperation;
    } catch (error) {
      options.captureClientDiagnosticError?.('sputnik_native_audio_answer_failed', error, {
        peer_id: peer.definition.peerId,
        remote_peer_id: normalizedPeerId,
      }, { code: 'sputnik_native_audio_answer_failed', immediate: true });
    }
    return true;
  }

  function ensureController(peer: SputnikPeerRuntime): GossipController {
    if (peer.controller) return peer.controller;
    const controller = new GossipController(peerRoomId(), peerCallId() || 'call');
    controller.setDataLaneConfig(GOSSIP_DATA_LANE_CONFIG);
    const transport = new GossipDirectTransport({
      roomId: peerRoomId(),
      callId: peerCallId() || 'call',
      localPeerId: peer.definition.peerId,
      onDataMessage: (message, fromPeerId) => {
        controller.addPeer(String(fromPeerId || ''));
        controller.handleData(peer.definition.peerId, message, String(fromPeerId || ''));
      },
      onStateChange: (peerId, linkState, eventType) => {
        const normalizedPeerId = String(peerId || '').trim();
        capture({
          eventType: 'sputnik_gossip_direct_link_state',
          message: 'Sputnik Gossip direct link state changed.',
          payload: {
            peer_id: peer.definition.peerId,
            neighbor_peer_id: normalizedPeerId,
            state: linkState,
            event_type: eventType,
          },
        });
      },
    });
    controller.setDataTransport(transport);
    controller.addPeer(peer.definition.peerId);
    controller.onDataMessage((delivery: GossipDelivery) => {
      state.receivedFrames += 1;
      if (delivery.message?.type === 'sfu/frame') {
        controller.recordTransportTelemetry?.(peer.definition.peerId, 'received', 1);
      }
    });
    peer.controller = controller;
    peer.dataTransport = transport;
    return controller;
  }

  function ensureAudioTransport(peer: SputnikPeerRuntime): GossipDirectTransport {
    if (peer.audioTransport) return peer.audioTransport;
    const transport = new GossipDirectTransport({
      roomId: peerRoomId(),
      callId: `${peerCallId() || 'call'}:audio`,
      localPeerId: peer.definition.peerId,
      onDataMessage: (message, fromPeerId) => {
        if (peer.definition.logicalPeerId !== 'alice') return;
        const messageType = String(message?.type || '');
        if (messageType !== 'gossip/audio-chunk' && messageType !== 'gossip/audio-ping') return;
        const sourcePeerId = String(fromPeerId || '').trim();
        if (sourcePeerId === '' || sourcePeerId === peer.definition.peerId) return;
        const audioLevel = Number((message as Record<string, unknown>).audio_level ?? -1);
        if (messageType === 'gossip/audio-chunk' && Number.isFinite(audioLevel) && audioLevel >= 0 && audioLevel < ALICE_RESPONSE_AUDIO_LEVEL_THRESHOLD) return;
        const now = Date.now();
        if (now - peer.lastAudioResponseAtMs < ALICE_RESPONSE_DELAY_MS + 900) return;
        peer.lastAudioResponseAtMs = now;
        if (peer.responseTimer) window.clearTimeout(peer.responseTimer);
        capture({
          eventType: 'sputnik_alice_audio_response_scheduled',
          message: 'Alice scheduled an audio response ping for incoming Gossip audio.',
          payload: {
            peer_id: peer.definition.peerId,
            from_peer_id: sourcePeerId,
            audio_level: Number.isFinite(audioLevel) ? audioLevel : null,
            response_delay_ms: ALICE_RESPONSE_DELAY_MS,
          },
        });
        peer.responseTimer = window.setTimeout(() => {
          peer.responseTimer = 0;
          publishAudioPingToGossip(peer, ALICE_RESPONSE_TONE_HZ, 360, ALICE_AUDIO_PING_GAIN, 'alice_response');
          peer.syntheticMedia?.playResponseNote();
        }, ALICE_RESPONSE_DELAY_MS);
      },
    });
    peer.audioTransport = transport;
    return transport;
  }

  function publishAudioPingToGossip(
    peer: SputnikPeerRuntime,
    frequencyHz: number,
    durationMs: number,
    gain: number,
    reason: string,
  ): boolean {
    if (!GOSSIP_DATA_LANE_CONFIG.publish) return false;
    const transport = ensureAudioTransport(peer);
    const sequenceKey = `${peer.definition.peerId}:audio-ping`;
    const nextSequence = Math.max(1, Number(peer.sequenceByTrack.get(sequenceKey) || 0) + 1);
    peer.sequenceByTrack.set(sequenceKey, nextSequence);
    transport.broadcastData({
      type: 'gossip/audio-ping',
      protocol_version: 1,
      publisher_id: peer.definition.peerId,
      publisher_user_id: peer.definition.peerId,
      timestamp: Date.now(),
      frame_sequence: nextSequence,
      frequency_hz: Math.max(80, Math.min(2000, Number(frequencyHz || 440))),
      duration_ms: Math.max(50, Math.min(1000, Number(durationMs || 250))),
      gain: Math.max(0.01, Math.min(0.6, Number(gain || 0.12))),
      reason,
      dev_sputnik_peer_id: peer.definition.logicalPeerId,
      route_id: `${peerCallId() || 'call'}:audio-ping:${peer.definition.peerId}:${Date.now()}:${nextSequence}`,
    }, peer.definition.peerId);
    capture({
      eventType: 'sputnik_gossip_audio_ping_published',
      message: 'Sputnik runtime published an explicit Gossip audio ping.',
      payload: {
        peer_id: peer.definition.peerId,
        logical_peer_id: peer.definition.logicalPeerId,
        frequency_hz: Math.max(80, Math.min(2000, Number(frequencyHz || 440))),
        duration_ms: Math.max(50, Math.min(1000, Number(durationMs || 250))),
        reason,
      },
    });
    return true;
  }

  function applyTopologyHint(peer: SputnikPeerRuntime, payload: unknown): boolean {
    const topologyHint = normalizeTopologyHintPayload(payload);
    if (!topologyHint) return false;
    if (String(topologyHint.peer_id || '').trim() !== peer.definition.peerId) return false;
    const controller = ensureController(peer);
    const audioTransport = ensureAudioTransport(peer);
    controller.addPeer(peer.definition.peerId);
    for (const neighbor of Array.isArray(topologyHint.neighbors) ? topologyHint.neighbors : []) {
      const row = neighbor && typeof neighbor === 'object' ? neighbor as Record<string, unknown> : {};
      const neighborPeerId = String(row.peer_id || '').trim();
      if (neighborPeerId !== '' && neighborPeerId !== peer.definition.peerId) {
        controller.addPeer(neighborPeerId);
      }
    }
    if (!controller.applyTopologyHint(peer.definition.peerId, topologyHint as never)) return false;
    peer.assignedNeighborIds.clear();
    const localPeer = controller.getPeer(peer.definition.peerId);
    for (const neighborId of localPeer?.neighbor_set || []) {
      if (topologyNeighborUsesRtcDataChannel(topologyHint, String(neighborId))) {
        peer.assignedNeighborIds.add(String(neighborId));
        peer.dataTransport?.connectPeer?.(String(neighborId));
        audioTransport.connectPeer?.(String(neighborId));
      }
    }
    peer.lastTopologyAtMs = Date.now();
    state.topologyPeerCount = Array.from(peers.values()).filter((entry) => entry.assignedNeighborIds.size > 0).length;
    syncAudioPublisher(peer, 'topology_hint_applied');
    return true;
  }

  function publishEncodedFrameToGossip(peer: SputnikPeerRuntime, frame: Record<string, unknown>): boolean {
    if (!GOSSIP_DATA_LANE_CONFIG.publish) return false;
    const controller = ensureController(peer);
    const trackId = String(frame.trackId || '').trim();
    if (trackId === '') return false;
    const sequenceKey = `${peer.definition.peerId}:${trackId}`;
    const nextSequence = Math.max(1, Number(peer.sequenceByTrack.get(sequenceKey) || 0) + 1);
    peer.sequenceByTrack.set(sequenceKey, nextSequence);
    const dataBuffer = dataBufferFromFrame(frame);
    const dataBase64 = dataBuffer.byteLength > 0 ? arrayBufferToBase64Url(dataBuffer) : '';
    const message: GossipFrameMessage = {
      type: 'sfu/frame',
      protocol_version: 2,
      publisher_id: peer.definition.peerId,
      publisher_user_id: peer.definition.peerId,
      track_id: trackId,
      timestamp: Number(frame.timestamp || Date.now()),
      frame_type: String(frame.type || '').trim() === 'keyframe' ? 'keyframe' : 'delta',
      frame_sequence: nextSequence,
      media_generation: SPUTNIK_MEDIA_GENERATION,
      sender_sent_at_ms: Date.now(),
      codec_id: String(frame.codecId || ''),
      runtime_id: String(frame.runtimeId || 'wlvc_sfu'),
      protection_mode: 'transport_only',
      data_base64: dataBase64,
      payload_chars: dataBase64.length,
      chunk_count: 1,
      layout_mode: String(frame.layoutMode || 'full_frame'),
      layer_id: String(frame.layerId || 'full'),
      cache_epoch: Math.max(0, Number(frame.cacheEpoch || 0)),
      tile_columns: Math.max(0, Number(frame.tileColumns || 0)),
      tile_rows: Math.max(0, Number(frame.tileRows || 0)),
      tile_width: Math.max(0, Number(frame.tileWidth || 0)),
      tile_height: Math.max(0, Number(frame.tileHeight || 0)),
      tile_indices: Array.isArray(frame.tileIndices) ? frame.tileIndices : [],
      roi_norm_x: Math.max(0, Number(frame.roiNormX || 0)),
      roi_norm_y: Math.max(0, Number(frame.roiNormY || 0)),
      roi_norm_width: Math.max(0, Number(frame.roiNormWidth || 0)),
      roi_norm_height: Math.max(0, Number(frame.roiNormHeight || 0)),
      dev_sputnik_peer_id: peer.definition.logicalPeerId,
    };
    controller.publishFrame(peer.definition.peerId, message);
    state.publishedFrames += 1;
    maybeSendTelemetrySnapshot(peer, 'sputnik_publish');
    return true;
  }

  async function publishAudioChunkToGossip(
    peer: SputnikPeerRuntime,
    blob: Blob,
    trackId: string,
    codecId: string,
  ): Promise<boolean> {
    if (!GOSSIP_DATA_LANE_CONFIG.publish || blob.size <= 0) return false;
    const transport = ensureAudioTransport(peer);
    const normalizedTrackId = String(trackId || '').trim();
    if (normalizedTrackId === '') return false;
    const dataBuffer = await blob.arrayBuffer();
    if (dataBuffer.byteLength <= 0) return false;
    const sequenceKey = `${peer.definition.peerId}:${normalizedTrackId}:audio`;
    const nextSequence = Math.max(1, Number(peer.sequenceByTrack.get(sequenceKey) || 0) + 1);
    peer.sequenceByTrack.set(sequenceKey, nextSequence);
    const dataBase64 = arrayBufferToBase64Url(dataBuffer);
    const message = {
      type: 'gossip/audio-chunk',
      protocol_version: 1,
      publisher_id: peer.definition.peerId,
      publisher_user_id: peer.definition.peerId,
      track_id: normalizedTrackId,
      timestamp: Date.now(),
      frame_sequence: nextSequence,
      media_generation: peer.audioMediaGeneration,
      sender_sent_at_ms: Date.now(),
      codec_id: String(codecId || 'audio/webm;codecs=opus'),
      protection_mode: 'transport_only',
      data_base64: dataBase64,
      payload_chars: dataBase64.length,
      duration_ms: SPUTNIK_AUDIO_CHUNK_MS,
      dev_sputnik_peer_id: peer.definition.logicalPeerId,
      ttl: 1,
      route_id: `${peerCallId() || 'call'}:audio:${peer.definition.peerId}:${Date.now()}:${nextSequence}`,
    };
    transport.broadcastData(message, peer.definition.peerId);
    if (peer.definition.logicalPeerId !== 'alice') {
      publishAudioPingToGossip(peer, peer.definition.toneHz, 300, SPUTNIK_AUDIO_PING_GAIN, 'sputnik_note');
    }
    maybeSendTelemetrySnapshot(peer, 'sputnik_audio_publish');
    return true;
  }

  function syncAudioPublisher(peer: SputnikPeerRuntime, reason: string): boolean {
    if (!GOSSIP_DATA_LANE_CONFIG.publish) return false;
    const stream = peer.syntheticMedia?.stream || null;
    const track = stream?.getAudioTracks().find((entry) => entry.readyState === 'live') || null;
    if (!track) return false;
    if (peer.audioRecorder && peer.audioRecorderTrackId === track.id && peer.audioRecorder.state === 'recording') {
      return true;
    }
    stopAudioPublisher(peer, 'restart');
    if (typeof MediaRecorder === 'undefined') return false;
    const mimeType = supportedGossipAudioMimeType();
    if (mimeType === '') return false;
    try {
      const recorder = new MediaRecorder(new MediaStream([track]), {
        mimeType,
        audioBitsPerSecond: 32000,
      });
      peer.audioRecorder = recorder;
      peer.audioRecorderTrackId = track.id;
      peer.audioMimeType = mimeType;
      peer.audioMediaGeneration += 1;
      recorder.addEventListener('dataavailable', (event) => {
        if (!event?.data || event.data.size <= 0) return;
        if (!peer.syntheticMedia?.hasRecentNote(SPUTNIK_AUDIO_CHUNK_MS + 350)) return;
        void publishAudioChunkToGossip(peer, event.data, track.id, mimeType);
      });
      recorder.addEventListener('error', () => stopAudioPublisher(peer, 'recorder_error'));
      recorder.start(SPUTNIK_AUDIO_CHUNK_MS);
      capture({
        eventType: 'sputnik_gossip_audio_started',
        message: 'Sputnik audio started over Gossip data-channel transport.',
        payload: {
          peer_id: peer.definition.peerId,
          reason,
          track_id: track.id,
          codec_id: mimeType,
        },
      });
      return true;
    } catch (error) {
      options.captureClientDiagnosticError?.('sputnik_gossip_audio_start_failed', error, {
        peer_id: peer.definition.peerId,
        reason,
      }, { code: 'sputnik_gossip_audio_start_failed', immediate: true });
      return false;
    }
  }

  function stopAudioPublisher(peer: SputnikPeerRuntime, reason: string): void {
    const recorder = peer.audioRecorder;
    peer.audioRecorder = null;
    peer.audioRecorderTrackId = '';
    peer.audioMimeType = '';
    if (recorder && recorder.state !== 'inactive') {
      try { recorder.stop(); } catch {}
    }
    if (recorder) {
      capture({
        eventType: 'sputnik_gossip_audio_stopped',
        message: 'Sputnik Gossip audio publisher stopped.',
        payload: {
          peer_id: peer.definition.peerId,
          reason,
        },
      });
    }
  }

  function stopSputnikEncodingPipeline(peer: SputnikPeerRuntime): void {
    const refs = peer.localRefs;
    if (refs.encodeIntervalRef.value) {
      clearTimeout(refs.encodeIntervalRef.value);
      refs.encodeIntervalRef.value = null;
    }
    for (const encoderRef of [refs.videoEncoderRef, refs.videoPatchEncoderRef]) {
      const encoder = encoderRef.value as { destroy?: () => void; reset?: () => void } | null;
      if (encoder?.destroy) {
        encoder.destroy();
      } else if (encoder?.reset) {
        encoder.reset();
      }
      encoderRef.value = null;
    }
    refs.videoPatchEncoderWidth.value = 0;
    refs.videoPatchEncoderHeight.value = 0;
    refs.videoPatchEncoderQuality.value = 0;
    peer.publisherState.wlvcEncodeInFlight = false;
  }

  function maybeSendTelemetrySnapshot(peer: SputnikPeerRuntime, reason: string): void {
    const now = Date.now();
    if (now - peer.lastTopologyAtMs < 0) return;
    if (peer.telemetryTimer !== 0) return;
    peer.telemetryTimer = window.setTimeout(() => {
      peer.telemetryTimer = 0;
      const snapshot = peer.controller?.createTelemetrySnapshot?.(peer.definition.peerId, {
        dataLaneMode: GOSSIP_DATA_LANE_CONFIG.mode,
        diagnosticsLabel: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
        mediaCarrierMode: VIDEOCHAT_MEDIA_CARRIER_CONFIG.mode,
        rolloutStrategy: VIDEOCHAT_MEDIA_CARRIER_CONFIG.mode,
      });
      if (snapshot) {
        sendSocketFrame(peer, {
          type: 'gossip/telemetry/snapshot',
          lane: 'ops',
          payload: {
            ...snapshot,
            reason,
            dev_sputnik_peer_id: peer.definition.logicalPeerId,
          },
        });
      }
    }, SPUTNIK_TELEMETRY_INTERVAL_MS);
  }

  function createPublisher(peer: SputnikPeerRuntime): ReturnType<typeof createLocalPublisherPipelineHelpers> {
    const videoProfile = Object.freeze({
      ...resolveSfuVideoQualityProfile('rescue'),
      keyFrameInterval: SPUTNIK_KEYFRAME_INTERVAL,
    });
    return createLocalPublisherPipelineHelpers({
      backgroundBaselineCollector: { reset: () => undefined },
      backgroundFilterController: {
        dispose: () => undefined,
        getCurrentMatteMaskSnapshot: () => null,
      },
      callbacks: {
        additionalPublisherFrameMetrics: () => ({
          dev_sputnik_peer_id: peer.definition.logicalPeerId,
          dev_sputnik_display_name: peer.definition.displayName,
        }),
        applyCallOutputPreferences: () => undefined,
        canProtectCurrentSfuTargets: () => false,
        captureClientDiagnostic: options.captureClientDiagnostic || (() => undefined),
        currentSfuVideoProfile: () => videoProfile,
        ensureMediaSecuritySession: () => null,
        getAssignedGossipNeighborCount: () => peer.assignedNeighborIds.size,
        getConnectedParticipantCount: () => Math.max(1, state.activePeerCount + 1),
        getRemotePeerCount: () => peer.assignedNeighborIds.size,
        getSfuClientBufferedAmount: () => 0,
        handleWlvcEncodeBackpressure: () => false,
        handleWlvcFramePayloadPressure: () => false,
        handleWlvcFrameSendFailure: () => false,
        handleWlvcRuntimeEncodeError: () => false,
        hintMediaSecuritySync: () => undefined,
        isSfuClientOpen: () => false,
        isWlvcRuntimePath: () => true,
        maybeFallbackToNativeRuntime: async () => false,
        mediaDebugLog: () => undefined,
        mountLocalPreview: false,
        noteWlvcSourceReadbackSuccess: () => true,
        publishLocalEncodedFrameToGossip: (frame: Record<string, unknown>) => publishEncodedFrameToGossip(peer, frame),
        reconfigureLocalTracksFromSelectedDevices: async () => false,
        renderCallVideoLayout: () => undefined,
        resetBackgroundRuntimeMetrics: () => undefined,
        resolveWlvcEncodeIntervalMs: (intervalMs: number) => Math.max(160, Number(intervalMs || 0)),
        resetWlvcBackpressureCounters: () => {
          peer.localRefs.sfuTransportState.wlvcBackpressureSkipCount = 0;
        },
        resetWlvcFrameSendFailureCounters: () => {
          peer.localRefs.sfuTransportState.wlvcFrameSendFailureCount = 0;
        },
        shouldDelayWlvcFrameForBackpressure: () => false,
        shouldSendTransportOnlySfuFrame: () => true,
        shouldThrottleWlvcEncodeLoop: () => false,
        stopActivityMonitor: () => undefined,
        stopSfuTrackAnnounceTimer: () => undefined,
      },
      captureClientDiagnosticError: options.captureClientDiagnosticError || (() => undefined),
      constants: {
        backgroundSnapshotEnabled: false,
        backgroundSnapshotMaxChangedRatio: SFU_BACKGROUND_SNAPSHOT_MAX_CHANGED_RATIO,
        backgroundSnapshotMaxPatchAreaRatio: SFU_BACKGROUND_SNAPSHOT_MAX_PATCH_AREA_RATIO,
        backgroundSnapshotMinChangedRatio: SFU_BACKGROUND_SNAPSHOT_MIN_CHANGED_RATIO,
        backgroundSnapshotMinIntervalMs: SFU_BACKGROUND_SNAPSHOT_MIN_INTERVAL_MS,
        backgroundSnapshotSampleStride: SFU_BACKGROUND_SNAPSHOT_SAMPLE_STRIDE,
        backgroundSnapshotTileDiffThreshold: SFU_BACKGROUND_SNAPSHOT_DIFF_THRESHOLD,
        backgroundSnapshotTileHeight: SFU_BACKGROUND_SNAPSHOT_TILE_HEIGHT,
        backgroundSnapshotTileWidth: SFU_BACKGROUND_SNAPSHOT_TILE_WIDTH,
        localTrackRecoveryBaseDelayMs: 1200,
        localTrackRecoveryMaxAttempts: 2,
        localTrackRecoveryMaxDelayMs: 5000,
        protectedMediaEnabled: false,
        selectiveTileBaseRefreshMs: 0,
        selectiveTileDiffThreshold: SFU_SELECTIVE_TILE_DIFF_THRESHOLD,
        selectiveTileEnabled: false,
        selectiveTileHeight: SFU_SELECTIVE_TILE_HEIGHT,
        selectiveTileMaxChangedRatio: SFU_SELECTIVE_TILE_MAX_CHANGED_RATIO,
        selectiveTileMaxPatchAreaRatio: SFU_SELECTIVE_TILE_MAX_PATCH_AREA_RATIO,
        selectiveTileSampleStride: SFU_SELECTIVE_TILE_SAMPLE_STRIDE,
        selectiveTileWidth: SFU_SELECTIVE_TILE_WIDTH,
        sendBufferHighWaterBytes: 0,
        sfuWlvcFrameQuality: videoProfile.frameQuality,
        sfuWlvcMaxDeltaFrameBytes: SFU_WLVC_MAX_DELTA_FRAME_BYTES,
        sfuWlvcMaxKeyframeFrameBytes: SFU_WLVC_MAX_KEYFRAME_FRAME_BYTES,
        wlvcEncodeErrorLogCooldownMs: WLVC_ENCODE_ERROR_LOG_COOLDOWN_MS,
        wlvcEncodeFailureThreshold: WLVC_ENCODE_FAILURE_THRESHOLD,
        wlvcEncodeFailureWindowMs: WLVC_ENCODE_FAILURE_WINDOW_MS,
        wlvcEncodeWarmupMs: WLVC_ENCODE_WARMUP_MS,
      },
      refs: peer.localRefs,
      state: peer.publisherState,
    });
  }

  async function connectPeer(peer: SputnikPeerRuntime): Promise<void> {
    const callId = peerCallId();
    const origins = options.getSocketOrigins().map((entry) => String(entry || '').trim()).filter(Boolean);
    if (origins.length === 0) throw new Error('Sputnik peers require an available websocket origin.');
    const attemptsPerOrigin = 2;
    let lastError: Error | null = null;
    for (const origin of origins) {
      const socketUrl = appendSputnikQuery(options.buildSocketUrl(peerRoomId(), origin, callId), peer.definition);
      for (let attempt = 1; attempt <= attemptsPerOrigin; attempt += 1) {
        try {
          const socket = new WebSocket(socketUrl);
          peer.socket = socket;
          let socketCountedAsConnected = false;

          socket.addEventListener('message', (event) => {
            let payload: Record<string, unknown>;
            try {
              payload = JSON.parse(String(event.data || '')) as Record<string, unknown>;
            } catch {
              return;
            }
            const type = String(payload.type || '').trim().toLowerCase();
            if (type === 'system/welcome') {
              sendSocketFrame(peer, { type: 'room/snapshot/request' });
              return;
            }
            if (type === 'room/snapshot' || type === 'room/joined' || type === 'room/left') {
              applyGossipTopologyFromRoomStatePayload(payload, peer.definition.peerId, (hint: unknown) => applyTopologyHint(peer, hint));
              return;
            }
            if (type === 'call/gossip-topology' || type === 'topology_hint') {
              applyTopologyHint(peer, payload);
              return;
            }
            if (type === 'call/offer' || type === 'call/answer' || type === 'call/ice') return;
          });
          socket.addEventListener('close', () => {
            if (socketCountedAsConnected) {
              socketCountedAsConnected = false;
              state.connectedSockets = Math.max(0, state.connectedSockets - 1);
            }
          });

          await new Promise<void>((resolve, reject) => {
            let settled = false;
            const timeout = window.setTimeout(() => {
              if (settled) return;
              settled = true;
              reject(new Error(`Sputnik websocket open timed out for ${peer.definition.displayName}.`));
            }, 6000);
            socket.addEventListener('open', () => {
              if (settled) return;
              settled = true;
              window.clearTimeout(timeout);
              socketCountedAsConnected = true;
              state.connectedSockets += 1;
              sendSocketFrame(peer, { type: 'room/join', room_id: peerRoomId() });
              sendSocketFrame(peer, { type: 'room/snapshot/request' });
              peer.pingTimer = window.setInterval(() => {
                sendSocketFrame(peer, { type: 'ping' });
              }, 10000);
              resolve();
            }, { once: true });
            socket.addEventListener('error', () => {
              if (settled) return;
              settled = true;
              window.clearTimeout(timeout);
              reject(new Error(`Sputnik websocket failed for ${peer.definition.displayName}.`));
            }, { once: true });
          });
          return;
        } catch (error) {
          if (peer.pingTimer) {
            window.clearInterval(peer.pingTimer);
            peer.pingTimer = 0;
          }
          if (peer.socket) {
            try { peer.socket.close(); } catch {}
            peer.socket = null;
          }
          const errorText = error instanceof Error ? error.message : String(error || 'unknown socket error');
          lastError = new Error(`${errorText} (origin=${origin}, attempt=${attempt}/${attemptsPerOrigin})`);
        }
      }
    }
    throw lastError || new Error(`Sputnik websocket failed for ${peer.definition.displayName}.`);
  }

  async function startPeer(definition: SputnikPeerDefinition, soundEnabled: boolean): Promise<void> {
    const syntheticMedia = createSyntheticMedia(definition, soundEnabled);
    const peer: SputnikPeerRuntime = {
      definition,
      socket: null,
      syntheticMedia,
      controller: null,
      dataTransport: null,
      audioTransport: null,
      nativeAudioPeers: new Map(),
      audioRecorder: null,
      audioRecorderTrackId: '',
      audioMimeType: '',
      audioMediaGeneration: 1,
      assignedNeighborIds: new Set(),
      sequenceByTrack: new Map(),
      responseTimer: 0,
      telemetryTimer: 0,
      pingTimer: 0,
      localRefs: createPublisherRefs(definition.peerId, syntheticMedia.stream),
      publisherState: createPublisherState(),
      publisher: null,
      lastTopologyAtMs: 0,
      lastAudioResponseAtMs: 0,
    };
    peers.set(definition.peerId, peer);
    try {
      ensureController(peer);
      await connectPeer(peer);
      peer.publisher = createPublisher(peer);
      const videoTrack = syntheticMedia.stream.getVideoTracks()[0] || null;
      if (videoTrack) {
        await peer.publisher.startEncodingPipeline(videoTrack);
      }
      syncAudioPublisher(peer, 'peer_started');
    } catch (error) {
      if (peer.responseTimer) window.clearTimeout(peer.responseTimer);
      if (peer.telemetryTimer) window.clearTimeout(peer.telemetryTimer);
      if (peer.pingTimer) window.clearInterval(peer.pingTimer);
      stopAudioPublisher(peer, 'start_failed');
      stopSputnikEncodingPipeline(peer);
      for (const nativeAudioPeer of peer.nativeAudioPeers.values()) {
        nativeAudioPeer.pc.close();
      }
      peer.nativeAudioPeers.clear();
      peer.dataTransport?.close?.();
      peer.audioTransport?.close?.();
      peer.controller?.dispose?.();
      peer.syntheticMedia?.stop?.();
      try {
        peer.socket?.close?.(1000, 'sputnik_start_failed');
      } catch {}
      peers.delete(definition.peerId);
      throw error;
    }
  }

  async function spawn(count: number, spawnOptions: { includeAlice?: boolean; soundEnabled?: boolean } = {}): Promise<boolean> {
    if (!state.enabled) return false;
    stop('replace');
    state.running = true;
    state.status = 'starting';
    state.lastError = '';
    const requestedDefinitions = buildPeerDefinitions(count, spawnOptions.includeAlice !== false);
    state.activePeerCount = requestedDefinitions.length;
    let definitions = requestedDefinitions;
    if (typeof options.preparePeerSessions === 'function') {
      try {
        definitions = await options.preparePeerSessions({
          callId: peerCallId(),
          roomId: peerRoomId(),
          peers: requestedDefinitions,
        });
      } catch (error) {
        state.lastError = error instanceof Error ? error.message : String(error);
      }
    }

    state.activePeerCount = definitions.length;
    const failures: string[] = [];
    let unresolved = [...definitions];
    const maxPasses = 6;
    for (let pass = 1; pass <= maxPasses && unresolved.length > 0; pass += 1) {
      const nextUnresolved: SputnikPeerDefinition[] = [];
      for (const definition of unresolved) {
        try {
          await startPeer(definition, spawnOptions.soundEnabled === true);
        } catch (error) {
          if (pass >= maxPasses) {
            failures.push(`${definition.displayName}: ${error instanceof Error ? error.message : String(error)}`);
          } else {
            nextUnresolved.push(definition);
          }
        }
      }
      unresolved = nextUnresolved;
      if (unresolved.length > 0) {
        await new Promise((resolve) => window.setTimeout(resolve, 250));
      }
    }

    if (failures.length > 0) {
      state.lastError = `Some peers failed: ${failures.join(' | ')}`;
    }
    state.status = 'running';
    capture({
      eventType: 'sputnik_peer_runtime_started',
      message: 'Dev-only Sputnik peers joined the call control plane.',
      payload: {
        peer_count: definitions.length,
        connected_sockets: state.connectedSockets,
        identity_mode: definitions.some((definition) => String(definition.sessionToken || '').trim() !== '')
          ? 'real_user_sessions'
          : 'query_identity_override',
        gossip_data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
        media_carrier_mode: VIDEOCHAT_MEDIA_CARRIER_CONFIG.mode,
      },
    });
    return true;
  }

  function stop(reason = 'manual'): void {
    for (const peer of peers.values()) {
      if (peer.responseTimer) window.clearTimeout(peer.responseTimer);
      if (peer.telemetryTimer) window.clearTimeout(peer.telemetryTimer);
      if (peer.pingTimer) window.clearInterval(peer.pingTimer);
      stopAudioPublisher(peer, reason);
      stopSputnikEncodingPipeline(peer);
      for (const nativeAudioPeer of peer.nativeAudioPeers.values()) {
        nativeAudioPeer.pc.close();
      }
      peer.nativeAudioPeers.clear();
      peer.dataTransport?.close?.();
      peer.audioTransport?.close?.();
      peer.controller?.dispose?.();
      peer.syntheticMedia?.stop?.();
      try {
        peer.socket?.close?.(1000, `sputnik_${reason}`);
      } catch {}
    }
    peers.clear();
    state.running = false;
    state.status = 'idle';
    state.activePeerCount = 0;
    state.connectedSockets = 0;
    state.topologyPeerCount = 0;
  }

  return {
    state,
    spawn,
    stop,
  };
}
