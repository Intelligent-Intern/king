<template>
  <nav class="nav" :aria-label="t('navigation.main')">
    <template v-for="item in visibleItems" :key="item.key || item.to">
      <section v-if="item.children" class="nav-group" :class="{ active: isActive(item), open: isExpanded(item) }">
        <div class="nav-parent-row">
          <RouterLink
            :to="item.to"
            class="nav-link nav-link-parent"
            :class="{ active: isActive(item) }"
            @click="emitNavigate"
          >
            <img :src="item.icon" alt="" />
            <span>{{ navigationLabel(item) }}</span>
          </RouterLink>
          <button
            class="nav-group-toggle"
            type="button"
            :aria-expanded="isExpanded(item)"
            :aria-label="groupToggleLabel(item)"
            :title="groupToggleLabel(item)"
            @click="toggleGroup(item)"
          >
            <img
              class="nav-group-toggle-icon"
              :class="{ expanded: isExpanded(item) }"
              src="/assets/orgas/kingrt/icons/forward.png"
              alt=""
            />
          </button>
        </div>
        <div v-if="isExpanded(item)" class="nav-submenu" :aria-label="t('navigation.group.navigation', { label: navigationLabel(item) })">
          <RouterLink
            v-for="child in item.children"
            :key="child.to"
            :to="child.to"
            class="nav-sublink"
            :class="{ active: isActive(child) }"
            @click="emitNavigate"
          >
            <span>{{ navigationLabel(child) }}</span>
          </RouterLink>
        </div>
      </section>
      <RouterLink
        v-else
        :to="item.to"
        class="nav-link"
        :class="{ active: isActive(item) }"
        @click="emitNavigate"
      >
        <img :src="item.icon" alt="" />
        <span>{{ navigationLabel(item) }}</span>
      </RouterLink>
    </template>
  </nav>
</template>

<script setup>
import { computed, reactive, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { sessionState } from '../domain/auth/session';
import { moduleAccessContextFromSession } from '../http/routeAccess.js';
import { t } from '../modules/localization/i18nRuntime.js';
import { useWorkspaceModuleStore } from '../stores/workspaceModuleStore.js';

const props = defineProps({
  currentPath: {
    type: String,
    default: '',
  },
  role: {
    type: String,
    default: '',
  },
});

const emit = defineEmits(['navigate']);
const expandedGroups = reactive({});
const moduleStore = useWorkspaceModuleStore();

const callNavigationItems = [
  { to: '/admin/calls', label: 'Video Calls', label_key: 'navigation.calls.admin', icon: '/assets/orgas/kingrt/icons/lobby.png', roles: ['admin'] },
  { to: '/user/dashboard', label: 'My Calls', label_key: 'navigation.calls.user', icon: '/assets/orgas/kingrt/icons/lobby.png', roles: ['user'] },
];

const visibleItems = computed(() => (
  [
    ...moduleStore.navigationFor(moduleAccessContextFromSession({ ...sessionState, role: props.role })),
    ...callNavigationItems,
  ]
    .filter((item) => props.role && item.roles.includes(props.role))
    .map((item) => (
      item.children
        ? { ...item, children: item.children.filter((child) => props.role && child.roles.includes(props.role)) }
        : item
    ))
));

watch(
  [() => props.currentPath, () => props.role],
  () => {
    for (const item of visibleItems.value) {
      if (Array.isArray(item.children) && item.children.length > 0 && isActive(item)) {
        expandedGroups[groupKey(item)] = true;
      }
    }
  },
  { immediate: true },
);

function groupKey(item) {
  return String(item.key || item.to || '');
}

function isExpanded(item) {
  return expandedGroups[groupKey(item)] === true;
}

function toggleGroup(item) {
  const key = groupKey(item);
  if (key === '') return;
  expandedGroups[key] = !isExpanded(item);
}

function navigationLabel(item) {
  const key = String(item?.label_key || '').trim();
  return key !== '' ? t(key) : String(item?.label || '');
}

function groupToggleLabel(item) {
  return isExpanded(item)
    ? t('navigation.group.collapse', { label: navigationLabel(item) })
    : t('navigation.group.expand', { label: navigationLabel(item) });
}

function isActive(item) {
  if (Array.isArray(item.children) && item.children.length > 0) {
    return props.currentPath === item.to || props.currentPath.startsWith(`${item.to}/`);
  }

  if (item.to.startsWith('/workspace/call')) {
    return props.currentPath.startsWith('/workspace/call');
  }

  return props.currentPath === item.to;
}

function emitNavigate() {
  emit('navigate');
}
</script>
