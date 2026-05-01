export function createCallWorkspaceChatRuntimeHelpers(context) {
  const {
    activeCallId,
    activeRoomId,
    apiRequest,
    buildFileAttachmentDraft,
    buildTextAttachmentDraft,
    chatAttachmentDraftToBase64,
    chatAttachmentDragActive,
    chatAttachmentError,
    chatAttachmentDrafts,
    chatAttachmentInputRef,
    chatByRoom,
    chatDraft,
    chatEmojiTrayOpen,
    chatInputRef,
    chatSending,
    chatUtf8ByteLength,
    connectSocket,
    connectionState,
    currentUserId,
    ensureRoomBuckets,
    isChatTextInlineAllowed,
    isSocketOnline,
    markParticipantActivity,
    markChatUnread,
    nextTick,
    normalizeRole,
    normalizeRoomId,
    reconnectAttempt,
    sanitizeChatAttachmentName,
    sendSocketFrame,
    sessionState,
    setNotice,
    typingByRoom,
    validateChatAttachmentDraft,
    CHAT_ATTACHMENT_MAX_COUNT,
    CHAT_INLINE_MAX_BYTES,
    CHAT_INLINE_MAX_CHARS,
    TYPING_LOCAL_STOP_MS,
  } = context;

  let typingStopTimer = null;
  let localTypingStarted = false;

  function clearTypingStopTimer() {
    if (typingStopTimer !== null) {
      clearTimeout(typingStopTimer);
      typingStopTimer = null;
    }
  }

function hasOpenRealtimeSocket() {
  return Boolean(isSocketOnline.value) && connectionState.value === 'online';
}

function stopLocalTyping() {
  clearTypingStopTimer();
  if (!localTypingStarted) return;
  localTypingStarted = false;
  void sendSocketFrame({ type: 'typing/stop' });
}

function formatBytes(bytes) {
  const value = Number(bytes || 0);
  if (!Number.isFinite(value) || value <= 0) return '0 B';
  if (value < 1024) return `${Math.round(value)} B`;
  if (value < 1024 * 1024) return `${(value / 1024).toFixed(value < 10 * 1024 ? 1 : 0)} KiB`;
  return `${(value / (1024 * 1024)).toFixed(value < 10 * 1024 * 1024 ? 1 : 0)} MiB`;
}

function clampChatInlineText(value) {
  const source = String(value || '');
  if (source.length <= CHAT_INLINE_MAX_CHARS && chatUtf8ByteLength(source) <= CHAT_INLINE_MAX_BYTES) {
    return source;
  }

  let next = '';
  for (const char of source) {
    const candidate = `${next}${char}`;
    if (candidate.length > CHAT_INLINE_MAX_CHARS || chatUtf8ByteLength(candidate) > CHAT_INLINE_MAX_BYTES) {
      break;
    }
    next = candidate;
  }
  return next;
}

function setChatAttachmentError(message) {
  chatAttachmentError.value = String(message || '').trim();
}

function addChatAttachmentDraft(rawDraft) {
  setChatAttachmentError('');
  const currentDrafts = chatAttachmentDrafts.value;
  const validation = validateChatAttachmentDraft(rawDraft, currentDrafts);
  if (!validation.ok) {
    setChatAttachmentError(validation.message || 'Attachment is not allowed.');
    return false;
  }

  chatAttachmentDrafts.value = [
    ...currentDrafts,
    {
      ...rawDraft,
      name: validation.name,
      extension: validation.extension,
      kind: validation.kind,
      contentType: validation.contentType,
    },
  ];
  return true;
}

function updateChatAttachmentDraftName(localId, value) {
  const normalizedId = String(localId || '').trim();
  if (normalizedId === '') return;
  const index = chatAttachmentDrafts.value.findIndex((draft) => draft.localId === normalizedId);
  if (index < 0) return;
  const nextDrafts = [...chatAttachmentDrafts.value];
  nextDrafts[index] = {
    ...nextDrafts[index],
    name: String(value || '').slice(0, 180),
  };
  chatAttachmentDrafts.value = nextDrafts;
}

function removeChatAttachmentDraft(localId) {
  const normalizedId = String(localId || '').trim();
  chatAttachmentDrafts.value = chatAttachmentDrafts.value.filter((draft) => draft.localId !== normalizedId);
  if (chatAttachmentDrafts.value.length === 0) {
    setChatAttachmentError('');
  }
}

function openChatAttachmentPicker() {
  const input = chatAttachmentInputRef.value;
  if (input instanceof HTMLInputElement) {
    input.click();
  }
}

async function addChatAttachmentFiles(files) {
  const incoming = Array.from(files || []).filter(Boolean);
  if (incoming.length === 0) return;
  if (chatAttachmentDrafts.value.length + incoming.length > CHAT_ATTACHMENT_MAX_COUNT) {
    setChatAttachmentError('Only 10 attachments are allowed per chat message.');
    return;
  }

  for (const file of incoming) {
    addChatAttachmentDraft(buildFileAttachmentDraft(file));
  }
}

function handleChatAttachmentPick(event) {
  const input = event?.target;
  const files = input instanceof HTMLInputElement ? input.files : null;
  void addChatAttachmentFiles(files);
  if (input instanceof HTMLInputElement) {
    input.value = '';
  }
}

function handleChatAttachmentDrop(event) {
  chatAttachmentDragActive.value = false;
  const files = event?.dataTransfer?.files || null;
  void addChatAttachmentFiles(files);
}

function handleChatPaste(event) {
  const text = event?.clipboardData?.getData('text/plain') || '';
  if (text === '') return;

  const input = chatInputRef.value;
  const currentDraft = String(chatDraft.value || '');
  const selectionStart = input instanceof HTMLInputElement && Number.isInteger(input.selectionStart)
    ? Number(input.selectionStart)
    : currentDraft.length;
  const selectionEnd = input instanceof HTMLInputElement && Number.isInteger(input.selectionEnd)
    ? Number(input.selectionEnd)
    : selectionStart;
  const combined = `${currentDraft.slice(0, selectionStart)}${text}${currentDraft.slice(selectionEnd)}`;
  if (isChatTextInlineAllowed(text) && isChatTextInlineAllowed(combined)) {
    return;
  }

  event.preventDefault();
  if (addChatAttachmentDraft(buildTextAttachmentDraft(text))) {
    setNotice('Large pasted text was converted to a chat attachment.');
  }
}

const CHAT_ATTACHMENT_UPLOAD_TIMEOUT_MIN_MS = 30_000;
const CHAT_ATTACHMENT_UPLOAD_TIMEOUT_MAX_MS = 180_000;
const CHAT_ATTACHMENT_UPLOAD_TIMEOUT_BASE_MS = 20_000;
const CHAT_ATTACHMENT_UPLOAD_TIMEOUT_PER_MIB_MS = 8_000;

function chatAttachmentUploadTimeoutMs(draft) {
  const sizeBytes = Math.max(0, Number(draft?.sizeBytes || draft?.file?.size || 0));
  const sizeMiB = Math.max(1, Math.ceil(sizeBytes / (1024 * 1024)));
  const timeoutMs = CHAT_ATTACHMENT_UPLOAD_TIMEOUT_BASE_MS + (sizeMiB * CHAT_ATTACHMENT_UPLOAD_TIMEOUT_PER_MIB_MS);
  return Math.max(CHAT_ATTACHMENT_UPLOAD_TIMEOUT_MIN_MS, Math.min(CHAT_ATTACHMENT_UPLOAD_TIMEOUT_MAX_MS, timeoutMs));
}

function isUploadTimeoutError(error) {
  if (!(error instanceof Error)) return false;
  if (error.name === 'TimeoutError') return true;
  const message = String(error.message || '').trim().toLowerCase();
  return message.includes('timed out') || message.includes('aborted without reason');
}

async function uploadChatAttachmentDraft(draft) {
  const callId = String(activeCallId.value || '').trim();
  if (callId === '') {
    throw new Error('Cannot upload chat attachment before the call context is loaded.');
  }

  const validation = validateChatAttachmentDraft(draft, []);
  if (!validation.ok) {
    throw new Error(validation.message || 'Attachment is not allowed.');
  }

  const timeoutMs = chatAttachmentUploadTimeoutMs(draft);
  let payload;
  try {
    payload = await apiRequest(`/api/calls/${encodeURIComponent(callId)}/chat/attachments`, {
      method: 'POST',
      timeoutMs,
      body: {
        file_name: sanitizeChatAttachmentName(draft.name, validation.extension || 'txt'),
        content_type: validation.contentType,
        content_base64: await chatAttachmentDraftToBase64({
          ...draft,
          name: sanitizeChatAttachmentName(draft.name, validation.extension || 'txt'),
        }),
      },
    });
  } catch (error) {
    if (isUploadTimeoutError(error)) {
      const timeoutSeconds = Math.round(timeoutMs / 1000);
      throw new Error(`Chat attachment upload timed out after ${timeoutSeconds}s. Try again or use a smaller file / faster connection.`);
    }
    throw error;
  }

  const attachment = payload?.result?.attachment;
  if (!attachment || typeof attachment !== 'object' || String(attachment.id || '').trim() === '') {
    throw new Error('Backend returned an invalid attachment payload.');
  }
  return attachment;
}

async function uploadChatAttachmentDrafts() {
  const uploaded = [];
  try {
    for (const draft of chatAttachmentDrafts.value) {
      uploaded.push(await uploadChatAttachmentDraft(draft));
    }
  } catch (error) {
    await cancelUploadedChatAttachments(uploaded);
    throw error;
  }
  return uploaded;
}

async function cancelUploadedChatAttachments(attachments) {
  const callId = String(activeCallId.value || '').trim();
  if (callId === '') return;
  const rows = Array.isArray(attachments) ? attachments : [];
  await Promise.all(rows.map(async (attachment) => {
    const attachmentId = String(attachment?.id || '').trim();
    if (attachmentId === '') return;
    try {
      await apiRequest(`/api/calls/${encodeURIComponent(callId)}/chat/attachments/${encodeURIComponent(attachmentId)}`, {
        method: 'DELETE',
      });
    } catch {
      // The draft is already invisible to chat peers; best-effort cleanup keeps retry UX intact.
    }
  }));
}

function focusChatInput() {
  nextTick(() => {
    const input = chatInputRef.value;
    if (input instanceof HTMLInputElement) {
      input.focus();
    }
  });
}

function toggleChatEmojiTray() {
  chatEmojiTrayOpen.value = !chatEmojiTrayOpen.value;
  if (chatEmojiTrayOpen.value) {
    focusChatInput();
  }
}

function insertChatEmoji(emoji) {
  const normalizedEmoji = String(emoji || '').trim();
  if (normalizedEmoji === '') return;

  const input = chatInputRef.value;
  const currentDraft = String(chatDraft.value || '');
  const selectionStart = input instanceof HTMLInputElement && Number.isInteger(input.selectionStart)
    ? Number(input.selectionStart)
    : currentDraft.length;
  const selectionEnd = input instanceof HTMLInputElement && Number.isInteger(input.selectionEnd)
    ? Number(input.selectionEnd)
    : selectionStart;
  const nextDraft = clampChatInlineText(`${currentDraft.slice(0, selectionStart)}${normalizedEmoji}${currentDraft.slice(selectionEnd)}`);
  const nextCursor = Math.min(selectionStart + normalizedEmoji.length, nextDraft.length);
  chatDraft.value = nextDraft;
  handleChatInput();

  nextTick(() => {
    const nextInput = chatInputRef.value;
    if (!(nextInput instanceof HTMLInputElement)) return;
    nextInput.focus();
    nextInput.setSelectionRange(nextCursor, nextCursor);
  });
}

function handleChatInput() {
  const clamped = clampChatInlineText(chatDraft.value);
  if (clamped !== chatDraft.value) {
    chatDraft.value = clamped;
  }

  if (!isSocketOnline.value) return;
  if (chatDraft.value.trim() === '') {
    stopLocalTyping();
    return;
  }

  if (!localTypingStarted) {
    localTypingStarted = sendSocketFrame({ type: 'typing/start' });
  }

  clearTypingStopTimer();
  typingStopTimer = setTimeout(() => {
    stopLocalTyping();
  }, TYPING_LOCAL_STOP_MS);
}

async function sendChatMessage() {
  const text = chatDraft.value.trim();
  const hasAttachments = chatAttachmentDrafts.value.length > 0;
  if ((text === '' && !hasAttachments) || chatSending.value) return;
  if (!hasOpenRealtimeSocket()) {
    if (connectionState.value === 'retrying') {
      reconnectAttempt.value = 0;
      void connectSocket();
    }
    setNotice('Realtime chat is reconnecting. The message is still in the composer.', 'error');
    return;
  }
  markParticipantActivity(currentUserId.value, 'chat');

  const clientMessageId = `client_${Date.now()}_${Math.random().toString(16).slice(2, 8)}`;
  chatSending.value = true;
  let attachments;
  try {
    attachments = hasAttachments ? await uploadChatAttachmentDrafts() : [];
  } catch (error) {
    setChatAttachmentError(error instanceof Error ? error.message : 'Could not upload chat attachment.');
    chatSending.value = false;
    return;
  }

  const sent = sendSocketFrame({
    type: 'chat/send',
    message: text,
    attachments: attachments.map((attachment) => ({ id: attachment.id })),
    client_message_id: clientMessageId,
  });

  if (!sent) {
    await cancelUploadedChatAttachments(attachments);
    setNotice('Could not send chat message while websocket is offline.', 'error');
    chatSending.value = false;
    return;
  }

  appendChatMessage({
    type: 'chat/message',
    room_id: activeRoomId.value,
    message: {
      id: clientMessageId,
      client_message_id: clientMessageId,
      text,
      attachments,
      sender: {
        user_id: currentUserId.value,
        display_name: String(sessionState.displayName || sessionState.email || '').trim() || 'You',
        role: normalizeRole(sessionState.role),
      },
      server_time: new Date().toISOString(),
    },
    time: new Date().toISOString(),
  });

  chatDraft.value = '';
  chatAttachmentDrafts.value = [];
  chatAttachmentError.value = '';
  chatEmojiTrayOpen.value = false;
  chatSending.value = false;
  stopLocalTyping();
}

function normalizeChatMessage(payload) {
  const roomId = normalizeRoomId(payload?.room_id || payload?.roomId || activeRoomId.value);
  const message = payload && typeof payload.message === 'object' ? payload.message : {};
  const sender = message && typeof message.sender === 'object' ? message.sender : {};
  const attachments = Array.isArray(message.attachments)
    ? message.attachments.map((attachment) => {
      const row = attachment && typeof attachment === 'object' ? attachment : {};
      return {
        id: String(row.id || '').trim(),
        name: String(row.name || 'attachment').trim() || 'attachment',
        content_type: String(row.content_type || 'application/octet-stream').trim() || 'application/octet-stream',
        size_bytes: Number(row.size_bytes || 0) || 0,
        kind: String(row.kind || 'document').trim() || 'document',
        download_url: String(row.download_url || '').trim(),
      };
    }).filter((attachment) => attachment.id !== '' && attachment.download_url !== '')
    : [];

  const idRaw = String(message.id || '').trim();
  const id = idRaw !== '' ? idRaw : `chat_${Date.now()}_${Math.random().toString(16).slice(2, 8)}`;

  return {
    id,
    room_id: roomId,
    text: String(message.text || '').trim(),
    sender: {
      user_id: Number(sender.user_id || 0) || 0,
      display_name: String(sender.display_name || 'Unknown user').trim() || 'Unknown user',
      role: normalizeRole(sender.role),
    },
    server_time: String(message.server_time || payload?.time || new Date().toISOString()),
    client_message_id: message.client_message_id ?? null,
    attachments,
  };
}

function appendChatMessage(payload) {
  const message = normalizeChatMessage(payload);
  if (message.text === '' && message.attachments.length === 0) return;
  if (Number.isInteger(message.sender.user_id) && message.sender.user_id > 0) {
    markParticipantActivity(message.sender.user_id, 'chat');
  }

  ensureRoomBuckets(message.room_id);
  const bucket = chatByRoom[message.room_id];
  const clientMessageId = String(message.client_message_id || '').trim();
  const existingIndex = bucket.findIndex((row) => (
    row.id === message.id
    || (clientMessageId !== '' && String(row.client_message_id || '').trim() === clientMessageId)
  ));
  if (existingIndex >= 0) {
    bucket[existingIndex] = {
      ...bucket[existingIndex],
      ...message,
    };
    return;
  }

  bucket.push(message);
  if (bucket.length > 240) {
    bucket.splice(0, bucket.length - 240);
  }
  markChatUnread(message);
}

function applyTypingEvent(payload) {
  const roomId = normalizeRoomId(payload?.room_id || payload?.roomId || activeRoomId.value);
  ensureRoomBuckets(roomId);

  const participant = payload && typeof payload.participant === 'object' ? payload.participant : {};
  const userId = Number(participant.user_id || participant.userId || 0);
  if (!Number.isInteger(userId) || userId <= 0 || userId === currentUserId.value) {
    return;
  }

  const type = String(payload?.type || '').trim().toLowerCase();
  const roomMap = typingByRoom[roomId];
  if (type === 'typing/stop') {
    delete roomMap[userId];
    return;
  }
  markParticipantActivity(userId, 'typing');

  const expiresInMs = Number(payload?.expires_in_ms || 3000);
  roomMap[userId] = {
    userId,
    displayName: String(participant.display_name || `User ${userId}`).trim() || `User ${userId}`,
    expiresAtMs: Date.now() + (Number.isFinite(expiresInMs) && expiresInMs > 0 ? expiresInMs : 3000),
  };
}

function normalizeLobbyEntry(entry) {
  const userId = Number(entry?.user_id || 0);
  return {
    user_id: Number.isInteger(userId) && userId > 0 ? userId : 0,
    display_name: String(entry?.display_name || '').trim() || `User ${userId || 'unknown'}`,
    role: normalizeRole(entry?.role),
    requested_unix_ms: Number(entry?.requested_unix_ms || 0),
    admitted_unix_ms: Number(entry?.admitted_unix_ms || 0),
  };
}


  return {
    addChatAttachmentDraft,
    appendChatMessage,
    applyTypingEvent,
    clearTypingStopTimer,
    focusChatInput,
    formatBytes,
    handleChatAttachmentDrop,
    handleChatAttachmentPick,
    handleChatInput,
    handleChatPaste,
    insertChatEmoji,
    normalizeChatMessage,
    normalizeLobbyEntry,
    openChatAttachmentPicker,
    removeChatAttachmentDraft,
    sendChatMessage,
    setChatAttachmentError,
    stopLocalTyping,
    toggleChatEmojiTray,
    updateChatAttachmentDraftName,
  };
}
