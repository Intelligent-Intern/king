<template>
  <section class="theme-preview" :style="previewStyle" :aria-label="t('theme_preview.aria')">
    <aside class="theme-preview-sidebar">
      <div class="theme-preview-brand">
        <img :src="sidebarLogoSrc" alt="" />
      </div>
      <nav class="theme-preview-nav" :aria-label="t('theme_preview.navigation_aria')">
        <a class="active" href="#" @click.prevent>
          <img src="/assets/orgas/kingrt/icons/lobby.png" alt="" />
          <span>{{ t('navigation.calls.admin') }}</span>
        </a>
        <a href="#" @click.prevent>
          <img src="/assets/orgas/kingrt/icons/adminon.png" alt="" />
          <span>{{ t('navigation.governance') }}</span>
        </a>
        <a href="#" @click.prevent>
          <img src="/assets/orgas/kingrt/icons/user.png" alt="" />
          <span>{{ t('navigation.governance.users') }}</span>
        </a>
      </nav>
    </aside>

    <main class="theme-preview-main">
      <header class="theme-preview-header">
        <div>
          <h5>{{ t('theme_preview.video_call_management') }}</h5>
          <span>{{ t('theme_preview.live_preview') }}</span>
        </div>
        <button class="theme-preview-action" type="button">{{ t('theme_preview.new_video_call') }}</button>
      </header>

      <section class="theme-preview-toolbar">
        <div class="theme-preview-tabs">
          <button class="active" type="button">{{ t('theme_preview.calls') }}</button>
          <button type="button">{{ t('theme_preview.calendar') }}</button>
        </div>
        <input type="text" readonly :value="t('theme_preview.search_call_title')" />
        <button class="theme-preview-icon" type="button">
          <img src="/assets/orgas/kingrt/icons/send.png" alt="" />
        </button>
      </section>

      <section class="theme-preview-table">
        <article v-for="call in calls" :key="call.id" class="theme-preview-row">
          <div>
            <strong>{{ t(call.titleKey) }}</strong>
            <span>{{ call.id }}</span>
          </div>
          <span class="theme-preview-status" :class="call.statusClass">{{ t(call.statusKey) }}</span>
          <span>{{ call.window }}</span>
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
import { computed } from 'vue';
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
});

const calls = Object.freeze([
  {
    id: '9fbe5c05',
    titleKey: 'theme_preview.call.platform_standup',
    statusKey: 'users.status_active',
    statusClass: 'ok',
    window: '09:30 - 10:00',
  },
  {
    id: '47632c72',
    titleKey: 'theme_preview.call.quarterly_review',
    statusKey: 'theme_preview.status_scheduled',
    statusClass: 'warn',
    window: '14:00 - 14:45',
  },
  {
    id: '8d2c1cd',
    titleKey: 'theme_preview.call.customer_escalation',
    statusKey: 'theme_preview.status_ended',
    statusClass: '',
    window: '17:00 - 17:35',
  },
]);

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

.theme-preview-nav a {
  min-height: 34px;
  display: grid;
  grid-template-columns: 18px minmax(0, 1fr);
  align-items: center;
  gap: 8px;
  border-radius: 6px;
  color: var(--text-secondary);
  text-decoration: none;
  padding: 0 8px;
}

.theme-preview-nav a.active {
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
  flex-wrap: wrap;
}

.theme-preview-tabs {
  display: inline-flex;
  gap: 4px;
}

.theme-preview-tabs button {
  padding: 0 9px;
}

.theme-preview-tabs button.active {
  background: var(--bg-tab-active);
}

.theme-preview-toolbar input {
  min-width: 160px;
  flex: 1 1 180px;
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
