export async function runGossipFramePixelProof(page) {
  return page.evaluate(async () => {
    const [
      { arrayBufferToBase64Url },
      {
        gossipFrameMessageFromEncodedFrame,
        sfuFrameFromGossipMessage,
      },
      { GossipController },
      {
        MEDIA_SESSION_PLAN_SCHEMA_VERSION,
        mediaSessionPlanAllowsLocalPublication,
      },
      { createSfuFrameDecodeHelpers },
      { createSfuRemotePeerHelpers },
      { createCallWorkspaceVideoLayoutHelpers },
      peerMedia,
    ] = await Promise.all([
      import('/src/lib/sfu/framePayload.ts'),
      import('/src/domain/realtime/workspace/callWorkspace/gossipMediaFrameEnvelope.ts'),
      import('/src/lib/gossipmesh/gossipController.ts'),
      import('/src/domain/realtime/media/mediaSessionPlan.ts'),
      import('/src/domain/realtime/sfu/frameDecode.ts'),
      import('/src/domain/realtime/sfu/remotePeers.ts'),
      import('/src/domain/realtime/workspace/callWorkspace/videoLayout.ts'),
      import('/src/domain/realtime/native/peerMedia.ts'),
    ]);

    const {
      mediaNodeForUserId,
      participantHasRenderableMedia,
      remotePeerMediaNode,
    } = peerMedia;

    const currentUserId = 101;
    const remoteUserId = 202;
    const callId = 'call-pixel-proof';
    const roomId = 'room-pixel-proof';
    const participantSessionId = 'psess_remote_pixel_202';
    const remotePublisherId = 'gossip-proof-remote-202';
    const remoteTrackId = 'avatar-canvas-proof-202';
    const frameWidth = 2;
    const frameHeight = 2;
    const frameQuality = 80;

    const diagnostics = [];
    const diagnosticErrors = [];
    const debugLogs = [];
    const activityMarks = [];
    const fallbackReasons = [];
    const decoderInitializers = [];
    const receiverDeliveries = [];
    const publisherTransportMessages = [];
    let renderLayoutCount = 0;
    let mediaRenderVersionBumpCount = 0;
    let outputPreferencesAppliedCount = 0;

    function captureClientDiagnostic(event) {
      diagnostics.push({
        code: String(event?.code || ''),
        eventType: String(event?.eventType || event?.event_type || ''),
        payload: event?.payload && typeof event.payload === 'object' ? { ...event.payload } : {},
      });
    }

    function captureClientDiagnosticError(code, error, payload = {}) {
      diagnosticErrors.push({
        code: String(code || ''),
        message: error instanceof Error ? error.message : String(error || ''),
        payload: { ...payload },
      });
    }

    function bumpMediaRenderVersion() {
      mediaRenderVersionBumpCount += 1;
      refs.mediaRenderVersion.value += 1;
    }

    function readCanvasPixel(canvas) {
      const context = canvas.getContext('2d', { willReadFrequently: true });
      if (!context) return [];
      return Array.from(context.getImageData(0, 0, 1, 1).data);
    }

    const avatarCanvas = document.createElement('canvas');
    avatarCanvas.width = frameWidth;
    avatarCanvas.height = frameHeight;
    const avatarContext = avatarCanvas.getContext('2d', { willReadFrequently: true });
    if (!avatarContext) {
      throw new Error('Avatar canvas 2D context unavailable.');
    }
    avatarContext.fillStyle = 'rgb(220, 40, 90)';
    avatarContext.fillRect(0, 0, 1, 1);
    avatarContext.fillStyle = 'rgb(20, 130, 230)';
    avatarContext.fillRect(1, 0, 1, 1);
    avatarContext.fillStyle = 'rgb(30, 180, 80)';
    avatarContext.fillRect(0, 1, 1, 1);
    avatarContext.fillStyle = 'rgb(240, 200, 40)';
    avatarContext.fillRect(1, 1, 1, 1);
    const expectedPixel = readCanvasPixel(avatarCanvas);
    const avatarStream = typeof avatarCanvas.captureStream === 'function'
      ? avatarCanvas.captureStream(30)
      : null;
    const avatarStreamTrackCount = typeof avatarStream?.getVideoTracks === 'function'
      ? avatarStream.getVideoTracks().length
      : 0;

    function buildLiveWlvcKeyframeBytes() {
      const bytes = new Uint8Array(33);
      const view = new DataView(bytes.buffer);
      view.setUint32(0, 0x574c5643, false);
      view.setUint8(4, 2);
      view.setUint8(5, 0);
      view.setUint8(6, frameQuality);
      view.setUint8(7, 1);
      view.setUint16(8, frameWidth, false);
      view.setUint16(10, frameHeight, false);
      view.setUint32(12, 0, false);
      view.setUint32(16, 0, false);
      view.setUint32(20, 0, false);
      view.setUint16(24, 1, false);
      view.setUint16(26, 1, false);
      view.setUint8(28, 0);
      view.setUint8(29, 1);
      view.setUint8(30, 0);
      view.setUint8(31, 0);
      view.setUint8(32, 0);
      return bytes.buffer;
    }

    class GossipPixelProofDecoder {
      constructor(options = {}) {
        this.decodeCount = 0;
        this.destroyed = false;
        this.options = { ...options };
        this.lastDescriptor = null;
      }

      decodeFrame(descriptor) {
        this.decodeCount += 1;
        this.lastDescriptor = {
          dataBytes: Number(descriptor?.data?.byteLength || 0),
          height: Number(descriptor?.height || 0),
          timestamp: Number(descriptor?.timestamp || 0),
          type: String(descriptor?.type || ''),
          width: Number(descriptor?.width || 0),
        };
        return {
          data: new Uint8ClampedArray([
            expectedPixel[0], expectedPixel[1], expectedPixel[2], expectedPixel[3],
            20, 130, 230, 255,
            30, 180, 80, 255,
            240, 200, 40, 255,
          ]),
          height: frameHeight,
          quality: frameQuality,
          width: frameWidth,
        };
      }

      reset() {}

      destroy() {
        this.destroyed = true;
      }
    }

    document.body.innerHTML = `
      <main class="workspace-call-view">
        <section id="local-video-container"></section>
        <section id="remote-video-container"></section>
        <section id="decoded-video-container"></section>
        <section id="workspace-fullscreen-video-slot"></section>
        <section id="proof-mini-slot-${remoteUserId}" data-user-id="${remoteUserId}" style="width: 160px; height: 90px;"></section>
        <section id="proof-grid-slot-${remoteUserId}" data-user-id="${remoteUserId}" style="width: 160px; height: 90px;"></section>
      </main>
    `;

    const localVideoElement = document.createElement('video');
    localVideoElement.dataset.userId = String(currentUserId);

    const remotePeersRef = { value: new Map() };
    const nativePeerConnectionsRef = { value: new Map() };
    const pendingSfuRemotePeerInitializers = new Map();
    const refs = {
      currentUserId: { value: currentUserId },
      localFilteredStreamRef: { value: null },
      localRawStreamRef: { value: null },
      localStreamRef: { value: null },
      localVideoElement: { value: localVideoElement },
      mediaRenderVersion: { value: 0 },
      nativePeerConnectionsRef,
      remotePeersRef,
      shouldMaintainNativePeerConnections: () => true,
    };

    let renderCallVideoLayout = () => {};
    const layoutHelpers = createCallWorkspaceVideoLayoutHelpers({
      callbacks: {
        applyCallOutputPreferences: () => {
          outputPreferencesAppliedCount += 1;
        },
        bumpMediaRenderVersion,
        currentLayoutMode: () => 'main_mini',
        fullscreenVideoUserId: () => 0,
        gridVideoParticipants: () => [{ displayName: 'Remote Pixel Proof', role: 'participant', userId: remoteUserId }],
        gridVideoSlotId: (userId) => `proof-grid-slot-${Number(userId || 0)}`,
        hasRenderableMediaForParticipant: participantHasRenderableMedia,
        lookupMediaNodeForUserId: mediaNodeForUserId,
        miniVideoParticipants: () => [{ displayName: 'Remote Pixel Proof', role: 'participant', userId: remoteUserId }],
        miniVideoSlotId: (userId) => `proof-mini-slot-${Number(userId || 0)}`,
        primaryVideoUserId: () => currentUserId,
        remotePeerMediaNode,
      },
      refs,
    });
    renderCallVideoLayout = () => {
      renderLayoutCount += 1;
      layoutHelpers.renderCallVideoLayout();
    };

    const remotePeerHelpers = createSfuRemotePeerHelpers({
      bumpMediaRenderVersion,
      captureClientDiagnosticError,
      createHybridDecoder: async (options) => {
        decoderInitializers.push({ ...options });
        return new GossipPixelProofDecoder(options);
      },
      currentUserId: () => currentUserId,
      isWlvcRuntimePath: () => true,
      markRaw: (value) => value,
      maybeFallbackToNativeRuntime: (reason) => {
        fallbackReasons.push(String(reason || ''));
        return false;
      },
      mediaDebugLog: (...args) => {
        debugLogs.push(args.map((arg) => String(arg)));
      },
      nextTick: () => Promise.resolve(),
      pendingSfuRemotePeerInitializers,
      remotePeersRef,
      renderCallVideoLayout,
      sfuFrameHeight: frameHeight,
      sfuFrameQuality: frameQuality,
      sfuFrameWidth: frameWidth,
      teardownRemotePeer: (peer) => {
        peer?.decodedCanvas?.remove?.();
        peer?.decoder?.destroy?.();
      },
    });

    const frameDecodeHelpers = createSfuFrameDecodeHelpers({
      bumpMediaRenderVersion,
      captureClientDiagnostic,
      captureClientDiagnosticError,
      currentUserId: () => currentUserId,
      ensureMediaSecuritySession: () => ({
        decryptFrame: async ({ data }) => data,
        decryptProtectedFrameEnvelope: async () => new ArrayBuffer(0),
      }),
      ensureSfuRemotePeerForFrame: remotePeerHelpers.ensureSfuRemotePeerForFrame,
      getSfuRemotePeerByFrameIdentity: remotePeerHelpers.getSfuRemotePeerByFrameIdentity,
      isWlvcRuntimePath: () => true,
      markParticipantActivity: (...args) => {
        activityMarks.push(args.map((arg) => String(arg)));
      },
      markRemotePeerRenderable: layoutHelpers.markRemotePeerRenderable,
      mediaDebugLog: (...args) => {
        debugLogs.push(args.map((arg) => String(arg)));
      },
      mediaRuntimePathRef: { value: 'gossip_primary_direct' },
      normalizeSfuPublisherId: remotePeerHelpers.normalizeSfuPublisherId,
      promotePeerToTsDecoder: (peer) => remotePeerHelpers.promotePeerToTsDecoder(peer, () => new GossipPixelProofDecoder()),
      recoverMediaSecurityForPublisher: () => false,
      remoteDecoderRuntimeName: remotePeerHelpers.remoteDecoderRuntimeName,
      remoteFrameActivityLastByUserId: new Map(),
      remoteFrameActivityMarkIntervalMs: 0,
      remotePeersRef,
      remoteSfuFrameDropLogCooldownMs: 0,
      remoteSfuFrameStaleTtlMs: 60_000,
      remoteVideoKeyframeWaitLogCooldownMs: 0,
      renderCallVideoLayout,
      sendMediaSecurityHello: () => false,
      sendRemoteSfuVideoQualityPressure: () => false,
      sfuFrameHeight: frameHeight,
      sfuFrameQuality: frameQuality,
      sfuFrameWidth: frameWidth,
      shouldRecoverMediaSecurityFromFrameError: () => false,
      updateSfuRemotePeerUserId: remotePeerHelpers.updateSfuRemotePeerUserId,
    });

    const wlvcPayload = buildLiveWlvcKeyframeBytes();
    const timestamp = Date.now();
    const blockedPlan = {
      schema_version: MEDIA_SESSION_PLAN_SCHEMA_VERSION,
      call_id: callId,
      room_id: roomId,
      plan_epoch: 7,
      participants: [{
        participant_session_id: participantSessionId,
        media_state: 'waiting_for_gossip',
        profile: 'video_720p30',
        transport: 'gossip_primary',
        security_policy: 'transport_only',
      }],
    };
    const activePlan = {
      ...blockedPlan,
      participants: [{
        ...blockedPlan.participants[0],
        media_state: 'streaming_720p30',
      }],
    };
    const planGateContext = {
      callId,
      minPlanEpoch: 7,
      participantSessionId,
      roomId,
    };
    const blockedPlanAllowsPublish = mediaSessionPlanAllowsLocalPublication(blockedPlan, planGateContext);
    const activePlanAllowsPublish = mediaSessionPlanAllowsLocalPublication(activePlan, planGateContext);
    if (blockedPlanAllowsPublish || !activePlanAllowsPublish) {
      throw new Error(`Unexpected media_session_plan gate result: ${JSON.stringify({
        activePlanAllowsPublish,
        blockedPlanAllowsPublish,
      })}`);
    }

    const avatarEncodedFrame = {
      cacheEpoch: 1,
      codecId: 'wlvc_v1',
      codecRuntime: {
        codec_id: 'wlvc_v1',
        encoder: 'wlvc_wasm',
      },
      data: wlvcPayload,
      frameHeight,
      frameWidth,
      layoutMode: 'full_frame',
      participantSessionId,
      plainData: wlvcPayload,
      publisherId: remotePublisherId,
      publisherUserId: String(remoteUserId),
      timestamp,
      trackId: remoteTrackId,
      transportMetrics: {
        avatar_canvas_stream_track_count: avatarStreamTrackCount,
        avatar_source_kind: avatarStreamTrackCount > 0 ? 'canvas_capture_stream' : 'canvas_frame',
        frame_height: frameHeight,
        frame_width: frameWidth,
        media_session_plan_epoch: activePlan.plan_epoch,
        media_session_plan_schema_version: activePlan.schema_version,
        plan_gate_result: 'streaming_720p30',
        profile_frame_height: frameHeight,
        profile_frame_width: frameWidth,
        profile_frame_rate: 30,
        synthetic_media_source: 'avatar_canvas',
      },
      runtime_id: 'wlvc_sfu',
      type: 'keyframe',
    };
    const sequenceMap = new Map();
    const publishedGossipMessage = gossipFrameMessageFromEncodedFrame(avatarEncodedFrame, sequenceMap, {
      callId,
      peerId: String(remoteUserId),
      plainRelay: true,
      roomId,
    });
    if (!publishedGossipMessage) {
      throw new Error('Avatar frame did not produce a gossip.media.frame.v1 publish envelope.');
    }

    const gossipLaneConfig = {
      diagnosticsLabel: 'gossip_data_active',
      enabled: true,
      mode: 'active',
      publish: true,
      receive: true,
    };
    const publisherController = new GossipController(roomId, callId);
    publisherController.setDataLaneConfig(gossipLaneConfig);
    publisherController.setDataTransport({
      kind: 'in_memory_harness',
      sendData: (targetPeerId, msg, fromPeerId) => {
        publisherTransportMessages.push({
          fromPeerId: String(fromPeerId || ''),
          message: { ...msg },
          targetPeerId: String(targetPeerId || ''),
        });
      },
    });
    publisherController.addPeer(String(remoteUserId));
    publisherController.addPeer(String(currentUserId));

    if (activePlanAllowsPublish) {
      publisherController.publishFrame(String(remoteUserId), publishedGossipMessage);
    }
    const publisherStatsBeforeDispose = publisherController.getStats();
    const publisherEventsBeforeDispose = publisherController.getEvents();

    const receiverController = new GossipController(roomId, callId);
    receiverController.setDataLaneConfig(gossipLaneConfig);
    receiverController.addPeer(String(currentUserId));
    receiverController.onDataMessage((delivery) => {
      receiverDeliveries.push({
        frameId: String(delivery?.frame_id || ''),
        fromPeerId: String(delivery?.from_peer_id || ''),
        messageType: String(delivery?.message?.type || ''),
        receivingPeerId: String(delivery?.receiving_peer_id || ''),
      });
      const adaptedFrame = sfuFrameFromGossipMessage(delivery?.message, delivery);
      if (!adaptedFrame) {
        throw new Error('Gossip media frame did not adapt to the renderer frame shape.');
      }
      frameDecodeHelpers.handleSFUEncodedFrame(adaptedFrame);
    });

    for (const entry of publisherTransportMessages) {
      if (entry.targetPeerId === String(currentUserId)) {
        receiverController.handleData(String(currentUserId), entry.message, entry.fromPeerId);
      }
    }

    async function waitForDecodedTile() {
      const deadline = performance.now() + 3000;
      let lastSnapshot = null;
      while (performance.now() < deadline) {
        await new Promise((resolve) => requestAnimationFrame(resolve));
        const peer = remotePeersRef.value.get(remotePublisherId);
        const canvas = peer?.decodedCanvas;
        const tile = document.getElementById(`proof-mini-slot-${remoteUserId}`);
        const pixel = canvas instanceof HTMLCanvasElement ? readCanvasPixel(canvas) : [];
        lastSnapshot = {
          canvasParentId: canvas?.parentElement?.id || '',
          frameCount: Number(peer?.frameCount || 0),
          hasCanvas: canvas instanceof HTMLCanvasElement,
          pixel,
          receivedFrameCount: Number(peer?.receivedFrameCount || 0),
          remotePeerCount: remotePeersRef.value.size,
        };
        const hasExpectedPixel = pixel.length === 4
          && pixel.every((value, index) => value === expectedPixel[index]);
        if (
          peer
          && canvas instanceof HTMLCanvasElement
          && tile instanceof HTMLElement
          && canvas.parentElement === tile
          && Number(peer.frameCount || 0) > 0
          && hasExpectedPixel
        ) {
          return { canvas, peer, pixel };
        }
      }
      throw new Error(`Timed out waiting for decoded Gossip frame tile: ${JSON.stringify({
        lastSnapshot,
        diagnostics,
        diagnosticErrors,
        debugLogs,
        fallbackReasons,
      })}`);
    }

    const { canvas, peer, pixel } = await waitForDecodedTile();
    const decoder = peer.decoder;
    const receiverStatsBeforeDispose = receiverController.getStats();
    const receiverEventsBeforeDispose = receiverController.getEvents();
    const publishedPayloadBase64 = String(publishedGossipMessage.payload || publishedGossipMessage.data_base64 || '');

    publisherController.dispose();
    receiverController.dispose();
    for (const track of typeof avatarStream?.getTracks === 'function' ? avatarStream.getTracks() : []) {
      track.stop();
    }

    return {
      activityMarkCount: activityMarks.length,
      activePlanAllowsPublish,
      avatarSourceKind: String(avatarEncodedFrame.transportMetrics.avatar_source_kind || ''),
      avatarStreamTrackCount,
      blockedPlanAllowsPublish,
      canvasDatasetSurfaceRole: String(canvas.dataset.callVideoSurfaceRole || ''),
      canvasDatasetUserId: String(canvas.dataset.userId || ''),
      canvasHeight: Number(canvas.height || 0),
      canvasSurfaceUserId: String(canvas.dataset.callVideoSurfaceUserId || ''),
      canvasWidth: Number(canvas.width || 0),
      decoderInitializerCount: decoderInitializers.length,
      decoderInvocations: Number(decoder?.decodeCount || 0),
      decoderLastDescriptor: decoder?.lastDescriptor || null,
      diagnosticCodes: diagnostics.map((entry) => entry.code).filter(Boolean),
      diagnosticErrorCodes: diagnosticErrors.map((entry) => entry.code).filter(Boolean),
      expectedPixel,
      fallbackReasons,
      frameCount: Number(peer.frameCount || 0),
      gossipControllerDeliveryCount: receiverDeliveries.length,
      mediaConnectionState: String(peer.mediaConnectionState || ''),
      mediaRenderVersionBumpCount,
      outputPreferencesAppliedCount,
      pixel,
      publishedCodecId: String(publishedGossipMessage.codec_id || ''),
      publishedContractVersion: String(publishedGossipMessage.contract_version || ''),
      publishedFrameKind: String(publishedGossipMessage.frame_kind || ''),
      publishedPayloadBytes: arrayBufferToBase64Url(wlvcPayload) === publishedPayloadBase64 ? wlvcPayload.byteLength : 0,
      publishedRuntimePath: String(publishedGossipMessage.runtime_path || ''),
      publishedTransportMessageCount: publisherTransportMessages.length,
      receivedFrameCount: Number(peer.receivedFrameCount || 0),
      receiverEvents: receiverEventsBeforeDispose.map((event) => String(event?.event || '')),
      receiverStats: receiverStatsBeforeDispose[String(currentUserId)] || {},
      remotePeerCount: remotePeersRef.value.size,
      remoteUserId,
      renderLayoutCount,
      senderEvents: publisherEventsBeforeDispose.map((event) => String(event?.event || '')),
      senderStats: publisherStatsBeforeDispose[String(remoteUserId)] || {},
      sourceMessageType: String(publishedGossipMessage.type || ''),
      tileCanvasParentId: String(canvas.parentElement?.id || ''),
      trackId: String(publishedGossipMessage.track_id || ''),
    };
  });
}
