<template>
  <section
    class="theme-preview-app"
    :class="{ compact, 'is-interactive': interactive, 'has-side-panel': sidePanel.open }"
    :style="previewStyle"
    :aria-label="t('theme_preview.aria')"
  >
    <aside class="theme-preview-left">
      <div class="theme-preview-brand">
        <img :src="sidebarLogoSrc" alt="" />
      </div>
      <nav class="theme-preview-nav" :aria-label="t('theme_preview.navigation_aria')">
        <button
          v-for="item in navigationItems"
          :key="item.id"
          type="button"
          :class="{ active: activeSection === item.id }"
          :disabled="item.disabled"
          @click="selectSection(item)"
        >
          <img :src="item.icon" alt="" />
          <span>{{ t(item.labelKey) }}</span>
        </button>
      </nav>
      <footer class="theme-preview-profile">
        <div class="theme-preview-avatar">{{ t('theme_preview.owner_initials') }}</div>
        <div>
          <strong>{{ t('theme_preview.owner_name') }}</strong>
          <span>{{ t('theme_preview.owner_role') }}</span>
        </div>
      </footer>
    </aside>
    <main class="theme-preview-main">
      <header class="theme-preview-header">
        <div>
          <h1>{{ t(activeNavigationItem.titleKey) }}</h1>
          <span>{{ t(activeNavigationItem.subtitleKey) }}</span>
        </div>
        <div class="theme-preview-header-actions">
          <button class="theme-preview-icon-btn" type="button" @click="openHelp">
            <span>?</span>
          </button>
          <button class="theme-preview-action" type="button" @click="openCreatePanel">
            {{ t(activeNavigationItem.actionKey) }}
          </button>
        </div>
      </header>
      <section class="theme-preview-toolbar">
        <div class="theme-preview-tabs">
          <button
            v-for="tab in activeNavigationItem.tabs"
            :key="tab.id"
            type="button"
            :class="{ active: activeTab === tab.id }"
            @click="selectTab(tab.id)"
          >
            {{ t(tab.labelKey) }}
          </button>
        </div>
        <input type="text" readonly :value="t(activeNavigationItem.searchKey)" />
        <button class="theme-preview-icon-btn" type="button">
          <img src="/assets/orgas/kingrt/icons/send.png" alt="" />
        </button>
      </section>
      <section v-if="activeSection === 'calls' && activeTab === 'workspace'" class="theme-preview-call">
        <div class="theme-preview-stage">
          <article class="theme-preview-main-video">
            <span>{{ t('theme_preview.call.main_video') }}</span>
            <strong>{{ t('theme_preview.row.alexander') }}</strong>
          </article>
          <aside class="theme-preview-mini-strip">
            <button
              v-for="participant in participants"
              :key="participant.id"
              type="button"
              @dblclick="openModal('theme_preview.modal_video_title')"
            >
              <span>{{ participant.initials }}</span>
              <strong>{{ t(participant.nameKey) }}</strong>
            </button>
          </aside>
        </div>
        <aside class="theme-preview-call-sidebar">
          <strong>{{ t('theme_preview.call.controls') }}</strong>
          <button type="button">{{ t('calls.enter.blur') }}</button>
          <button type="button">{{ t('calls.enter.strong_blur') }}</button>
          <button type="button" @click="openModal('theme_preview.modal_invite_title')">
            {{ t('theme_preview.call.invite') }}
          </button>
        </aside>
      </section>
      <section v-else-if="activeSection === 'administration'" class="theme-preview-cards">
        <article v-for="card in administrationCards" :key="card.id" class="theme-preview-card">
          <img :src="card.icon" alt="" />
          <strong>{{ t(card.titleKey) }}</strong>
          <span>{{ t(card.textKey) }}</span>
          <button type="button" @click="openCreatePanel">{{ t('theme_preview.configure') }}</button>
        </article>
      </section>
      <section v-else class="theme-preview-table">
        <article v-for="row in activeRows" :key="row.id" class="theme-preview-row">
          <div>
            <strong>{{ t(row.titleKey) }}</strong>
            <span>{{ row.id }}</span>
          </div>
          <span class="theme-preview-status" :class="row.statusClass">{{ t(row.statusKey) }}</span>
          <span>{{ row.detail }}</span>
          <div class="theme-preview-row-actions">
            <button type="button" @click="openCreatePanel"><img src="/assets/orgas/kingrt/icons/gear.png" alt="" /></button>
            <button type="button" @click="openModal('theme_preview.modal_relation_title')">
              <img src="/assets/orgas/kingrt/icons/add_to_call.png" alt="" />
            </button>
          </div>
        </article>
      </section>
      <footer class="theme-preview-footer">
        <button type="button"><img src="/assets/orgas/kingrt/icons/backward.png" alt="" /></button>
        <span>{{ t('pagination.page_short', { page: 1, pageCount: 3 }) }}</span>
        <button type="button"><img src="/assets/orgas/kingrt/icons/forward.png" alt="" /></button>
      </footer>
    </main>
    <aside v-if="sidePanel.open" class="theme-preview-side-panel">
      <header>
        <strong>{{ t(sidePanel.titleKey) }}</strong>
        <button type="button" @click="closeSidePanel">x</button>
      </header>
      <label>
        <span>{{ t('theme_preview.form.name') }}</span>
        <input type="text" :value="t(activeNavigationItem.formNameKey)" readonly />
      </label>
      <label>
        <span>{{ t('theme_preview.form.status') }}</span>
        <select disabled>
          <option>{{ t('users.status_active') }}</option>
        </select>
      </label>
      <label>
        <span>{{ t('theme_preview.form.description') }}</span>
        <textarea :value="t(activeNavigationItem.formDescriptionKey)" readonly></textarea>
      </label>
      <footer>
        <button type="button" @click="closeSidePanel">{{ t('theme_preview.select') }}</button>
      </footer>
    </aside>
    <section v-if="modal.open" class="theme-preview-modal-backdrop" @click.self="closeModal">
      <article class="theme-preview-modal">
        <header>
          <strong>{{ t(modal.titleKey) }}</strong>
          <button type="button" @click="closeModal">x</button>
        </header>
        <p>{{ t('theme_preview.modal_body') }}</p>
        <footer>
          <button type="button" @click="closeModal">{{ t('theme_preview.close') }}</button>
          <button type="button" @click="closeModal">{{ t('theme_preview.confirm') }}</button>
        </footer>
      </article>
    </section>
  </section>
</template>
<script setup>
import { computed, reactive, ref } from 'vue';
import { t } from '../../modules/localization/i18nRuntime.js';
const props = defineProps({
  colors: {
    type: Object,
    default: () => ({}),
  },
  sidebarLogoSrc: {
    type: String,
    required: true,
  },
  compact: {
    type: Boolean,
    default: false,
  },
  interactive: {
    type: Boolean,
    default: false,
  },
});
const activeSection = ref('calls');
const activeTab = ref('list');
const sidePanel = reactive({ open: false, titleKey: 'theme_preview.panel_create_title' });
const modal = reactive({ open: false, titleKey: 'theme_preview.modal_help_title' });
const navigationItems = Object.freeze([
  {
    id: 'calls',
    icon: '/assets/orgas/kingrt/icons/lobby.png',
    labelKey: 'navigation.calls.admin',
    titleKey: 'theme_preview.video_call_management',
    subtitleKey: 'theme_preview.calls_subtitle',
    actionKey: 'theme_preview.new_video_call',
    searchKey: 'theme_preview.search_call_title',
    formNameKey: 'theme_preview.call.platform_standup',
    formDescriptionKey: 'theme_preview.form.call_description',
    tabs: [
      { id: 'list', labelKey: 'theme_preview.calls' },
      { id: 'calendar', labelKey: 'theme_preview.calendar' },
      { id: 'workspace', labelKey: 'theme_preview.call_workspace' },
    ],
  },
  {
    id: 'governance',
    icon: '/assets/orgas/kingrt/icons/adminon.png',
    labelKey: 'navigation.governance',
    titleKey: 'theme_preview.governance_management',
    subtitleKey: 'theme_preview.governance_subtitle',
    actionKey: 'theme_preview.new_role',
    searchKey: 'theme_preview.search_governance',
    formNameKey: 'theme_preview.row.platform_admin',
    formDescriptionKey: 'theme_preview.form.governance_description',
    tabs: [
      { id: 'roles', labelKey: 'theme_preview.roles' },
      { id: 'groups', labelKey: 'theme_preview.groups' },
      { id: 'modules', labelKey: 'theme_preview.modules' },
    ],
  },
  {
    id: 'users',
    icon: '/assets/orgas/kingrt/icons/user.png',
    labelKey: 'navigation.governance.users',
    titleKey: 'theme_preview.user_management',
    subtitleKey: 'theme_preview.users_subtitle',
    actionKey: 'theme_preview.new_user',
    searchKey: 'theme_preview.search_users',
    formNameKey: 'theme_preview.row.jochen',
    formDescriptionKey: 'theme_preview.form.user_description',
    tabs: [
      { id: 'users', labelKey: 'theme_preview.users' },
      { id: 'credentials', labelKey: 'theme_preview.credentials' },
    ],
  },
  {
    id: 'administration',
    icon: '/assets/orgas/kingrt/icons/gear.png',
    labelKey: 'navigation.administration',
    titleKey: 'theme_preview.administration_title',
    subtitleKey: 'theme_preview.administration_subtitle',
    actionKey: 'theme_preview.configure',
    searchKey: 'theme_preview.search_administration',
    formNameKey: 'theme_preview.card.email',
    formDescriptionKey: 'theme_preview.form.administration_description',
    tabs: [
      { id: 'email', labelKey: 'theme_preview.email' },
      { id: 'backgrounds', labelKey: 'theme_preview.backgrounds' },
    ],
  },
  {
    id: 'theme-editor-disabled',
    icon: '/assets/orgas/kingrt/icons/gear.png',
    labelKey: 'navigation.administration.theme_editor',
    disabled: true,
    titleKey: 'theme_preview.video_call_management',
    subtitleKey: 'theme_preview.calls_subtitle',
    actionKey: 'theme_preview.new_video_call',
    searchKey: 'theme_preview.search_call_title',
    formNameKey: 'theme_preview.call.platform_standup',
    formDescriptionKey: 'theme_preview.form.call_description',
    tabs: [],
  },
]);
const rowsBySection = Object.freeze({
  calls: [
    row('9fbe5c05', 'theme_preview.call.platform_standup', 'users.status_active', 'ok', '09:30 - 10:00'),
    row('47632c72', 'theme_preview.call.quarterly_review', 'theme_preview.status_scheduled', 'warn', '14:00 - 14:45'),
    row('8d2c1cd', 'theme_preview.call.customer_escalation', 'theme_preview.status_ended', '', '17:00 - 17:35'),
  ],
  governance: [
    row('role-admin', 'theme_preview.row.platform_admin', 'users.status_active', 'ok', '42 permissions'),
    row('group-sales', 'theme_preview.row.sales_group', 'theme_preview.status_scheduled', 'warn', '8 members'),
    row('module-calendar', 'theme_preview.row.calendar_module', 'theme_preview.status_ended', '', '3 routes'),
  ],
  users: [
    row('u-0001', 'theme_preview.row.jochen', 'users.status_active', 'ok', 'admin'),
    row('u-0042', 'theme_preview.row.alexander', 'users.status_active', 'ok', 'editor'),
    row('u-0088', 'theme_preview.row.pierre', 'theme_preview.status_scheduled', 'warn', 'viewer'),
  ],
});
const participants = Object.freeze([
  { id: 'alexander', initials: 'AK', nameKey: 'theme_preview.row.alexander' },
  { id: 'pierre', initials: 'PM', nameKey: 'theme_preview.row.pierre' },
  { id: 'jochen', initials: 'JK', nameKey: 'theme_preview.row.jochen' },
]);
const administrationCards = Object.freeze([
  card('email', '/assets/orgas/kingrt/icons/send.png', 'theme_preview.card.email', 'theme_preview.card.email_text'),
  card('texts', '/assets/orgas/kingrt/icons/chat.png', 'theme_preview.card.email_texts', 'theme_preview.card.email_texts_text'),
  card('backgrounds', '/assets/orgas/kingrt/icons/lobby.png', 'theme_preview.card.backgrounds', 'theme_preview.card.backgrounds_text'),
]);
const activeNavigationItem = computed(() => (
  navigationItems.find((item) => item.id === activeSection.value) || navigationItems[0]
));
const activeRows = computed(() => rowsBySection[activeSection.value] || rowsBySection.calls);
const previewStyle = computed(() => {
  const style = {};
  const colors = props.colors && typeof props.colors === 'object' ? props.colors : {};
  for (const [key, value] of Object.entries(colors)) {
    if (key.startsWith('--') && typeof value === 'string') style[key] = value;
  }
  return style;
});
function row(id, titleKey, statusKey, statusClass, detail) {
  return { id, titleKey, statusKey, statusClass, detail };
}
function card(id, icon, titleKey, textKey) {
  return { id, icon, titleKey, textKey };
}
function selectSection(item) {
  if (!props.interactive || item.disabled) return;
  activeSection.value = item.id;
  activeTab.value = item.tabs[0]?.id || 'list';
  closeSidePanel();
  closeModal();
}
function selectTab(id) {
  if (!props.interactive) return;
  activeTab.value = id;
}
function openCreatePanel() {
  if (!props.interactive) return;
  sidePanel.open = true;
  sidePanel.titleKey = activeNavigationItem.value.actionKey;
  closeModal();
}
function closeSidePanel() {
  sidePanel.open = false;
}
function openHelp() {
  openModal('theme_preview.modal_help_title');
}
function openModal(titleKey) {
  if (!props.interactive) return;
  modal.open = true;
  modal.titleKey = titleKey;
  closeSidePanel();
}
function closeModal() {
  modal.open = false;
}
</script>
<style scoped>
.theme-preview-app {
  width: 100%;
  height: 100%;
  min-height: 420px;
  display: grid;
  grid-template-columns: 184px minmax(0, 1fr);
  overflow: hidden;
  position: relative;
  background: var(--bg-shell);
  color: var(--text-main);
  container-type: inline-size;
}
.theme-preview-left {
  min-height: 0;
  display: grid;
  grid-template-rows: auto minmax(0, 1fr) auto;
  background: var(--brand-bg);
  border-inline-end: 1px solid var(--border-subtle);
}
.theme-preview-brand {
  height: 62px;
  display: grid;
  place-items: center;
  border-bottom: 1px solid var(--border-subtle);
  padding: 9px;
}
.theme-preview-brand img {
  max-width: 100%;
  max-height: 40px;
  object-fit: contain;
}
.theme-preview-nav {
  display: grid;
  align-content: start;
  gap: 6px;
  overflow: auto;
  padding: 10px;
}
.theme-preview-nav button,
.theme-preview-profile {
  min-width: 0;
  display: grid;
  grid-template-columns: 18px minmax(0, 1fr);
  align-items: center;
  gap: 8px;
}
.theme-preview-nav button {
  min-height: 34px;
  border: 0;
  border-radius: 0;
  background: transparent;
  color: var(--text-secondary);
  cursor: pointer;
  padding: 0 8px;
}
.theme-preview-nav button.active,
.theme-preview-nav button:hover:not(:disabled) {
  background: var(--bg-tab-active);
  color: var(--text-primary);
}
.theme-preview-nav button:disabled {
  cursor: not-allowed;
  opacity: 0.45;
}
.theme-preview-nav img,
.theme-preview-icon-btn img,
.theme-preview-row-actions img,
.theme-preview-footer img,
.theme-preview-card img {
  width: 16px;
  height: 16px;
  object-fit: contain;
}
.theme-preview-profile {
  grid-template-columns: 28px minmax(0, 1fr);
  border-top: 1px solid var(--border-subtle);
  padding: 10px;
}
.theme-preview-profile > div:last-child {
  min-width: 0;
  display: grid;
  gap: 1px;
}
.theme-preview-avatar {
  width: 28px;
  height: 28px;
  display: grid;
  place-items: center;
  background: var(--bg-icon-active);
  color: var(--text-primary);
  font-size: 11px;
  font-weight: 800;
}
.theme-preview-profile strong,
.theme-preview-profile span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.theme-preview-profile span {
  color: var(--text-muted);
  font-size: 11px;
}
.theme-preview-main {
  min-width: 0;
  min-height: 0;
  display: grid;
  grid-template-rows: auto auto minmax(0, 1fr) auto;
  gap: 10px;
  background: var(--bg-main);
  padding: 12px;
}
.theme-preview-header,
.theme-preview-toolbar,
.theme-preview-footer,
.theme-preview-header-actions,
.theme-preview-row-actions,
.theme-preview-modal header,
.theme-preview-modal footer,
.theme-preview-side-panel header,
.theme-preview-side-panel footer {
  display: flex;
  align-items: center;
  gap: 8px;
}
.theme-preview-header,
.theme-preview-toolbar,
.theme-preview-side-panel header {
  justify-content: space-between;
}
.theme-preview-header h1 {
  margin: 0;
  color: var(--text-primary);
  font-size: 16px;
}
.theme-preview-header span,
.theme-preview-row span,
.theme-preview-card span {
  color: var(--text-muted);
  font-size: 11px;
}
.theme-preview-header-actions {
  justify-content: flex-end;
}
.theme-preview-action,
.theme-preview-icon-btn,
.theme-preview-tabs button,
.theme-preview-row-actions button,
.theme-preview-footer button,
.theme-preview-card button,
.theme-preview-call-sidebar button,
.theme-preview-modal button,
.theme-preview-side-panel button {
  min-height: 30px;
  border: 1px solid var(--border-subtle);
  border-radius: 0;
  background: var(--bg-action);
  color: var(--text-primary);
}
.theme-preview-action {
  padding: 0 12px;
}
.theme-preview-icon-btn,
.theme-preview-row-actions button,
.theme-preview-footer button {
  width: 32px;
  padding: 0;
}
.theme-preview-toolbar {
  justify-content: flex-end;
  gap: 20px;
}
.theme-preview-tabs {
  display: inline-flex;
  gap: 4px;
  margin-inline-end: auto;
}
.theme-preview-tabs button {
  padding: 0 9px;
}
.theme-preview-tabs button.active {
  background: var(--bg-tab-active);
}
.theme-preview-toolbar input,
.theme-preview-side-panel input,
.theme-preview-side-panel select,
.theme-preview-side-panel textarea {
  min-width: 0;
  border: 1px solid var(--border-subtle);
  background: var(--bg-input);
  color: var(--text-primary);
  padding: 0 10px;
}
.theme-preview-toolbar input {
  flex: 0 1 320px;
  height: 32px;
}
.theme-preview-table,
.theme-preview-cards,
.theme-preview-call {
  min-height: 0;
  overflow: auto;
}
.theme-preview-table {
  display: grid;
  align-content: start;
  gap: 8px;
}
.theme-preview-row {
  min-height: 52px;
  display: grid;
  grid-template-columns: minmax(0, 1.35fr) auto auto auto;
  align-items: center;
  gap: 10px;
  border: 1px solid var(--border-subtle);
  background: var(--bg-row);
  padding: 7px 9px;
}
.theme-preview-row strong {
  display: block;
  min-width: 0;
  overflow: hidden;
  color: var(--text-primary);
  font-size: 12px;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.theme-preview-status {
  border: 1px solid var(--border-subtle);
  padding: 3px 7px;
}
.theme-preview-status.ok {
  color: var(--ok);
}
.theme-preview-status.warn {
  color: var(--wait);
}
.theme-preview-cards {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
}
.theme-preview-card {
  min-width: 0;
  display: grid;
  align-content: start;
  gap: 8px;
  border: 1px solid var(--border-subtle);
  background: var(--bg-surface);
  padding: 12px;
}
.theme-preview-card img {
  width: 24px;
  height: 24px;
}
.theme-preview-call {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 170px;
  gap: 10px;
}
.theme-preview-stage,
.theme-preview-main-video,
.theme-preview-mini-strip button,
.theme-preview-call-sidebar {
  border: 1px solid var(--border-subtle);
  background: var(--bg-surface);
}
.theme-preview-stage {
  min-height: 0;
  display: grid;
  grid-template-rows: minmax(0, 1fr) auto;
  gap: 8px;
  padding: 8px;
}
.theme-preview-main-video {
  min-height: 160px;
  display: grid;
  place-items: center;
  align-content: center;
  gap: 5px;
}
.theme-preview-mini-strip {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 6px;
}
.theme-preview-mini-strip button {
  min-width: 0;
  min-height: 58px;
  color: var(--text-primary);
}
.theme-preview-mini-strip span {
  display: block;
  color: var(--brand-cyan-hover);
  font-weight: 800;
}
.theme-preview-call-sidebar {
  display: grid;
  align-content: start;
  gap: 8px;
  padding: 10px;
}
.theme-preview-footer {
  justify-content: center;
  color: var(--text-muted);
}
.theme-preview-side-panel {
  min-width: 0;
  min-height: 0;
  display: grid;
  grid-template-rows: auto min-content min-content minmax(0, 1fr) auto;
  gap: 12px;
  border-inline-start: 1px solid var(--border-subtle);
  background: var(--bg-surface);
  padding: 12px;
}
.theme-preview-side-panel label {
  display: grid;
  gap: 6px;
  color: var(--text-muted);
  font-size: 11px;
}
.theme-preview-side-panel input,
.theme-preview-side-panel select {
  height: 32px;
}
.theme-preview-side-panel textarea {
  min-height: 96px;
  resize: none;
  padding-top: 8px;
}
.theme-preview-side-panel footer {
  justify-content: flex-end;
}
.theme-preview-modal-backdrop {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  background: var(--bg-shell);
  padding: 18px;
}
.theme-preview-modal {
  width: min(380px, 100%);
  display: grid;
  gap: 12px;
  border: 1px solid var(--border-subtle);
  background: var(--bg-surface);
  padding: 14px;
}
.theme-preview-modal header,
.theme-preview-modal footer {
  justify-content: space-between;
}
.theme-preview-modal p {
  margin: 0;
  color: var(--text-muted);
  font-size: 12px;
}
.theme-preview-app.has-side-panel {
  grid-template-columns: 184px minmax(0, 1fr) minmax(240px, 26%);
}
.theme-preview-app:not(.is-interactive) button,
.theme-preview-app:not(.is-interactive) input,
.theme-preview-app:not(.is-interactive) select,
.theme-preview-app:not(.is-interactive) textarea {
  pointer-events: none;
}
.theme-preview-app.compact {
  min-height: 270px;
  grid-template-columns: 122px minmax(0, 1fr);
  font-size: 11px;
}
.theme-preview-app.compact .theme-preview-brand {
  height: 44px;
  padding: 6px;
}
.theme-preview-app.compact .theme-preview-nav,
.theme-preview-app.compact .theme-preview-main {
  padding: 7px;
}
.theme-preview-app.compact .theme-preview-profile,
.theme-preview-app.compact .theme-preview-header span,
.theme-preview-app.compact .theme-preview-action,
.theme-preview-app.compact .theme-preview-footer,
.theme-preview-app.compact .theme-preview-row:nth-child(n+3),
.theme-preview-app.compact .theme-preview-status,
.theme-preview-app.compact .theme-preview-row > span:nth-of-type(2),
.theme-preview-app.compact .theme-preview-row-actions {
  display: none;
}
.theme-preview-app.compact .theme-preview-header h1 {
  font-size: 13px;
}
.theme-preview-app.compact .theme-preview-nav button {
  min-height: 28px;
  padding: 0 6px;
}
.theme-preview-app.compact .theme-preview-toolbar {
  gap: 8px;
}
.theme-preview-app.compact .theme-preview-toolbar input {
  flex-basis: 140px;
  height: 28px;
}
.theme-preview-app.compact .theme-preview-row {
  min-height: 42px;
  grid-template-columns: minmax(0, 1fr);
}
@container (max-width: 620px) {
  .theme-preview-app,
  .theme-preview-app.has-side-panel,
  .theme-preview-app.compact {
    grid-template-columns: 1fr;
  }
  .theme-preview-left {
    display: none;
  }
  .theme-preview-row,
  .theme-preview-call,
  .theme-preview-cards {
    grid-template-columns: 1fr;
  }
}
</style>
