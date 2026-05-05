<template>
  <section class="view-card marketplace-view">
    <AppPageHeader class="section marketplace-head" title="Marketplace" />

    <section class="toolbar marketplace-toolbar">
      <label class="search-field search-field-main" aria-label="Search marketplace apps">
        <input
          v-model.trim="queryDraft"
          class="input"
          type="search"
          placeholder="Search by name, manufacturer, or website"
          @keydown.enter.prevent="applySearchNow"
        />
      </label>

      <AppSelect v-model="categoryFilter" aria-label="Category filter" @change="applySearchNow">
        <option value="all">All categories</option>
        <option v-for="option in categoryOptions" :key="option.value" :value="option.value">
          {{ option.label }}
        </option>
      </AppSelect>

      <AppIconButton
        class="marketplace-toolbar-search-btn"
        icon="/assets/orgas/kingrt/icons/send.png"
        title="Search marketplace apps"
        aria-label="Search marketplace apps"
        @click="applySearchNow"
      />
    </section>

    <section v-if="notice" class="section marketplace-banner ok">{{ notice }}</section>
    <section v-if="error" class="section marketplace-banner error">{{ error }}</section>
    <section v-if="loading && rows.length === 0" class="section marketplace-empty">Loading marketplace apps...</section>

    <AdminMarketplaceTable
      v-else
      :rows="rows"
      :mutating-app-id="mutatingAppId"
      @edit-app="openEditApp"
      @delete-app="deleteApp"
    />

    <footer class="footer marketplace-footer">
      <AppPagination
        :page="page"
        :page-count="pageCount"
        :total="pagination.total"
        total-label="apps"
        :has-prev="pagination.hasPrev"
        :has-next="pagination.hasNext"
        :disabled="loading"
        @page-change="goToPage"
      />
    </footer>

    <div v-if="dialogOpen" class="marketplace-modal" role="dialog" aria-modal="true" :aria-label="dialogTitle">
      <div class="marketplace-modal-backdrop" @click="closeDialog"></div>
      <div class="marketplace-modal-dialog">
        <header class="marketplace-modal-head marketplace-modal-head-brand">
          <div class="marketplace-modal-head-left">
            <img class="marketplace-modal-head-logo" src="/assets/orgas/kingrt/logo.svg" alt="" />
            <div>
              <h4>{{ dialogTitle }}</h4>
              <p>Manage callable marketplace entries for video calls.</p>
            </div>
          </div>
          <button class="icon-mini-btn" type="button" aria-label="Close" @click="closeDialog">
            <img src="/assets/orgas/kingrt/icons/cancel.png" alt="" />
          </button>
        </header>

        <section class="marketplace-modal-body">
          <label class="marketplace-field">
            <span>Name</span>
            <input v-model.trim="form.name" class="input" type="text" placeholder="Whiteboard" />
          </label>
          <label class="marketplace-field">
            <span>Manufacturer</span>
            <input v-model.trim="form.manufacturer" class="input" type="text" placeholder="Intelligent Intern" />
          </label>
          <label class="marketplace-field">
            <span>Website</span>
            <input v-model.trim="form.website" class="input" type="url" placeholder="https://example.com" />
          </label>
          <label class="marketplace-field">
            <span>Category</span>
            <AppSelect v-model="form.category">
              <option v-for="option in categoryOptions" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </AppSelect>
          </label>
          <label class="marketplace-field marketplace-field-wide">
            <span>Description</span>
            <textarea
              v-model.trim="form.description"
              class="input marketplace-textarea"
              rows="5"
              placeholder="Optional notes about the app, feature scope, or integration path."
            ></textarea>
          </label>
        </section>

        <section v-if="formError" class="marketplace-banner error">{{ formError }}</section>

        <footer class="marketplace-modal-actions">
          <button class="btn" type="button" :disabled="formSaving" @click="closeDialog">Cancel</button>
          <button class="btn btn-cyan" type="button" :disabled="formSaving" @click="submitForm">
            {{ dialogSubmitLabel }}
          </button>
        </footer>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import AppIconButton from '../../../components/AppIconButton.vue';
import AppPageHeader from '../../../components/AppPageHeader.vue';
import AppPagination from '../../../components/AppPagination.vue';
import AppSelect from '../../../components/AppSelect.vue';
import AdminMarketplaceTable from './AdminMarketplaceTable.vue';
import { createAdminMarketplaceApi } from './adminMarketplaceApi';

const CATEGORY_OPTIONS = [
  { value: 'whiteboard', label: 'Whiteboard' },
  { value: 'avatar', label: 'Avatar' },
  { value: 'assistant', label: 'Assistant' },
  { value: 'collaboration', label: 'Collaboration' },
  { value: 'utility', label: 'Utility' },
  { value: 'other', label: 'Other' },
];

const router = useRouter();
const apiRequest = createAdminMarketplaceApi({ router });
const queryDraft = ref('');
const queryApplied = ref('');
const categoryFilter = ref('all');
const page = ref(1);
const rows = ref([]);
const loading = ref(false);
const error = ref('');
const notice = ref('');
const mutatingAppId = ref(0);
const dialogOpen = ref(false);
const formSaving = ref(false);
const formError = ref('');
const form = reactive({
  mode: 'create',
  id: 0,
  name: '',
  manufacturer: '',
  website: '',
  category: 'whiteboard',
  description: '',
});
const pagination = reactive({
  total: 0,
  pageCount: 1,
  hasPrev: false,
  hasNext: false,
});

let loadToken = 0;
let searchTimer = 0;

const categoryOptions = computed(() => CATEGORY_OPTIONS);
const pageCount = computed(() => pagination.pageCount);
const dialogTitle = computed(() => (form.mode === 'edit' ? 'Edit marketplace app' : 'Add marketplace app'));
const dialogSubmitLabel = computed(() => (form.mode === 'edit' ? 'Save changes' : 'Create app'));

async function loadApps() {
  const token = ++loadToken;
  loading.value = true;
  error.value = '';

  try {
    const payload = await apiRequest('/api/admin/marketplace/apps', {
      query: {
        query: queryApplied.value,
        category: categoryFilter.value,
        page: page.value,
        page_size: 10,
      },
    });

    if (token !== loadToken) return;

    rows.value = Array.isArray(payload.apps) ? payload.apps : [];
    const paging = payload.pagination || {};
    const nextPageCount = Number.isInteger(paging.page_count) && paging.page_count > 0 ? paging.page_count : 1;
    pagination.total = Number.isInteger(paging.total) ? paging.total : rows.value.length;
    pagination.pageCount = nextPageCount;
    pagination.hasPrev = Boolean(paging.has_prev);
    pagination.hasNext = Boolean(paging.has_next);
    if (page.value > pagination.pageCount) {
      page.value = pagination.pageCount;
      if (token === loadToken) {
        await loadApps();
      }
      return;
    }
  } catch (err) {
    if (token !== loadToken) return;
    rows.value = [];
    pagination.total = 0;
    pagination.pageCount = 1;
    pagination.hasPrev = false;
    pagination.hasNext = false;
    error.value = err instanceof Error ? err.message : 'Could not load marketplace apps.';
  } finally {
    if (token === loadToken) loading.value = false;
  }
}

function applySearchNow() {
  queryApplied.value = queryDraft.value.trim();
  page.value = 1;
  void loadApps();
}

watch(queryDraft, () => {
  window.clearTimeout(searchTimer);
  searchTimer = window.setTimeout(() => {
    queryApplied.value = queryDraft.value.trim();
    page.value = 1;
    void loadApps();
  }, 250);
});

function goToPage(nextPage) {
  if (!Number.isInteger(nextPage) || nextPage < 1 || nextPage === page.value) return;
  page.value = nextPage;
  void loadApps();
}

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
  formError.value = '';
  dialogOpen.value = true;
}

function closeDialog() {
  if (formSaving.value) return;
  dialogOpen.value = false;
}

async function submitForm() {
  formSaving.value = true;
  formError.value = '';

  try {
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
      notice.value = 'Marketplace app updated.';
    } else {
      await apiRequest('/api/admin/marketplace/apps', {
        method: 'POST',
        body,
      });
      notice.value = 'Marketplace app created.';
    }

    dialogOpen.value = false;
    await loadApps();
  } catch (err) {
    formError.value = err instanceof Error ? err.message : 'Could not save marketplace app.';
  } finally {
    formSaving.value = false;
  }
}

async function deleteApp(app) {
  const appId = Number(app?.id || 0);
  if (appId <= 0) return;
  const label = String(app?.name || 'this app').trim() || 'this app';
  if (!window.confirm(`Delete ${label}?`)) return;

  mutatingAppId.value = appId;
  error.value = '';
  try {
    await apiRequest(`/api/admin/marketplace/apps/${encodeURIComponent(String(appId))}`, {
      method: 'DELETE',
    });
    notice.value = 'Marketplace app deleted.';
    await loadApps();
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Could not delete marketplace app.';
  } finally {
    mutatingAppId.value = 0;
  }
}

onMounted(() => {
  void loadApps();
});

onBeforeUnmount(() => {
  window.clearTimeout(searchTimer);
});
</script>

<style scoped src="./AdminMarketplaceView.css"></style>
