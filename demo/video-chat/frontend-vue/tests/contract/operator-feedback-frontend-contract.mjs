import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');

function read(relPath) {
  return fs.readFileSync(path.join(frontendRoot, relPath), 'utf8');
}

function collect(condition, message) {
  if (!condition) failures.push(message);
}

const failures = [];
const workspaceView = read('src/domain/realtime/CallWorkspaceView.vue');
const workspaceTemplate = read('src/domain/realtime/CallWorkspaceView.template.html');
const chatRuntime = read('src/domain/realtime/workspace/callWorkspace/chatRuntime.ts');
const socketLifecycle = read('src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts');
const callWorkspaceMessages = read('src/modules/localization/callWorkspaceMessages.js');
const packageJson = JSON.parse(read('package.json'));

collect(
  /const\s+chatOperatorFeedbackChecked\s*=\s*ref\(false\)/.test(workspaceView),
  'CallWorkspaceView.vue must own a false-by-default chatOperatorFeedbackChecked ref',
);
collect(
  /chatOperatorFeedbackChecked,/.test(workspaceView),
  'CallWorkspaceView.vue must pass chatOperatorFeedbackChecked into the extracted chat runtime helper',
);
collect(
  /workspace-chat-operator-feedback/.test(workspaceTemplate)
    && /type="checkbox"/.test(workspaceTemplate)
    && /v-model="chatOperatorFeedbackChecked"/.test(workspaceTemplate),
  'chat composer must render an Operator feedback checkbox bound to chatOperatorFeedbackChecked',
);
collect(
  /calls\.workspace\.operator_feedback/.test(workspaceTemplate)
    && /calls\.workspace\.operator_feedback/.test(callWorkspaceMessages),
  'operator feedback checkbox must use the calls.workspace.operator_feedback localization key',
);
collect(
  /chatOperatorFeedbackChecked/.test(chatRuntime)
    && /operator_feedback:\s*chatOperatorFeedbackChecked\.value/.test(chatRuntime),
  'chat/send websocket payload must include operator_feedback from the checkbox state',
);
collect(
  /sendSocketFrame\(\{[\s\S]{0,1200}message:\s*text[\s\S]{0,1200}operator_feedback:/m.test(chatRuntime),
  'chat/send websocket payload must carry the message text and operator_feedback flag in the same frame',
);
collect(
  /chatOperatorFeedbackChecked\.value\s*=\s*false/.test(chatRuntime),
  'successful chat send must reset the Operator feedback checkbox',
);
collect(
  /operator-feedback\/deployed/.test(socketLifecycle),
  'socket lifecycle must handle operator-feedback/deployed notifications',
);
collect(
  /requestedFeature/.test(socketLifecycle)
    && /feature '\$\{requestedFeature\}' deployed/.test(socketLifecycle)
    && /setNotice\(/.test(socketLifecycle),
  "deployed notification handler must trigger a toast with feature '<requested feature>' deployed",
);
collect(
  packageJson.scripts?.['test:contract:operator-feedback']?.includes('operator-feedback-frontend-contract.mjs')
    && packageJson.scripts?.['test:contract:operator-feedback']?.includes('operator-feedback-contract.sh'),
  'package.json must wire npm run test:contract:operator-feedback to frontend and backend contracts',
);

if (failures.length > 0) {
  for (const failure of failures) {
    process.stderr.write(`[operator-feedback-frontend-contract] FAIL: ${failure}\n`);
  }
  process.exit(1);
}

process.stdout.write('[operator-feedback-frontend-contract] PASS\n');
