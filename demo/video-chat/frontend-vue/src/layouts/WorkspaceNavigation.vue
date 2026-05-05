<template>
  <nav class="nav" aria-label="Main navigation">
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
            <span>{{ item.label }}</span>
          </RouterLink>
          <button
            class="nav-group-toggle"
            type="button"
            :aria-expanded="isExpanded(item)"
            :aria-label="isExpanded(item) ? `Collapse ${item.label}` : `Expand ${item.label}`"
            :title="isExpanded(item) ? `Collapse ${item.label}` : `Expand ${item.label}`"
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
        <div v-if="isExpanded(item)" class="nav-submenu" :aria-label="`${item.label} navigation`">
          <RouterLink
            v-for="child in item.children"
            :key="child.to"
            :to="child.to"
            class="nav-sublink"
            :class="{ active: isActive(child) }"
            @click="emitNavigate"
          >
            <span>{{ child.label }}</span>
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
        <span>{{ item.label }}</span>
      </RouterLink>
    </template>
  </nav>
</template>

<script setup>
import { computed, reactive, watch } from 'vue';
import { RouterLink } from 'vue-router';

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

const adminIcon = '/assets/orgas/kingrt/icons/adminon.png';
const gearIcon = '/assets/orgas/kingrt/icons/gear.png';
const navigationItems = [
  { to: '/admin/overview', label: 'Overview', icon: '/assets/orgas/kingrt/icons/users.png', roles: ['admin'] },
  {
    key: 'administration',
    to: '/admin/administration',
    label: 'Administration',
    icon: gearIcon,
    roles: ['admin'],
    children: [
      { to: '/admin/administration/marketplace', label: 'Marketplace', roles: ['admin'] },
      { to: '/admin/administration/localization', label: 'Localization', roles: ['admin'] },
      { to: '/admin/administration/app-configuration', label: 'App Configuration', roles: ['admin'] },
      { to: '/admin/administration/theme-editor', label: 'Theme Editor', roles: ['admin'] },
    ],
  },
  {
    key: 'governance',
    to: '/admin/governance',
    label: 'Governance',
    icon: adminIcon,
    roles: ['admin'],
    children: [
      { to: '/admin/governance/users', label: 'Nutzer', roles: ['admin'] },
      { to: '/admin/governance/groups', label: 'Gruppen', roles: ['admin'] },
      { to: '/admin/governance/organizations', label: 'Organisationen', roles: ['admin'] },
      { to: '/admin/governance/modules', label: 'Module', roles: ['admin'] },
      { to: '/admin/governance/permissions', label: 'Rechte', roles: ['admin'] },
      { to: '/admin/governance/roles', label: 'Rollen', roles: ['admin'] },
      { to: '/admin/governance/grants', label: 'Freigaben', roles: ['admin'] },
      { to: '/admin/governance/policies', label: 'Richtlinien', roles: ['admin'] },
      { to: '/admin/governance/audit-log', label: 'Audit Log', roles: ['admin'] },
      { to: '/admin/governance/data-portability', label: 'Export / Import', roles: ['admin'] },
      { to: '/admin/governance/compliance', label: 'Compliance', roles: ['admin'] },
    ],
  },
  { to: '/admin/calls', label: 'Video Calls', icon: '/assets/orgas/kingrt/icons/lobby.png', roles: ['admin'] },
  { to: '/user/dashboard', label: 'My Calls', icon: '/assets/orgas/kingrt/icons/lobby.png', roles: ['user'] },
];

const visibleItems = computed(() => (
  navigationItems
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
