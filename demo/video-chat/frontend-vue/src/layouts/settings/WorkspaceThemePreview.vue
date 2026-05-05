<template>
  <section class="theme-preview" :class="{ compact }" :style="previewStyle" :aria-label="t('theme_preview.aria')">
    <aside class="theme-preview-sidebar">
      <div class="theme-preview-brand">
        <img :src="sidebarLogoSrc" alt="" />
      </div>
      <nav class="theme-preview-nav" :aria-label="t('theme_preview.navigation_aria')">
        <button
          v-for="item in navigationItems"
          :key="item.id"
          type="button"
          :class="{ active: activeSection === item.id }"
          @click="activeSection = item.id"
        >
          <img :src="item.icon" alt="" />
          <span>{{ t(item.labelKey) }}</span>
        </button>
      </nav>
    </aside>

    <main class="theme-preview-main">
      <header class="theme-preview-header">
        <div>
          <h5>{{ t(activeNavigationItem.titleKey) }}</h5>
          <span>{{ t('theme_preview.live_preview') }}</span>
        </div>
        <button class="theme-preview-action" type="button">{{ t(activeNavigationItem.actionKey) }}</button>
      </header>

      <section class="theme-preview-toolbar">
        <div class="theme-preview-tabs">
          <button
            v-for="(tab, index) in activeNavigationItem.tabs"
            :key="tab"
            :class="{ active: index === 0 }"
            type="button"
          >
            {{ t(tab) }}
          </button>
        </div>
        <input type="text" readonly :value="t(activeNavigationItem.searchKey)" />
        <button class="theme-preview-icon" type="button">
          <img src="/assets/orgas/kingrt/icons/send.png" alt="" />
        </button>
      </section>

      <section class="theme-preview-table">
        <article v-for="row in activeRows" :key="row.id" class="theme-preview-row">
          <div>
            <strong>{{ t(row.titleKey) }}</strong>
            <span>{{ row.id }}</span>
          </div>
          <span class="theme-preview-status" :class="row.statusClass">{{ t(row.statusKey) }}</span>
          <span>{{ row.detail }}</span>
          <div class="theme-preview-row-actions">
            <button type="button"><img src="/assets/orgas/kingrt/icons/gear.png" alt="" /></button>
            <button type="button"><img src="/assets/orgas/kingrt/icons/add_to_call.png" alt="" /></button>
          </div>
        </article>
      </section>

      <footer class="theme-preview-footer">
        <button type="button"><img src="/assets/orgas/kingrt/icons/backward.png" alt="" /></button>
        <span>{{ t('pagination.page_short', { page: 1, pageCount: 2 }) }}</span>
        <button type="button"><img src="/assets/orgas/kingrt/icons/forward.png" alt="" /></button>
      </footer>
    </main>
  </section>
</template>

<script setup>
import { computed, ref } from 'vue';
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
});

const activeSection = ref('calls');

const navigationItems = Object.freeze([
  {
    id: 'calls',
    icon: '/assets/orgas/kingrt/icons/lobby.png',
    labelKey: 'navigation.calls.admin',
    titleKey: 'theme_preview.video_call_management',
    actionKey: 'theme_preview.new_video_call',
    searchKey: 'theme_preview.search_call_title',
    tabs: ['theme_preview.calls', 'theme_preview.calendar'],
  },
  {
    id: 'governance',
    icon: '/assets/orgas/kingrt/icons/adminon.png',
    labelKey: 'navigation.governance',
    titleKey: 'theme_preview.governance_management',
    actionKey: 'theme_preview.new_role',
    searchKey: 'theme_preview.search_governance',
    tabs: ['theme_preview.roles', 'theme_preview.groups'],
  },
  {
    id: 'users',
    icon: '/assets/orgas/kingrt/icons/user.png',
    labelKey: 'navigation.governance.users',
    titleKey: 'theme_preview.user_management',
    actionKey: 'theme_preview.new_user',
    searchKey: 'theme_preview.search_users',
    tabs: ['theme_preview.users', 'theme_preview.credentials'],
  },
]);

const rowsBySection = Object.freeze({
  calls: [
    {
      id: '9fbe5c05',
      titleKey: 'theme_preview.call.platform_standup',
      statusKey: 'users.status_active',
      statusClass: 'ok',
      detail: '09:30 - 10:00',
    },
    {
      id: '47632c72',
      titleKey: 'theme_preview.call.quarterly_review',
      statusKey: 'theme_preview.status_scheduled',
      statusClass: 'warn',
      detail: '14:00 - 14:45',
    },
    {
      id: '8d2c1cd',
      titleKey: 'theme_preview.call.customer_escalation',
      statusKey: 'theme_preview.status_ended',
      statusClass: '',
      detail: '17:00 - 17:35',
    },
  ],
  governance: [
    {
      id: 'role-admin',
      titleKey: 'theme_preview.row.platform_admin',
      statusKey: 'users.status_active',
      statusClass: 'ok',
      detail: '42 permissions',
    },
    {
      id: 'group-sales',
      titleKey: 'theme_preview.row.sales_group',
      statusKey: 'theme_preview.status_scheduled',
      statusClass: 'warn',
      detail: '8 members',
    },
    {
      id: 'module-calendar',
      titleKey: 'theme_preview.row.calendar_module',
      statusKey: 'theme_preview.status_ended',
      statusClass: '',
      detail: '3 routes',
    },
  ],
  users: [
    {
      id: 'u-0001',
      titleKey: 'theme_preview.row.jochen',
      statusKey: 'users.status_active',
      statusClass: 'ok',
      detail: 'admin',
    },
    {
      id: 'u-0042',
      titleKey: 'theme_preview.row.alexander',
      statusKey: 'users.status_active',
      statusClass: 'ok',
      detail: 'editor',
    },
    {
      id: 'u-0088',
      titleKey: 'theme_preview.row.pierre',
      statusKey: 'theme_preview.status_scheduled',
      statusClass: 'warn',
      detail: 'viewer',
    },
  ],
});

const activeNavigationItem = computed(() => (
  navigationItems.find((item) => item.id === activeSection.value) || navigationItems[0]
));
const activeRows = computed(() => rowsBySection[activeSection.value] || rowsBySection.calls);

const previewStyle = computed(() => {
  const style = {};
  const colors = props.colors && typeof props.colors === 'object' ? props.colors : {};
  for (const [key, value] of Object.entries(colors)) {
    if (key.startsWith('--')) {
      style[key] = value;
    }
  }
  return style;
});
</script>

<style scoped>
.theme-preview {
  min-height: 420px;
  display: grid;
  grid-template-columns: 170px minmax(0, 1fr);
  overflow: hidden;
  border: 1px solid var(--border-subtle);
  border-radius: 8px;
  background: var(--bg-shell);
}

.theme-preview-sidebar {
  min-height: 0;
  display: grid;
  grid-template-rows: auto minmax(0, 1fr);
  background: var(--bg-sidebar);
  border-inline-end: 1px solid var(--border-subtle);
}

.theme-preview-brand {
  height: 58px;
  display: grid;
  place-items: center;
  border-bottom: 1px solid var(--border-subtle);
  background: var(--brand-bg);
  padding: 8px;
}

.theme-preview-brand img {
  max-width: 100%;
  max-height: 38px;
  object-fit: contain;
}

.theme-preview-nav {
  display: grid;
  gap: 6px;
  align-content: start;
  padding: 10px;
}

.theme-preview-nav button {
  min-height: 34px;
  display: grid;
  grid-template-columns: 18px minmax(0, 1fr);
  align-items: center;
  gap: 8px;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: var(--text-secondary);
  cursor: pointer;
  text-decoration: none;
  padding: 0 8px;
}

.theme-preview-nav button.active,
.theme-preview-nav button:hover {
  background: var(--bg-tab-active);
  color: var(--text-primary);
}

.theme-preview-nav img,
.theme-preview-icon img,
.theme-preview-row-actions img,
.theme-preview-footer img {
  width: 16px;
  height: 16px;
  object-fit: contain;
}

.theme-preview-main {
  min-width: 0;
  min-height: 0;
  display: grid;
  grid-template-rows: auto auto minmax(0, 1fr) auto;
  background: var(--bg-main);
  color: var(--text-main);
  padding: 10px;
  gap: 10px;
}

.theme-preview-header,
.theme-preview-toolbar,
.theme-preview-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.theme-preview-header h5 {
  margin: 0;
  font-size: 17px;
  color: var(--text-primary);
}

.theme-preview-header span,
.theme-preview-row span {
  color: var(--text-muted);
  font-size: 11px;
}

.theme-preview-action,
.theme-preview-tabs button,
.theme-preview-icon,
.theme-preview-row-actions button,
.theme-preview-footer button {
  min-height: 30px;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: var(--bg-action);
  color: var(--text-primary);
}

.theme-preview-action {
  padding: 0 10px;
}

.theme-preview-toolbar {
  justify-content: flex-end;
  flex-wrap: wrap;
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

.theme-preview-toolbar input {
  min-width: 160px;
  flex: 0 1 320px;
  max-width: 320px;
  height: 32px;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: var(--bg-input);
  color: var(--text-dim);
  padding: 0 10px;
}

.theme-preview-icon,
.theme-preview-row-actions button,
.theme-preview-footer button {
  width: 32px;
  padding: 0;
}

.theme-preview-table {
  min-height: 0;
  display: grid;
  align-content: start;
  gap: 7px;
  overflow: hidden;
}

.theme-preview-row {
  min-height: 50px;
  display: grid;
  grid-template-columns: minmax(0, 1.35fr) auto auto auto;
  align-items: center;
  gap: 8px;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: var(--bg-row);
  padding: 6px 8px;
}

.theme-preview-row strong {
  display: block;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 12px;
  color: var(--text-primary);
}

.theme-preview-status {
  border-radius: 999px;
  border: 1px solid var(--border-subtle);
  padding: 3px 7px;
}

.theme-preview-status.ok {
  color: var(--ok);
}

.theme-preview-status.warn {
  color: var(--wait);
}

.theme-preview-row-actions {
  display: inline-flex;
  gap: 4px;
}

.theme-preview-footer {
  justify-content: center;
  color: var(--text-muted);
}

.theme-preview-footer span {
  font-size: 12px;
}

.theme-preview.compact {
  min-height: 270px;
  grid-template-columns: 124px minmax(0, 1fr);
}

.theme-preview.compact .theme-preview-brand {
  height: 44px;
}

.theme-preview.compact .theme-preview-nav {
  padding: 6px;
}

.theme-preview.compact .theme-preview-nav button {
  min-height: 28px;
  gap: 6px;
  padding: 0 6px;
}

.theme-preview.compact .theme-preview-main {
  padding: 8px;
  gap: 7px;
}

.theme-preview.compact .theme-preview-toolbar {
  gap: 8px;
}

.theme-preview.compact .theme-preview-header h5 {
  font-size: 13px;
}

.theme-preview.compact .theme-preview-header span,
.theme-preview.compact .theme-preview-action,
.theme-preview.compact .theme-preview-footer,
.theme-preview.compact .theme-preview-row:nth-child(n+3) {
  display: none;
}

.theme-preview.compact .theme-preview-tabs button,
.theme-preview.compact .theme-preview-icon,
.theme-preview.compact .theme-preview-row-actions button {
  min-height: 26px;
}

.theme-preview.compact .theme-preview-toolbar input {
  min-width: 0;
  flex: 0 1 150px;
  height: 28px;
}

.theme-preview.compact .theme-preview-row {
  min-height: 42px;
  grid-template-columns: minmax(0, 1fr);
}

.theme-preview.compact .theme-preview-status,
.theme-preview.compact .theme-preview-row > span:nth-of-type(2),
.theme-preview.compact .theme-preview-row-actions {
  display: none;
}

@media (max-width: 760px) {
  .theme-preview {
    grid-template-columns: 1fr;
  }

  .theme-preview-sidebar {
    display: none;
  }

  .theme-preview-row {
    grid-template-columns: 1fr;
    align-items: start;
  }
}
</style>
