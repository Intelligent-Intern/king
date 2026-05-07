<template>
  <section class="app-config-crud">
    <section class="app-config-crud-main">
      <section class="app-config-toolbar">
        <label class="search-field search-field-main" :aria-label="t('administration.email_text_search')">
          <input
            v-model.trim="query"
            class="input"
            type="search"
            :placeholder="t('administration.email_text_search')"
            @keydown.enter.prevent="applySearch"
          />
        </label>
        <AppIconButton
          icon="/assets/orgas/kingrt/icons/send.png"
          :title="t('administration.apply_search')"
          :aria-label="t('administration.apply_search')"
          @click="applySearch"
        />
      </section>

      <section v-if="state.loading" class="settings-upload-status">{{ t('common.loading') }}</section>
      <section v-if="state.error" class="settings-upload-status error">{{ state.error }}</section>

      <AppConfigurationEmailTextsTable
        :rows="rows"
        :loading="state.loading"
        @edit-row="openEdit"
        @delete-row="deleteRow"
      />

      <footer class="app-config-pagination">
        <AppPagination
          :page="pagination.page"
          :page-count="pagination.page_count"
          :total="pagination.total"
          :total-label="t('administration.email_texts_total')"
          :has-prev="pagination.page > 1"
          :has-next="pagination.page < pagination.page_count"
          :disabled="state.loading"
          @page-change="goToPage"
        />
      </footer>
    </section>

    <AppConfigurationEmailTextEditor
      :open="editor.open"
      :editor="editor"
      :form="form"
      @close="closeEditor"
      @save="saveEditor"
    />
  </section>
</template>

<script setup>
import AppIconButton from '../../../components/AppIconButton.vue';
import AppPagination from '../../../components/AppPagination.vue';
import { t } from '../../localization/i18nRuntime.js';
import AppConfigurationEmailTextEditor from './AppConfigurationEmailTextEditor.vue';
import AppConfigurationEmailTextsTable from './AppConfigurationEmailTextsTable.vue';
import { useAppConfigurationEmailTexts } from './useAppConfigurationEmailTexts.js';

const {
  rows,
  query,
  pagination,
  state,
  editor,
  form,
  applySearch,
  goToPage,
  openEdit,
  closeEditor,
  saveEditor,
  deleteRow,
} = useAppConfigurationEmailTexts({ t });
</script>

<style scoped>
.app-config-crud {
  height: 100%;
  min-height: 0;
  display: flex;
  overflow: hidden;
}

.app-config-crud-main {
  flex: 1 1 auto;
  min-width: 0;
  min-height: 0;
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.app-config-toolbar {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 20px;
}

.app-config-toolbar .search-field {
  flex: 0 1 360px;
  width: min(360px, 100%);
}

.app-config-pagination {
  display: flex;
  justify-content: center;
  margin-top: auto;
}

.settings-upload-status.error {
  color: var(--color-heading);
}

@media (max-width: 900px) {
  .app-config-crud {
    flex-direction: column;
  }
}

@media (max-width: 760px) {
  .app-config-toolbar .btn,
  .app-config-toolbar .search-field,
  .app-config-toolbar .icon-mini-btn {
    flex: 1 1 100%;
    width: 100%;
    margin-inline-end: 0;
  }
}
</style>
