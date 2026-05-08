import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const videoChatRoot = path.resolve(__dirname, '../../..');

function read(relativePath) {
  return fs.readFileSync(path.join(videoChatRoot, relativePath), 'utf8');
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `[iam-king-participants-owner-timeout-contract] missing ${label}`);
}

const ownerAbsence = read('backend-king-php/domain/realtime/realtime_owner_absence.php');
const roomSnapshot = read('backend-king-php/domain/realtime/realtime_room_snapshot.php');
const kingParticipantsHelper = read('backend-king-php/tests/call-access-king-participants-helper.php');
const ownerTimeoutContract = read('backend-king-php/tests/call-access-owner-timeout-contract.php');
const ownerAbsenceUiState = read('frontend-vue/src/domain/realtime/workspace/callWorkspace/ownerAbsenceState.js');
const ownerAbsenceBanner = read('frontend-vue/src/domain/realtime/OwnerAbsenceCountdownBanner.vue');
const callWorkspaceMessages = read('frontend-vue/src/modules/localization/callWorkspaceMessages.js');
const callWorkspaceTemplate = read('frontend-vue/src/domain/realtime/CallWorkspaceView.template.html');
const callWorkspaceView = read('frontend-vue/src/domain/realtime/CallWorkspaceView.vue');
const roomState = read('frontend-vue/src/domain/realtime/workspace/callWorkspace/roomState.ts');
const joinView = read('frontend-vue/src/domain/calls/access/JoinView.vue');
const seedMatrixHelper = read('frontend-vue/tests/e2e/helpers/callAccessSeedMatrix.js');
const browserSpec = read('frontend-vue/tests/e2e/call-access-owner-absence-browser.spec.js');
const packageJson = read('frontend-vue/package.json');

requireContains(ownerAbsence, 'const VIDEOCHAT_OWNER_ABSENCE_TIMER_MS = 15 * 60 * 1000;', '15-minute owner absence timer');
requireContains(ownerAbsence, 'const VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS = 5 * 60 * 1000;', '5-minute owner absence countdown');
requireContains(ownerAbsence, 'function videochat_realtime_owner_absence_snapshot(PDO $pdo, string $callId, string $roomId, ?int $nowMs = null): array', 'CI-safe owner absence snapshot clock');
requireContains(ownerAbsence, 'function videochat_realtime_apply_owner_absence_timeout(PDO $pdo, string $callId, string $roomId, ?int $nowMs = null): array', 'CI-safe owner absence transition clock');
requireContains(ownerAbsence, 'function videochat_realtime_owner_absence_persist_stale_owner_departure(', 'network-loss stale owner departure persistence');
requireContains(ownerAbsence, "'status' => 'owner_present'", 'owner return cancellation status');
requireContains(ownerAbsence, "'status' => 'no_participants'", 'no-participant non-ending state');
requireContains(ownerAbsence, '$endsAtMs = $absentSinceMs + VIDEOCHAT_OWNER_ABSENCE_TIMER_MS;', '15-minute total owner absence deadline');
requireContains(ownerAbsence, '$countdownStartsAtMs = max($absentSinceMs, $endsAtMs - VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS);', 'countdown is inside final five minutes');
requireContains(ownerAbsence, "$status = $countdownStarted ? 'countdown' : 'monitoring';", 'monitoring-to-countdown state split');
requireContains(ownerAbsence, '$absentSinceMs = $lastSeenMs + videochat_realtime_presence_db_ttl_ms();', 'network-loss absence starts at stale heartbeat cutoff');
requireContains(ownerAbsence, "$status = 'ended';", 'implicit ended state');
requireContains(ownerAbsence, "SET status = 'ended',", 'persisted implicit call ending');
requireContains(ownerAbsence, "$payload['ended_reason'] = 'owner_absent_timeout';", 'owner absence end reason');

requireContains(roomSnapshot, "require_once __DIR__ . '/realtime_owner_absence.php';", 'room snapshot owner-absence helper import');
requireContains(roomSnapshot, 'function videochat_realtime_db_room_participants(callable $openDatabase, array $connection, ?int $nowMs = null): array', 'CI-safe room participant clock');
requireContains(roomSnapshot, 'videochat_realtime_db_room_participants($openDatabase, $connection, $nowMs)', 'room snapshot uses fake clock for DB participants');
requireContains(roomSnapshot, 'videochat_realtime_apply_owner_absence_timeout($openDatabase(), $callId, $roomId, $nowMs)', 'authoritative snapshot owner-timeout application');
requireContains(roomSnapshot, "'call_lifecycle' => [", 'room snapshot lifecycle payload');
requireContains(roomSnapshot, "'owner_absence' => $ownerAbsence", 'room snapshot owner absence payload');
requireContains(roomSnapshot, "'call_lifecycle' => $payload['call_lifecycle'] ?? [],", 'room snapshot signature includes lifecycle');

requireContains(kingParticipantsHelper, 'function videochat_iam_king_participant_client(', 'simulated King participant client helper');
requireContains(kingParticipantsHelper, 'function videochat_iam_king_participant_touch(PDO $pdo, array $connection, int $nowMs): void', 'simulated King participant heartbeat helper');
requireContains(kingParticipantsHelper, 'function videochat_iam_king_participant_leave(', 'simulated King participant leave helper');
requireContains(kingParticipantsHelper, 'function videochat_iam_king_participant_snapshot(', 'simulated King participant snapshot helper');
requireContains(kingParticipantsHelper, 'videochat_realtime_presence_db_upsert($pdo, $connection, $nowMs);', 'participant clients use fake clock presence upsert');
requireContains(kingParticipantsHelper, 'videochat_realtime_remove_call_presence(static fn (): PDO => $pdo, $effectiveConnection);', 'participant leave removes realtime presence');

requireContains(ownerTimeoutContract, 'videochat_iam_king_participant_client(', 'owner-timeout contract uses simulated clients');
requireContains(ownerTimeoutContract, 'owner_browser_crash', 'browser crash owner absence mode proof');
requireContains(ownerTimeoutContract, 'owner_context_killed', 'context killed owner absence mode proof');
requireContains(ownerTimeoutContract, 'owner_network_disconnected', 'network disconnected owner absence mode proof');
requireContains(ownerTimeoutContract, 'VIDEOCHAT_OWNER_ABSENCE_TIMER_MS - VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS - 1000', 'before-countdown boundary proof');
requireContains(ownerTimeoutContract, 'owner_returned_before_countdown', 'owner return before final countdown proof');
requireContains(ownerTimeoutContract, 'VIDEOCHAT_OWNER_ABSENCE_TIMER_MS - VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS;', 'countdown start boundary proof');
requireContains(ownerTimeoutContract, 'videochat_realtime_presence_db_ttl_ms()', 'stale heartbeat owner absence proof');
requireContains(ownerTimeoutContract, "($beforeCountdown['status'] ?? '') === 'monitoring'", 'monitoring assertion');
requireContains(ownerTimeoutContract, "($countdown['status'] ?? '') === 'countdown'", 'countdown assertion');
requireContains(ownerTimeoutContract, "($countdown['countdown_remaining_ms'] ?? 0) === VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS", 'five-minute countdown assertion');
requireContains(ownerTimeoutContract, "($ownerReturn['status'] ?? '') === 'owner_present'", 'owner return cancellation assertion');
requireContains(ownerTimeoutContract, "videochat_iam_owner_timeout_left_at($pdo, $callId, $ownerUserId) === ''", 'owner left marker cancellation assertion');
requireContains(ownerTimeoutContract, "($ended['status'] ?? '') === 'ended'", 'implicit end assertion');
requireContains(ownerTimeoutContract, "videochat_iam_owner_timeout_call_status($pdo, $callId) === 'ended'", 'persisted ended assertion');

requireContains(ownerAbsenceUiState, 'normalizeOwnerAbsencePayload', 'frontend owner-absence payload normalizer');
requireContains(ownerAbsenceUiState, 'shouldShowOwnerAbsenceMonitoring', 'frontend owner-absence monitoring visibility rule');
requireContains(ownerAbsenceUiState, "state.status === 'monitoring' && !state.ownerPresent", 'frontend timer-start notification rule');
requireContains(ownerAbsenceUiState, "state.status === 'countdown' && state.countdownStarted", 'frontend countdown visibility rule');
requireContains(ownerAbsenceUiState, "state.status === 'ended'", 'frontend ended visibility rule');
requireContains(ownerAbsenceUiState, 'formatOwnerAbsenceCountdown', 'frontend countdown formatter');
requireContains(ownerAbsenceBanner, 'data-testid="owner-absence-countdown"', 'browser-visible owner absence banner');
requireContains(ownerAbsenceBanner, 'monitoringVisible', 'browser-visible owner absence monitoring state');
requireContains(ownerAbsenceBanner, "calls.workspace.owner_absence_monitoring", 'localized monitoring message');
requireContains(ownerAbsenceBanner, "calls.workspace.owner_absence_countdown", 'localized countdown message');
requireContains(ownerAbsenceBanner, "calls.workspace.owner_absence_ended", 'localized ended message');
requireContains(callWorkspaceMessages, "calls.workspace.owner_absence_monitoring", 'owner absence monitoring translation');
requireContains(callWorkspaceTemplate, '<OwnerAbsenceCountdownBanner :owner-absence="ownerAbsenceState" />', 'workspace owner-absence banner mounting');
requireContains(callWorkspaceView, 'const ownerAbsenceState = ref(null);', 'workspace owner-absence state ref');
requireContains(roomState, 'ownerAbsenceState.value = normalizeOwnerAbsencePayload(ownerAbsence);', 'room snapshot owner-absence state application');
requireContains(joinView, 'const errorPayload = payload && typeof payload === \'object\'', 'late ended join preserves backend error code');
requireContains(joinView, 'localizedApiErrorMessage(errorPayload', 'late ended join renders call-access conflict state');
requireContains(seedMatrixHelper, 'call_lifecycle', 'fake realtime snapshot lifecycle payload');
requireContains(seedMatrixHelper, 'owner_absence: ownerAbsencePayload(ownerAbsenceOverrides)', 'fake realtime owner absence shape');
requireContains(seedMatrixHelper, 'window.__iamCallAccessEmitRoomSnapshot', 'browser test realtime snapshot emitter');
requireContains(browserSpec, 'realtime_owner_absence.php', 'browser spec reads backend owner-absence contract constants');
requireContains(browserSpec, 'e2e_journey_024_owner_absence_countdown_then_auto_end', 'browser auto-end journey proof');
requireContains(browserSpec, 'e2e_journey_025_owner_absence_countdown_then_reconnect_cancels_end', 'browser owner-return journey proof');
requireContains(browserSpec, 'e2e_end_implicit_008_countdown_synchronized_across_participants', 'browser synchronized countdown proof');
requireContains(browserSpec, 'e2e_end_implicit_009_countdown_survives_participant_refresh', 'browser refresh countdown proof');
requireContains(browserSpec, "owner_absent_timeout", 'browser spec asserts owner absence end reason');
requireContains(browserSpec, 'A countdown will appear if they do not return.', 'browser timer-start participant notification proof');
requireContains(browserSpec, "countdown_remaining_ms: ownerAbsenceContract.countdownMs - 60_000", 'browser spec asserts countdown update');
requireContains(browserSpec, 'This call link cannot be used for the current call state.', 'browser late-user ended state proof');
requireContains(packageJson, 'tests/e2e/call-access-owner-absence-browser.spec.js', 'focused call-access command includes owner-absence browser proof');

process.stdout.write('[iam-king-participants-owner-timeout-contract] PASS\n');
