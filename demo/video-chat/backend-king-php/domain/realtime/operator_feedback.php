<?php

declare(strict_types=1);

require_once __DIR__ . '/../../support/auth_rbac.php';
require_once __DIR__ . '/../../support/operator_feedback_migrations.php';
require_once __DIR__ . '/../../support/tenant_context.php';
require_once __DIR__ . '/../calls/call_management.php';

function videochat_operator_feedback_bootstrap(PDO $pdo): void
{
    foreach (videochat_operator_feedback_migration_statements() as $sql) {
        $pdo->exec($sql);
    }
}

function videochat_operator_feedback_public_id(): string
{
    if (function_exists('videochat_generate_call_id')) {
        return videochat_generate_call_id();
    }

    return strtolower(bin2hex(random_bytes(16)));
}

function videochat_operator_feedback_table_exists(PDO $pdo, string $tableName): bool
{
    $query = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1");
    $query->execute([':name' => $tableName]);

    return $query->fetchColumn() !== false;
}

function videochat_operator_feedback_column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    if (function_exists('videochat_tenant_table_has_column')) {
        return videochat_tenant_table_has_column($pdo, $tableName, $columnName);
    }

    $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $tableName);
    if (!is_string($safeTable) || $safeTable === '') {
        return false;
    }

    $columns = $pdo->query('PRAGMA table_info(' . $safeTable . ')');
    foreach ($columns ?: [] as $column) {
        if (strcasecmp((string) ($column['name'] ?? ''), $columnName) === 0) {
            return true;
        }
    }

    return false;
}

function videochat_operator_feedback_truthy(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value === 1;
    }
    if (is_string($value)) {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on', 'operator'], true);
    }

    return false;
}

function videochat_operator_feedback_requested_from_payload(array $payload): bool
{
    foreach (['operator_feedback', 'operatorFeedback', 'feedback_operator', 'send_to_operator'] as $key) {
        if (array_key_exists($key, $payload) && videochat_operator_feedback_truthy($payload[$key])) {
            return true;
        }
    }

    foreach (['feedback', 'metadata'] as $containerKey) {
        $container = $payload[$containerKey] ?? null;
        if (!is_array($container)) {
            continue;
        }
        foreach (['operator', 'operator_feedback', 'operatorFeedback'] as $key) {
            if (array_key_exists($key, $container) && videochat_operator_feedback_truthy($container[$key])) {
                return true;
            }
        }
        if (strtolower(trim((string) ($container['type'] ?? ''))) === 'operator') {
            return true;
        }
    }

    return false;
}

function videochat_operator_feedback_normalize_status(mixed $status, string $fallback = 'open'): string
{
    $fallback = in_array($fallback, ['open', 'in_progress', 'deployed'], true) ? $fallback : 'open';
    $normalized = strtolower(trim((string) $status));

    return in_array($normalized, ['open', 'in_progress', 'deployed'], true) ? $normalized : $fallback;
}

function videochat_operator_feedback_text_excerpt(string $text, int $limit = 80): string
{
    $normalized = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    if ($normalized === '') {
        return 'requested feature';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($normalized, 'UTF-8') > $limit
            ? rtrim(mb_substr($normalized, 0, $limit - 1, 'UTF-8')) . '...'
            : $normalized;
    }

    return strlen($normalized) > $limit ? rtrim(substr($normalized, 0, $limit - 1)) . '...' : $normalized;
}

function videochat_operator_feedback_auth_user(array $authContext): array
{
    $user = is_array($authContext['user'] ?? null) ? $authContext['user'] : [];
    $tenant = is_array($authContext['tenant'] ?? null) ? $authContext['tenant'] : [];
    $permissions = is_array($tenant['permissions'] ?? null) ? (array) $tenant['permissions'] : [];

    return [
        'user_id' => (int) ($user['id'] ?? 0),
        'role' => videochat_normalize_role_slug((string) ($user['role'] ?? 'user')),
        'tenant_id' => videochat_tenant_id_from_auth_context($authContext),
        'permissions' => $permissions,
    ];
}

function videochat_operator_feedback_can_manage_queue(array $authContext): bool
{
    $auth = videochat_operator_feedback_auth_user($authContext);
    if ((int) ($auth['user_id'] ?? 0) <= 0) {
        return false;
    }
    if ((string) ($auth['role'] ?? '') === 'admin') {
        return true;
    }

    $permissions = is_array($auth['permissions'] ?? null) ? $auth['permissions'] : [];
    foreach (['platform_admin', 'tenant_admin', 'operator_feedback', 'manage_feedback', 'manage_organizations'] as $permission) {
        if ((bool) ($permissions[$permission] ?? false)) {
            return true;
        }
    }

    return false;
}

function videochat_operator_feedback_sender_organization(PDO $pdo, int $tenantId, int $userId): array
{
    if (
        $tenantId <= 0
        || $userId <= 0
        || !videochat_operator_feedback_table_exists($pdo, 'organizations')
        || !videochat_operator_feedback_table_exists($pdo, 'organization_memberships')
    ) {
        return ['id' => null, 'public_id' => '', 'name' => ''];
    }

    $query = $pdo->prepare(
        <<<'SQL'
SELECT organizations.id, organizations.public_id, organizations.name
FROM organization_memberships
INNER JOIN organizations
    ON organizations.id = organization_memberships.organization_id
   AND organizations.tenant_id = organization_memberships.tenant_id
WHERE organization_memberships.tenant_id = :tenant_id
  AND organization_memberships.user_id = :user_id
  AND organization_memberships.status = 'active'
  AND organizations.status = 'active'
ORDER BY
  CASE organization_memberships.membership_role WHEN 'admin' THEN 0 ELSE 1 END ASC,
  organizations.id ASC
LIMIT 1
SQL
    );
    $query->execute([':tenant_id' => $tenantId, ':user_id' => $userId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['id' => null, 'public_id' => '', 'name' => ''];
    }

    return [
        'id' => (int) ($row['id'] ?? 0) > 0 ? (int) $row['id'] : null,
        'public_id' => (string) ($row['public_id'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
    ];
}

function videochat_operator_feedback_call_context(PDO $pdo, string $callId, int $actorUserId, string $actorRole, ?int $tenantId = null): array
{
    $normalizedCallId = trim($callId);
    if ($normalizedCallId === '' || preg_match('/^[A-Za-z0-9._-]{1,200}$/', $normalizedCallId) !== 1 || $actorUserId <= 0) {
        return ['ok' => false, 'reason' => 'validation_failed', 'errors' => ['call_id' => 'invalid_call_id'], 'context' => null];
    }

    $hasTenantColumn = videochat_operator_feedback_column_exists($pdo, 'calls', 'tenant_id');
    $tenantSelect = $hasTenantColumn ? 'calls.tenant_id AS tenant_id,' : '0 AS tenant_id,';
    $tenantWhere = $hasTenantColumn && is_int($tenantId) && $tenantId > 0 ? 'AND calls.tenant_id = :tenant_id' : '';
    $query = $pdo->prepare(
        <<<SQL
SELECT
    calls.id,
    calls.room_id,
    calls.owner_user_id,
    calls.status,
    calls.access_mode,
    {$tenantSelect}
    cp.call_role,
    cp.invite_state
FROM calls
LEFT JOIN call_participants cp
    ON cp.call_id = calls.id
   AND cp.user_id = :actor_user_id
   AND cp.source = 'internal'
WHERE calls.id = :call_id
  {$tenantWhere}
LIMIT 1
SQL
    );
    $params = [':call_id' => $normalizedCallId, ':actor_user_id' => $actorUserId];
    if ($tenantWhere !== '') {
        $params[':tenant_id'] = (int) $tenantId;
    }
    $query->execute($params);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['ok' => false, 'reason' => 'not_found', 'errors' => [], 'context' => null];
    }

    $isSystemAdmin = videochat_user_has_system_admin_call_rights($pdo, $actorUserId, $actorRole);
    $isOwner = (int) ($row['owner_user_id'] ?? 0) === $actorUserId;
    $callRole = videochat_normalize_call_participant_role((string) ($row['call_role'] ?? 'participant'));
    $isParticipant = is_string($row['call_role'] ?? null) && trim((string) $row['call_role']) !== '';
    $isFreeForAll = videochat_normalize_call_access_mode($row['access_mode'] ?? 'invite_only') === 'free_for_all';
    $callTenantId = (int) ($row['tenant_id'] ?? 0);
    $isOrganizationAdmin = videochat_user_is_organization_admin_for_call(
        $pdo,
        $row,
        $actorUserId,
        is_int($tenantId) && $tenantId > 0 ? $tenantId : null
    );
    if (!$isSystemAdmin && !$isOwner && !$isParticipant && !$isFreeForAll && !$isOrganizationAdmin) {
        return ['ok' => false, 'reason' => 'forbidden', 'errors' => [], 'context' => null];
    }

    return [
        'ok' => true,
        'reason' => 'ok',
        'errors' => [],
        'context' => [
            'call_id' => (string) ($row['id'] ?? $normalizedCallId),
            'room_id' => (string) ($row['room_id'] ?? ''),
            'tenant_id' => $callTenantId,
            'owner_user_id' => (int) ($row['owner_user_id'] ?? 0),
            'call_role' => $isOwner ? 'owner' : $callRole,
            'can_manage' => $isSystemAdmin || $isOwner || $callRole === 'moderator' || $isOrganizationAdmin,
            'organization' => videochat_operator_feedback_sender_organization($pdo, $callTenantId, $actorUserId),
        ],
    ];
}

function videochat_operator_feedback_connection_call_id(array $connection): string
{
    if (function_exists('videochat_realtime_connection_call_id')) {
        return videochat_realtime_connection_call_id($connection);
    }

    foreach (['active_call_id', 'requested_call_id', 'call_id'] as $key) {
        $value = trim((string) ($connection[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function videochat_operator_feedback_insert(PDO $pdo, array $data): array
{
    videochat_operator_feedback_bootstrap($pdo);
    $now = gmdate('c');
    $messageText = trim((string) ($data['message_text'] ?? ''));
    if ($messageText === '') {
        return ['ok' => false, 'reason' => 'validation_failed', 'errors' => ['message_text' => 'required'], 'feedback' => null];
    }

    $metadataJson = json_encode(is_array($data['metadata'] ?? null) ? $data['metadata'] : [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($metadataJson) || $metadataJson === '') {
        $metadataJson = '{}';
    }

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT OR IGNORE INTO call_operator_feedback(
    public_id, tenant_id, organization_id, organization_public_id, organization_name,
    call_id, room_id, sender_user_id, session_id, client_message_id, chat_message_id,
    message_text, status, toast_feature_label, metadata_json, created_at, updated_at
) VALUES(
    :public_id, :tenant_id, :organization_id, :organization_public_id, :organization_name,
    :call_id, :room_id, :sender_user_id, :session_id, :client_message_id, :chat_message_id,
    :message_text, 'open', :toast_feature_label, :metadata_json, :created_at, :updated_at
)
SQL
    );
    $insert->execute([
        ':public_id' => (string) ($data['public_id'] ?? videochat_operator_feedback_public_id()),
        ':tenant_id' => (int) ($data['tenant_id'] ?? 0) > 0 ? (int) $data['tenant_id'] : null,
        ':organization_id' => (int) ($data['organization_id'] ?? 0) > 0 ? (int) $data['organization_id'] : null,
        ':organization_public_id' => (string) ($data['organization_public_id'] ?? ''),
        ':organization_name' => (string) ($data['organization_name'] ?? ''),
        ':call_id' => (string) ($data['call_id'] ?? ''),
        ':room_id' => (string) ($data['room_id'] ?? ''),
        ':sender_user_id' => (int) ($data['sender_user_id'] ?? 0),
        ':session_id' => (string) ($data['session_id'] ?? ''),
        ':client_message_id' => (string) ($data['client_message_id'] ?? ''),
        ':chat_message_id' => (string) ($data['chat_message_id'] ?? ''),
        ':message_text' => $messageText,
        ':toast_feature_label' => videochat_operator_feedback_text_excerpt($messageText),
        ':metadata_json' => $metadataJson,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return videochat_operator_feedback_fetch_by_message($pdo, (string) ($data['chat_message_id'] ?? ''));
}

function videochat_operator_feedback_fetch_by_message(PDO $pdo, string $chatMessageId): array
{
    $query = $pdo->prepare('SELECT * FROM call_operator_feedback WHERE chat_message_id = :chat_message_id LIMIT 1');
    $query->execute([':chat_message_id' => $chatMessageId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['ok' => false, 'reason' => 'insert_ignored', 'errors' => [], 'feedback' => null];
    }

    return ['ok' => true, 'reason' => 'stored', 'errors' => [], 'feedback' => videochat_operator_feedback_public_payload($row)];
}

function videochat_operator_feedback_persist_from_chat_event(PDO $pdo, array $connection, array $event, array $command): array
{
    if (!videochat_operator_feedback_requested_from_payload($command)) {
        return ['ok' => true, 'reason' => 'not_requested', 'errors' => [], 'feedback' => null];
    }

    $message = is_array($event['message'] ?? null) ? $event['message'] : [];
    $callId = videochat_operator_feedback_connection_call_id($connection);
    $actorUserId = (int) ($connection['user_id'] ?? 0);
    $actorRole = (string) ($connection['role'] ?? 'user');
    $context = videochat_operator_feedback_call_context(
        $pdo,
        $callId,
        $actorUserId,
        $actorRole,
        is_numeric($connection['tenant_id'] ?? null) ? (int) $connection['tenant_id'] : null
    );
    if (!(bool) ($context['ok'] ?? false) || !is_array($context['context'] ?? null)) {
        return $context + ['feedback' => null];
    }

    $callContext = $context['context'];
    $roomId = (string) ($event['room_id'] ?? ($connection['room_id'] ?? ''));
    if ($roomId === '' || $roomId !== (string) ($callContext['room_id'] ?? '')) {
        return ['ok' => false, 'reason' => 'validation_failed', 'errors' => ['room_id' => 'call_room_mismatch'], 'feedback' => null];
    }

    $organization = is_array($callContext['organization'] ?? null) ? $callContext['organization'] : [];
    return videochat_operator_feedback_insert($pdo, [
        'tenant_id' => (int) ($callContext['tenant_id'] ?? 0),
        'organization_id' => (int) ($organization['id'] ?? 0),
        'organization_public_id' => (string) ($organization['public_id'] ?? ''),
        'organization_name' => (string) ($organization['name'] ?? ''),
        'call_id' => (string) ($callContext['call_id'] ?? $callId),
        'room_id' => $roomId,
        'sender_user_id' => $actorUserId,
        'session_id' => (string) ($connection['session_id'] ?? ''),
        'client_message_id' => (string) ($message['client_message_id'] ?? ($command['client_message_id'] ?? '')),
        'chat_message_id' => (string) ($message['id'] ?? videochat_operator_feedback_public_id()),
        'message_text' => (string) ($message['text'] ?? ''),
        'metadata' => [
            'source' => 'call_chat',
            'attachment_count' => count(is_array($message['attachments'] ?? null) ? $message['attachments'] : []),
        ],
    ]);
}

function videochat_operator_feedback_create_from_payload(PDO $pdo, string $callId, array $authContext, array $payload): array
{
    $auth = videochat_operator_feedback_auth_user($authContext);
    $context = videochat_operator_feedback_call_context($pdo, $callId, (int) $auth['user_id'], (string) $auth['role'], (int) $auth['tenant_id']);
    if (!(bool) ($context['ok'] ?? false) || !is_array($context['context'] ?? null)) {
        return $context + ['feedback' => null];
    }

    $callContext = $context['context'];
    $messageText = (string) ($payload['message_text'] ?? ($payload['text'] ?? ($payload['message'] ?? '')));
    $organization = is_array($callContext['organization'] ?? null) ? $callContext['organization'] : [];
    $clientMessageId = trim((string) ($payload['client_message_id'] ?? ''));
    $chatMessageId = trim((string) ($payload['chat_message_id'] ?? ($payload['message_id'] ?? '')));
    if ($chatMessageId === '') {
        $chatMessageId = 'operator_feedback_' . substr(hash('sha256', $callId . "\n" . (int) $auth['user_id'] . "\n" . $clientMessageId . "\n" . $messageText), 0, 32);
    }

    return videochat_operator_feedback_insert($pdo, [
        'tenant_id' => (int) ($callContext['tenant_id'] ?? 0),
        'organization_id' => (int) ($organization['id'] ?? 0),
        'organization_public_id' => (string) ($organization['public_id'] ?? ''),
        'organization_name' => (string) ($organization['name'] ?? ''),
        'call_id' => (string) ($callContext['call_id'] ?? $callId),
        'room_id' => (string) ($callContext['room_id'] ?? ''),
        'sender_user_id' => (int) $auth['user_id'],
        'session_id' => (string) ($payload['session_id'] ?? (($authContext['session'] ?? [])['id'] ?? '')),
        'client_message_id' => $clientMessageId,
        'chat_message_id' => $chatMessageId,
        'message_text' => $messageText,
        'metadata' => ['source' => 'rest'],
    ]);
}

function videochat_operator_feedback_public_payload(array $row): array
{
    return [
        'id' => (string) ($row['public_id'] ?? ''),
        'tenant_id' => is_numeric($row['tenant_id'] ?? null) ? (int) $row['tenant_id'] : null,
        'organization' => [
            'id' => is_numeric($row['organization_id'] ?? null) ? (int) $row['organization_id'] : null,
            'public_id' => (string) ($row['organization_public_id'] ?? ''),
            'name' => (string) ($row['organization_name'] ?? ''),
        ],
        'call_id' => (string) ($row['call_id'] ?? ''),
        'room_id' => (string) ($row['room_id'] ?? ''),
        'sender_user_id' => (int) ($row['sender_user_id'] ?? 0),
        'session_id' => (string) ($row['session_id'] ?? ''),
        'client_message_id' => (string) ($row['client_message_id'] ?? ''),
        'chat_message_id' => (string) ($row['chat_message_id'] ?? ''),
        'message_text' => (string) ($row['message_text'] ?? ''),
        'status' => videochat_operator_feedback_normalize_status($row['status'] ?? 'open'),
        'triage_notes' => (string) ($row['triage_notes'] ?? ''),
        'sprint_ticket_ref' => (string) ($row['sprint_ticket_ref'] ?? ''),
        'toast' => [
            'feature_label' => (string) ($row['toast_feature_label'] ?? ''),
            'deployed_at' => is_string($row['deployed_at'] ?? null) ? (string) $row['deployed_at'] : null,
            'delivered_at' => is_string($row['toast_delivered_at'] ?? null) ? (string) $row['toast_delivered_at'] : null,
            'delivered_to_user_id' => is_numeric($row['toast_delivered_to_user_id'] ?? null) ? (int) $row['toast_delivered_to_user_id'] : null,
        ],
        'created_at' => (string) ($row['created_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

function videochat_operator_feedback_list_queue(PDO $pdo, array $authContext, array $queryParams = [], ?string $callId = null): array
{
    videochat_operator_feedback_bootstrap($pdo);
    $auth = videochat_operator_feedback_auth_user($authContext);
    $isQueueManager = videochat_operator_feedback_can_manage_queue($authContext);
    $callContext = null;
    if (is_string($callId) && trim($callId) !== '') {
        $context = videochat_operator_feedback_call_context($pdo, $callId, (int) $auth['user_id'], (string) $auth['role'], (int) $auth['tenant_id']);
        if (!(bool) ($context['ok'] ?? false) || !is_array($context['context'] ?? null)) {
            return $context + ['queue' => null];
        }
        $callContext = $context['context'];
        if (!$isQueueManager && !(bool) ($callContext['can_manage'] ?? false)) {
            return ['ok' => false, 'reason' => 'forbidden', 'errors' => ['scope' => 'operator_queue_manager_required'], 'queue' => null];
        }
    } elseif (!$isQueueManager) {
        return ['ok' => false, 'reason' => 'forbidden', 'errors' => ['scope' => 'operator_queue_manager_required'], 'queue' => null];
    }

    $status = strtolower(trim((string) ($queryParams['status'] ?? 'open')));
    $page = max(1, (int) ($queryParams['page'] ?? 1));
    $pageSize = max(1, min(100, (int) ($queryParams['page_size'] ?? ($queryParams['limit'] ?? 25))));
    $offset = ($page - 1) * $pageSize;
    $where = [];
    $params = [];
    if ($status !== '' && $status !== 'all') {
        $where[] = 'status = :status';
        $params[':status'] = videochat_operator_feedback_normalize_status($status);
    }
    if (is_array($callContext)) {
        $where[] = 'call_id = :call_id';
        $params[':call_id'] = (string) ($callContext['call_id'] ?? $callId);
    } elseif ((int) $auth['tenant_id'] > 0) {
        $where[] = 'tenant_id = :tenant_id';
        $params[':tenant_id'] = (int) $auth['tenant_id'];
    }
    $whereSql = $where === [] ? '1 = 1' : implode(' AND ', $where);

    $count = $pdo->prepare("SELECT COUNT(*) FROM call_operator_feedback WHERE {$whereSql}");
    $count->execute($params);
    $total = (int) ($count->fetchColumn() ?: 0);

    $query = $pdo->prepare(
        "SELECT * FROM call_operator_feedback WHERE {$whereSql} ORDER BY created_at ASC, id ASC LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $key => $value) {
        $query->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $query->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $query->bindValue(':offset', $offset, PDO::PARAM_INT);
    $query->execute();

    $rows = [];
    while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (is_array($row)) {
            $rows[] = videochat_operator_feedback_public_payload($row);
        }
    }

    return [
        'ok' => true,
        'reason' => 'ok',
        'errors' => [],
        'queue' => [
            'items' => $rows,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'page_count' => (int) ceil($total / $pageSize),
                'returned' => count($rows),
            ],
        ],
    ];
}

function videochat_operator_feedback_update_status(PDO $pdo, string $feedbackId, array $authContext, array $payload): array
{
    videochat_operator_feedback_bootstrap($pdo);
    if (!videochat_operator_feedback_can_manage_queue($authContext)) {
        return ['ok' => false, 'reason' => 'forbidden', 'errors' => ['scope' => 'operator_queue_manager_required'], 'feedback' => null];
    }

    $auth = videochat_operator_feedback_auth_user($authContext);
    $status = videochat_operator_feedback_normalize_status($payload['status'] ?? '');
    $notes = trim((string) ($payload['triage_notes'] ?? ($payload['notes'] ?? '')));
    $ticket = trim((string) ($payload['sprint_ticket_ref'] ?? ($payload['ticket'] ?? '')));
    $rowQuery = $pdo->prepare('SELECT * FROM call_operator_feedback WHERE public_id = :public_id LIMIT 1');
    $rowQuery->execute([':public_id' => trim($feedbackId)]);
    $row = $rowQuery->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || ((int) $auth['tenant_id'] > 0 && (int) ($row['tenant_id'] ?? 0) !== (int) $auth['tenant_id']) && (string) $auth['role'] !== 'admin') {
        return ['ok' => false, 'reason' => 'not_found', 'errors' => [], 'feedback' => null];
    }

    $featureLabel = trim((string) ($payload['toast_feature_label'] ?? ($payload['feature_label'] ?? '')));
    if ($featureLabel === '') {
        $featureLabel = trim((string) ($row['toast_feature_label'] ?? ''));
    }
    if ($featureLabel === '') {
        $featureLabel = videochat_operator_feedback_text_excerpt((string) ($row['message_text'] ?? ''));
    }

    $now = gmdate('c');
    $update = $pdo->prepare(
        <<<'SQL'
UPDATE call_operator_feedback
SET status = :status,
    triage_notes = :triage_notes,
    sprint_ticket_ref = :sprint_ticket_ref,
    toast_feature_label = :toast_feature_label,
    deployed_at = CASE WHEN :status = 'deployed' THEN COALESCE(deployed_at, :now) ELSE deployed_at END,
    deployed_by_user_id = CASE WHEN :status = 'deployed' THEN :actor_user_id ELSE deployed_by_user_id END,
    updated_at = :now
WHERE public_id = :public_id
SQL
    );
    $update->execute([
        ':status' => $status,
        ':triage_notes' => $notes !== '' ? $notes : (string) ($row['triage_notes'] ?? ''),
        ':sprint_ticket_ref' => $ticket !== '' ? $ticket : (string) ($row['sprint_ticket_ref'] ?? ''),
        ':toast_feature_label' => $featureLabel,
        ':actor_user_id' => (int) $auth['user_id'],
        ':now' => $now,
        ':public_id' => trim($feedbackId),
    ]);

    $rowQuery->execute([':public_id' => trim($feedbackId)]);
    $updated = $rowQuery->fetch(PDO::FETCH_ASSOC);

    return ['ok' => is_array($updated), 'reason' => 'updated', 'errors' => [], 'feedback' => is_array($updated) ? videochat_operator_feedback_public_payload($updated) : null];
}

function videochat_operator_feedback_pending_toasts(PDO $pdo, string $callId, array $authContext): array
{
    videochat_operator_feedback_bootstrap($pdo);
    $auth = videochat_operator_feedback_auth_user($authContext);
    $context = videochat_operator_feedback_call_context($pdo, $callId, (int) $auth['user_id'], (string) $auth['role'], (int) $auth['tenant_id']);
    if (!(bool) ($context['ok'] ?? false)) {
        return $context + ['toasts' => []];
    }

    $query = $pdo->prepare(
        <<<'SQL'
SELECT *
FROM call_operator_feedback
WHERE call_id = :call_id
  AND sender_user_id = :sender_user_id
  AND status = 'deployed'
  AND toast_delivered_at IS NULL
ORDER BY deployed_at ASC, id ASC
LIMIT 20
SQL
    );
    $query->execute([':call_id' => trim($callId), ':sender_user_id' => (int) $auth['user_id']]);
    $toasts = [];
    while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!is_array($row)) {
            continue;
        }
        $toasts[] = [
            'id' => (string) ($row['public_id'] ?? ''),
            'message' => "feature '" . videochat_operator_feedback_text_excerpt((string) ($row['toast_feature_label'] ?? $row['message_text'] ?? '')) . "' deployed",
            'feature_label' => videochat_operator_feedback_text_excerpt((string) ($row['toast_feature_label'] ?? $row['message_text'] ?? '')),
            'deployed_at' => (string) ($row['deployed_at'] ?? ''),
        ];
    }

    return ['ok' => true, 'reason' => 'ok', 'errors' => [], 'toasts' => $toasts];
}

function videochat_operator_feedback_mark_toast_delivered(PDO $pdo, string $callId, string $feedbackId, array $authContext): array
{
    videochat_operator_feedback_bootstrap($pdo);
    $auth = videochat_operator_feedback_auth_user($authContext);
    $context = videochat_operator_feedback_call_context($pdo, $callId, (int) $auth['user_id'], (string) $auth['role'], (int) $auth['tenant_id']);
    if (!(bool) ($context['ok'] ?? false)) {
        return $context + ['feedback' => null];
    }

    $update = $pdo->prepare(
        <<<'SQL'
UPDATE call_operator_feedback
SET toast_delivered_at = COALESCE(toast_delivered_at, :now),
    toast_delivered_to_user_id = :user_id,
    updated_at = :now
WHERE public_id = :public_id
  AND call_id = :call_id
  AND sender_user_id = :user_id
  AND status = 'deployed'
SQL
    );
    $update->execute([
        ':now' => gmdate('c'),
        ':user_id' => (int) $auth['user_id'],
        ':public_id' => trim($feedbackId),
        ':call_id' => trim($callId),
    ]);
    if ($update->rowCount() <= 0) {
        return ['ok' => false, 'reason' => 'not_found', 'errors' => [], 'feedback' => null];
    }

    $query = $pdo->prepare('SELECT * FROM call_operator_feedback WHERE public_id = :public_id LIMIT 1');
    $query->execute([':public_id' => trim($feedbackId)]);
    $row = $query->fetch(PDO::FETCH_ASSOC);

    return ['ok' => is_array($row), 'reason' => 'delivered', 'errors' => [], 'feedback' => is_array($row) ? videochat_operator_feedback_public_payload($row) : null];
}
