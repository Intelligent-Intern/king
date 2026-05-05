<template>
  <AppModalShell
    :open="open"
    :title="activeDialogTitle"
    :aria-label="activeDialogTitle"
    root-class-name="users-modal"
    backdrop-class="users-modal-backdrop"
    dialog-class="users-modal-dialog"
    header-class="users-modal-head users-modal-head-brand"
    header-left-class="users-modal-head-left"
    logo-class="users-modal-head-logo"
    title-class=""
    :body-class="relationStackOpen ? 'users-modal-body users-modal-body-relation' : (avatarEditorOpen ? 'users-avatar-modal-body' : 'users-modal-body')"
    footer-class="users-modal-footer"
    :close-label="t('users.close_user_modal')"
    maximizable
    :maximized="editorMaximized"
    @update:maximized="editorMaximized = $event"
    @close="$emit('close')"
  >
    <template #body>
      <CrudRelationStack
        v-if="relationStackOpen"
        :open="relationStackOpen"
        :relation="activeRelation"
        :selections="relationSelections"
        :row-provider="relationRowsForEntity"
        :create-draft="createGovernanceRelationRow"
        :can-create-draft-for-entity="canCreateGovernanceRelationRow"
        :relation-filter="relationStackRelationFilter"
        :show-nested-relations="relationStackShowsNestedRelations"
        @close="closeRelationStack"
        @apply="applyRelationSelection"
      />

      <template v-else-if="!avatarEditorOpen">
        <form id="userEditorForm" class="users-edit-form" autocomplete="off" @submit.prevent="$emit('submit-form')">
          <label v-if="form.mode === 'create'" class="users-field">
            <span>{{ t('users.email') }}</span>
            <input v-model.trim="form.email" class="input" type="email" autocomplete="email" />
          </label>

          <section v-else class="users-field users-field-wide">
            <span>{{ t('users.emails') }}</span>
            <div class="users-email-list">
              <article
                v-for="emailRow in userEmailRows"
                :key="emailRow.id"
                class="users-email-row"
              >
                <div class="users-email-main">
                  <div class="users-email-value">{{ emailRow.email }}</div>
                  <div class="users-email-meta">
                    <span class="tag" :class="emailRow.is_verified ? 'ok' : 'warn'">
                      {{ emailRow.is_verified ? t('users.email_confirmed') : t('users.email_unconfirmed') }}
                    </span>
                    <span v-if="emailRow.is_primary" class="tag ok">{{ t('users.email_primary') }}</span>
                  </div>
                </div>
                <AppIconButton
                  v-if="!emailRow.is_verified"
                  icon="/assets/orgas/kingrt/icons/remove_user.png"
                  :disabled="formSaving || userEmailMutatingId === emailRow.id"
                  danger
                  @click="$emit('delete-pending-email', emailRow)"
                />
              </article>
              <p v-if="userEmailRows.length === 0" class="users-email-empty">{{ t('users.no_emails_configured') }}</p>
            </div>
            <div class="users-email-create">
              <input
                v-model.trim="emailDraftModel"
                class="input"
                type="email"
                autocomplete="email"
                :placeholder="t('users.add_new_email')"
                :disabled="formSaving || userEmailSubmitting || userEmailLoading"
              />
              <button
                class="btn"
                type="button"
                :disabled="formSaving || userEmailSubmitting || userEmailLoading"
                @click="$emit('create-pending-email')"
              >
                {{ userEmailSubmitting ? t('users.sending') : t('users.send_confirmation') }}
              </button>
            </div>
          </section>

          <label class="users-field">
            <span>{{ t('users.display_name') }}</span>
            <input v-model.trim="form.display_name" class="input" type="text" />
          </label>

          <label v-if="form.mode === 'create'" class="users-field">
            <span>{{ t('users.password') }}</span>
            <input v-model="form.password" class="input" type="password" autocomplete="new-password" />
          </label>

          <label v-if="form.mode === 'create'" class="users-field">
            <span>{{ t('users.repeat_password') }}</span>
            <input v-model="form.password_repeat" class="input" type="password" autocomplete="new-password" />
          </label>

          <section class="users-field">
            <span>{{ t('users.role') }}</span>
            <button
              class="users-relation-link"
              type="button"
              :disabled="!canEditRole"
              @click="openRelation(roleRelation)"
            >
              <strong>+1</strong>
              <span>{{ currentRoleLabel }}</span>
            </button>
          </section>

          <section class="users-field">
            <span>{{ t('users.governance_roles') }}</span>
            <button
              class="users-relation-link"
              type="button"
              :disabled="!canEditGovernanceRoles"
              @click="openRelation(governanceRoleRelation)"
            >
              <strong>+1</strong>
              <span>{{ currentGovernanceRolesLabel }}</span>
            </button>
          </section>

          <section class="users-field">
            <span>{{ t('governance.relation.groups') }}</span>
            <button
              class="users-relation-link"
              type="button"
              :disabled="!canEditGovernanceGroups"
              @click="openRelation(governanceGroupRelation)"
            >
              <strong>+1</strong>
              <span>{{ currentGovernanceGroupsLabel }}</span>
            </button>
          </section>

          <label v-if="form.mode === 'edit'" class="users-field">
            <span>{{ t('users.status') }}</span>
            <AppSelect v-model="form.status" :disabled="!canEditStatus">
              <option value="active">{{ t('users.status_active') }}</option>
              <option value="disabled">{{ t('users.status_disabled') }}</option>
            </AppSelect>
          </label>

          <label v-if="form.mode === 'edit'" class="users-field">
            <span>{{ t('users.time_format') }}</span>
            <AppSelect v-model="form.time_format">
              <option value="24h">24h</option>
              <option value="12h">12h</option>
            </AppSelect>
          </label>

          <section v-if="form.mode === 'edit'" class="users-field">
            <span>{{ t('users.theme') }}</span>
            <button class="users-relation-link" type="button" @click="openRelation(themeRelation)">
              <strong>+1</strong>
              <span>{{ currentThemeLabel }}</span>
            </button>
          </section>

          <section class="users-field">
            <span>{{ t('users.theme_editor') }}</span>
            <label class="users-checkbox-row">
              <input v-model="themeEditorChecked" type="checkbox" :disabled="themeEditorDisabled" />
              <span>{{ themeEditorLabel }}</span>
            </label>
          </section>

          <section v-if="form.mode === 'edit'" class="users-field users-field-wide users-avatar-edit-row">
            <div class="users-avatar-preview-wrap">
              <img class="users-avatar-preview" :src="avatarPreviewSrc" :alt="t('users.avatar_preview_alt')" />
            </div>
            <div class="users-avatar-edit-actions">
              <button class="btn btn-cyan" type="button" :disabled="formSaving" @click="$emit('open-avatar-editor')">
                {{ t('users.change_avatar') }}
              </button>
            </div>
          </section>
        </form>
      </template>

      <template v-else>
        <div class="users-avatar-preview-wrap">
          <img class="users-avatar-preview users-avatar-preview-large" :src="avatarEditorPreviewSrc" :alt="t('users.avatar_preview')" />
        </div>
        <label class="users-avatar-file">
          <span>{{ t('users.upload_avatar') }}</span>
          <input class="input" type="file" accept="image/png,image/jpeg,image/webp" @change="$emit('avatar-file-select', $event)" />
        </label>
        <section class="users-avatar-defaults">
          <span>{{ t('users.set_default') }}</span>
          <div class="users-avatar-defaults-actions">
            <button
              v-for="option in defaultAvatarOptions"
              :key="option.path"
              class="btn"
              type="button"
              :disabled="formSaving"
              @click="$emit('set-default-avatar', option.path)"
            >
              {{ option.label }}
            </button>
          </div>
        </section>
        <p class="users-avatar-hint">{{ t('users.avatar_hint') }}</p>
      </template>
    </template>

    <template #after-body>
      <p v-if="formError" class="users-form-error">{{ formError }}</p>
    </template>

    <template #footer v-if="!relationStackOpen">
      <button
        class="btn btn-cyan"
        :type="avatarEditorOpen ? 'button' : 'submit'"
        :form="avatarEditorOpen ? undefined : 'userEditorForm'"
        :disabled="formSaving"
        @click="avatarEditorOpen && $emit('save-avatar-changes')"
      >
        {{ formSaving ? t('common.saving') : (avatarEditorOpen ? t('users.save_avatar') : dialogSubmitLabel) }}
      </button>
    </template>
  </AppModalShell>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import AppIconButton from '../../../../components/AppIconButton.vue';
import AppModalShell from '../../../../components/AppModalShell.vue';
import AppSelect from '../../../../components/AppSelect.vue';
import { sessionState } from '../../../../domain/auth/session.js';
import { moduleAccessContextFromSession } from '../../../../http/routeAccess.js';
import CrudRelationStack from '../../../governance/components/CrudRelationStack.vue';
import { GOVERNANCE_CRUD_DESCRIPTORS } from '../../../governance/crudDescriptors.js';
import { createGovernanceCrudPersistence } from '../../../governance/useGovernanceCrudPersistence.js';
import { buildGovernanceCatalogRows } from '../../../governanceCatalog.js';
import { workspaceModuleRegistry } from '../../../index.js';
import { t } from '../../../localization/i18nRuntime.js';
import { useUserEditorModal } from './useUserEditorModal.js';

const roleRelation = Object.freeze({
  key: 'role',
  target_entity: 'user_roles',
  label_key: 'users.role',
  selection_mode: 'single',
});
const governanceRoleRelation = Object.freeze({
  key: 'governance_roles',
  target_entity: 'governance_roles',
  label_key: 'users.governance_roles',
  selection_mode: 'multiple',
});
const governanceGroupRelation = Object.freeze({
  key: 'governance_groups',
  target_entity: 'groups',
  label_key: 'governance.relation.groups',
  selection_mode: 'multiple',
});
const themeRelation = Object.freeze({
  key: 'theme',
  target_entity: 'user_themes',
  label_key: 'users.theme',
  selection_mode: 'single',
});

const props = defineProps({
  open: {
    type: Boolean,
    default: false,
  },
  dialogTitle: {
    type: String,
    required: true,
  },
  dialogSubmitLabel: {
    type: String,
    required: true,
  },
  form: {
    type: Object,
    required: true,
  },
  formSaving: {
    type: Boolean,
    default: false,
  },
  formError: {
    type: String,
    default: '',
  },
  avatarEditorOpen: {
    type: Boolean,
    default: false,
  },
  avatarPreviewSrc: {
    type: String,
    required: true,
  },
  avatarEditorPreviewSrc: {
    type: String,
    required: true,
  },
  defaultAvatarOptions: {
    type: Array,
    default: () => [],
  },
  userEmailRows: {
    type: Array,
    default: () => [],
  },
  userEmailDraft: {
    type: String,
    default: '',
  },
  userEmailLoading: {
    type: Boolean,
    default: false,
  },
  userEmailSubmitting: {
    type: Boolean,
    default: false,
  },
  userEmailMutatingId: {
    type: Number,
    default: 0,
  },
  canEditRole: {
    type: Boolean,
    default: true,
  },
  canEditGovernanceRoles: {
    type: Boolean,
    default: true,
  },
  canEditGovernanceGroups: {
    type: Boolean,
    default: true,
  },
  canEditStatus: {
    type: Boolean,
    default: true,
  },
  canEditThemeEditor: {
    type: Boolean,
    default: true,
  },
  themeOptions: {
    type: Array,
    default: () => [
      { id: 'dark', label: 'dark' },
      { id: 'light', label: 'light' },
    ],
  },
  governanceRoleOptions: {
    type: Array,
    default: () => [],
  },
  governanceGroupOptions: {
    type: Array,
    default: () => [],
  },
});

const emit = defineEmits([
  'close',
  'update:userEmailDraft',
  'delete-pending-email',
  'create-pending-email',
  'open-avatar-editor',
  'avatar-file-select',
  'set-default-avatar',
  'submit-form',
  'save-avatar-changes',
]);

const relationStackOpen = ref(false);
const activeRelation = ref(null);
const governancePersistence = createGovernanceCrudPersistence();
const relationCreatedRowsByEntity = reactive({
  groups: [],
});
const canCreateGovernanceGroups = computed(() => {
  const context = moduleAccessContextFromSession(sessionState);
  return context.allPermissions === true || context.permissions.includes('governance.groups.create');
});
const roleRows = computed(() => [
  { id: 'user', key: 'user', name: t('users.role_user'), status: 'active' },
  { id: 'admin', key: 'admin', name: t('users.role_admin'), status: 'active' },
]);
const themeRows = computed(() => props.themeOptions.map((theme) => ({
  id: String(theme.id || ''),
  key: String(theme.id || ''),
  name: String(theme.label || theme.id || ''),
  status: 'active',
})).filter((theme) => theme.id !== ''));
const governanceRoleRows = computed(() => props.governanceRoleOptions.map((role) => ({
  id: String(role.id || ''),
  key: String(role.key || role.id || ''),
  name: String(role.name || role.key || role.id || ''),
  status: String(role.status || 'active'),
  relationships: role.relationships,
})).filter((role) => role.id !== ''));
const governanceGroupRows = computed(() => mergeRowsById([
  ...relationCreatedRowsByEntity.groups,
  ...props.governanceGroupOptions.map((group) => normalizeGovernanceOption(group)),
]));
const currentRoleLabel = computed(() => (
  selectedRow(roleRows.value, props.form.role)?.name || String(props.form.role || '')
));
const currentThemeLabel = computed(() => (
  selectedRow(themeRows.value, props.form.theme)?.name || String(props.form.theme || '')
));
const currentGovernanceRolesLabel = computed(() => {
  const selected = selectedRowsByValues(governanceRoleRows.value, props.form.governance_roles);
  return selected.length > 0 ? selected.map((row) => row.name).join(', ') : t('common.not_available');
});
const currentGovernanceGroupsLabel = computed(() => {
  const selected = selectedRowsByValues(governanceGroupRows.value, props.form.governance_groups);
  return selected.length > 0 ? selected.map((row) => row.name).join(', ') : t('common.not_available');
});
const relationSelections = computed(() => ({
  role: selectedRows(roleRows.value, props.form.role),
  governance_roles: selectedRowsByValues(governanceRoleRows.value, props.form.governance_roles),
  governance_groups: selectedRowsByValues(governanceGroupRows.value, props.form.governance_groups),
  theme: selectedRows(themeRows.value, props.form.theme),
}));
const relationStackShowsNestedRelations = computed(() => activeRelation.value?.key === 'governance_groups');
const activeDialogTitle = computed(() => (
  relationStackOpen.value && activeRelation.value
    ? t('governance.relation_picker.title', { relation: relationLabel(activeRelation.value) })
    : props.dialogTitle
));

const {
  editorMaximized,
  emailDraftModel,
  themeEditorChecked,
  themeEditorDisabled,
  themeEditorLabel,
} = useUserEditorModal({ props, emit, t });

function selectedRow(rows, value) {
  const normalized = String(value || '').trim();
  return rows.find((row) => row.id === normalized || row.key === normalized) || null;
}

function selectedRows(rows, value) {
  const row = selectedRow(rows, value);
  return row ? [row] : [];
}

function selectedRowsByValues(rows, values) {
  return (Array.isArray(values) ? values : [])
    .map((value) => {
      const id = String(value?.id || value?.key || value || '').trim();
      const row = rows.find((candidate) => candidate.id === id || candidate.key === id) || {};
      return id === '' ? null : { ...row, ...(typeof value === 'object' ? value : { id, key: id }) };
    })
    .filter(Boolean);
}

function relationRowsForEntity(entityKey) {
  if (entityKey === 'user_roles') return roleRows.value;
  if (entityKey === 'governance_roles') return governanceRoleRows.value;
  if (entityKey === 'groups') return governanceGroupRows.value;
  if (entityKey === 'roles') return governanceRoleRows.value;
  if (entityKey === 'modules' || entityKey === 'permissions') return governanceCatalogRows(entityKey);
  if (entityKey === 'user_themes') return themeRows.value;
  return [];
}

function governanceCatalogRows(entityKey) {
  return buildGovernanceCatalogRows(workspaceModuleRegistry, `admin-governance-${entityKey}`);
}

function normalizeGovernanceOption(row) {
  return {
    id: String(row?.id || '').trim(),
    key: String(row?.key || row?.id || '').trim(),
    name: String(row?.name || row?.key || row?.id || '').trim(),
    status: String(row?.status || 'active'),
    relationships: row?.relationships,
  };
}

function mergeRowsById(rows) {
  const seen = new Set();
  return rows.map((row) => normalizeGovernanceOption(row)).filter((row) => {
    if (row.id === '' || seen.has(row.id)) return false;
    seen.add(row.id);
    return true;
  });
}

function canCreateGovernanceRelationRow(entityKey) {
  return String(entityKey || '').trim() === 'groups' && props.canEditGovernanceGroups && canCreateGovernanceGroups.value;
}

function relationLabel(relation) {
  const key = String(relation?.label_key || '').trim();
  return key !== '' ? t(key) : String(relation?.key || '');
}

function relationStackRelationFilter(relation) {
  if (activeRelation.value?.key !== 'governance_groups') return true;
  return ['modules', 'permissions'].includes(String(relation?.target_entity || '').trim());
}

async function createGovernanceRelationRow(entityKey, payload = {}) {
  const key = String(entityKey || '').trim();
  if (!canCreateGovernanceRelationRow(key)) return null;
  const descriptor = GOVERNANCE_CRUD_DESCRIPTORS[key];
  if (!descriptor) return null;
  const savedRow = await governancePersistence.createRow(descriptor, payload);
  const row = normalizeGovernanceOption(savedRow);
  if (row.id === '') {
    return null;
  }
  relationCreatedRowsByEntity.groups = mergeRowsById([row, ...relationCreatedRowsByEntity.groups]);
  return row;
}

function openRelation(relation) {
  if (relation?.key === 'role' && !props.canEditRole) return;
  if (relation?.key === 'governance_roles' && !props.canEditGovernanceRoles) return;
  if (relation?.key === 'governance_groups' && !props.canEditGovernanceGroups) return;
  activeRelation.value = relation;
  relationStackOpen.value = true;
}

function closeRelationStack() {
  relationStackOpen.value = false;
  activeRelation.value = null;
}

function applyRelationSelection(payload) {
  const row = Array.isArray(payload?.selectedRows) ? payload.selectedRows[0] : null;
  const value = String(row?.key || row?.id || '').trim();
  if (value !== '') {
    if (payload?.relation?.key === 'role') props.form.role = value;
    if (payload?.relation?.key === 'theme') props.form.theme = value;
  }
  if (payload?.relation?.key === 'governance_roles') {
    props.form.governance_roles = (Array.isArray(payload?.selectedRows) ? payload.selectedRows : [])
      .map((selectedRow) => ({
        id: String(selectedRow?.id || '').trim(),
        key: String(selectedRow?.key || selectedRow?.id || '').trim(),
        name: String(selectedRow?.name || selectedRow?.key || selectedRow?.id || '').trim(),
        status: String(selectedRow?.status || 'active'),
        relationships: selectedRow?.relationships,
      }))
      .filter((selectedRow) => selectedRow.id !== '');
  }
  if (payload?.relation?.key === 'governance_groups') {
    props.form.governance_groups = (Array.isArray(payload?.selectedRows) ? payload.selectedRows : [])
      .map((selectedRow) => ({
        id: String(selectedRow?.id || '').trim(),
        key: String(selectedRow?.key || selectedRow?.id || '').trim(),
        name: String(selectedRow?.name || selectedRow?.key || selectedRow?.id || '').trim(),
        status: String(selectedRow?.status || 'active'),
        relationships: selectedRow?.relationships,
      }))
      .filter((selectedRow) => selectedRow.id !== '');
  }
  closeRelationStack();
}
</script>

<style scoped src="../admin/UsersView.css"></style>
