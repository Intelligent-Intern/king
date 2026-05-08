import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(root, '../../..');

async function read(relativePath) {
  return readFile(path.join(repoRoot, relativePath), 'utf8');
}

const [
  packageJson,
  mapSource,
  templateSource,
  cssSource,
  messagesSource,
] = await Promise.all([
  read('demo/video-chat/frontend-vue/package.json'),
  read('demo/video-chat/frontend-vue/src/modules/users/pages/overview/useGossipNetworkMap.ts'),
  read('demo/video-chat/frontend-vue/src/modules/users/pages/overview/OverviewView.template.html'),
  read('demo/video-chat/frontend-vue/src/modules/users/pages/overview/OverviewView.css'),
  read('demo/video-chat/frontend-vue/src/modules/localization/usersOverviewMessages.js'),
]);

assert.match(
  packageJson,
  /gossip-overview-map-analysis-contract\.mjs/,
  'gossip contract suite must include the overview map analysis contract',
);

assert.match(
  mapSource,
  /analysis:\s*string\[\]/,
  'gossip network map state must expose text analysis lines for the overview detail panel',
);

assert.match(
  mapSource,
  /export function useGossipNetworkMaps\([\s\S]*operationsState\.runningCalls[\s\S]*buildGossipNetworkMapForCall/,
  'overview gossip map state must build one map per live call instead of one aggregate mesh',
);

assert.match(
  mapSource,
  /callKey:\s*string[\s\S]*roomId:\s*string[\s\S]*lifecycle:\s*string/,
  'each gossip map must carry call identity and lifecycle metadata',
);

assert.match(
  mapSource,
  /function buildAnalysis\([\s\S]*gossip_analysis_topology[\s\S]*gossip_analysis_health/,
  'gossip network map must build visible topology and health analysis text',
);

assert.match(
  mapSource,
  /function positionEdges\([\s\S]*x1:\s*from\.x[\s\S]*y1:\s*from\.y[\s\S]*x2:\s*to\.x[\s\S]*y2:\s*to\.y/,
  'gossip network map must precompute edge coordinates from positioned nodes',
);

assert.doesNotMatch(
  templateSource,
  /gossipNetworkMap\.nodes\.find/,
  'overview template must not do node coordinate lookups while drawing gossip map edges',
);

assert.match(
  templateSource,
  /:x1="edge\.x1"[\s\S]*:y1="edge\.y1"[\s\S]*:x2="edge\.x2"[\s\S]*:y2="edge\.y2"/,
  'overview gossip map must render edges from precomputed coordinates',
);

assert.match(
  templateSource,
  /class="gossip-network-analysis"[\s\S]*v-for="line in gossipNetworkMap\.analysis"/,
  'overview gossip detail panel must render text topology analysis',
);

assert.match(
  templateSource,
  /class="gossip-network-call-picker"[\s\S]*v-model="selectedGossipCallKey"[\s\S]*v-for="option in gossipCallOptions"/,
  'overview gossip map must expose a live-call selector',
);

assert.match(
  cssSource,
  /\.gossip-network-analysis[\s\S]*\.gossip-network-analysis li/,
  'overview gossip analysis text must have dedicated styling',
);

assert.match(
  cssSource,
  /\.gossip-network-call-picker[\s\S]*\.gossip-network-call-select/,
  'overview gossip live-call selector must have dedicated styling',
);

for (const key of [
  'users.overview.gossip_analysis_health',
  'users.overview.gossip_analysis_repairs',
  'users.overview.gossip_analysis_topology',
  'users.overview.gossip_analysis_traffic',
  'users.overview.gossip_analysis_waiting',
  'users.overview.gossip_select_call',
]) {
  assert.match(messagesSource, new RegExp(key.replaceAll('.', '\\.')), `${key} must be localized`);
}

console.log('[gossip-overview-map-analysis-contract] PASS');
