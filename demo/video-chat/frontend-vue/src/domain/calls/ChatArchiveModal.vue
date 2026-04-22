<template>
  <AppModalShell
    :open="open"
    title="Chat archive"
    :subtitle="callTitle || callId || 'Read-only transcript'"
    title-id="chat-archive-title"
    subtitle-class="chat-archive-subtitle"
    dialog-class="calls-modal-dialog chat-archive-dialog"
    body-class="calls-modal-body chat-archive-body"
    footer-class="calls-modal-footer chat-archive-footer"
    close-label="Close chat archive"
    class="chat-archive-modal"
    data-testid="chat-archive-modal"
    @close="closeArchive"
  >
    <template #body>
      <section class="chat-archive-toolbar" aria-label="Chat archive filters" data-testid="chat-archive-toolbar">
        <label class="chat-archive-field">
          <span>Search</span>
          <input
            v-model="queryDraft"
            class="input"
            type="search"
            placeholder="Search messages or files"
            @keydown.enter.prevent="reloadArchive"
          />
        </label>
        <label class="chat-archive-field">
          <span>File type</span>
          <AppSelect v-model="fileKind" aria-label="File type filter" @change="reloadArchive">
            <option v-for="option in fileKindOptions" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </AppSelect>
        </label>
        <button class="btn btn-cyan chat-archive-search" type="button" :disabled="loading || !callId" @click="reloadArchive">
          Search
        </button>
      </section>

      <div class="chat-archive-grid" data-testid="chat-archive-grid">
        <section class="chat-archive-column chat-archive-messages" aria-label="Archived chat messages" data-testid="chat-archive-messages">
          <header class="chat-archive-column-header">
            <span>Messages</span>
            <small>{{ archive.messages.length }} loaded</small>
          </header>

          <section v-if="loading" class="chat-archive-empty">Loading chat archive...</section>
          <section v-else-if="error" class="chat-archive-empty chat-archive-error">{{ error }}</section>
          <section v-else-if="archive.messages.length === 0" class="chat-archive-empty">No archived messages found.</section>
          <ol v-else class="chat-archive-message-list">
            <li v-for="message in archive.messages" :key="message.id" class="chat-archive-message">
              <div class="chat-archive-message-head">
                <strong>{{ message.sender?.display_name || 'Unknown' }}</strong>
                <time>{{ formatArchiveTime(message.server_time) }}</time>
              </div>
              <p v-if="message.text" class="chat-archive-message-text">{{ message.text }}</p>
              <div v-if="message.attachments?.length" class="chat-archive-chips">
                <a
                  v-for="attachment in message.attachments"
                  :key="attachment.id"
                  class="chat-archive-chip"
                  :href="attachment.download_url"
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  {{ attachment.name }}
                </a>
              </div>
            </li>
          </ol>

          <footer class="chat-archive-load-more">
            <button
              class="btn"
              type="button"
              :disabled="loading || !archive.pagination.hasNext"
              @click="loadNextPage"
            >
              {{ archive.pagination.hasNext ? 'Load more' : 'No more messages' }}
            </button>
          </footer>
        </section>

        <aside class="chat-archive-column chat-archive-files" aria-label="Archived chat files" data-testid="chat-archive-files">
          <header class="chat-archive-column-header">
            <span>Files</span>
            <small>{{ fileCount }} files</small>
          </header>

          <section
            v-for="group in fileGroups"
            :key="group.key"
            class="chat-archive-file-group"
            :class="{ empty: groupedFiles(group.key).length === 0 }"
          >
            <h5>{{ group.label }}</h5>
            <p v-if="groupedFiles(group.key).length === 0">No files</p>
            <ul v-else class="chat-archive-file-list">
              <li v-for="file in groupedFiles(group.key)" :key="file.id" class="chat-archive-file">
                <div>
                  <strong>{{ file.name }}</strong>
                  <span>{{ file.sender?.display_name || 'Unknown' }} · {{ formatArchiveBytes(file.size_bytes) }}</span>
                  <time>{{ formatArchiveTime(file.attached_at || file.created_at) }}</time>
                </div>
                <a
                  class="btn chat-archive-download"
                  :href="file.download_url"
                  target="_blank"
                  rel="noopener noreferrer"
                  :aria-label="`Download ${file.name}`"
                >
                  Download
                </a>
              </li>
            </ul>
          </section>
        </aside>
      </div>
    </template>

    <template #footer>
      <span class="chat-archive-readonly">Read-only archive. Messages and files cannot be changed here.</span>
      <button class="btn" type="button" @click="closeArchive">Close</button>
    </template>
  </AppModalShell>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import AppModalShell from '../../components/AppModalShell.vue';
import AppSelect from '../../components/AppSelect.vue';
import { sessionState } from '../auth/session';
import { currentBackendOrigin, fetchBackend } from '../../support/backendFetch';
import { formatDateTimeDisplay } from '../../support/dateTimeFormat';
import {
  CHAT_ARCHIVE_FILE_GROUPS,
  CHAT_ARCHIVE_FILE_KIND_OPTIONS,
  chatArchiveFileCount,
  formatChatArchiveBytes,
  normalizeChatArchivePayload,
} from './chatArchive';

const props = defineProps({
  open: {
    type: Boolean,
    default: false,
  },
  callId: {
    type: String,
    default: '',
  },
  callTitle: {
    type: String,
    default: '',
  },
});

const emit = defineEmits(['close']);

const loading = ref(false);
const error = ref('');
const queryDraft = ref('');
const fileKind = ref('all');
const archive = reactive(normalizeChatArchivePayload({}));

const fileGroups = CHAT_ARCHIVE_FILE_GROUPS;
const fileKindOptions = CHAT_ARCHIVE_FILE_KIND_OPTIONS;
const fileCount = computed(() => chatArchiveFileCount(archive.files.groups));

function applyArchive(nextArchive, append = false) {
  if (!append) {
    archive.messages.splice(0, archive.messages.length, ...nextArchive.messages);
  } else {
    archive.messages.push(...nextArchive.messages);
  }
  archive.callId = nextArchive.callId;
  archive.roomId = nextArchive.roomId;
  archive.readOnly = nextArchive.readOnly;
  archive.files = nextArchive.files;
  archive.pagination = nextArchive.pagination;
  archive.filters = nextArchive.filters;
  archive.retention = nextArchive.retention;
  archive.export = nextArchive.export;
}

function requestHeaders() {
  const headers = { accept: 'application/json' };
  const token = String(sessionState.sessionToken || '').trim();
  if (token !== '') {
    headers.authorization = `Bearer ${token}`;
  }
  return headers;
}

async function loadArchive({ cursor = 0, append = false } = {}) {
  const callId = String(props.callId || '').trim();
  if (callId === '') return;

  loading.value = true;
  error.value = '';
  try {
    const { response } = await fetchBackend(`/api/calls/${encodeURIComponent(callId)}/chat-archive`, {
      method: 'GET',
      query: {
        limit: 50,
        cursor,
        q: queryDraft.value,
        file_kind: fileKind.value,
      },
      headers: requestHeaders(),
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok) {
      const message = typeof payload?.error?.message === 'string' ? payload.error.message : `Request failed (${response.status}).`;
      throw new Error(message);
    }
    if (!payload || payload.status !== 'ok') {
      throw new Error('Backend returned an invalid archive payload.');
    }
    applyArchive(normalizeChatArchivePayload(payload), append);
  } catch (loadError) {
    const message = loadError instanceof Error ? loadError.message.trim() : '';
    error.value = message || `Could not load chat archive (${currentBackendOrigin()}).`;
    if (!append) {
      applyArchive(normalizeChatArchivePayload({}), false);
    }
  } finally {
    loading.value = false;
  }
}

function reloadArchive() {
  void loadArchive({ cursor: 0, append: false });
}

function loadNextPage() {
  if (!archive.pagination.hasNext || archive.pagination.nextCursor === null) return;
  void loadArchive({ cursor: archive.pagination.nextCursor, append: true });
}

function groupedFiles(key) {
  return Array.isArray(archive.files?.groups?.[key]) ? archive.files.groups[key] : [];
}

function closeArchive() {
  emit('close');
}

function formatArchiveTime(isoValue) {
  return formatDateTimeDisplay(isoValue, {
    dateFormat: sessionState.dateFormat,
    timeFormat: sessionState.timeFormat,
    fallback: 'n/a',
  });
}

function formatArchiveBytes(sizeBytes) {
  return formatChatArchiveBytes(sizeBytes);
}

watch(
  () => [props.open, props.callId],
  ([open]) => {
    if (!open) return;
    queryDraft.value = '';
    fileKind.value = 'all';
    void loadArchive({ cursor: 0, append: false });
  },
  { immediate: true },
);
</script>

<style scoped>
:deep(.chat-archive-dialog) {
  width: min(1180px, calc(100vw - 32px));
  height: min(820px, calc(100dvh - 32px));
  max-height: calc(100dvh - 32px);
  overflow: hidden;
  grid-template-rows: auto minmax(0, 1fr) auto;
}

:deep(.chat-archive-subtitle) {
  margin: 4px 0 0;
  color: var(--text-muted);
  font-size: 0.82rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

:deep(.chat-archive-body) {
  display: grid;
  grid-template-rows: auto minmax(0, 1fr);
  gap: 12px;
  min-height: 0;
  overflow: hidden;
}

.chat-archive-toolbar {
  display: grid;
  grid-template-columns: minmax(220px, 1fr) minmax(180px, 220px) auto;
  align-items: end;
  gap: 10px;
}

.chat-archive-field {
  display: grid;
  gap: 6px;
  min-width: 0;
  color: var(--text-muted);
  font-size: 12px;
}

.chat-archive-field .ii-select {
  width: 100%;
}

.chat-archive-search {
  min-width: 94px;
}

.chat-archive-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.35fr) minmax(300px, 0.75fr);
  gap: 12px;
  min-height: 0;
  overflow: hidden;
}

.chat-archive-column {
  display: grid;
  grid-template-rows: auto minmax(0, 1fr) auto;
  min-height: 0;
  border: 1px solid var(--border-subtle);
  border-radius: 8px;
  background: var(--bg-surface);
  overflow: hidden;
}

.chat-archive-column-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  min-height: 42px;
  padding: 10px;
  border-bottom: 1px solid var(--border-subtle);
  background: var(--bg-surface-strong);
  color: var(--text-primary);
  font-size: 13px;
  font-weight: 700;
}

.chat-archive-column-header small {
  color: var(--text-muted);
  font-weight: 700;
}

.chat-archive-message-list {
  display: grid;
  align-content: start;
  gap: 10px;
  margin: 0;
  padding: 10px;
  overflow: auto;
  list-style: none;
}

.chat-archive-message {
  padding: 10px;
  border: 1px solid var(--border-subtle);
  border-radius: 8px;
  background: var(--bg-surface-strong);
}

.chat-archive-message-head,
.chat-archive-file > div {
  display: grid;
  gap: 4px;
}

.chat-archive-message-head {
  grid-template-columns: 1fr auto;
  align-items: baseline;
}

.chat-archive-message-head time,
.chat-archive-file time,
.chat-archive-file span {
  color: var(--text-muted);
  font-size: 0.78rem;
}

.chat-archive-message-text {
  margin: 10px 0 0;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  line-height: 1.45;
}

.chat-archive-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
}

.chat-archive-chip {
  border: 1px solid var(--border-subtle);
  border-radius: 8px;
  color: var(--brand-cyan);
  padding: 6px 10px;
  text-decoration: none;
  font-weight: 700;
  font-size: 0.78rem;
}

.chat-archive-empty {
  padding: 12px;
  color: var(--text-muted);
}

.chat-archive-error {
  color: var(--color-ffb5b5);
}

.chat-archive-load-more {
  padding: 0 10px 10px;
}

.chat-archive-files {
  display: block;
  overflow: auto;
}

.chat-archive-files .chat-archive-column-header {
  position: sticky;
  top: 0;
  z-index: 1;
}

.chat-archive-file-group {
  padding: 10px;
  border-bottom: 1px solid var(--border-subtle);
}

.chat-archive-file-group:last-child {
  border-bottom: 0;
}

.chat-archive-file-group h5 {
  margin: 0 0 10px;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.chat-archive-file-group.empty p {
  margin: 0;
  color: var(--text-muted);
}

.chat-archive-file-list {
  display: grid;
  gap: 10px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.chat-archive-file {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 12px;
  padding: 10px;
  border: 1px solid var(--border-subtle);
  border-radius: 8px;
  background: var(--bg-surface-strong);
}

.chat-archive-file strong,
.chat-archive-file span,
.chat-archive-file time,
.chat-archive-chip {
  overflow-wrap: anywhere;
}

.chat-archive-download {
  min-height: 34px;
  align-items: center;
  white-space: nowrap;
  text-decoration: none;
}

:deep(.chat-archive-footer) {
  align-items: center;
  justify-content: space-between;
  min-width: 0;
}

.chat-archive-readonly {
  color: var(--text-muted);
  font-size: 0.82rem;
  min-width: 0;
}

@media (max-width: 980px) {
  :deep(.chat-archive-dialog) {
    width: min(960px, calc(100vw - 14px));
    height: min(920px, calc(100dvh - 14px));
    max-height: calc(100dvh - 14px);
    --calls-enter-dialog-padding: 10px;
    gap: 8px;
  }

  .chat-archive-grid {
    grid-template-columns: 1fr;
    grid-template-rows: minmax(0, 1.1fr) minmax(220px, 0.9fr);
    gap: 10px;
  }

  .chat-archive-toolbar {
    grid-template-columns: minmax(0, 1fr) minmax(180px, 240px) auto;
    gap: 8px;
  }
}

@media (max-width: 720px) {
  :deep(.calls-modal) {
    padding: 4px;
    place-items: stretch;
  }

  :deep(.chat-archive-dialog) {
    width: 100%;
    height: calc(100dvh - 8px);
    max-height: calc(100dvh - 8px);
    --calls-enter-dialog-padding: 8px;
    border-radius: 8px;
    gap: 8px;
  }

  :deep(.calls-modal-header) {
    gap: 8px;
  }

  :deep(.calls-modal-header-enter) {
    padding: 10px;
  }

  :deep(.calls-modal-header-enter-logo) {
    height: 20px;
  }

  :deep(.calls-modal-header .calls-enter-title) {
    font-size: 13px;
  }

  :deep(.chat-archive-subtitle) {
    font-size: 0.74rem;
  }

  :deep(.chat-archive-body) {
    overflow: auto;
    grid-template-rows: auto auto;
    align-content: start;
  }

  .chat-archive-toolbar {
    grid-template-columns: 1fr;
  }

  .chat-archive-search {
    width: 100%;
  }

  .chat-archive-grid {
    display: grid;
    grid-template-columns: 1fr;
    grid-template-rows: none;
    overflow: visible;
  }

  .chat-archive-message-list {
    max-height: min(48dvh, 420px);
  }

  .chat-archive-files {
    max-height: min(38dvh, 360px);
    overflow: auto;
  }

  .chat-archive-column-header {
    min-height: 38px;
    padding: 8px;
  }

  .chat-archive-message,
  .chat-archive-file {
    padding: 8px;
  }

  .chat-archive-message-head {
    grid-template-columns: 1fr;
  }

  .chat-archive-file {
    grid-template-columns: minmax(0, 1fr);
  }

  .chat-archive-download {
    width: 100%;
    justify-content: center;
  }

  :deep(.chat-archive-footer) {
    flex-wrap: wrap;
    gap: 8px;
  }

  :deep(.chat-archive-footer .btn) {
    width: 100%;
  }
}

@media (max-width: 420px) {
  :deep(.calls-modal) {
    padding: 0;
  }

  :deep(.chat-archive-dialog) {
    width: 100%;
    height: 100dvh;
    max-height: 100dvh;
    border-radius: 0;
  }

  .chat-archive-readonly {
    font-size: 0.76rem;
  }
}
</style>
