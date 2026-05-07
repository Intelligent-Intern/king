import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const LAPTOP_BREAKPOINT = 1440;
const TABLET_BREAKPOINT = 1180;
const MOBILE_BREAKPOINT = 760;

export function useWorkspaceShellViewport({ isCallWorkspace }) {
  const leftSidebarCollapsed = ref(false);
  const isTabletSidebarOpen = ref(false);
  const isMobileSidebarOpen = ref(false);
  const viewportMode = ref('desktop');
  let laptopMedia = null;
  let tabletMedia = null;
  let mobileMedia = null;

  const isTabletViewport = computed(() => viewportMode.value === 'tablet');
  const isMobileViewport = computed(() => viewportMode.value === 'mobile');
  const isLaptopViewport = computed(() => viewportMode.value === 'laptop');
  const isDesktopViewport = computed(() => viewportMode.value === 'desktop');
  const isDesktopLikeViewport = computed(() => isDesktopViewport.value || isLaptopViewport.value);
  const sidebarExpanded = computed(() => {
    if (isTabletViewport.value) return isTabletSidebarOpen.value;
    if (isMobileViewport.value) return isMobileSidebarOpen.value;
    return !leftSidebarCollapsed.value;
  });
  const leftSidebarToggleIcon = computed(() => (
    !sidebarExpanded.value
      ? '/assets/orgas/kingrt/icons/forward.png'
      : '/assets/orgas/kingrt/icons/backward.png'
  ));
  const leftSidebarToggleLabel = computed(() => (
    !sidebarExpanded.value ? 'Open sidebar' : 'Hide sidebar'
  ));
  const shellClasses = computed(() => ({
    'left-collapsed': (isDesktopLikeViewport.value && leftSidebarCollapsed.value) || (isMobileViewport.value && !isMobileSidebarOpen.value),
    'laptop-mode': isLaptopViewport.value,
    'tablet-mode': isTabletViewport.value,
    'tablet-left-open': isTabletViewport.value && isTabletSidebarOpen.value,
    'mobile-mode': isMobileViewport.value,
    'mobile-left-open': isMobileViewport.value && isMobileSidebarOpen.value,
    'call-workspace-mode': isCallWorkspace.value,
  }));
  const leftSidebarClasses = computed(() => ({
    collapsed: (isDesktopLikeViewport.value && leftSidebarCollapsed.value) || (isMobileViewport.value && !isMobileSidebarOpen.value),
  }));

  function syncViewportState() {
    if (!laptopMedia || !tabletMedia || !mobileMedia) return;

    if (mobileMedia.matches) {
      viewportMode.value = 'mobile';
      leftSidebarCollapsed.value = false;
      isTabletSidebarOpen.value = false;
      isMobileSidebarOpen.value = false;
      return;
    }

    if (tabletMedia.matches) {
      viewportMode.value = 'tablet';
      leftSidebarCollapsed.value = false;
      isTabletSidebarOpen.value = false;
      isMobileSidebarOpen.value = false;
      return;
    }

    if (laptopMedia.matches) {
      viewportMode.value = 'laptop';
      isTabletSidebarOpen.value = false;
      isMobileSidebarOpen.value = false;
      return;
    }

    viewportMode.value = 'desktop';
    isTabletSidebarOpen.value = false;
    isMobileSidebarOpen.value = false;
  }

  function syncMobileScrollLock(forceUnlock = false) {
    if (typeof document === 'undefined') return;
    const lockScroll = !forceUnlock && isMobileViewport.value && isMobileSidebarOpen.value;
    document.documentElement.style.overflow = lockScroll ? 'hidden' : '';
    document.body.style.overflow = lockScroll ? 'hidden' : '';
  }

  function handleViewportChange() {
    syncViewportState();
  }

  function handleLeftSidebarToggle() {
    if (isTabletViewport.value) {
      isTabletSidebarOpen.value = !isTabletSidebarOpen.value;
      return;
    }

    if (isMobileViewport.value) {
      isMobileSidebarOpen.value = !isMobileSidebarOpen.value;
      return;
    }

    leftSidebarCollapsed.value = true;
  }

  function showLeftSidebar() {
    if (isTabletViewport.value) {
      isTabletSidebarOpen.value = true;
      return;
    }

    if (isMobileViewport.value) {
      isMobileSidebarOpen.value = true;
      return;
    }

    leftSidebarCollapsed.value = false;
  }

  function handleMainClick() {
    if (isTabletViewport.value && isTabletSidebarOpen.value) {
      isTabletSidebarOpen.value = false;
      return;
    }

    if (isMobileViewport.value && isMobileSidebarOpen.value) {
      isMobileSidebarOpen.value = false;
    }
  }

  function handleNavItemClick() {
    if (isTabletViewport.value && isTabletSidebarOpen.value) {
      isTabletSidebarOpen.value = false;
    }

    if (isMobileViewport.value && isMobileSidebarOpen.value) {
      isMobileSidebarOpen.value = false;
    }
  }

  function closeMobileSidebarForRouteChange() {
    if (isMobileViewport.value && isMobileSidebarOpen.value) {
      isMobileSidebarOpen.value = false;
    }
  }

  watch([isMobileViewport, isMobileSidebarOpen], () => {
    syncMobileScrollLock();
  }, { immediate: true });

  onMounted(() => {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return;
    laptopMedia = window.matchMedia(`(max-width: ${LAPTOP_BREAKPOINT}px)`);
    tabletMedia = window.matchMedia(`(max-width: ${TABLET_BREAKPOINT}px)`);
    mobileMedia = window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT}px)`);
    syncViewportState();
    if (typeof laptopMedia.addEventListener === 'function') {
      laptopMedia.addEventListener('change', handleViewportChange);
      tabletMedia.addEventListener('change', handleViewportChange);
      mobileMedia.addEventListener('change', handleViewportChange);
    } else if (typeof laptopMedia.addListener === 'function') {
      laptopMedia.addListener(handleViewportChange);
      tabletMedia.addListener(handleViewportChange);
      mobileMedia.addListener(handleViewportChange);
    }
  });

  onBeforeUnmount(() => {
    if (!laptopMedia || !tabletMedia || !mobileMedia) {
      syncMobileScrollLock(true);
      return;
    }
    if (typeof laptopMedia.removeEventListener === 'function') {
      laptopMedia.removeEventListener('change', handleViewportChange);
      tabletMedia.removeEventListener('change', handleViewportChange);
      mobileMedia.removeEventListener('change', handleViewportChange);
    } else if (typeof laptopMedia.removeListener === 'function') {
      laptopMedia.removeListener(handleViewportChange);
      tabletMedia.removeListener(handleViewportChange);
      mobileMedia.removeListener(handleViewportChange);
    }
    laptopMedia = null;
    tabletMedia = null;
    mobileMedia = null;
    syncMobileScrollLock(true);
  });

  return {
    leftSidebarCollapsed,
    isTabletSidebarOpen,
    isMobileSidebarOpen,
    isTabletViewport,
    isMobileViewport,
    isDesktopLikeViewport,
    leftSidebarToggleIcon,
    leftSidebarToggleLabel,
    shellClasses,
    leftSidebarClasses,
    handleLeftSidebarToggle,
    showLeftSidebar,
    handleMainClick,
    handleNavItemClick,
    closeMobileSidebarForRouteChange,
  };
}
