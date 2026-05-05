<template>
  <AdminPageFrame class="administration-localization-view" title="Localization">
    <template #actions>
      <button class="btn" type="button" :disabled="loading" @click="loadLocalizationAdminData">
        Refresh
      </button>
      <label v-if="isSuperAdmin" class="btn btn-cyan localization-file-button">
        <span>Upload CSV</span>
        <input class="localization-file-input" type="file" accept=".csv,text/csv" @change="selectCsv" />
      </label>
      <button v-if="isSuperAdmin" class="btn" type="button" :disabled="!csvContent || previewing" @click="previewCsv">
        Preview
      </button>
      <button v-if="isSuperAdmin" class="btn btn-cyan" type="button" :disabled="!canCommitCsv" @click="commitCsv">
        Commit
      </button>
    </template>

    <template #toolbar>
      <label class="search-field search-field-main" aria-label="Search languages">
        <input v-model.trim="query" class="input" type="search" placeholder="Search languages" />
      </label>
      <span class="settings-upload-status">{{ csvStatus }}</span>
    </template>

    <p v-if="!isSuperAdmin" class="localization-notice">
      CSV imports are restricted to the primary superadmin account.
    </p>
    <p v-if="message" class="localization-notice" :class="{ error: messageKind === 'error' }">
      {{ message }}
    </p>

    <AdminTableFrame class="localization-table-wrap">
      <table class="governance-table localization-table">
        <thead>
          <tr>
            <th>Language</th>
            <th>Code</th>
            <th>Direction</th>
            <th>Source</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="language in pagedLanguages" :key="language.code">
            <td data-label="Language">{{ language.label }}</td>
            <td data-label="Code">
              <span class="code">{{ language.code }}</span>
            </td>
            <td data-label="Direction">
              <span class="tag" :class="language.direction === 'rtl' ? 'warn' : 'ok'">
                {{ language.direction.toUpperCase() }}
              </span>
            </td>
            <td data-label="Source">intelligent-intern.com</td>
          </tr>
          <tr v-if="filteredLanguages.length === 0">
            <td colspan="4" class="localization-empty-cell">No languages match the current filter.</td>
          </tr>
        </tbody>
      </table>
    </AdminTableFrame>

    <section v-if="preview" class="localization-section">
      <h2>CSV Preview</h2>
      <div class="localization-summary-row">
        <span>Rows: {{ preview.total_rows }}</span>
        <span>Valid: {{ preview.valid_rows }}</span>
        <span>Errors: {{ preview.error_count }}</span>
      </div>
      <AdminTableFrame class="localization-table-wrap">
        <table class="governance-table localization-table compact">
          <thead>
            <tr>
              <th>Row</th>
              <th>Locale</th>
              <th>Namespace</th>
              <th>Key</th>
              <th>Value</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="resource in preview.resources.slice(0, 8)" :key="`${resource.row}-${resource.locale}-${resource.namespace}-${resource.resource_key}`">
              <td data-label="Row">{{ resource.row }}</td>
              <td data-label="Locale">{{ resource.locale }}</td>
              <td data-label="Namespace">{{ resource.namespace }}</td>
              <td data-label="Key">{{ resource.resource_key }}</td>
              <td data-label="Value">{{ resource.value }}</td>
            </tr>
            <tr v-if="preview.resources.length === 0">
              <td colspan="5" class="localization-empty-cell">No valid rows in preview.</td>
            </tr>
          </tbody>
        </table>
      </AdminTableFrame>
      <ul v-if="preview.errors.length" class="localization-errors">
        <li v-for="error in preview.errors.slice(0, 10)" :key="`${error.row}-${error.field}-${error.code}`">
          Row {{ error.row }}: {{ error.field }} {{ error.code }}
        </li>
      </ul>
    </section>

    <section class="localization-section">
      <h2>Bundles</h2>
      <AdminTableFrame class="localization-table-wrap">
        <table class="governance-table localization-table compact">
          <thead>
            <tr>
              <th>Locale</th>
              <th>Namespace</th>
              <th>Tenant</th>
              <th>Keys</th>
              <th>Updated</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="bundle in bundles" :key="`${bundle.tenant_id || 'global'}-${bundle.locale}-${bundle.namespace}`">
              <td data-label="Locale">{{ bundle.locale }}</td>
              <td data-label="Namespace">{{ bundle.namespace }}</td>
              <td data-label="Tenant">{{ bundle.tenant_id || 'global' }}</td>
              <td data-label="Keys">{{ bundle.resource_count }}</td>
              <td data-label="Updated">{{ bundle.updated_at || 'n/a' }}</td>
            </tr>
            <tr v-if="bundles.length === 0">
              <td colspan="5" class="localization-empty-cell">No translation bundles imported yet.</td>
            </tr>
          </tbody>
        </table>
      </AdminTableFrame>
    </section>

    <section class="localization-section">
      <h2>Import History</h2>
      <AdminTableFrame class="localization-table-wrap">
        <table class="governance-table localization-table compact">
          <thead>
            <tr>
              <th>File</th>
              <th>Status</th>
              <th>Rows</th>
              <th>Imported</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="entry in imports" :key="entry.id">
              <td data-label="File">{{ entry.file_name || entry.id }}</td>
              <td data-label="Status">{{ entry.status }}</td>
              <td data-label="Rows">{{ entry.row_count }}</td>
              <td data-label="Imported">{{ entry.committed_at || entry.created_at }}</td>
            </tr>
            <tr v-if="imports.length === 0">
              <td colspan="4" class="localization-empty-cell">No CSV imports yet.</td>
            </tr>
          </tbody>
        </table>
      </AdminTableFrame>
    </section>

    <template #footer>
      <AppPagination
        :page="page"
        :page-count="pageCount"
        :total="filteredLanguages.length"
        total-label="languages"
        :has-prev="page > 1"
        :has-next="page < pageCount"
        @page-change="goToPage"
      />
    </template>
  </AdminPageFrame>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import AppPagination from '../../../components/AppPagination.vue';
import AdminPageFrame from '../../../components/admin/AdminPageFrame.vue';
import AdminTableFrame from '../../../components/admin/AdminTableFrame.vue';
import { sessionState } from '../../../domain/auth/session';
import { fetchBackend } from '../../../support/backendFetch';
import {
  SUPPORTED_LOCALIZATION_LANGUAGES,
  localizationLanguageDirection,
} from '../../../support/localizationOptions';

const pageSize = 10;
const query = ref('');
const page = ref(1);
const csvFileName = ref('');
const csvContent = ref('');
const message = ref('');
const messageKind = ref('info');
const loading = ref(false);
const previewing = ref(false);
const committing = ref(false);
const locales = ref([]);
const bundles = ref([]);
const imports = ref([]);
const preview = ref(null);

const isSuperAdmin = computed(() => Number(sessionState.userId || 0) === 1 && String(sessionState.role || '') === 'admin');

const languages = computed(() => {
  const source = locales.value.length > 0 ? locales.value : SUPPORTED_LOCALIZATION_LANGUAGES;
  return source.map((language) => ({
    ...language,
    direction: language.direction === 'rtl' ? 'rtl' : localizationLanguageDirection(language.code),
  }));
});
const filteredLanguages = computed(() => {
  const needle = query.value.trim().toLowerCase();
  if (needle === '') return languages.value;
  return languages.value.filter((language) => (
    String(language.code || '').includes(needle)
    || String(language.label || '').toLowerCase().includes(needle)
    || String(language.direction || '').includes(needle)
  ));
});
const pageCount = computed(() => Math.max(1, Math.ceil(filteredLanguages.value.length / pageSize)));
const pagedLanguages = computed(() => {
  const offset = (page.value - 1) * pageSize;
  return filteredLanguages.value.slice(offset, offset + pageSize);
});
const csvStatus = computed(() => {
  if (committing.value) return 'Committing CSV...';
  if (previewing.value) return 'Previewing CSV...';
  if (csvFileName.value) return `Selected ${csvFileName.value}`;
  return 'No CSV selected.';
});
const canCommitCsv = computed(() => (
  isSuperAdmin.value
  && !!csvContent.value
  && !previewing.value
  && !committing.value
  && preview.value
  && Number(preview.value.error_count || 0) === 0
  && Number(preview.value.valid_rows || 0) > 0
));

watch(filteredLanguages, () => {
  if (page.value > pageCount.value) {
    page.value = pageCount.value;
  }
});

function goToPage(nextPage) {
  page.value = Math.max(1, Math.min(pageCount.value, Number(nextPage) || 1));
}

function authHeaders() {
  const token = String(sessionState.sessionToken || '').trim();
  return {
    'Content-Type': 'application/json',
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
  };
}

async function readJsonResponse(response) {
  try {
    return await response.json();
  } catch {
    return null;
  }
}

function setMessage(nextMessage, kind = 'info') {
  message.value = nextMessage;
  messageKind.value = kind;
}

async function apiJson(path, options = {}) {
  const { response } = await fetchBackend(path, {
    method: options.method || 'GET',
    headers: authHeaders(),
    body: options.body ? JSON.stringify(options.body) : undefined,
  });
  const payload = await readJsonResponse(response);
  if (!response.ok || !payload || payload.status !== 'ok') {
    const fallback = options.fallback || 'Localization request failed.';
    const errorMessage = payload?.error?.message || fallback;
    const error = new Error(errorMessage);
    error.payload = payload;
    error.status = response.status;
    throw error;
  }
  return payload;
}

async function loadLocalizationAdminData() {
  loading.value = true;
  try {
    const [localePayload, bundlePayload, importPayload] = await Promise.all([
      apiJson('/api/admin/localization/locales', { fallback: 'Could not load locales.' }),
      apiJson('/api/admin/localization/bundles', { fallback: 'Could not load bundles.' }),
      apiJson('/api/admin/localization/imports', { fallback: 'Could not load imports.' }),
    ]);
    locales.value = Array.isArray(localePayload.locales) ? localePayload.locales : [];
    bundles.value = Array.isArray(bundlePayload.bundles) ? bundlePayload.bundles : [];
    imports.value = Array.isArray(importPayload.imports) ? importPayload.imports : [];
  } catch (error) {
    setMessage(error instanceof Error ? error.message : 'Could not load localization data.', 'error');
  } finally {
    loading.value = false;
  }
}

async function readFileText(file) {
  if (!file) return '';
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result || ''));
    reader.onerror = () => reject(new Error('Could not read CSV file.'));
    reader.readAsText(file);
  });
}

async function selectCsv(event) {
  const file = event?.target?.files?.[0] || null;
  csvFileName.value = file?.name || '';
  csvContent.value = '';
  preview.value = null;
  if (!file) return;
  try {
    csvContent.value = await readFileText(file);
    setMessage('CSV loaded. Run preview before committing.');
  } catch (error) {
    setMessage(error instanceof Error ? error.message : 'Could not read CSV file.', 'error');
  }
}

async function previewCsv() {
  if (!csvContent.value || previewing.value) return;
  previewing.value = true;
  try {
    const payload = await apiJson('/api/admin/localization/imports/preview', {
      method: 'POST',
      body: {
        csv: csvContent.value,
        file_name: csvFileName.value,
      },
      fallback: 'CSV preview failed.',
    });
    preview.value = payload.result?.preview || null;
    const errors = Number(preview.value?.error_count || 0);
    setMessage(errors > 0 ? `Preview has ${errors} validation errors.` : 'Preview passed. CSV can be committed.', errors > 0 ? 'error' : 'info');
  } catch (error) {
    setMessage(error instanceof Error ? error.message : 'CSV preview failed.', 'error');
  } finally {
    previewing.value = false;
  }
}

async function commitCsv() {
  if (!canCommitCsv.value) return;
  committing.value = true;
  try {
    const payload = await apiJson('/api/admin/localization/imports/commit', {
      method: 'POST',
      body: {
        csv: csvContent.value,
        file_name: csvFileName.value,
      },
      fallback: 'CSV import failed.',
    });
    preview.value = payload.result?.preview || preview.value;
    csvContent.value = '';
    csvFileName.value = '';
    setMessage('CSV import committed.');
    await loadLocalizationAdminData();
  } catch (error) {
    const payload = error?.payload || null;
    preview.value = payload?.error?.details?.preview || preview.value;
    setMessage(error instanceof Error ? error.message : 'CSV import failed.', 'error');
  } finally {
    committing.value = false;
  }
}

onMounted(() => {
  void loadLocalizationAdminData();
});
</script>

<style scoped>
.localization-file-button {
  position: relative;
  overflow: hidden;
}

.localization-file-input {
  position: absolute;
  inset: 0;
  opacity: 0;
  cursor: pointer;
}

.localization-table {
  margin-top: 10px;
}

.localization-table.compact {
  font-size: 0.9rem;
}

.localization-section {
  margin-top: 22px;
}

.localization-section h2 {
  margin: 0 0 8px;
  font-size: 1rem;
}

.localization-summary-row {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  color: var(--text-muted);
  font-size: 0.9rem;
}

.localization-notice {
  margin: 10px 0 0;
  color: var(--text-muted);
}

.localization-notice.error,
.localization-errors {
  color: var(--danger);
}

.localization-errors {
  margin: 10px 0 0;
  padding-left: 18px;
}

.localization-empty-cell {
  padding: 18px 12px;
  text-align: center;
  color: var(--text-muted);
}
</style>
