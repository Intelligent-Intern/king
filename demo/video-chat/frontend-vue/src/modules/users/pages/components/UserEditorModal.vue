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
    close-label="Close user modal"
    maximizable
    :maximized="editorMaximized"
    @update:maximized="editorMaximized = $event"
    @close="$emit('close')"
  >
    <template #body>
      <template v-if="!avatarEditorOpen">
        <form id="userEditorForm" class="users-edit-form" autocomplete="off" @submit.prevent="$emit('submit-form')">
          <label v-if="form.mode === 'create'" class="users-field">
            <span>Email</span>
            <input v-model.trim="form.email" class="input" type="email" autocomplete="email" />
          </label>

          <section v-else class="users-field users-field-wide">
            <span>Emails</span>
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
                      {{ emailRow.is_verified ? 'confirmed' : 'unconfirmed' }}
                    </span>
                    <span v-if="emailRow.is_primary" class="tag ok">primary</span>
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
              <p v-if="userEmailRows.length === 0" class="users-email-empty">No emails configured.</p>
            </div>
            <div class="users-email-create">
              <input
                v-model.trim="emailDraftModel"
                class="input"
                type="email"
                autocomplete="email"
                placeholder="Add new email"
                :disabled="formSaving || userEmailSubmitting || userEmailLoading"
              />
              <button
                class="btn"
                type="button"
                :disabled="formSaving || userEmailSubmitting || userEmailLoading"
                @click="$emit('create-pending-email')"
              >
                {{ userEmailSubmitting ? 'Sending…' : 'Send confirmation' }}
              </button>
            </div>
          </section>

          <label class="users-field">
            <span>Display name</span>
            <input v-model.trim="form.display_name" class="input" type="text" />
          </label>

          <label v-if="form.mode === 'create'" class="users-field">
            <span>Password</span>
            <input v-model="form.password" class="input" type="password" autocomplete="new-password" />
          </label>

          <label v-if="form.mode === 'create'" class="users-field">
            <span>Repeat password</span>
            <input v-model="form.password_repeat" class="input" type="password" autocomplete="new-password" />
          </label>

          <label class="users-field">
            <span>Role</span>
            <AppSelect v-model="form.role" :disabled="!canEditRole">
              <option value="user">user</option>
              <option value="admin">admin</option>
            </AppSelect>
          </label>

          <label v-if="form.mode === 'edit'" class="users-field">
            <span>Status</span>
            <AppSelect v-model="form.status" :disabled="!canEditStatus">
              <option value="active">active</option>
              <option value="disabled">disabled</option>
            </AppSelect>
          </label>

          <label v-if="form.mode === 'edit'" class="users-field">
            <span>Time format</span>
            <AppSelect v-model="form.time_format">
              <option value="24h">24h</option>
              <option value="12h">12h</option>
            </AppSelect>
          </label>

          <label v-if="form.mode === 'edit'" class="users-field">
            <span>Theme</span>
            <AppSelect v-model="form.theme">
              <option v-for="theme in themeOptions" :key="theme.id" :value="theme.id">
                {{ theme.label }}
              </option>
            </AppSelect>
          </label>

          <section class="users-field">
            <span>Theme editor</span>
            <label class="users-checkbox-row">
              <input v-model="themeEditorChecked" type="checkbox" :disabled="themeEditorDisabled" />
              <span>{{ themeEditorLabel }}</span>
            </label>
          </section>

          <section v-if="form.mode === 'edit'" class="users-field users-field-wide users-avatar-edit-row">
            <div class="users-avatar-preview-wrap">
              <img class="users-avatar-preview" :src="avatarPreviewSrc" alt="User avatar preview" />
            </div>
            <div class="users-avatar-edit-actions">
              <button class="btn btn-cyan" type="button" :disabled="formSaving" @click="$emit('open-avatar-editor')">
                Change avatar
              </button>
            </div>
          </section>
        </form>
      </template>

      <template v-else>
        <div class="users-avatar-preview-wrap">
          <img class="users-avatar-preview users-avatar-preview-large" :src="avatarEditorPreviewSrc" alt="Avatar preview" />
        </div>
        <label class="users-avatar-file">
          <span>Upload avatar</span>
          <input class="input" type="file" accept="image/png,image/jpeg,image/webp" @change="$emit('avatar-file-select', $event)" />
        </label>
        <section class="users-avatar-defaults">
          <span>Set default</span>
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
        <p class="users-avatar-hint">Upload a file or pick one default avatar.</p>
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
        {{ formSaving ? 'Saving...' : (avatarEditorOpen ? 'Save avatar' : dialogSubmitLabel) }}
      </button>
    </template>
  </AppModalShell>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import AppIconButton from '../../../../components/AppIconButton.vue';
import AppModalShell from '../../../../components/AppModalShell.vue';
import AppSelect from '../../../../components/AppSelect.vue';

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

const editorMaximized = ref(false);

watch(() => props.open, (open) => {
  if (!open) {
    editorMaximized.value = false;
  }
});

const emailDraftModel = computed({
  get: () => props.userEmailDraft,
  set: (value) => emit('update:userEmailDraft', value),
});

const roleAutomaticallyEditsThemes = computed(() => String(props.form?.role || '').trim() === 'admin');
const themeEditorChecked = computed({
  get: () => roleAutomaticallyEditsThemes.value || props.form.theme_editor_enabled === true,
  set: (value) => {
    if (roleAutomaticallyEditsThemes.value) return;
    props.form.theme_editor_enabled = value === true;
  },
});
const themeEditorDisabled = computed(() => !props.canEditThemeEditor || roleAutomaticallyEditsThemes.value);
const themeEditorLabel = computed(() => (
  roleAutomaticallyEditsThemes.value
    ? 'Admins can create and edit themes automatically'
    : 'Allow this user to create and edit themes'
));
</script>

<style scoped src="../admin/UsersView.css"></style>
