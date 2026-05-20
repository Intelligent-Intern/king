import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(new URL('.', import.meta.url).pathname, '../..');

function read(relativePath) {
  return readFileSync(resolve(root, relativePath), 'utf8');
}

function assert(condition, message) {
  if (!condition) {
    console.error(`FAIL: ${message}`);
    process.exit(1);
  }
}

const shell = read('src/layouts/WorkspaceShell.vue');
const leftSidebar = read('src/layouts/CallWorkspaceLeftSidebar.vue');
const spawner = read('src/domain/realtime/sputnikWindowSpawner.js');
const mediaShim = read('src/domain/realtime/sputnikMediaShim.js');
const joinView = read('src/domain/calls/access/JoinView.vue');
const workspaceView = read('src/domain/realtime/CallWorkspaceView.vue');
const router = read('../backend-king-php/http/router.php');
const sputnikModule = read('../backend-king-php/http/module_call_sputnik.php');
const sputnikDomain = read('../backend-king-php/domain/calls/call_sputnik.php');
const compose = read('../docker-compose.v1.yml');
const deploy = read('../scripts/deploy.sh');
const runner = read('scripts/sputnik-runner-server.mjs');

assert(shell.includes('Number(sessionState.userId || 0) === 1'), 'Sputnik controls must be hard-gated to user id 1.');
assert(shell.includes(':show-sputnik-controls="showSputnikControls"'), 'Workspace shell must pass the user-1 Sputnik gate into the call sidebar.');
assert(shell.includes(':sputnik-swarm-state="sputnikSwarmState"'), 'Workspace shell must pass Sputnik lifecycle state into the call sidebar.');
assert(shell.includes('createSputnikWindowSpawner'), 'Workspace shell must delegate Sputnik window lifecycle to a helper.');
assert(shell.includes('sputnikSwarmState.stop();'), 'Workspace shell must close spawned Sputnik windows on teardown.');

assert(leftSidebar.includes('v-if="showSputnikControls"'), 'Left sidebar must render Sputnik controls only through the user-1 gate.');
assert(leftSidebar.includes('10 Sputniks rein') && leftSidebar.includes('Sputniks raus'), 'Left sidebar must include spawn and stop controls.');
assert(leftSidebar.includes('showSputnikControls') && leftSidebar.includes('sputnikSwarmState'), 'Left sidebar must declare Sputnik props.');

assert(spawner.includes('const SPUTNIK_COUNT = 10'), 'Spawner must create exactly 10 Sputnik participants by default.');
assert(!spawner.includes('window.open'), 'Sputnik controls must not depend on browser popups.');
assert(spawner.includes('/sputnik-swarm'), 'Spawner must delegate lifecycle to the server Sputnik runner endpoint.');
assert(spawner.includes("method: 'POST'"), 'Spawner must start the server runner with POST.');
assert(spawner.includes("method: 'DELETE'"), 'Spawner must stop the server runner with DELETE.');

assert(router.includes("require_once __DIR__ . '/module_call_sputnik.php'"), 'Router must load the Sputnik backend module.');
assert(router.includes("'call_sputnik'"), 'Router module order must include Sputnik before generic call routes.');
assert(sputnikModule.includes('/api/calls/{id}/sputnik-swarm'), 'Backend module must expose the documented Sputnik swarm endpoint.');
assert(sputnikDomain.includes('videochat_sputnik_can_control') && sputnikDomain.includes('return $userId === 1;'), 'Backend must hard-gate Sputnik controls to user id 1.');
assert(sputnikDomain.includes('videochat_create_call_access_link_for_user'), 'Backend must launch Sputniks through normal call access links.');
assert(compose.includes('videochat-sputnik-runner-v1') && compose.includes('node scripts/sputnik-runner-server.mjs'), 'Compose must define the server-side Sputnik runner service.');
assert(compose.includes('init: true'), 'Sputnik runner service must run with an init process so stopped Chromium children are reaped.');
assert(deploy.includes('videochat-sputnik-runner-v1') && deploy.includes('--profile sputnik'), 'Production deploy must start the Sputnik runner service.');
assert(runner.includes('chromium.launch') && runner.includes('sputnik_auto_join'), 'Runner must launch headless Chromium Sputnik participants through the normal join flow.');

assert(mediaShim.includes('canvas.captureStream') && mediaShim.includes('createOscillator'), 'Sputnik clients must use synthetic video and beep audio.');
assert(mediaShim.includes('bindNativeMediaDeviceMethod'), 'Sputnik media shim must bind native MediaDevices methods to the original object.');
assert(mediaShim.includes("['addEventListener', 'removeEventListener', 'dispatchEvent']"), 'Sputnik media shim must preserve devicechange listeners without illegal native invocation.');
assert(joinView.includes('installSputnikMediaDeviceShim(sputnikConfig)'), 'Join view must install Sputnik media before preview starts.');
assert(joinView.includes('state.guestName = sputnikConfig.name'), 'Join view must fill the Sputnik guest name.');
assert(joinView.includes('void startSessionAndJoin()'), 'Join view must auto-enter spawned Sputniks into the normal join flow.');
assert(joinView.includes('sputnikWorkspaceQuery(sputnikConfig)'), 'Join view must preserve Sputnik media flags into the workspace.');
assert(workspaceView.includes('installSputnikMediaDeviceShim(sputnikConfig)'), 'Workspace must install Sputnik media before publishing local tracks.');

console.log('OK: Sputnik sidebar controls are user-1 gated and use server-side headless Chromium participants.');
