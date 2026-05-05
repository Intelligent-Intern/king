<template>
  <section class="view-card administration-localization-view">
    <AppPageHeader class="section localization-head" title="Localization">
      <template #actions>
        <label class="btn btn-cyan localization-file-button">
          <span>Upload CSV</span>
          <input class="localization-file-input" type="file" accept=".csv,text/csv" @change="selectCsv" />
        </label>
      </template>
    </AppPageHeader>

    <section class="toolbar localization-toolbar">
      <label class="search-field search-field-main" aria-label="Search languages">
        <input v-model.trim="query" class="input" type="search" placeholder="Search languages" />
      </label>
      <span class="settings-upload-status">{{ csvStatus }}</span>
    </section>

    <section class="table-wrap localization-table-wrap">
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
    </section>

    <footer class="footer localization-footer">
      <AppPagination
        :page="page"
        :page-count="pageCount"
        :total="filteredLanguages.length"
        total-label="languages"
        :has-prev="page > 1"
        :has-next="page < pageCount"
        @page-change="goToPage"
      />
    </footer>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import AppPageHeader from '../../components/AppPageHeader.vue';
import AppPagination from '../../components/AppPagination.vue';
import {
  SUPPORTED_LOCALIZATION_LANGUAGES,
  localizationLanguageDirection,
} from '../../support/localizationOptions';

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
.administration-localization-view {
  min-height: 100%;
  display: flex;
  flex-direction: column;
  gap: 0;
  background: transparent;
}

.administration-localization-view > :first-child {
  border-top-left-radius: 0;
  border-top-right-radius: 5px;
}

.localization-head,
.localization-toolbar,
.localization-footer {
  background: var(--bg-ui-chrome);
}

.localization-head,
.localization-toolbar {
  display: flex;
  gap: 10px;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
}

.localization-toolbar {
  padding-bottom: 25px;
}

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

.localization-table-wrap {
  flex: 1 1 auto;
  min-height: 0;
  margin-top: 0;
  padding-left: 10px;
  padding-right: 10px;
}

.localization-table {
  margin-top: 10px;
}

.localization-empty-cell {
  padding: 18px 12px;
  text-align: center;
  color: var(--text-muted);
}

.localization-footer {
  display: flex;
  justify-content: center;
  margin-top: auto;
  padding-left: 10px;
  padding-right: 10px;
}
</style>
