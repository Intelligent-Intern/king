<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../domain/realtime/realtime_chat.php';
require_once __DIR__ . '/../domain/realtime/operator_feedback.php';
require_once __DIR__ . '/../http/module_operator_feedback.php';

function videochat_operator_feedback_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[operator-feedback-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_operator_feedback_contract_response(int $status, array $payload): array
{
    return [
        'status' => $status,
        'headers' => ['content-type' => 'application/json'],
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
}

function videochat_operator_feedback_contract_error(int $status, string $code, string $message, array $details = []): array
{
    return videochat_operator_feedback_contract_response($status, [
        'status' => 'error',
        'error' => [
            'code' => $code,
            'message' => $message,
            'details' => $details,
        ],
        'time' => gmdate('c'),
    ]);
}

function videochat_operator_feedback_contract_decode(array $request): array
{
    $body = $request['body'] ?? '';
    $decoded = is_string($body) ? json_decode($body, true) : null;

    return is_array($decoded) ? [$decoded, null] : [null, 'invalid_json'];
}

function videochat_operator_feedback_contract_body(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function videochat_operator_feedback_contract_auth(PDO $pdo, int $userId, string $role): array
{
    $tenant = videochat_tenant_context_for_user($pdo, $userId);
    videochat_operator_feedback_contract_assert(is_array($tenant), 'tenant context missing for auth fixture');

    return [
        'ok' => true,
        'token' => 'sess_operator_feedback_' . $userId,
        'user' => ['id' => $userId, 'role' => $role, 'status' => 'active'],
        'session' => ['id' => 'sess_operator_feedback_' . $userId],
        'tenant' => videochat_tenant_auth_payload($tenant),
    ];
}

try {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        fwrite(STDOUT, "[operator-feedback-contract] SKIP: PDO sqlite driver not available\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-operator-feedback-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);
    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    videochat_operator_feedback_bootstrap($pdo);

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'admin' LIMIT 1")->fetchColumn();
    $userRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1")->fetchColumn();
    $organizationId = (int) $pdo->query('SELECT id FROM organizations WHERE tenant_id = ' . $tenantId . ' ORDER BY id ASC LIMIT 1')->fetchColumn();
    videochat_operator_feedback_contract_assert($tenantId > 0 && $adminRoleId > 0 && $userRoleId > 0 && $organizationId > 0, 'seed fixtures missing');

    $now = gmdate('c');
    $adminUserId = 9700;
    $ownerUserId = 9701;
    $participantUserId = 9702;
    $otherUserId = 9703;
    $callId = 'operator-feedback-call';
    $roomId = 'operator-feedback-room';

    $insertUser = $pdo->prepare(
        <<<'SQL'
INSERT OR REPLACE INTO users(id, email, display_name, password_hash, role_id, status, created_at, updated_at)
VALUES(:id, :email, :display_name, :password_hash, :role_id, 'active', :created_at, :updated_at)
SQL
    );
    foreach ([
        [$adminUserId, 'operator-feedback-admin@example.test', 'Feedback Admin', $adminRoleId],
        [$ownerUserId, 'operator-feedback-owner@example.test', 'Feedback Owner', $userRoleId],
        [$participantUserId, 'operator-feedback-user@example.test', 'Feedback User', $userRoleId],
        [$otherUserId, 'operator-feedback-other@example.test', 'Feedback Other', $userRoleId],
    ] as [$id, $email, $displayName, $roleId]) {
        $insertUser->execute([
            ':id' => $id,
            ':email' => $email,
            ':display_name' => $displayName,
            ':password_hash' => password_hash('pw', PASSWORD_DEFAULT),
            ':role_id' => $roleId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        videochat_tenant_attach_user($pdo, $id, $tenantId, $roleId === $adminRoleId ? 'admin' : 'member');
    }

    $pdo->prepare(
        <<<'SQL'
INSERT OR IGNORE INTO organization_memberships(tenant_id, organization_id, user_id, membership_role, status, created_at, updated_at)
VALUES(:tenant_id, :organization_id, :user_id, 'member', 'active', :created_at, :updated_at)
SQL
    )->execute([
        ':tenant_id' => $tenantId,
        ':organization_id' => $organizationId,
        ':user_id' => $participantUserId,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $pdo->prepare(
        <<<'SQL'
INSERT OR REPLACE INTO rooms(id, tenant_id, name, visibility, status, created_by_user_id, created_at, updated_at)
VALUES(:id, :tenant_id, 'Operator Feedback Room', 'private', 'active', :owner_user_id, :created_at, :updated_at)
SQL
    )->execute([
        ':id' => $roomId,
        ':tenant_id' => $tenantId,
        ':owner_user_id' => $ownerUserId,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $pdo->prepare(
        <<<'SQL'
INSERT OR REPLACE INTO calls(id, tenant_id, room_id, title, access_mode, owner_user_id, status, starts_at, ends_at, created_at, updated_at)
VALUES(:id, :tenant_id, :room_id, 'Operator Feedback Call', 'invite_only', :owner_user_id, 'active', :starts_at, :ends_at, :created_at, :updated_at)
SQL
    )->execute([
        ':id' => $callId,
        ':tenant_id' => $tenantId,
        ':room_id' => $roomId,
        ':owner_user_id' => $ownerUserId,
        ':starts_at' => $now,
        ':ends_at' => gmdate('c', time() + 3600),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $insertParticipant = $pdo->prepare(
        <<<'SQL'
INSERT OR REPLACE INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at)
VALUES(:call_id, :user_id, :email, :display_name, 'internal', :call_role, 'allowed', :joined_at, NULL)
SQL
    );
    foreach ([
        [$ownerUserId, 'operator-feedback-owner@example.test', 'Feedback Owner', 'owner'],
        [$participantUserId, 'operator-feedback-user@example.test', 'Feedback User', 'participant'],
    ] as [$userId, $email, $displayName, $callRole]) {
        $insertParticipant->execute([
            ':call_id' => $callId,
            ':user_id' => $userId,
            ':email' => $email,
            ':display_name' => $displayName,
            ':call_role' => $callRole,
            ':joined_at' => $now,
        ]);
    }

    $state = videochat_presence_state_init();
    $connection = videochat_presence_connection_descriptor(
        [
            'id' => $participantUserId,
            'display_name' => 'Feedback User',
            'role' => 'user',
        ],
        'sess-feedback-user',
        'conn-feedback-user',
        'socket-feedback-user',
        $roomId
    );
    $connection['active_call_id'] = $callId;
    $join = videochat_presence_join_room($state, $connection, $roomId, static fn (): bool => true);
    $connection = (array) ($join['connection'] ?? $connection);
    $connection['active_call_id'] = $callId;
    $connection['tenant_id'] = $tenantId;
    $connection['session_id'] = 'sess-feedback-user';

    $command = videochat_chat_decode_client_frame(json_encode([
        'type' => 'chat/send',
        'message' => 'Please add a better planning image zoom mode',
        'client_message_id' => 'feedback-client-001',
        'operator_feedback' => true,
    ], JSON_UNESCAPED_SLASHES));
    videochat_operator_feedback_contract_assert((bool) ($command['ok'] ?? false), 'operator feedback chat frame should decode');
    videochat_operator_feedback_contract_assert((bool) ($command['operator_feedback'] ?? false), 'operator feedback flag should normalize');

    $publish = videochat_chat_publish($state, $connection, $command, static fn (): bool => true, 1_779_000_001_000);
    videochat_operator_feedback_contract_assert((bool) ($publish['ok'] ?? false), 'normal chat publish should still succeed');
    $persist = videochat_operator_feedback_persist_from_chat_event($pdo, $connection, (array) ($publish['event'] ?? []), $command);
    videochat_operator_feedback_contract_assert((bool) ($persist['ok'] ?? false), 'flagged chat message should persist as operator feedback');
    $feedback = is_array($persist['feedback'] ?? null) ? $persist['feedback'] : [];
    $feedbackId = (string) ($feedback['id'] ?? '');
    videochat_operator_feedback_contract_assert($feedbackId !== '', 'feedback public id missing');
    videochat_operator_feedback_contract_assert((string) ($feedback['status'] ?? '') === 'open', 'new feedback status should be open');
    videochat_operator_feedback_contract_assert((string) ($feedback['call_id'] ?? '') === $callId, 'feedback call id mismatch');
    videochat_operator_feedback_contract_assert((string) ($feedback['room_id'] ?? '') === $roomId, 'feedback room id mismatch');
    videochat_operator_feedback_contract_assert((string) ($feedback['client_message_id'] ?? '') === 'feedback-client-001', 'feedback client message id mismatch');
    videochat_operator_feedback_contract_assert((string) (($feedback['organization'] ?? [])['public_id'] ?? '') !== '', 'organization context should be captured');

    $participantAuth = videochat_operator_feedback_contract_auth($pdo, $participantUserId, 'user');
    $ownerAuth = videochat_operator_feedback_contract_auth($pdo, $ownerUserId, 'user');
    $adminAuth = videochat_operator_feedback_contract_auth($pdo, $adminUserId, 'admin');
    $otherAuth = videochat_operator_feedback_contract_auth($pdo, $otherUserId, 'user');
    $jsonResponse = 'videochat_operator_feedback_contract_response';
    $errorResponse = 'videochat_operator_feedback_contract_error';
    $decodeBody = 'videochat_operator_feedback_contract_decode';
    $openDatabase = static fn (): PDO => $pdo;

    $participantQueue = videochat_handle_operator_feedback_routes(
        '/api/calls/operator-feedback',
        'GET',
        ['uri' => '/api/calls/operator-feedback'],
        $participantAuth,
        $jsonResponse,
        $errorResponse,
        $decodeBody,
        $openDatabase
    );
    videochat_operator_feedback_contract_assert((int) ($participantQueue['status'] ?? 0) === 403, 'participant must not read global queue');

    $adminQueue = videochat_handle_operator_feedback_routes(
        '/api/calls/operator-feedback',
        'GET',
        ['uri' => '/api/calls/operator-feedback?status=open'],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeBody,
        $openDatabase
    );
    videochat_operator_feedback_contract_assert((int) ($adminQueue['status'] ?? 0) === 200, 'admin should read global queue');
    $adminQueueBody = videochat_operator_feedback_contract_body($adminQueue);
    videochat_operator_feedback_contract_assert((int) (((($adminQueueBody['result'] ?? [])['queue'] ?? [])['pagination'] ?? [])['total'] ?? 0) >= 1, 'admin queue should include feedback');

    $ownerCallQueue = videochat_handle_operator_feedback_routes(
        '/api/calls/' . $callId . '/operator-feedback',
        'GET',
        ['uri' => '/api/calls/' . $callId . '/operator-feedback?status=all'],
        $ownerAuth,
        $jsonResponse,
        $errorResponse,
        $decodeBody,
        $openDatabase
    );
    videochat_operator_feedback_contract_assert((int) ($ownerCallQueue['status'] ?? 0) === 200, 'call owner should read call feedback queue');

    $otherCallQueue = videochat_handle_operator_feedback_routes(
        '/api/calls/' . $callId . '/operator-feedback',
        'GET',
        ['uri' => '/api/calls/' . $callId . '/operator-feedback?status=all'],
        $otherAuth,
        $jsonResponse,
        $errorResponse,
        $decodeBody,
        $openDatabase
    );
    videochat_operator_feedback_contract_assert((int) ($otherCallQueue['status'] ?? 0) === 403, 'non-participant must not read call feedback queue');

    $restCreate = videochat_handle_operator_feedback_routes(
        '/api/calls/' . $callId . '/operator-feedback',
        'POST',
        [
            'uri' => '/api/calls/' . $callId . '/operator-feedback',
            'body' => json_encode(['message_text' => 'REST fallback feedback', 'client_message_id' => 'rest-feedback-001'], JSON_UNESCAPED_SLASHES),
        ],
        $participantAuth,
        $jsonResponse,
        $errorResponse,
        $decodeBody,
        $openDatabase
    );
    videochat_operator_feedback_contract_assert((int) ($restCreate['status'] ?? 0) === 201, 'REST feedback create should persist');

    $patch = videochat_handle_operator_feedback_routes(
        '/api/calls/operator-feedback/' . $feedbackId,
        'PATCH',
        [
            'uri' => '/api/calls/operator-feedback/' . $feedbackId,
            'body' => json_encode([
                'status' => 'deployed',
                'toast_feature_label' => 'planning image zoom mode',
                'sprint_ticket_ref' => 'OCA-04',
            ], JSON_UNESCAPED_SLASHES),
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeBody,
        $openDatabase
    );
    videochat_operator_feedback_contract_assert((int) ($patch['status'] ?? 0) === 200, 'admin should mark feedback deployed');
    $patchBody = videochat_operator_feedback_contract_body($patch);
    $patchedFeedback = (($patchBody['result'] ?? [])['feedback'] ?? []);
    videochat_operator_feedback_contract_assert((string) ($patchedFeedback['status'] ?? '') === 'deployed', 'feedback status should be deployed');
    videochat_operator_feedback_contract_assert((string) ((($patchedFeedback['toast'] ?? [])['feature_label'] ?? '')) === 'planning image zoom mode', 'toast feature label mismatch');

    $toasts = videochat_handle_operator_feedback_routes(
        '/api/calls/' . $callId . '/operator-feedback/toasts',
        'GET',
        ['uri' => '/api/calls/' . $callId . '/operator-feedback/toasts'],
        $participantAuth,
        $jsonResponse,
        $errorResponse,
        $decodeBody,
        $openDatabase
    );
    videochat_operator_feedback_contract_assert((int) ($toasts['status'] ?? 0) === 200, 'sender should read pending deployed toasts');
    $toastBody = videochat_operator_feedback_contract_body($toasts);
    $toastRows = (($toastBody['result'] ?? [])['toasts'] ?? []);
    videochat_operator_feedback_contract_assert(count($toastRows) === 1, 'exactly one pending toast expected');
    videochat_operator_feedback_contract_assert((string) (($toastRows[0] ?? [])['message'] ?? '') === "feature 'planning image zoom mode' deployed", 'toast message mismatch');

    $delivered = videochat_handle_operator_feedback_routes(
        '/api/calls/' . $callId . '/operator-feedback/' . $feedbackId . '/toast-delivered',
        'POST',
        ['uri' => '/api/calls/' . $callId . '/operator-feedback/' . $feedbackId . '/toast-delivered'],
        $participantAuth,
        $jsonResponse,
        $errorResponse,
        $decodeBody,
        $openDatabase
    );
    videochat_operator_feedback_contract_assert((int) ($delivered['status'] ?? 0) === 200, 'sender should mark deployed toast delivered');

    $toastsAfter = videochat_handle_operator_feedback_routes(
        '/api/calls/' . $callId . '/operator-feedback/toasts',
        'GET',
        ['uri' => '/api/calls/' . $callId . '/operator-feedback/toasts'],
        $participantAuth,
        $jsonResponse,
        $errorResponse,
        $decodeBody,
        $openDatabase
    );
    $toastsAfterBody = videochat_operator_feedback_contract_body($toastsAfter);
    videochat_operator_feedback_contract_assert(count((($toastsAfterBody['result'] ?? [])['toasts'] ?? [])) === 0, 'delivered toast should not be returned again');

    @unlink($databasePath);
    @unlink($databasePath . '-wal');
    @unlink($databasePath . '-shm');
    @unlink($databasePath . '.bootstrap.lock');
    fwrite(STDOUT, "[operator-feedback-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, "[operator-feedback-contract] ERROR: {$error->getMessage()}\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
}
