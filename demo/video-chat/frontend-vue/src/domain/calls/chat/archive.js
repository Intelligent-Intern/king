export const CHAT_ARCHIVE_FILE_GROUPS = [
  { key: 'images', label: 'Images' },
  { key: 'pdfs', label: 'PDFs' },
  { key: 'office', label: 'Office' },
  { key: 'text', label: 'Text / CSV / MD' },
  { key: 'documents', label: 'Documents' },
];

export const CHAT_ARCHIVE_FILE_KIND_OPTIONS = [
  { value: 'all', label: 'All files' },
  { value: 'image', label: 'Images' },
  { value: 'pdf', label: 'PDFs' },
  { value: 'office', label: 'Office' },
  { value: 'text', label: 'Text / CSV / MD' },
  { value: 'document', label: 'Documents' },
];

export function normalizeChatArchivePayload(payload) {
  const archive = payload?.result?.archive && typeof payload.result.archive === 'object'
    ? payload.result.archive
    : payload?.archive && typeof payload.archive === 'object'
      ? payload.archive
      : {};
  const messages = Array.isArray(archive.messages) ? archive.messages : [];
  const rawGroups = archive.files?.groups && typeof archive.files.groups === 'object'
    ? archive.files.groups
    : {};
  const groups = {};

  for (const group of CHAT_ARCHIVE_FILE_GROUPS) {
    groups[group.key] = Array.isArray(rawGroups[group.key]) ? rawGroups[group.key] : [];
  }

  return {
    callId: typeof archive.call_id === 'string' ? archive.call_id : '',
    roomId: typeof archive.room_id === 'string' ? archive.room_id : '',
    readOnly: archive.read_only !== false,
    messages,
    files: {
      groups,
      limit: Number.isInteger(archive.files?.limit) ? archive.files.limit : 200,
    },
    pagination: {
      cursor: Number.isInteger(archive.pagination?.cursor) ? archive.pagination.cursor : 0,
      limit: Number.isInteger(archive.pagination?.limit) ? archive.pagination.limit : 50,
      returned: Number.isInteger(archive.pagination?.returned) ? archive.pagination.returned : messages.length,
      hasNext: Boolean(archive.pagination?.has_next),
      nextCursor: Number.isInteger(archive.pagination?.next_cursor) ? archive.pagination.next_cursor : null,
    },
    filters: {
      query: typeof archive.filters?.query === 'string' ? archive.filters.query : '',
      senderUserId: Number.isInteger(archive.filters?.sender_user_id) ? archive.filters.sender_user_id : 0,
      fileKind: typeof archive.filters?.file_kind === 'string' ? archive.filters.file_kind : 'all',
    },
    retention: archive.retention && typeof archive.retention === 'object' ? archive.retention : {},
    export: archive.export && typeof archive.export === 'object' ? archive.export : {},
  };
}

export function chatArchiveFileCount(groups) {
  if (!groups || typeof groups !== 'object') return 0;
  return CHAT_ARCHIVE_FILE_GROUPS.reduce((count, group) => (
    count + (Array.isArray(groups[group.key]) ? groups[group.key].length : 0)
  ), 0);
}

export function chatArchiveIsReadOnly(archive) {
  return archive?.readOnly !== false;
}

export function formatChatArchiveBytes(sizeBytes) {
  const bytes = Number(sizeBytes || 0);
  if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
