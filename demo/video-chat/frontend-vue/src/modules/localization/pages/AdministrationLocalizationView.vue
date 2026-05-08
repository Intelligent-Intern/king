<template>
  <AdminPageFrame class="administration-localization-view" :title="t('localization.admin.title')">
    <template #toolbar>
      <label class="search-field search-field-main" :aria-label="t('localization.admin.search_languages')">
        <input v-model.trim="query" class="input" type="search" :placeholder="t('localization.admin.search_languages')" />
      </label>
    </template>

    <p v-if="message" class="localization-notice" :class="{ error: messageKind === 'error' }">
      {{ message }}
    </p>

    <AdminTableFrame class="localization-table-wrap">
      <table class="governance-table localization-table">
        <thead>
          <tr>
            <th>{{ t('localization.admin.language') }}</th>
            <th>{{ t('localization.admin.code') }}</th>
            <th>{{ t('localization.admin.direction') }}</th>
            <th>{{ t('localization.admin.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="language in pagedLanguages" :key="language.code">
            <td :data-label="t('localization.admin.language')">{{ language.label }}</td>
            <td :data-label="t('localization.admin.code')">
              <span class="code">{{ language.code }}</span>
            </td>
            <td :data-label="t('localization.admin.direction')">
              <span class="tag" :class="language.direction === 'rtl' ? 'warn' : 'ok'">
                {{ language.direction.toUpperCase() }}
              </span>
            </td>
            <td :data-label="t('localization.admin.actions')">
              <button class="btn btn-cyan localization-edit-button" type="button" @click="openEditor(language.code)">
                {{ t('localization.admin.edit') }}
              </button>
            </td>
          </tr>
          <tr v-if="filteredLanguages.length === 0">
            <td colspan="4" class="localization-empty-cell">{{ t('localization.admin.no_languages') }}</td>
          </tr>
        </tbody>
      </table>
    </AdminTableFrame>

    <AdministrationLocalizationEditor
      :open="editorOpen"
      :languages="languages"
      :translation-rows="translationRows"
      :editor-left-locale="editorLeftLocale"
      :editor-right-locale="editorRightLocale"
      :editor-loading="editorLoading"
      :saving="saving"
      :editor-value="editorValue"
      :locale-direction="localeDirection"
      @update:left-locale="editorLeftLocale = $event"
      @update:right-locale="editorRightLocale = $event"
      @update-value="updateEditorValue($event.locale, $event.fullKey, $event.value)"
      @save="saveEditor"
    />

    <template #footer>
      <AppPagination
        :page="page"
        :page-count="pageCount"
        :total="filteredLanguages.length"
        :total-label="t('localization.admin.languages_total')"
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
import { buildLocalizedApiError } from '../apiErrorMessages.js';
import AdministrationLocalizationEditor from '../components/AdministrationLocalizationEditor.vue';
import { ENGLISH_MESSAGES } from '../englishMessages.js';
import { t } from '../i18nRuntime.js';

const pageSize = 10;
const query = ref('');
const page = ref(1);
const message = ref('');
const messageKind = ref('info');
const saving = ref(false);
const editorLoading = ref(false);
const locales = ref([]);
const editorOpen = ref(false);
const editorLeftLocale = ref('en');
const editorRightLocale = ref('de');
const editorValues = ref({});
const editorOriginalValues = ref({});

const translationRows = Object.keys(ENGLISH_MESSAGES)
  .sort((left, right) => left.localeCompare(right))
  .map((fullKey) => {
    const separatorIndex = fullKey.indexOf('.');
    return {
      fullKey,
      namespace: separatorIndex > 0 ? fullKey.slice(0, separatorIndex) : 'common',
      resourceKey: separatorIndex > 0 ? fullKey.slice(separatorIndex + 1) : fullKey,
      englishValue: String(ENGLISH_MESSAGES[fullKey] ?? ''),
    };
  });

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

watch(filteredLanguages, () => {
  if (page.value > pageCount.value) {
    page.value = pageCount.value;
  }
});

watch([editorLeftLocale, editorRightLocale], () => {
  if (editorOpen.value) {
    void loadEditorResources();
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
    const fallback = options.fallback || t('localization.admin.request_failed');
    const error = buildLocalizedApiError(payload, fallback, response.status);
    error.status = response.status;
    throw error;
  }
  return payload;
}

function fieldKey(locale, fullKey) {
  return `${locale}:${fullKey}`;
}

function localeDirection(locale) {
  return localizationLanguageDirection(locale);
}

function editorValue(locale, fullKey) {
  return String(editorValues.value[fieldKey(locale, fullKey)] ?? '');
}

function updateEditorValue(locale, fullKey, value) {
  editorValues.value = {
    ...editorValues.value,
    [fieldKey(locale, fullKey)]: String(value ?? ''),
  };
}

async function loadLocalizationAdminData() {
  try {
    const localePayload = await apiJson('/api/admin/localization/locales', {
      fallback: t('localization.admin.load_locales_failed'),
    });
    locales.value = Array.isArray(localePayload.locales) ? localePayload.locales : [];
  } catch (error) {
    setMessage(error instanceof Error ? error.message : t('localization.admin.load_data_failed'), 'error');
  }
}

async function loadLocaleResources(locale) {
  const payload = await apiJson(`/api/localization/resources?locale=${encodeURIComponent(locale)}`, {
    fallback: t('localization.admin.load_translations_failed'),
  });
  const resources = payload && typeof payload.resources === 'object' && payload.resources !== null ? payload.resources : {};
  const values = {};
  for (const row of translationRows) {
    values[row.fullKey] = locale === 'en'
      ? String(resources[row.fullKey] ?? row.englishValue)
      : String(resources[row.fullKey] ?? '');
  }
  return values;
}

async function loadEditorResources() {
  editorLoading.value = true;
  try {
    const selectedLocales = Array.from(new Set([editorLeftLocale.value, editorRightLocale.value]));
    const resourcePairs = await Promise.all(selectedLocales.map(async (locale) => [locale, await loadLocaleResources(locale)]));
    const nextValues = {};
    const nextOriginalValues = {};
    for (const [locale, resources] of resourcePairs) {
      for (const row of translationRows) {
        const key = fieldKey(locale, row.fullKey);
        nextValues[key] = String(resources[row.fullKey] ?? '');
        nextOriginalValues[key] = nextValues[key];
      }
    }
    editorValues.value = nextValues;
    editorOriginalValues.value = nextOriginalValues;
  } catch (error) {
    setMessage(error instanceof Error ? error.message : t('localization.admin.load_translations_failed'), 'error');
  } finally {
    editorLoading.value = false;
  }
}

function openEditor(locale) {
  editorLeftLocale.value = 'en';
  editorRightLocale.value = locale === 'en' ? 'de' : locale;
  editorOpen.value = true;
  void loadEditorResources();
}

async function saveEditor() {
  if (editorLoading.value || saving.value) return;
  saving.value = true;
  try {
    const resources = [];
    const selectedLocales = Array.from(new Set([editorLeftLocale.value, editorRightLocale.value]));
    for (const locale of selectedLocales) {
      for (const row of translationRows) {
        const key = fieldKey(locale, row.fullKey);
        const value = editorValues.value[key] ?? '';
        if (String(value) === String(editorOriginalValues.value[key] ?? '')) {
          continue;
        }
        resources.push({
          locale,
          namespace: row.namespace,
          resource_key: row.resourceKey,
          value: String(value),
        });
      }
    }

    if (resources.length === 0) {
      setMessage(t('localization.admin.no_translation_changes'));
      return;
    }

    const payload = await apiJson('/api/admin/localization/resources', {
      method: 'PUT',
      body: { resources },
      fallback: t('localization.admin.save_translations_failed'),
    });
    for (const resource of resources) {
      const key = fieldKey(resource.locale, `${resource.namespace}.${resource.resource_key}`);
      editorOriginalValues.value[key] = resource.value;
    }
    setMessage(t('localization.admin.translations_saved', { count: payload.saved_count || resources.length }));
  } catch (error) {
    setMessage(error instanceof Error ? error.message : t('localization.admin.save_translations_failed'), 'error');
  } finally {
    saving.value = false;
  }
}

onMounted(() => {
  void loadLocalizationAdminData();
});
</script>

<style scoped>
.localization-table {
  margin-top: 10px;
}

.localization-edit-button {
  min-width: 82px;
}

.localization-notice {
  margin: 10px 20px 0;
  color: var(--text-muted);
}

.localization-notice.error {
  color: var(--danger);
}

.localization-empty-cell {
  padding: 18px 12px;
  text-align: center;
  color: var(--text-muted);
}

</style>
