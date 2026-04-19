export const CHAT_INLINE_MAX_CHARS = 2000;
export const CHAT_INLINE_MAX_BYTES = 8192;
export const CHAT_ATTACHMENT_MAX_COUNT = 10;
export const CHAT_ATTACHMENT_MAX_IMAGES = 10;
export const CHAT_ATTACHMENT_MAX_IMAGE_BYTES = 8 * 1024 * 1024;
export const CHAT_ATTACHMENT_MAX_DOCUMENT_BYTES = 25 * 1024 * 1024;
export const CHAT_ATTACHMENT_MAX_TEXT_BYTES = 8 * 1024 * 1024;

const mimeByExtension = {
  jpg: 'image/jpeg',
  jpeg: 'image/jpeg',
  png: 'image/png',
  webp: 'image/webp',
  gif: 'image/gif',
  txt: 'text/plain',
  csv: 'text/csv',
  md: 'text/markdown',
  pdf: 'application/pdf',
  doc: 'application/msword',
  docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  xls: 'application/vnd.ms-excel',
  xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  ppt: 'application/vnd.ms-powerpoint',
  pptx: 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
  odt: 'application/vnd.oasis.opendocument.text',
  ods: 'application/vnd.oasis.opendocument.spreadsheet',
  odp: 'application/vnd.oasis.opendocument.presentation',
};

const blockedExtensions = new Set(['exe', 'dll', 'com', 'bat', 'cmd', 'ps1', 'sh', 'js', 'msi', 'jar', 'app', 'deb', 'rpm', 'zip', 'rar', '7z', 'tar', 'gz']);

export function chatAttachmentAllowedExtensions() {
  return Object.keys(mimeByExtension);
}

export function chatUtf8ByteLength(value) {
  const text = String(value || '');
  if (typeof TextEncoder !== 'undefined') {
    return new TextEncoder().encode(text).length;
  }
  return unescape(encodeURIComponent(text)).length;
}

export function isChatTextInlineAllowed(value) {
  const text = String(value || '');
  return text.length <= CHAT_INLINE_MAX_CHARS && chatUtf8ByteLength(text) <= CHAT_INLINE_MAX_BYTES;
}

export function chatAttachmentExtension(filename) {
  const match = String(filename || '').trim().toLowerCase().match(/\.([a-z0-9]{1,12})$/);
  return match ? match[1] : '';
}

export function chatAttachmentKind(extension) {
  if (['jpg', 'jpeg', 'png', 'webp', 'gif'].includes(extension)) return 'image';
  if (['txt', 'csv', 'md'].includes(extension)) return 'text';
  if (extension === 'pdf') return 'pdf';
  return 'document';
}

export function chatAttachmentMimeForExtension(extension, fallback = 'application/octet-stream') {
  return mimeByExtension[String(extension || '').toLowerCase()] || fallback;
}

export function sanitizeChatAttachmentName(name, fallbackExtension = 'txt') {
  let safe = String(name || '').trim().split(/[\\/]/).pop() || '';
  safe = safe.replace(/[^A-Za-z0-9._ -]+/g, '_').replace(/\s+/g, ' ').replace(/^[ .]+|[ .]+$/g, '');
  if (safe === '') safe = `attachment.${fallbackExtension}`;
  if (!chatAttachmentExtension(safe)) safe = `${safe}.${fallbackExtension}`;
  if (safe.length > 160) {
    const extension = chatAttachmentExtension(safe);
    const stem = safe.slice(0, Math.max(32, 150 - extension.length)).replace(/[ ._-]+$/g, '');
    safe = extension ? `${stem}.${extension}` : stem;
  }
  return safe;
}

export function detectTextAttachmentExtension(text) {
  const value = String(text || '');
  const lines = value.split(/\r?\n/).filter((line) => line.trim() !== '');
  const markdownSignals = [
    /^#{1,6}\s+/m,
    /^[-*+]\s+/m,
    /^```/m,
    /\[[^\]]+\]\([^)]+\)/,
    /^>\s+/m,
  ];
  if (markdownSignals.some((pattern) => pattern.test(value))) return 'md';

  const separators = [',', ';', '\t'];
  for (const separator of separators) {
    if (lines.length < 2) continue;
    const widths = lines.map((line) => line.split(separator).length);
    const first = widths[0];
    if (first > 1 && widths.every((width) => width === first)) return 'csv';
  }

  return 'txt';
}

export function buildTextAttachmentDraft(text, now = new Date()) {
  const content = String(text || '');
  const extension = detectTextAttachmentExtension(content);
  const stamp = now.toISOString().replace(/[-:]/g, '').replace(/\..+$/, 'Z');
  const name = `chat-paste-${stamp}.${extension}`;
  return {
    localId: `draft_${Date.now()}_${Math.random().toString(16).slice(2, 8)}`,
    name,
    contentType: chatAttachmentMimeForExtension(extension),
    sizeBytes: chatUtf8ByteLength(content),
    kind: 'text',
    extension,
    textContent: content,
    file: null,
    preview: content.slice(0, 400),
    source: 'paste',
  };
}

export function validateChatAttachmentDraft(draft, existingDrafts = []) {
  const name = sanitizeChatAttachmentName(draft?.name || '', 'txt');
  const extension = chatAttachmentExtension(name);
  if (!extension || blockedExtensions.has(extension) || !mimeByExtension[extension]) {
    return { ok: false, code: 'attachment_type_not_allowed', message: 'File type is not allowed.' };
  }

  const kind = chatAttachmentKind(extension);
  const sizeBytes = Number(draft?.sizeBytes || draft?.file?.size || 0);
  const maxBytes = kind === 'image'
    ? CHAT_ATTACHMENT_MAX_IMAGE_BYTES
    : kind === 'text'
      ? CHAT_ATTACHMENT_MAX_TEXT_BYTES
      : CHAT_ATTACHMENT_MAX_DOCUMENT_BYTES;
  if (!Number.isFinite(sizeBytes) || sizeBytes <= 0) {
    return { ok: false, code: 'attachment_empty', message: 'Attachment is empty.' };
  }
  if (sizeBytes > maxBytes) {
    return { ok: false, code: 'attachment_too_large', message: 'Attachment is too large.' };
  }

  if (existingDrafts.length + 1 > CHAT_ATTACHMENT_MAX_COUNT) {
    return { ok: false, code: 'attachment_count_exceeded', message: 'Only 10 attachments are allowed per chat message.' };
  }

  const imageCount = existingDrafts.filter((row) => chatAttachmentKind(chatAttachmentExtension(row.name)) === 'image').length;
  if (kind === 'image' && imageCount + 1 > CHAT_ATTACHMENT_MAX_IMAGES) {
    return { ok: false, code: 'attachment_count_exceeded', message: 'Only 10 images are allowed per chat message.' };
  }

  return { ok: true, code: '', message: '', name, extension, kind, contentType: chatAttachmentMimeForExtension(extension, draft?.contentType || 'application/octet-stream') };
}

export function buildFileAttachmentDraft(file) {
  const extension = chatAttachmentExtension(file?.name || '');
  return {
    localId: `draft_${Date.now()}_${Math.random().toString(16).slice(2, 8)}`,
    name: sanitizeChatAttachmentName(file?.name || 'attachment', extension || 'txt'),
    contentType: file?.type || chatAttachmentMimeForExtension(extension),
    sizeBytes: Number(file?.size || 0),
    kind: chatAttachmentKind(extension),
    extension,
    textContent: '',
    file,
    preview: '',
    source: 'file',
  };
}

export async function chatAttachmentDraftToBase64(draft) {
  if (draft?.source === 'paste') {
    const text = String(draft.textContent || '');
    const bytes = new TextEncoder().encode(text);
    let binary = '';
    for (let index = 0; index < bytes.length; index += 1) {
      binary += String.fromCharCode(bytes[index]);
    }
    return btoa(binary);
  }

  const file = draft?.file;
  if (!(file instanceof File)) {
    throw new Error('Attachment draft has no file payload.');
  }

  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => {
      const result = String(reader.result || '');
      const commaIndex = result.indexOf(',');
      resolve(commaIndex >= 0 ? result.slice(commaIndex + 1) : result);
    };
    reader.onerror = () => reject(new Error('Could not read attachment file.'));
    reader.readAsDataURL(file);
  });
}
