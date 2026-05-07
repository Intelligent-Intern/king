import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { createEntitySummaryCache, normalizeEntitySummary } from '../../src/modules/governance/entitySummaryCache.js';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

async function source(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
}

function escapeRegExp(value) {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function assertLoopHasNoRequestCall(sourceText, loopSnippet, label, endTag = 'tr') {
  const pattern = new RegExp(`${escapeRegExp(loopSnippet)}[\\s\\S]*?<\\/${endTag}>`);
  const match = sourceText.match(pattern);
  assert.ok(match, `${label} loop must stay present in the row template`);
  assert.doesNotMatch(
    match[0],
    /\b(?:fetch|fetchBackend|apiJson|apiRequest)\s*\(/,
    `${label} row rendering must not request relation summaries per row`,
  );
}

function functionWindow(sourceText, functionName, size = 1000) {
  const index = sourceText.indexOf(`function ${functionName}`);
  assert.notEqual(index, -1, `${functionName} must stay present`);
  return sourceText.slice(index, index + size);
}

const cache = createEntitySummaryCache();
const summary = normalizeEntitySummary('groups', {
  id: 'group:core',
  name: 'Core Team',
  key: 'core',
  status: 'active',
});
assert.equal(summary.entity_key, 'groups', 'summary must preserve entity key');
assert.equal(summary.id, 'group:core', 'summary must preserve stable id');
assert.equal(summary.name, 'Core Team', 'summary must expose display label');

cache.upsertRows('groups', [summary]);
assert.equal(cache.getSummary('groups', 'group:core')?.name, 'Core Team', 'cache must return hydrated summaries');
cache.upsertSummary('groups', {
  id: 'group:members',
  name: 'Member Group',
  relationships: {
    members: [
      { entity_key: 'users', id: '7', name: 'Ada Member', key: 'ada@example.test', status: 'active' },
    ],
  },
});
assert.equal(cache.getSummary('users', '7')?.name, 'Ada Member', 'nested relationship summaries must hydrate the target entity cache');
assert.deepEqual(cache.missingIds('groups', ['group:core', 'group:ops']), ['group:ops'], 'cache must detect missing ids only');
assert.deepEqual(
  cache.buildBatchSummaryRequest('groups', ['group:core', 'group:ops']),
  { entity_key: 'groups', ids: ['group:ops'] },
  'batch summary requests must include only missing ids',
);
assert.deepEqual(
  cache.buildBatchSummaryRequests([
    { entity_key: 'groups', ids: ['group:core', 'group:ops'] },
    { entity_key: 'users', ids: ['7', '8'] },
  ]),
  [
    { entity_key: 'groups', ids: ['group:ops'] },
    { entity_key: 'users', ids: ['8'] },
  ],
  'multi-entity batch summary requests must include only unresolved ids',
);

let fetchCalls = 0;
await cache.loadMissingSummaries('groups', ['group:core', 'group:ops'], async (request) => {
  fetchCalls += 1;
  assert.deepEqual(request, { entity_key: 'groups', ids: ['group:ops'] }, 'fetch must be a single batch request');
  return {
    included: {
      groups: [
        { id: 'group:ops', name: 'Operations', key: 'ops', status: 'active' },
      ],
    },
  };
});
assert.equal(fetchCalls, 1, 'missing summaries must be fetched in one batch');
assert.equal(cache.getSummary('groups', 'group:ops')?.name, 'Operations', 'batch payload must hydrate included summaries');

let multiFetchCalls = 0;
const grouped = await cache.loadMissingSummaryRequests([
  { entity_key: 'groups', ids: ['group:core', 'group:ops'] },
  { entity_key: 'users', ids: ['7', '8'] },
], async (request) => {
  multiFetchCalls += 1;
  assert.deepEqual(request, { requests: [{ entity_key: 'users', ids: ['8'] }] }, 'multi-entity fetch must collapse missing ids into one backend request');
  return {
    result: {
      included: {
        users: [
          { id: '8', name: 'Grace Member', key: 'grace@example.test', status: 'active' },
        ],
      },
    },
  };
});
assert.equal(multiFetchCalls, 1, 'multi-entity missing summaries must use one batch call');
assert.deepEqual(grouped.users.map((row) => row.id), ['7', '8'], 'multi-entity batch results must return hydrated summaries by entity');

await cache.loadMissingSummaries('groups', ['group:core', 'group:ops'], async () => {
  throw new Error('already hydrated rows must not refetch');
});

const viewSource = await source('src/modules/governance/pages/GovernanceCrudView.vue');
assert.match(viewSource, /createEntitySummaryCache/, 'governance CRUD view must own a normalized summary cache');
assert.match(viewSource, /entitySummaryCache\.upsertRows/, 'governance CRUD view must hydrate summaries in batches');
assert.doesNotMatch(viewSource, /v-for="row in pagedRows"[\s\S]{0,800}fetch\(/, 'row rendering must not fetch relation summaries per row');

const persistenceSource = await source('src/modules/governance/useGovernanceCrudPersistence.js');
assert.match(persistenceSource, /fetchSummaryBatch/, 'governance persistence must expose a batch summary loader');
assert.match(persistenceSource, /\/api\/governance\/summaries/, 'batch summaries must use the governance summaries endpoint');

const userTableSource = await source('src/modules/users/pages/components/UsersTable.vue');
const usersViewSource = await source('src/modules/users/pages/admin/UsersView.vue');
const userEditorSource = await source('src/modules/users/pages/components/UserEditorModal.vue');
const marketplaceTableSource = await source('src/modules/marketplace/pages/AdminMarketplaceTable.vue');
const localizationAdminSource = await source('src/modules/localization/pages/AdministrationLocalizationView.vue');
const themeSettingsSource = await source('src/layouts/settings/WorkspaceThemeSettings.vue');
const administrationSettingsSource = await source('src/layouts/settings/WorkspaceAdministrationSettings.vue');
const backendSummarySource = await source('../backend-king-php/domain/tenancy/governance_summaries.php');

assertLoopHasNoRequestCall(userTableSource, 'v-for="user in rows"', 'user management');
assert.match(usersViewSource, /loadGovernanceRoleOptions\(apiRequest\)/, 'user editor role relation options must load through a preloaded option endpoint');
assert.match(usersViewSource, /loadGovernanceGroupOptions\(apiRequest\)/, 'user editor group relation options must load through a preloaded option endpoint');

const userRelationProvider = functionWindow(userEditorSource, 'relationRowsForEntity');
assert.match(userRelationProvider, /governanceRoleRows\.value/, 'user relation provider must use preloaded governance role rows');
assert.match(userRelationProvider, /governanceGroupRows\.value/, 'user relation provider must use preloaded governance group rows');
assert.match(userRelationProvider, /themeRows\.value/, 'user relation provider must use preloaded theme rows');
assert.match(userRelationProvider, /governanceCatalogRows\(entityKey\)/, 'user relation provider must use local governance module and permission catalogs');
assert.doesNotMatch(userRelationProvider, /\b(?:fetch|fetchBackend|apiJson|apiRequest)\s*\(/, 'user relation provider must not request rows during render');
assert.doesNotMatch(userRelationProvider, /governancePersistence\./, 'user relation provider must not create or fetch persisted governance rows during render');

assertLoopHasNoRequestCall(marketplaceTableSource, 'v-for="app in rows"', 'marketplace');
assertLoopHasNoRequestCall(localizationAdminSource, 'v-for="language in pagedLanguages"', 'localization languages');
assertLoopHasNoRequestCall(localizationAdminSource, 'v-for="resource in preview.resources.slice(0, 8)"', 'localization CSV preview');
assertLoopHasNoRequestCall(localizationAdminSource, 'v-for="bundle in bundles"', 'localization bundles');
assertLoopHasNoRequestCall(localizationAdminSource, 'v-for="entry in imports"', 'localization imports');
assertLoopHasNoRequestCall(themeSettingsSource, 'v-for="theme in pagedThemes"', 'theme management', 'article');
assertLoopHasNoRequestCall(administrationSettingsSource, 'v-for="(recipient, index) in leadRecipients"', 'administration lead recipients', 'div');

for (const entity of ['users', 'groups', 'organizations', 'roles', 'grants', 'policies', 'data-portability']) {
  assert.match(
    backendSummarySource,
    new RegExp(`'${escapeRegExp(entity)}'`),
    `governance summaries endpoint must support ${entity}`,
  );
}
assert.match(backendSummarySource, /result' => \['included' => \$included\]/, 'summary endpoint must return included summaries in result payload');
assert.match(backendSummarySource, /'included' => \$included/, 'summary endpoint must also expose top-level included summaries');

console.log('[governance-summary-cache-contract] PASS');
