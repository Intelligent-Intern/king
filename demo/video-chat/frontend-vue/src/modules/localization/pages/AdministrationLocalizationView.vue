<template>
  <AdminPageFrame class="administration-localization-view" title="Localization">
    <template #actions>
      <label class="btn btn-cyan localization-file-button">
        <span>Upload CSV</span>
        <input class="localization-file-input" type="file" accept=".csv,text/csv" @change="selectCsv" />
      </label>
    </template>

    <template #toolbar>
      <label class="search-field search-field-main" aria-label="Search languages">
        <input v-model.trim="query" class="input" type="search" placeholder="Search languages" />
      </label>
      <span class="settings-upload-status">{{ csvStatus }}</span>
    </template>

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
import { computed, ref, watch } from 'vue';
import AppPagination from '../../../components/AppPagination.vue';
import AdminPageFrame from '../../../components/admin/AdminPageFrame.vue';
import AdminTableFrame from '../../../components/admin/AdminTableFrame.vue';
import {
  SUPPORTED_LOCALIZATION_LANGUAGES,
  localizationLanguageDirection,
} from '../../../support/localizationOptions';

const pageSize = 10;
const query = ref('');
const page = ref(1);
const csvFileName = ref('');

const languages = computed(() => SUPPORTED_LOCALIZATION_LANGUAGES.map((language) => ({
  ...language,
  direction: localizationLanguageDirection(language.code),
})));
const filteredLanguages = computed(() => {
  const needle = query.value.trim().toLowerCase();
  if (needle === '') return languages.value;
  return languages.value.filter((language) => (
    language.code.includes(needle)
    || language.label.toLowerCase().includes(needle)
    || language.direction.includes(needle)
  ));
});
const pageCount = computed(() => Math.max(1, Math.ceil(filteredLanguages.value.length / pageSize)));
const pagedLanguages = computed(() => {
  const offset = (page.value - 1) * pageSize;
  return filteredLanguages.value.slice(offset, offset + pageSize);
});
const csvStatus = computed(() => (csvFileName.value ? `Selected ${csvFileName.value}` : 'No CSV selected.'));

watch(filteredLanguages, () => {
  if (page.value > pageCount.value) {
    page.value = pageCount.value;
  }
});

function goToPage(nextPage) {
  page.value = Math.max(1, Math.min(pageCount.value, Number(nextPage) || 1));
}

function selectCsv(event) {
  const file = event?.target?.files?.[0] || null;
  csvFileName.value = file?.name || '';
}
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

.localization-empty-cell {
  padding: 18px 12px;
  text-align: center;
  color: var(--text-muted);
}

</style>
