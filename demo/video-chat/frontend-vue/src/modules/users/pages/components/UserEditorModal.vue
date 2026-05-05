<template>
  <AppModalShell
    :open="open"
    :title="dialogTitle"
    :aria-label="dialogTitle"
    root-class-name="users-modal"
    backdrop-class="users-modal-backdrop"
    dialog-class="users-modal-dialog"
    header-class="users-modal-head users-modal-head-brand"
    header-left-class="users-modal-head-left"
    logo-class="users-modal-head-logo"
    title-class=""
    :body-class="avatarEditorOpen ? 'users-avatar-modal-body' : 'users-modal-body'"
    footer-class="users-modal-footer"
    :close-label="t('users.close_user_modal')"
    maximizable
    :maximized="editorMaximized"
    @update:maximized="editorMaximized = $event"
    @close="$emit('close')"
  >
    <template #body>
      <template v-if="!avatarEditorOpen">
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

    <template #footer>
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

  <CrudRelationStack
    :open="relationStackOpen"
    :relation="activeRelation"
    :selections="relationSelections"
    :row-provider="relationRowsForEntity"
    :maximized="relationStackMaximized"
    :show-nested-relations="false"
    @update:maximized="relationStackMaximized = $event"
    @close="closeRelationStack"
    @apply="applyRelationSelection"
  />
</template>

<script setup>
import { computed, ref } from 'vue';
import AppIconButton from '../../../../components/AppIconButton.vue';
import AppModalShell from '../../../../components/AppModalShell.vue';
import AppSelect from '../../../../components/AppSelect.vue';
import CrudRelationStack from '../../../governance/components/CrudRelationStack.vue';
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
const relationStackMaximized = ref(false);
const activeRelation = ref(null);
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
})).filter((role) => role.id !== ''));
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
const relationSelections = computed(() => ({
  role: selectedRows(roleRows.value, props.form.role),
  governance_roles: selectedRowsByValues(governanceRoleRows.value, props.form.governance_roles),
  theme: selectedRows(themeRows.value, props.form.theme),
}));

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
  const normalizedValues = new Set((Array.isArray(values) ? values : [])
    .map((value) => String(value?.id || value?.key || value || '').trim())
    .filter(Boolean));
  return rows.filter((row) => normalizedValues.has(row.id) || normalizedValues.has(row.key));
}

function relationRowsForEntity(entityKey) {
  if (entityKey === 'user_roles') return roleRows.value;
  if (entityKey === 'governance_roles') return governanceRoleRows.value;
  if (entityKey === 'user_themes') return themeRows.value;
  return [];
}

function openRelation(relation) {
  if (relation?.key === 'role' && !props.canEditRole) return;
  if (relation?.key === 'governance_roles' && !props.canEditGovernanceRoles) return;
  activeRelation.value = relation;
  relationStackMaximized.value = false;
  relationStackOpen.value = true;
}

function closeRelationStack() {
  relationStackOpen.value = false;
  relationStackMaximized.value = false;
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
      }))
      .filter((selectedRow) => selectedRow.id !== '');
  }
  closeRelationStack();
}
</script>

<style scoped src="../admin/UsersView.css"></style>
