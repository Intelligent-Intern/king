import { computed } from 'vue';

function sidebarStateValue(source, key) {
  const candidate = source?.[key];
  if (candidate && typeof candidate === 'object' && 'value' in candidate) {
    return Boolean(candidate.value);
  }
  return Boolean(candidate);
}

export function createCallWorkspaceShellViewport({
  compactMiniStripPlacement,
  isCompactViewport,
  workspaceSidebarState,
}) {
  const isShellLeftSidebarCollapsed = computed(() => sidebarStateValue(workspaceSidebarState, 'leftSidebarCollapsed'));
  const isShellTabletViewport = computed(() => sidebarStateValue(workspaceSidebarState, 'isTabletViewport'));
  const isShellTabletSidebarOpen = computed(() => sidebarStateValue(workspaceSidebarState, 'isTabletSidebarOpen'));
  const isShellMobileViewport = computed(() => sidebarStateValue(workspaceSidebarState, 'isMobileViewport'));
  const isCompactLayoutViewport = computed(() => isShellMobileViewport.value || isShellTabletViewport.value);
  const isCompactHeaderVisible = computed(() => isCompactViewport.value && isCompactLayoutViewport.value);
  const isCompactMiniStripAbove = computed(() => (
    isCompactLayoutViewport.value
    && compactMiniStripPlacement.value === 'above'
  ));
  const showLeftSidebarRestoreButton = computed(() => {
    if (isCompactHeaderVisible.value || isShellMobileViewport.value) return false;
    if (isShellTabletViewport.value) return !isShellTabletSidebarOpen.value;
    return !isCompactViewport.value && isShellLeftSidebarCollapsed.value;
  });

  return {
    isCompactHeaderVisible,
    isCompactLayoutViewport,
    isCompactMiniStripAbove,
    isShellMobileViewport,
    isShellTabletViewport,
    showLeftSidebarRestoreButton,
  };
}
