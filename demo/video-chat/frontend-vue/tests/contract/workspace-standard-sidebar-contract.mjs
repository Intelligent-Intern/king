import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

async function source(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
}

const shell = await source('src/layouts/WorkspaceShell.vue');
const standardSidebar = await source('src/layouts/WorkspaceStandardSidebar.vue');
const shellViewport = await source('src/layouts/useWorkspaceShellViewport.js');
const shellCss = await source('src/styles/shell.css');
const responsiveCss = await source('src/styles/responsive.css');

assert.match(
  shell,
  /import WorkspaceStandardSidebar from ['"]\.\/WorkspaceStandardSidebar\.vue['"]/,
  'workspace shell must import the focused standard sidebar component',
);

assert.match(
  shell,
  /<WorkspaceStandardSidebar[\s\S]*:sidebar-logo-src="sidebarLogoSrc"[\s\S]*:profile-avatar-src="profileAvatarSrc"[\s\S]*@sign-out="handleSignOut"/,
  'workspace shell must delegate logo, navigation, profile, and logout wiring to the standard sidebar component',
);

assert.match(
  shell,
  /import \{ useWorkspaceShellViewport \} from ['"]\.\/useWorkspaceShellViewport\.js['"]/,
  'workspace shell must import the focused viewport/sidebar state composable',
);

assert.match(
  shell,
  /useWorkspaceShellViewport\(\{ isCallWorkspace \}\)/,
  'workspace shell must initialize responsive sidebar state through the composable',
);

assert.doesNotMatch(
  shell,
  /<WorkspaceNavigation|class="sidebar-profile avatar-only"|class="logout-wrap"|function syncViewportState|window\.matchMedia|syncMobileScrollLock/,
  'workspace shell must not keep standard sidebar internals or viewport media-query lifecycle inline',
);

assert.match(
  standardSidebar,
  /import WorkspaceNavigation from ['"]\.\/WorkspaceNavigation\.vue['"]/,
  'standard sidebar must own primary navigation rendering',
);

assert.match(
  standardSidebar,
  /<div class="brand-strip">[\s\S]*<div class="sidebar-scroll-body">/,
  'standard sidebar must keep the brand strip outside the scroll body',
);

const scrollStart = standardSidebar.indexOf('<div class="sidebar-scroll-body">');
const scriptStart = standardSidebar.indexOf('<script setup>');
assert.notEqual(scrollStart, -1, 'standard sidebar scroll body must exist');
assert.notEqual(scriptStart, -1, 'standard sidebar script must exist');
const scrollMarkup = standardSidebar.slice(scrollStart, scriptStart);

assert.match(
  scrollMarkup,
  /<WorkspaceNavigation[\s\S]*<section class="sidebar-profile avatar-only">[\s\S]*<div class="logout-wrap">/,
  'navigation, avatar, and logout must all live in the scrollable sidebar body',
);

assert.doesNotMatch(
  scrollMarkup,
  /brand-strip/,
  'brand strip must not scroll with the standard sidebar body',
);

assert.match(
  shellCss,
  /\.sidebar-content\.left\s*\{[\s\S]*?grid-template-rows:\s*auto minmax\(0,\s*1fr\);[\s\S]*?overflow:\s*hidden;/,
  'standard sidebar shell must keep the brand strip fixed above the scroll body',
);

assert.match(
  shellCss,
  /\.sidebar-scroll-body\s*\{[\s\S]*?overflow-y:\s*auto;[\s\S]*?flex-direction:\s*column;/,
  'standard sidebar scroll body must own vertical scrolling',
);

assert.match(
  shellCss,
  /\.sidebar-profile\.avatar-only\s*\{[\s\S]*?margin-top:\s*auto;[\s\S]*?margin-bottom:\s*50px;/,
  'avatar dock must remain at the bottom of the scrollable standard sidebar body',
);

assert.match(
  responsiveCss,
  /\.shell\.tablet-mode:not\(\.tablet-left-open\) \.logout-wrap,[\s\S]*?\.sidebar-profile[\s\S]*?display:\s*none;/,
  'collapsed tablet sidebar must hide scroll-body dock controls through responsive CSS',
);

assert.match(
  shellViewport,
  /export function useWorkspaceShellViewport\(\{ isCallWorkspace \}\)/,
  'viewport composable must expose the workspace shell viewport contract',
);

assert.match(
  shellViewport,
  /const LAPTOP_BREAKPOINT = 1440;[\s\S]*const TABLET_BREAKPOINT = 1180;[\s\S]*const MOBILE_BREAKPOINT = 760;/,
  'viewport composable must own the shell breakpoints',
);

assert.match(
  shellViewport,
  /watch\(\[isMobileViewport, isMobileSidebarOpen\][\s\S]*syncMobileScrollLock\(\);[\s\S]*\{ immediate: true \}\);/,
  'viewport composable must own mobile scroll lock synchronization',
);

assert.match(
  shellViewport,
  /onMounted\(\(\) => \{[\s\S]*window\.matchMedia[\s\S]*addEventListener\('change', handleViewportChange\)/,
  'viewport composable must own media-query listener registration',
);

assert.match(
  shellViewport,
  /onBeforeUnmount\(\(\) => \{[\s\S]*removeEventListener\('change', handleViewportChange\)[\s\S]*syncMobileScrollLock\(true\);/,
  'viewport composable must own media-query cleanup and forced scroll unlock',
);

console.log('[workspace-standard-sidebar-contract] PASS');
