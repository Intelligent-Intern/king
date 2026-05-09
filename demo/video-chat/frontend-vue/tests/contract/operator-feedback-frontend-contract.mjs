import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');

function read(relPath) {
  return fs.readFileSync(path.join(frontendRoot, relPath), 'utf8');
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `[operator-feedback-frontend-contract] missing ${label}`);
}

const template = read('src/domain/realtime/CallWorkspaceView.template.html');
const runtime = read('src/domain/realtime/workspace/callWorkspace/chatRuntime.ts');
const adapter = read('src/domain/realtime/workspace/callWorkspace/operatorFeedbackAdapter.ts');
const messages = read('src/modules/localization/callWorkspaceMessages.js');
const panelsCss = read('src/domain/realtime/CallWorkspacePanels.css');
const chatCss = read('src/domain/realtime/workspace/callWorkspace/CallWorkspaceChat.css');

requireContains(template, 'class="workspace-chat-operator-toggle"', 'visible operator checkbox label');
requireContains(template, 'v-model="chatRuntimeHelpers.operatorFeedbackState.selected"', 'operator checkbox state binding');
requireContains(template, 'chatRuntimeHelpers.operatorFeedbackState.toastMessage', 'operator toast binding');
requireContains(template, 'workspace-chat-operator-badge', 'operator chat badge');

requireContains(runtime, 'operatorFeedbackState = reactive', 'reactive operator feedback state');
requireContains(runtime, "type: 'chat/send'", 'normal chat send path remains');
requireContains(runtime, '...buildOperatorFeedbackChatFramePatch(operatorFeedbackPayload)', 'operator feedback chat payload patch');
requireContains(runtime, 'operatorFeedbackState.selected = false', 'operator checkbox reset after successful send');
requireContains(runtime, "t('calls.workspace.operator_feedback_sent')", 'operator sent toast');
requireContains(runtime, 'maybeShowOperatorFeedbackDeploymentToast(payload)', 'future deployment toast adapter hook');

requireContains(adapter, 'POST /api/calls/{call_id}/operator-feedback', 'documented backend route payload');
requireContains(adapter, 'chat/send.operator_feedback', 'documented chat payload fallback');
requireContains(adapter, "return `feature '${feature}' deployed`;", 'exact deployed feature toast copy');
requireContains(adapter, 'kind: OPERATOR_FEEDBACK_KIND', 'operator payload marker');
requireContains(adapter, "status: 'submitted'", 'operator submission status');

requireContains(messages, "'calls.workspace.operator_feedback_checkbox': 'Operator'", 'operator checkbox copy');
requireContains(messages, "'calls.workspace.operator_feedback_sent': 'Operator feedback sent.'", 'operator sent copy');
requireContains(panelsCss, "@import './workspace/callWorkspace/CallWorkspaceChat.css';", 'chat CSS extraction import');
requireContains(chatCss, '.workspace-chat-operator-toggle', 'operator toggle styling');
requireContains(chatCss, '.workspace-chat-operator-toast', 'operator toast styling');

process.stdout.write('[operator-feedback-frontend-contract] PASS\n');
