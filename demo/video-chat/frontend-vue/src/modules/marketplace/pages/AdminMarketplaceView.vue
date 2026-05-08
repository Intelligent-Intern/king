<template>
  <AdminPageFrame class="marketplace-view" :title="t('marketplace.title')">
    <template #toolbar>
      <AdminSearchToolbar
        v-model="queryDraft"
        :search-label="t('marketplace.search')"
        :search-placeholder="t('marketplace.search_placeholder')"
        @submit="applySearchNow"
      >
        <AppSelect v-model="categoryFilter" :aria-label="t('marketplace.category_filter')" @change="applySearchNow">
          <option value="all">{{ t('marketplace.all_categories') }}</option>
          <option v-for="option in categoryOptions" :key="option.value" :value="option.value">
            {{ t(option.label_key) }}
          </option>
        </AppSelect>
      </AdminSearchToolbar>
    </template>

    <section v-if="notice" class="section marketplace-banner ok">{{ notice }}</section>
    <section v-if="error" class="section marketplace-banner error">{{ error }}</section>
    <section v-if="loading && rows.length === 0" class="section marketplace-empty">{{ t('marketplace.loading') }}</section>

    <AdminMarketplaceTable
      v-else
      :rows="rows"
      :mutating-app-id="mutatingAppId"
      :installing-app-key="installingAppKey"
      @install-call-app="installCallApp"
      @edit-app="openEditApp"
      @delete-app="deleteApp"
    />

    <template #footer>
      <AppPagination
        :page="page"
        :page-count="pageCount"
        :total="pagination.total"
        :total-label="t('marketplace.apps_total')"
        :has-prev="pagination.hasPrev"
        :has-next="pagination.hasNext"
        :disabled="loading"
        @page-change="goToPage"
      />
    </template>

    <AppSidePanelShell
      :open="dialogOpen"
      :title="dialogTitle"
      :subtitle="t('marketplace.form_subtitle')"
      :aria-label="dialogTitle"
      root-class-name="marketplace-side-panel"
      backdrop-class="marketplace-side-panel-backdrop"
      dialog-class="marketplace-side-panel-dialog"
      body-class="marketplace-side-panel-body"
      footer-class="marketplace-side-panel-actions"
      :close-label="t('marketplace.close')"
      @close="closeDialog"
    >
      <template #body>
        <label class="marketplace-field">
          <span>{{ t('marketplace.name') }}</span>
          <input v-model.trim="form.name" class="input" type="text" :placeholder="t('marketplace.category.whiteboard')" />
        </label>
        <label class="marketplace-field">
          <span>{{ t('marketplace.manufacturer') }}</span>
          <input v-model.trim="form.manufacturer" class="input" type="text" :placeholder="t('marketplace.manufacturer_placeholder')" />
        </label>
        <label class="marketplace-field">
          <span>{{ t('marketplace.website') }}</span>
          <input v-model.trim="form.website" class="input" type="url" :placeholder="t('marketplace.website_placeholder')" />
        </label>
        <label class="marketplace-field">
          <span>{{ t('marketplace.category') }}</span>
          <AppSelect v-model="form.category">
            <option v-for="option in categoryOptions" :key="option.value" :value="option.value">
              {{ t(option.label_key) }}
            </option>
          </AppSelect>
        </label>
        <label class="marketplace-field marketplace-field-wide">
          <span>{{ t('marketplace.description') }}</span>
          <textarea
            v-model.trim="form.description"
            class="input marketplace-textarea"
            rows="5"
            :placeholder="t('marketplace.description_placeholder')"
          ></textarea>
        </label>
      </template>

      <template #after-body>
        <section v-if="formError" class="marketplace-banner error">{{ formError }}</section>
      </template>

      <template #footer>
        <AdminSidePanelSubmitFooter
          type="button"
          :saving="formSaving"
          :label="dialogSubmitLabel"
          :saving-label="t('common.saving')"
          @submit="submitForm"
        />
      </template>
    </AppSidePanelShell>
  </AdminPageFrame>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import AppPagination from '../../../components/AppPagination.vue';
import AppSelect from '../../../components/AppSelect.vue';
import AppSidePanelShell from '../../../components/AppSidePanelShell.vue';
import AdminPageFrame from '../../../components/admin/AdminPageFrame.vue';
import AdminSearchToolbar from '../../../components/admin/AdminSearchToolbar.vue';
import AdminSidePanelSubmitFooter from '../../../components/admin/AdminSidePanelSubmitFooter.vue';
import AdminMarketplaceTable from './AdminMarketplaceTable.vue';
import { useAdminListController } from '../../../components/admin/useAdminListController.js';
import { useAdminSidePanelForm } from '../../../components/admin/useAdminSidePanelForm.js';
import { createAdminMarketplaceApi } from './adminMarketplaceApi';
import { t } from '../../localization/i18nRuntime.js';

const CATEGORY_OPTIONS = [
  { value: 'whiteboard', label_key: 'marketplace.category.whiteboard' },
  { value: 'avatar', label_key: 'marketplace.category.avatar' },
  { value: 'assistant', label_key: 'marketplace.category.assistant' },
  { value: 'collaboration', label_key: 'marketplace.category.collaboration' },
  { value: 'utility', label_key: 'marketplace.category.utility' },
  { value: 'other', label_key: 'marketplace.category.other' },
];

const router = useRouter();
const apiRequest = createAdminMarketplaceApi({ router });
const categoryFilter = ref('all');
const notice = ref('');
const mutatingAppId = ref(0);
const installingAppKey = ref('');
const sidePanelForm = useAdminSidePanelForm();
const dialogOpen = sidePanelForm.open;
const formSaving = sidePanelForm.saving;
const formError = sidePanelForm.error;
const form = reactive({
  mode: 'create',
  id: 0,
  name: '',
  manufacturer: '',
  website: '',
  category: 'whiteboard',
  description: '',
});
const {
  queryDraft,
  page,
  rows,
  loading,
  error,
  pagination,
  pageCount,
  loadRows,
  applySearchNow,
  goToPage,
} = useAdminListController({
  pageSize: 10,
  load: ({ query, page: currentPage, pageSize }) => apiRequest('/api/admin/marketplace/apps', {
    query: {
      query,
      category: categoryFilter.value,
      page: currentPage,
      page_size: pageSize,
    },
  }),
  rows: (payload) => (Array.isArray(payload.apps) ? payload.apps : []),
  loadErrorMessage: () => t('marketplace.load_failed'),
});

const categoryOptions = computed(() => CATEGORY_OPTIONS);
const dialogTitle = computed(() => (form.mode === 'edit' ? t('marketplace.edit_app') : t('marketplace.add_app')));
const dialogSubmitLabel = computed(() => (form.mode === 'edit' ? t('common.save_changes') : t('marketplace.add_app')));

function resetForm(mode = 'create') {
  form.mode = mode;
  form.id = 0;
  form.name = '';
  form.manufacturer = '';
  form.website = '';
  form.category = 'whiteboard';
  form.description = '';
}

function openEditApp(app) {
  resetForm('edit');
  form.id = Number(app?.id || 0);
  form.name = String(app?.name || '').trim();
  form.manufacturer = String(app?.manufacturer || '').trim();
  form.website = String(app?.website || '').trim();
  form.category = String(app?.category || 'whiteboard').trim() || 'whiteboard';
  form.description = String(app?.description || '').trim();
  sidePanelForm.openPanel();
}

function closeDialog() {
  sidePanelForm.closePanel();
}

async function submitForm() {
  await sidePanelForm.runSubmit(async () => {
    const body = {
      name: form.name,
      manufacturer: form.manufacturer,
      website: form.website,
      category: form.category,
      description: form.description,
    };

    if (form.mode === 'edit' && form.id > 0) {
      await apiRequest(`/api/admin/marketplace/apps/${encodeURIComponent(String(form.id))}`, {
        method: 'PATCH',
        body,
      });
      notice.value = t('marketplace.app_updated');
    } else {
      await apiRequest('/api/admin/marketplace/apps', {
        method: 'POST',
        body,
      });
      notice.value = t('marketplace.app_created');
    }

    dialogOpen.value = false;
    await loadRows();
  }, t('marketplace.save_failed'));
}

async function deleteApp(app) {
  const appId = Number(app?.id || 0);
  if (appId <= 0) return;
  const label = String(app?.name || t('marketplace.this_app')).trim() || t('marketplace.this_app');
  if (!window.confirm(t('marketplace.confirm_delete', { name: label }))) return;

  mutatingAppId.value = appId;
  error.value = '';
  try {
    await apiRequest(`/api/admin/marketplace/apps/${encodeURIComponent(String(appId))}`, {
      method: 'DELETE',
    });
    notice.value = t('marketplace.app_deleted');
    await loadRows();
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('marketplace.delete_failed');
  } finally {
    mutatingAppId.value = 0;
  }
}

async function installCallApp(app) {
  const catalog = app && typeof app === 'object' && app.call_app_catalog && typeof app.call_app_catalog === 'object'
    ? app.call_app_catalog
    : null;
  const appKey = String(catalog?.app_key || '').trim();
  if (appKey === '') return;

  const label = String(app?.name || appKey).trim() || appKey;
  installingAppKey.value = appKey;
  error.value = '';
  try {
    await apiRequest(`/api/marketplace/call-apps/${encodeURIComponent(appKey)}/orders`, {
      method: 'POST',
    });
    await apiRequest(`/api/marketplace/call-apps/${encodeURIComponent(appKey)}/installations`, {
      method: 'POST',
      body: {
        default_app_policy: 'blocked_by_default',
        config: {},
      },
    });
    notice.value = t('marketplace.call_app_installed', { name: label });
    await loadRows();
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('marketplace.call_app_install_failed', { name: label });
  } finally {
    installingAppKey.value = '';
  }
}

onMounted(() => {
  void loadRows();
});
</script>

<style scoped src="./AdminMarketplaceView.css"></style>
