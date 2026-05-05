import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { createEntitySummaryCache, normalizeEntitySummary } from '../../src/modules/governance/entitySummaryCache.js';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

async function source(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
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
assert.deepEqual(cache.missingIds('groups', ['group:core', 'group:ops']), ['group:ops'], 'cache must detect missing ids only');
assert.deepEqual(
  cache.buildBatchSummaryRequest('groups', ['group:core', 'group:ops']),
  { entity_key: 'groups', ids: ['group:ops'] },
  'batch summary requests must include only missing ids',
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

console.log('[governance-summary-cache-contract] PASS');
