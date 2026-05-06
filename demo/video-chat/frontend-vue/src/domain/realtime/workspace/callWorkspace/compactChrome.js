import { computed } from 'vue';

function refLikeBoolean(source, key) {
  const candidate = source?.[key];
  if (candidate && typeof candidate === 'object' && 'value' in candidate) {
    return Boolean(candidate.value);
  }
  return Boolean(candidate);
}

export function createCallWorkspaceCompactChrome({
  compactMiniStripPlacement,
  isCompactViewport,
  workspaceSidebarState,
}) {
  const isShellLeftSidebarCollapsed = computed(() => (
    refLikeBoolean(workspaceSidebarState, 'leftSidebarCollapsed')
  ));

  const isShellTabletViewport = computed(() => (
    refLikeBoolean(workspaceSidebarState, 'isTabletViewport')
  ));

  const isShellTabletSidebarOpen = computed(() => (
    refLikeBoolean(workspaceSidebarState, 'isTabletSidebarOpen')
  ));

  const isShellMobileViewport = computed(() => (
    refLikeBoolean(workspaceSidebarState, 'isMobileViewport')
  ));

  const isCompactLayoutViewport = computed(() => (
    isShellMobileViewport.value
    || isShellTabletViewport.value
  ));

  const isCompactHeaderVisible = computed(() => (
    Boolean(isCompactViewport?.value)
    && isCompactLayoutViewport.value
  ));

  const isCompactMiniStripAbove = computed(() => (
    isCompactLayoutViewport.value
    && compactMiniStripPlacement?.value === 'above'
  ));

  const showLeftSidebarRestoreButton = computed(() => {
    if (isCompactHeaderVisible.value || isShellMobileViewport.value) return false;
    if (isShellTabletViewport.value) return !isShellTabletSidebarOpen.value;
    return !Boolean(isCompactViewport?.value) && isShellLeftSidebarCollapsed.value;
  });

  return {
    isCompactHeaderVisible,
    isCompactMiniStripAbove,
    isCompactLayoutViewport,
    isShellLeftSidebarCollapsed,
    isShellMobileViewport,
    isShellTabletSidebarOpen,
    isShellTabletViewport,
    showLeftSidebarRestoreButton,
  };
}
