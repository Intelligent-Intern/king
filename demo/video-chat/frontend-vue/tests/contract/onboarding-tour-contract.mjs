import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { ENGLISH_MESSAGES } from '../../src/modules/localization/englishMessages.js';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

async function source(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
}

const appPageHeader = await source('src/components/AppPageHeader.vue');
const navigationBuilder = await source('src/modules/navigationBuilder.js');
const tourRuntime = await source('src/modules/onboarding/tourRuntime.js');
const sessionStore = await source('src/domain/auth/session.js');
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
  'onboarding.default.overview_title',
  'onboarding.default.overview_body',
  'onboarding.default.actions_title',
  'onboarding.default.actions_body',
  'settings.onboarding_badges',
  'settings.no_onboarding_badges',
]) {
  assert.ok(ENGLISH_MESSAGES[key], `missing English onboarding key: ${key}`);
}

assert.match(navigationBuilder, /tour: normalizeTourMetadata\(route, actions\)/, 'route metadata must carry normalized tour metadata');
assert.match(tourRuntime, /routeTourAction/, 'tour runtime must expose route tour action lookup');
assert.match(tourRuntime, /routeTourDefinition/, 'tour runtime must build route tour definitions');
assert.match(tourRuntime, /isTourCompleted/, 'tour runtime must detect completed tours');

assert.match(governanceDescriptor, /kind: 'tour'/, 'governance routes must expose tour actions');
assert.match(administrationDescriptor, /kind: 'tour'/, 'administration routes must expose tour actions');

assert.match(appPageHeader, /app-page-header-tour-btn/, 'page header must render the tour icon action');
assert.match(appPageHeader, /<span aria-hidden="true">\?<\/span>/, 'tour action must use the expected question-mark icon');
assert.match(appPageHeader, /completeOnboardingTour\(tourKey\)/, 'tour completion must persist through the session API');
assert.match(appPageHeader, /onboarding-tour-steps/, 'tour modal must render steps');
assert.match(appPageHeader, /sessionState\.onboardingCompletedTours/, 'tour completion state must come from session state');

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
