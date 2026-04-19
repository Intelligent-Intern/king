<template>
  <div class="calls-modal" :hidden="!open" role="dialog" aria-modal="true" aria-label="Chat archive modal">
    <div class="calls-modal-backdrop" @click="closeArchive"></div>
    <div class="calls-modal-dialog chat-archive-dialog">
      <header class="calls-modal-header calls-modal-header-enter">
        <div class="calls-modal-header-enter-left">
          <img class="calls-modal-header-enter-logo" src="/assets/orgas/kingrt/logo.svg" alt="" />
          <div>
            <h4 class="calls-enter-title">Chat archive</h4>
            <p class="chat-archive-subtitle">{{ callTitle || callId || 'Read-only transcript' }}</p>
          </div>
        </div>
        <button class="icon-mini-btn" type="button" aria-label="Close chat archive" @click="closeArchive">
          <img src="/assets/orgas/kingrt/icons/cancel.png" alt="" />
        </button>
      </header>

      <div class="chat-archive-controls" aria-label="Chat archive filters">
        <input
          v-model="queryDraft"
          class="input"
          type="search"
          placeholder="Search messages or files"
          @keydown.enter.prevent="reloadArchive"
        />
        <select v-model="fileKind" class="chat-archive-select" aria-label="File type filter" @change="reloadArchive">
          <option v-for="option in fileKindOptions" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
        <button class="btn btn-cyan" type="button" :disabled="loading || !callId" @click="reloadArchive">
          Search
        </button>
      </div>

      <div class="calls-modal-body chat-archive-body">
        <section class="chat-archive-column chat-archive-messages" aria-label="Archived chat messages">
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

        <aside class="chat-archive-column chat-archive-files" aria-label="Archived chat files">
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
                <a class="icon-mini-btn" :href="file.download_url" target="_blank" rel="noopener noreferrer" aria-label="Download file">
                  <img src="/assets/orgas/kingrt/icons/chat.png" alt="" />
                  <span class="chat-archive-download-fallback">Download</span>
                </a>
              </li>
            </ul>
          </section>
        </aside>
      </div>

      <footer class="calls-modal-footer chat-archive-footer">
        <span class="chat-archive-readonly">Read-only archive. Messages and files cannot be changed here.</span>
        <button class="btn" type="button" @click="closeArchive">Close</button>
      </footer>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
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
.chat-archive-dialog {
  width: min(1180px, calc(100vw - 32px));
  max-height: calc(100vh - 36px);
}

.chat-archive-subtitle {
  margin: 4px 0 0;
  color: rgba(255, 255, 255, 0.66);
  font-size: 0.82rem;
}

.chat-archive-controls {
  display: grid;
  grid-template-columns: minmax(180px, 1fr) minmax(160px, 220px) auto;
  gap: 10px;
  padding: 0 22px 16px;
}

.chat-archive-select {
  min-height: 42px;
  border: 1px solid rgba(255, 255, 255, 0.14);
  border-radius: 14px;
  background: rgba(7, 12, 20, 0.78);
  color: #f7fbff;
  padding: 0 12px;
}

.chat-archive-body {
  display: grid;
  grid-template-columns: minmax(0, 1.2fr) minmax(320px, 0.8fr);
  gap: 16px;
  min-height: min(62vh, 680px);
  max-height: min(66vh, 720px);
  overflow: hidden;
}

.chat-archive-column {
  min-height: 0;
  border: 1px solid rgba(255, 255, 255, 0.12);
  border-radius: 20px;
  background: rgba(3, 8, 16, 0.44);
  overflow: hidden;
}

.chat-archive-column-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  padding: 14px 16px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
  font-weight: 800;
}

.chat-archive-column-header small {
  color: rgba(255, 255, 255, 0.58);
  font-weight: 700;
}

.chat-archive-message-list {
  display: grid;
  gap: 10px;
  max-height: calc(100% - 108px);
  margin: 0;
  padding: 14px;
  overflow: auto;
  list-style: none;
}

.chat-archive-message {
  padding: 14px;
  border-radius: 16px;
  background: rgba(255, 255, 255, 0.06);
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
  color: rgba(255, 255, 255, 0.58);
  font-size: 0.78rem;
}

.chat-archive-message-text {
  margin: 10px 0 0;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.chat-archive-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
}

.chat-archive-chip {
  border: 1px solid rgba(98, 219, 255, 0.38);
  border-radius: 999px;
  color: #8be6ff;
  padding: 6px 10px;
  text-decoration: none;
  font-weight: 800;
  font-size: 0.78rem;
}

.chat-archive-empty {
  padding: 18px;
  color: rgba(255, 255, 255, 0.62);
}

.chat-archive-error {
  color: #ff9aa8;
}

.chat-archive-load-more {
  padding: 0 14px 14px;
}

.chat-archive-files {
  overflow: auto;
}

.chat-archive-file-group {
  padding: 14px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.chat-archive-file-group h5 {
  margin: 0 0 10px;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.chat-archive-file-group.empty p {
  margin: 0;
  color: rgba(255, 255, 255, 0.42);
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
  padding: 12px;
  border-radius: 14px;
  background: rgba(255, 255, 255, 0.05);
}

.chat-archive-download-fallback {
  display: none;
  font-size: 0.68rem;
  font-weight: 800;
}

.chat-archive-footer {
  justify-content: space-between;
}

.chat-archive-readonly {
  color: rgba(255, 255, 255, 0.62);
  font-size: 0.82rem;
}

@media (max-width: 840px) {
  .chat-archive-controls,
  .chat-archive-body {
    grid-template-columns: 1fr;
  }

  .chat-archive-body {
    max-height: none;
    overflow: visible;
  }

  .chat-archive-message-list {
    max-height: 52vh;
  }

  .chat-archive-files {
    max-height: 42vh;
  }
}
</style>
