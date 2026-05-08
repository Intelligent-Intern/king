import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(root, '../../..');

async function read(relativePath) {
  return readFile(path.join(repoRoot, relativePath), 'utf8');
}

const [sidebarSource, sidebarStyles, grantButtonSource] = await Promise.all([
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/CallAppsSidebarPanel.vue'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/CallAppsSidebarPanel.css'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/CallAppParticipantGrantButton.vue'),
]);
const sidebarCombinedSource = `${sidebarSource}\n${sidebarStyles}`;

assert.match(
  sidebarSource,
  /call-apps-item-side[\s\S]*call-apps-item-state[\s\S]*call-apps-item-action[\s\S]*Selected[\s\S]*Select/,
  'Call Apps list rows must expose a responsive selected/select action area instead of relying on a bare app key',
);

assert.match(
  sidebarSource,
  /fieldset class="call-apps-policy"[\s\S]*Default participant access[\s\S]*type="radio" value="blocked_by_default"[\s\S]*Grant individually[\s\S]*type="radio" value="allowed_by_default"[\s\S]*Participants can open/,
  'Call Apps attach flow must make default participant access explicit with inline grant/restrict choices',
);

assert.match(
  sidebarSource,
  /call-apps-access-default[\s\S]*activeSessionDefaultAccessLabel[\s\S]*Default: allowed[\s\S]*Default: blocked/s,
  'Call Apps access panel must show the active session default access policy',
);

assert.match(
  sidebarSource,
  /call-apps-access-state" :class="grantStateClass\(participant\)"[\s\S]*variant="label"[\s\S]*@grant-updated="applyLocalGrantUpdate"/,
  'Call Apps access rows must combine readable state badges with labeled backend-backed grant buttons',
);

assert.match(
  sidebarCombinedSource,
  /\.call-apps-list-item[\s\S]*grid-template-columns:\s*minmax\(0,\s*1fr\)[\s\S]*\.call-apps-item-side[\s\S]*flex-wrap:\s*wrap[\s\S]*\.call-apps-access-row[\s\S]*grid-template-columns:\s*minmax\(0,\s*1fr\)[\s\S]*@container\s*\(min-width:\s*380px\)[\s\S]*\.call-apps-access-row[\s\S]*grid-template-columns:\s*minmax\(0,\s*1fr\)\s*auto/s,
  'Call Apps sidebar list and access actions must stack at narrow widths and expand at wider sidebar widths',
);

assert.doesNotMatch(
  sidebarCombinedSource,
  /call-apps-card|card-heavy|nested-card/i,
  'Call Apps sidebar access UX must not introduce nested card UI',
);

assert.match(
  grantButtonSource,
  /variant:\s*\{[\s\S]*validator:\s*\(value\) => \['icon', 'label'\]\.includes\(value\)[\s\S]*variant-label/,
  'Call App grant button must support a labeled sidebar variant while preserving the icon variant',
);

assert.match(
  grantButtonSource,
  /effectiveGrantState\.value === 'allowed'[\s\S]*remove_user\.png[\s\S]*add_to_call\.png[\s\S]*effectiveGrantState\.value === 'allowed' \? 'Revoke' : 'Allow'/,
  'Call App grant button must show the next action clearly: allowed means Revoke, denied means Allow',
);

assert.match(
  grantButtonSource,
  /Only the call owner or a moderator can change Call App access[\s\S]*buttonLabel\.value\} Call App access for/,
  'Call App grant button titles must explain unavailable controls and name the affected participant',
);

console.log('[call-app-sidebar-access-ux-contract] PASS');
