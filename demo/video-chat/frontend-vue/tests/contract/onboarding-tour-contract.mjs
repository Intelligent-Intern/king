import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { ENGLISH_MESSAGES } from '../../src/modules/localization/englishMessages.js';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

async function source(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
}

const appPageHeader = await source('src/components/AppPageHeader.vue');
const packageJson = await source('package.json');
const navigationBuilder = await source('src/modules/navigationBuilder.js');
const tourRuntime = await source('src/modules/onboarding/tourRuntime.js');
const onboardingDriver = await source('src/modules/onboarding/useOnboardingDriver.js');
const onboardingDriverStyles = await source('src/modules/onboarding/onboardingDriver.css');
const sessionStore = await source('src/domain/auth/session.ts');
const sessionNormalizers = await source('src/domain/auth/sessionNormalizers.js');
const aboutPanel = await source('src/layouts/settings/WorkspaceAboutSettings.vue');
const workspaceShell = await source('src/layouts/WorkspaceShell.vue');
const governanceDescriptor = await source('src/modules/governance/descriptor.js');
const administrationDescriptor = await source('src/modules/administration/descriptor.js');

for (const key of [
  'onboarding.take_the_tour',
  'onboarding.complete_tour',
  'onboarding.completed',
  'onboarding.completed_badge_earned',
  'onboarding.default.header_title',
  'onboarding.default.header_body',
  'onboarding.default.overview_title',
  'onboarding.default.overview_body',
  'onboarding.default.actions_title',
  'onboarding.default.actions_body',
  'onboarding.default.filters_title',
  'onboarding.default.filters_body',
  'onboarding.default.content_title',
  'onboarding.default.content_body',
  'onboarding.default.pagination_title',
  'onboarding.default.pagination_body',
  'settings.onboarding_badges',
  'settings.no_onboarding_badges',
]) {
  assert.ok(ENGLISH_MESSAGES[key], `missing English onboarding key: ${key}`);
}

assert.match(packageJson, /"driver\.js":/, 'onboarding tours must use the shared driver.js tour library');
assert.match(navigationBuilder, /tour: normalizeTourMetadata\(route, actions\)/, 'route metadata must carry normalized tour metadata');
assert.match(navigationBuilder, /selector: normalizeString\(stepSource\.selector \|\| stepSource\.element\)/, 'route tour metadata must preserve highlight selectors');
assert.match(tourRuntime, /routeTourAction/, 'tour runtime must expose route tour action lookup');
assert.match(tourRuntime, /routeTourDefinition/, 'tour runtime must build route tour definitions');
assert.match(tourRuntime, /isTourCompleted/, 'tour runtime must detect completed tours');
assert.match(tourRuntime, /selector: '.app-page-header'/, 'default tour must highlight the page header');
assert.match(tourRuntime, /selector: '.admin-page-frame-toolbar, .toolbar'/, 'default tour must highlight search and filter toolbars');
assert.match(tourRuntime, /selector: '.admin-table-frame, .table-wrap, .view-card'/, 'default tour must highlight page content');

assert.match(governanceDescriptor, /kind: 'tour'/, 'governance routes must expose tour actions');
assert.match(administrationDescriptor, /kind: 'tour'/, 'administration routes must expose tour actions');

assert.match(appPageHeader, /app-page-header-tour-btn/, 'page header must render the tour icon action');
assert.match(appPageHeader, /<span aria-hidden="true">\?<\/span>/, 'tour action must use the expected question-mark icon');
assert.match(appPageHeader, /useOnboardingDriver/, 'page header must delegate guided tour behavior to the onboarding driver');
assert.doesNotMatch(appPageHeader, /onboarding-tour-steps/, 'tour UI must not fall back to the old modal step list');
assert.match(onboardingDriver, /from 'driver\.js'/, 'guided tour behavior must use driver.js');
assert.match(onboardingDriver, /import 'driver\.js\/dist\/driver\.css'/, 'driver.js base CSS must be loaded');
assert.match(onboardingDriver, /completeOnboardingTour\(tourKey\)/, 'tour completion must persist through the session API');
assert.match(onboardingDriver, /sessionState\.onboardingCompletedTours/, 'tour completion state must come from session state');
assert.match(onboardingDriver, /availableTourSteps/, 'driver tours must skip page steps whose target selector is absent');
assert.match(onboardingDriver, /popoverClass: 'kingrt-tour-popover'/, 'driver popovers must use the KingRT style hook');
assert.match(onboardingDriver, /onNextClick/, 'driver must intercept the final next click for completion');
assert.match(onboardingDriverStyles, /\.driver-popover\.kingrt-tour-popover/, 'guided tour popover must have KingRT styling');
assert.match(onboardingDriverStyles, /\.driver-active-element/, 'guided tours must visibly outline the highlighted page region');

assert.match(sessionStore, /onboardingCompletedTours: \[\]/, 'session state must carry completed tours');
assert.match(sessionStore, /onboardingBadges: \[\]/, 'session state must carry onboarding badges');
assert.match(sessionStore, /completeOnboardingTour/, 'session store must expose tour completion API');
assert.match(sessionStore, /\/api\/user\/onboarding\/tours\/complete/, 'tour completion must call the backend endpoint');
assert.match(sessionNormalizers, /normalizeOnboardingCompletedTours/, 'session normalizers must normalize completed tours');
assert.match(sessionNormalizers, /normalizeOnboardingBadges/, 'session normalizers must normalize badges');

assert.match(aboutPanel, /settings\.onboarding_badges/, 'profile settings must show onboarding badges');
assert.match(aboutPanel, /settings\.no_onboarding_badges/, 'profile settings must show empty badge state');
assert.doesNotMatch(workspaceShell, /:onboarding-badges=/, 'oversized settings shell must not grow to pass badge props');
assert.match(aboutPanel, /sessionState\.onboardingBadges/, 'profile panel must read session badges inside the extracted panel');

console.log('[onboarding-tour-contract] PASS');
