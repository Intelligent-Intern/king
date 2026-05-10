<?php

declare(strict_types=1);

require_once __DIR__ . '/call_app_availability.php';
require_once __DIR__ . '/call_app_call_subjects.php';

function videochat_call_app_session_public_id(string $prefix): string
{
    return videochat_call_app_marketplace_generate_public_id($prefix);
}

function videochat_call_app_session_document_id(string $callId, string $appKey, string $sessionId): string
{
    return 'doc_' . hash('sha256', strtolower(trim($callId)) . ':' . strtolower(trim($appKey)) . ':' . trim($sessionId));
}

function videochat_call_app_session_valid_policy(string $policy): bool
{
    return in_array($policy, ['allowed_by_default', 'blocked_by_default'], true);
}

function videochat_call_app_session_default_grant_state(string $policy): string
{
    return $policy === 'allowed_by_default' ? 'allowed' : 'denied';
}

function videochat_call_app_permission_action_keys(): array
{
    return ['read', 'write', 'delete'];
}

function videochat_call_app_permission_actions_json(array $actions): string
{
    return json_encode(array_values($actions), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
}

function videochat_call_app_permission_action_map(array $actions): array
{
    $set = array_flip(array_values($actions));
    $map = [];
    foreach (videochat_call_app_permission_action_keys() as $key) {
        $map[$key] = isset($set[$key]);
    }
    return $map;
}

function videochat_call_app_normalize_permission_actions(mixed $value): array
{
    $raw = [];
    if (is_array($value)) {
        $isMap = array_keys($value) !== range(0, max(0, count($value) - 1));
        if ($isMap) {
            foreach (videochat_call_app_permission_action_keys() as $key) {
                if (array_key_exists($key, $value) && filter_var($value[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true) {
                    $raw[] = $key;
                }
            }
            foreach ($value as $key => $_enabled) {
                if (!in_array((string) $key, videochat_call_app_permission_action_keys(), true)) {
                    $raw[(string) $key] = (string) $key;
                }
            }
        } else {
            $raw = $value;
        }
    }
    $allowed = array_flip(videochat_call_app_permission_action_keys());
    $actions = [];
    $errors = [];
    foreach ($raw as $index => $action) {
        $normalized = strtolower(trim((string) $action));
        if ($normalized === '' || !isset($allowed[$normalized])) {
            $errors[(string) $index] = 'must_be_read_write_or_delete';
            continue;
        }
        $actions[$normalized] = $normalized;
    }
    $ordered = [];
    foreach (videochat_call_app_permission_action_keys() as $key) {
        if (isset($actions[$key])) {
            $ordered[] = $key;
        }
    }
    return ['ok' => $errors === [], 'actions' => $ordered, 'errors' => $errors];
}

function videochat_call_app_default_permission_actions(): array
{
    return videochat_call_app_permission_action_keys();
}

function videochat_call_app_default_permission_actions_for_grant_state(string $grantState): array
{
    return strtolower(trim($grantState)) === 'allowed' ? videochat_call_app_default_permission_actions() : [];
}

function videochat_call_app_effective_permission_actions(string $grantState, array $actions): array
{
    return strtolower(trim($grantState)) === 'allowed' ? array_values($actions) : [];
}

function videochat_call_app_decode_permission_actions(string $json): array
{
    $decoded = videochat_call_app_marketplace_decode_json($json, null);
    $normalized = videochat_call_app_normalize_permission_actions(is_array($decoded) ? $decoded : videochat_call_app_default_permission_actions());
    if (!(bool) ($normalized['ok'] ?? false)) {
        return videochat_call_app_default_permission_actions();
    }
    return (array) ($normalized['actions'] ?? []);
}

function videochat_call_app_supported_permission_actions(array $session): array
{
    $app = is_array($session['app'] ?? null) ? $session['app'] : [];
    $capabilities = is_array($app['capabilities'] ?? null) ? $app['capabilities'] : [];
    $supported = [];
    if (in_array('call_apps.crdt.read', $capabilities, true) || in_array('call_apps.crdt.replay', $capabilities, true)) {
        $supported[] = 'read';
    }
    if (in_array('call_apps.crdt.append', $capabilities, true) || in_array('call_apps.presence.publish', $capabilities, true)) {
        $supported[] = 'write';
    }
    if (in_array('call_apps.permissions.manage', $capabilities, true)) {
        $supported[] = 'delete';
    }
    return $supported === [] ? videochat_call_app_default_permission_actions() : array_values(array_unique($supported));
}

function videochat_call_app_fetch_available_installation(PDO $pdo, int $tenantId, string $appKey): ?array
{
    $statement = $pdo->prepare(
        <<<'SQL'
SELECT
    catalog.*,
    installations.id AS installation_row_id,
    installations.public_id AS installation_public_id,
    installations.status AS installation_status,
    installations.config_json,
    installations.default_app_policy,
    installations.installed_at,
    installations.updated_at AS installation_updated_at,
    entitlements.public_id AS entitlement_public_id,
    entitlements.status AS entitlement_status,
    entitlements.expires_at
FROM organization_call_app_installations installations
INNER JOIN organization_call_app_entitlements entitlements ON entitlements.id = installations.entitlement_id
INNER JOIN call_app_catalog_entries catalog
    ON catalog.app_key = installations.app_key
   AND catalog.app_version = installations.app_version
WHERE installations.tenant_id = :tenant_id
  AND lower(installations.app_key) = lower(:app_key)
  AND installations.status = 'enabled'
  AND entitlements.tenant_id = :tenant_id
  AND entitlements.status = 'active'
  AND (entitlements.expires_at IS NULL OR trim(entitlements.expires_at) = '' OR entitlements.expires_at > :now)
  AND catalog.health_status = 'healthy'
ORDER BY catalog.verified_at DESC, catalog.app_version DESC
LIMIT 1
SQL
    );
    $statement->execute([
        ':tenant_id' => $tenantId,
        ':app_key' => trim($appKey),
        ':now' => gmdate('c'),
    ]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    return [
        'installation_row_id' => (int) ($row['installation_row_id'] ?? 0),
        'available_app' => videochat_call_app_available_row($row),
    ];
}

function videochat_call_app_session_row(array $row, array $grants = []): array
{
    $session = [
        'id' => (string) ($row['public_id'] ?? ''),
        'tenant_id' => (int) ($row['tenant_id'] ?? 0),
        'call_id' => (string) ($row['call_id'] ?? ''),
        'app_key' => (string) ($row['app_key'] ?? ''),
        'version' => (string) ($row['app_version'] ?? ''),
        'document_id' => (string) ($row['document_id'] ?? ''),
        'status' => (string) ($row['status'] ?? 'active'),
        'default_app_policy' => (string) ($row['default_app_policy'] ?? 'blocked_by_default'),
        'created_by_user_id' => (int) ($row['created_by_user_id'] ?? 0),
        'activated_by_user_id' => is_numeric($row['activated_by_user_id'] ?? null) ? (int) $row['activated_by_user_id'] : null,
        'removed_by_user_id' => is_numeric($row['removed_by_user_id'] ?? null) ? (int) $row['removed_by_user_id'] : null,
        'created_at' => (string) ($row['created_at'] ?? ''),
        'activated_at' => is_string($row['activated_at'] ?? null) ? (string) $row['activated_at'] : null,
        'removed_at' => is_string($row['removed_at'] ?? null) ? (string) $row['removed_at'] : null,
        'updated_at' => (string) ($row['updated_at'] ?? ''),
        'app' => [
            'name' => (string) ($row['name'] ?? ''),
            'category' => (string) ($row['category'] ?? 'other'),
            'mcp_endpoint' => (string) ($row['mcp_endpoint'] ?? ''),
            'iframe_entrypoint' => (string) ($row['iframe_entrypoint'] ?? ''),
            'crdt_protocol' => (string) ($row['crdt_protocol'] ?? ''),
            'health_status' => (string) ($row['health_status'] ?? 'unknown'),
            'capabilities' => videochat_call_app_marketplace_decode_json((string) ($row['capabilities_json'] ?? '[]'), []),
            'export_formats' => videochat_call_app_marketplace_decode_json((string) ($row['export_formats_json'] ?? '[]'), []),
        ],
        'grants' => $grants,
    ];
    $session['permission_actions'] = videochat_call_app_supported_permission_actions($session);
    return $session;
}

/**
 * @return array<int, array<string, mixed>>
 */
function videochat_call_app_fetch_session_grants(PDO $pdo, int $tenantId, int $sessionRowId): array
{
    $statement = $pdo->prepare(
        <<<'SQL'
SELECT *
FROM call_app_participant_grants
WHERE tenant_id = :tenant_id
  AND app_session_id = :app_session_id
ORDER BY subject_type DESC, user_id ASC, guest_id ASC
SQL
    );
    $statement->execute([':tenant_id' => $tenantId, ':app_session_id' => $sessionRowId]);
    $grants = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $grantState = (string) ($row['grant_state'] ?? 'denied');
        $storedPermissionActions = videochat_call_app_decode_permission_actions((string) ($row['permission_actions_json'] ?? '["read","write","delete"]'));
        $effectivePermissionActions = videochat_call_app_effective_permission_actions($grantState, $storedPermissionActions);
        $grants[] = [
            'subject_type' => (string) ($row['subject_type'] ?? ''),
            'user_id' => is_numeric($row['user_id'] ?? null) ? (int) $row['user_id'] : null,
            'guest_id' => (string) ($row['guest_id'] ?? ''),
            'grant_state' => $grantState,
            'permission_actions' => $effectivePermissionActions,
            'permissions' => videochat_call_app_permission_action_map($effectivePermissionActions),
            'source' => (string) ($row['source'] ?? 'default'),
            'changed_by_user_id' => is_numeric($row['changed_by_user_id'] ?? null) ? (int) $row['changed_by_user_id'] : null,
            'changed_at' => (string) ($row['changed_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    return $grants;
}

function videochat_call_app_fetch_audit_events(PDO $pdo, int $tenantId, int $sessionRowId, int $limit = 25): array
{
    $boundedLimit = max(1, min(100, $limit));
    $statement = $pdo->prepare(
        <<<SQL
SELECT *
FROM call_app_audit_events
WHERE tenant_id = :tenant_id
  AND app_session_id = :app_session_id
ORDER BY created_at DESC, id DESC
LIMIT {$boundedLimit}
SQL
    );
    $statement->execute([':tenant_id' => $tenantId, ':app_session_id' => $sessionRowId]);
    $events = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $payload = videochat_call_app_marketplace_decode_json((string) ($row['payload_json'] ?? '{}'), []);
        $events[] = [
            'id' => (string) ($row['public_id'] ?? ''),
            'event_type' => (string) ($row['event_type'] ?? ''),
            'subject_type' => (string) ($row['subject_type'] ?? ''),
            'user_id' => is_numeric($row['user_id'] ?? null) ? (int) $row['user_id'] : null,
            'guest_id' => (string) ($row['guest_id'] ?? ''),
            'grant_state' => (string) ($row['grant_state'] ?? ''),
            'actor_user_id' => is_numeric($row['actor_user_id'] ?? null) ? (int) $row['actor_user_id'] : null,
            'payload' => is_array($payload) ? $payload : [],
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
    return $events;
}

function videochat_call_app_normalize_grant_patch(array $payload): array
{
    $rawGrants = is_array($payload['grants'] ?? null) ? $payload['grants'] : [];
    if ($rawGrants === []) {
        return ['ok' => false, 'reason' => 'validation_failed', 'errors' => ['grants' => 'must_not_be_empty'], 'grants' => []];
    }

    $grants = [];
    $errors = [];
    foreach ($rawGrants as $index => $row) {
        $grant = is_array($row) ? $row : [];
        $subjectType = strtolower(trim((string) ($grant['subject_type'] ?? 'user')));
        $grantState = strtolower(trim((string) ($grant['grant_state'] ?? '')));
        $userId = is_numeric($grant['user_id'] ?? null) ? (int) $grant['user_id'] : null;
        $guestId = trim((string) ($grant['guest_id'] ?? ''));
        $hasPermissionActions = array_key_exists('permission_actions', $grant)
            || array_key_exists('permissions', $grant);
        $rawPermissionActions = $grant['permission_actions']
            ?? ($grant['permissions'] ?? videochat_call_app_default_permission_actions_for_grant_state($grantState));
        $field = 'grants.' . $index;

        if (!in_array($subjectType, ['user', 'guest'], true)) {
            $errors[$field . '.subject_type'] = 'must_be_user_or_guest';
            continue;
        }
        if (!in_array($grantState, ['allowed', 'denied'], true)) {
            $errors[$field . '.grant_state'] = 'must_be_allowed_or_denied';
            continue;
        }
        if ($subjectType === 'user' && (($userId ?? 0) <= 0 || $guestId !== '')) {
            $errors[$field . '.user_id'] = 'must_be_positive_user_id';
            continue;
        }
        if ($subjectType === 'guest' && (($userId ?? 0) > 0 || $guestId === '')) {
            $errors[$field . '.guest_id'] = 'must_be_known_guest_id';
            continue;
        }
        if ($hasPermissionActions && !is_array($rawPermissionActions)) {
            $errors[$field . '.permission_actions'] = 'must_be_array';
            continue;
        }
        $normalizedPermissionActions = videochat_call_app_normalize_permission_actions($rawPermissionActions);
        if (!(bool) ($normalizedPermissionActions['ok'] ?? false)) {
            foreach ((array) ($normalizedPermissionActions['errors'] ?? []) as $actionIndex => $error) {
                $errors[$field . '.permission_actions.' . $actionIndex] = $error;
            }
            continue;
        }

        $key = $subjectType === 'user' ? 'user:' . $userId : 'guest:' . $guestId;
        $grants[$key] = [
            'subject_type' => $subjectType,
            'user_id' => $subjectType === 'user' ? $userId : null,
            'guest_id' => $subjectType === 'guest' ? $guestId : '',
            'grant_state' => $grantState,
            'permission_actions' => (array) ($normalizedPermissionActions['actions'] ?? []),
        ];
    }

    if ($errors !== []) {
        return ['ok' => false, 'reason' => 'validation_failed', 'errors' => $errors, 'grants' => []];
    }
    return ['ok' => true, 'reason' => '', 'errors' => [], 'grants' => array_values($grants)];
}

function videochat_call_app_write_grant_audit_event(PDO $pdo, int $tenantId, array $sessionRecord, int $actorUserId, array $grant): array
{
    $publicId = videochat_call_app_session_public_id('caa');
    $now = gmdate('c');
    $payload = [
        'app_session_id' => (string) ($sessionRecord['public_id'] ?? ''),
        'call_id' => (string) ($sessionRecord['call_id'] ?? ''),
        'app_key' => (string) ($sessionRecord['app_key'] ?? ''),
        'subject_type' => (string) ($grant['subject_type'] ?? ''),
        'user_id' => $grant['user_id'] ?? null,
        'guest_id' => (string) ($grant['guest_id'] ?? ''),
        'grant_state' => (string) ($grant['grant_state'] ?? ''),
        'permission_actions' => array_values((array) ($grant['permission_actions'] ?? videochat_call_app_default_permission_actions())),
        'permissions' => videochat_call_app_permission_action_map((array) ($grant['permission_actions'] ?? videochat_call_app_default_permission_actions())),
        'retired_launch_tokens' => (int) ($grant['retired_launch_tokens'] ?? 0),
        'reconnect_policy' => (string) ($grant['reconnect_policy'] ?? 'current_grant_rechecked_on_reconnect'),
    ];
    $statement = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_app_audit_events(
    public_id, tenant_id, app_session_id, call_id, event_type, subject_type,
    user_id, guest_id, grant_state, actor_user_id, payload_json, created_at
) VALUES(
    :public_id, :tenant_id, :app_session_id, :call_id, 'participant_grant_changed', :subject_type,
    :user_id, :guest_id, :grant_state, :actor_user_id, :payload_json, :created_at
)
SQL
    );
    $statement->execute([
        ':public_id' => $publicId,
        ':tenant_id' => $tenantId,
        ':app_session_id' => (int) ($sessionRecord['id'] ?? 0),
        ':call_id' => (string) ($sessionRecord['call_id'] ?? ''),
        ':subject_type' => (string) ($grant['subject_type'] ?? ''),
        ':user_id' => $grant['user_id'] ?? null,
        ':guest_id' => (string) ($grant['guest_id'] ?? ''),
        ':grant_state' => (string) ($grant['grant_state'] ?? ''),
        ':actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
        ':payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':created_at' => $now,
    ]);
    return [
        'id' => $publicId,
        'event_type' => 'participant_grant_changed',
        'subject_type' => (string) ($grant['subject_type'] ?? ''),
        'user_id' => $grant['user_id'] ?? null,
        'guest_id' => (string) ($grant['guest_id'] ?? ''),
        'grant_state' => (string) ($grant['grant_state'] ?? ''),
        'permission_actions' => array_values((array) ($grant['permission_actions'] ?? videochat_call_app_default_permission_actions())),
        'permissions' => videochat_call_app_permission_action_map((array) ($grant['permission_actions'] ?? videochat_call_app_default_permission_actions())),
        'actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
        'payload' => $payload,
        'created_at' => $now,
    ];
}

function videochat_call_app_retire_launch_tokens_for_grant(PDO $pdo, int $tenantId, int $sessionRowId, array $grant, string $now): int
{
    $grantState = (string) ($grant['grant_state'] ?? '');
    $previousGrantState = (string) ($grant['previous_grant_state'] ?? '');
    $permissionActionsChanged = (bool) ($grant['permission_actions_changed'] ?? false);
    $shouldRetire = $grantState === 'denied'
        || ($grantState === 'allowed' && $previousGrantState === 'allowed' && $permissionActionsChanged);
    if (!$shouldRetire) {
        return 0;
    }
    if ((string) ($grant['subject_type'] ?? '') !== 'user' || (int) ($grant['user_id'] ?? 0) <= 0) {
        return 0;
    }

    $statement = $pdo->prepare(
        <<<'SQL'
UPDATE call_app_launch_tokens
SET revoked_at = :revoked_at,
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND app_session_id = :app_session_id
  AND issued_to_user_id = :issued_to_user_id
  AND (revoked_at IS NULL OR trim(revoked_at) = '')
SQL
    );
    $statement->execute([
        ':revoked_at' => $now,
        ':updated_at' => $now,
        ':tenant_id' => $tenantId,
        ':app_session_id' => $sessionRowId,
        ':issued_to_user_id' => (int) $grant['user_id'],
    ]);
    return max(0, (int) $statement->rowCount());
}

function videochat_call_app_update_participant_grants(PDO $pdo, int $tenantId, string $sessionId, int $actorUserId, array $payload): array
{
    $record = videochat_call_app_fetch_session_record($pdo, $tenantId, $sessionId);
    if (!is_array($record)) {
        return ['ok' => false, 'reason' => 'session_not_found'];
    }
    if ((string) ($record['status'] ?? '') === 'removed') {
        return ['ok' => false, 'reason' => 'session_removed'];
    }

    $normalized = videochat_call_app_normalize_grant_patch($payload);
    if (!(bool) ($normalized['ok'] ?? false)) {
        return $normalized;
    }

    $sessionRowId = (int) ($record['id'] ?? 0);
    $callId = (string) ($record['call_id'] ?? '');
    $now = gmdate('c');
    $changed = [];
    $auditEvents = [];
    foreach ((array) ($normalized['grants'] ?? []) as $grant) {
        if (!videochat_call_app_grant_subject_in_call($pdo, $callId, (string) $grant['subject_type'], $grant['user_id'], (string) $grant['guest_id'])) {
            return ['ok' => false, 'reason' => 'validation_failed', 'errors' => ['grants' => 'contains_unknown_call_participant']];
        }
    }

    $select = $pdo->prepare(
        <<<'SQL'
SELECT id, grant_state, permission_actions_json
FROM call_app_participant_grants
WHERE tenant_id = :tenant_id
  AND app_session_id = :app_session_id
  AND subject_type = :subject_type
  AND ((:subject_type = 'user' AND user_id = :user_id) OR (:subject_type = 'guest' AND guest_id = :guest_id))
LIMIT 1
SQL
    );
    $update = $pdo->prepare(
        <<<'SQL'
UPDATE call_app_participant_grants
SET grant_state = :grant_state,
    permission_actions_json = :permission_actions_json,
    source = 'explicit',
    changed_by_user_id = :changed_by_user_id,
    changed_at = :changed_at,
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND id = :id
SQL
    );
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_app_participant_grants(
    tenant_id, app_session_id, subject_type, user_id, guest_id, grant_state, permission_actions_json,
    source, changed_by_user_id, changed_at, created_at, updated_at
) VALUES(
    :tenant_id, :app_session_id, :subject_type, :user_id, :guest_id, :grant_state, :permission_actions_json,
    'explicit', :changed_by_user_id, :changed_at, :created_at, :updated_at
)
SQL
    );

    foreach ((array) ($normalized['grants'] ?? []) as $grant) {
        $select->execute([
            ':tenant_id' => $tenantId,
            ':app_session_id' => $sessionRowId,
            ':subject_type' => (string) $grant['subject_type'],
            ':user_id' => $grant['user_id'],
            ':guest_id' => (string) $grant['guest_id'],
        ]);
        $existing = $select->fetch(PDO::FETCH_ASSOC);
        $existingId = is_array($existing) ? (int) ($existing['id'] ?? 0) : 0;
        $previousGrantState = is_array($existing) ? (string) ($existing['grant_state'] ?? '') : '';
        $previousPermissionActions = is_array($existing)
            ? videochat_call_app_decode_permission_actions((string) ($existing['permission_actions_json'] ?? '["read","write","delete"]'))
            : videochat_call_app_default_permission_actions();
        $nextPermissionActions = array_values((array) ($grant['permission_actions'] ?? videochat_call_app_default_permission_actions()));
        $permissionActionsChanged = $previousPermissionActions !== $nextPermissionActions;
        $params = [
            ':grant_state' => (string) $grant['grant_state'],
            ':permission_actions_json' => videochat_call_app_permission_actions_json($nextPermissionActions),
            ':changed_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
            ':changed_at' => $now,
            ':updated_at' => $now,
            ':tenant_id' => $tenantId,
        ];
        if ($existingId > 0) {
            $update->execute($params + [':id' => $existingId]);
        } else {
            $insert->execute($params + [
                ':app_session_id' => $sessionRowId,
                ':subject_type' => (string) $grant['subject_type'],
                ':user_id' => $grant['user_id'],
                ':guest_id' => (string) $grant['guest_id'],
                ':created_at' => $now,
            ]);
        }
        $grantForTokenRetirement = $grant + [
            'permission_actions_changed' => $permissionActionsChanged,
            'previous_grant_state' => $previousGrantState,
        ];
        $retiredTokens = videochat_call_app_retire_launch_tokens_for_grant($pdo, $tenantId, $sessionRowId, $grantForTokenRetirement, $now);
        $auditedGrant = $grant + [
            'retired_launch_tokens' => $retiredTokens,
            'reconnect_policy' => $retiredTokens > 0 ? 'active_launch_tokens_revoked_on_grant_restriction' : 'current_grant_rechecked_on_reconnect',
        ];
        $changed[] = $auditedGrant;
        $auditEvents[] = videochat_call_app_write_grant_audit_event($pdo, $tenantId, $record, $actorUserId, $auditedGrant);
    }

    return [
        'ok' => true,
        'state' => 'updated',
        'changed_grants' => $changed,
        'audit_events' => $auditEvents,
        'session' => videochat_call_app_fetch_session($pdo, $tenantId, $sessionId),
    ];
}

function videochat_call_app_fetch_session(PDO $pdo, int $tenantId, string $sessionId, bool $includeGrants = true): ?array
{
    $statement = $pdo->prepare(
        <<<'SQL'
SELECT
    sessions.*,
    catalog.name,
    catalog.category,
    catalog.mcp_endpoint,
    catalog.iframe_entrypoint,
    catalog.crdt_protocol,
    catalog.health_status,
    catalog.capabilities_json,
    catalog.export_formats_json
FROM call_app_sessions sessions
INNER JOIN call_app_catalog_entries catalog
    ON catalog.app_key = sessions.app_key
   AND catalog.app_version = sessions.app_version
WHERE sessions.tenant_id = :tenant_id
  AND sessions.public_id = :public_id
LIMIT 1
SQL
    );
    $statement->execute([':tenant_id' => $tenantId, ':public_id' => trim($sessionId)]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    $grants = $includeGrants ? videochat_call_app_fetch_session_grants($pdo, $tenantId, (int) ($row['id'] ?? 0)) : [];
    return videochat_call_app_session_row($row, $grants);
}

function videochat_call_app_fetch_session_record(PDO $pdo, int $tenantId, string $sessionId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM call_app_sessions WHERE tenant_id = :tenant_id AND public_id = :public_id LIMIT 1');
    $statement->execute([':tenant_id' => $tenantId, ':public_id' => trim($sessionId)]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/**
 * @return array<int, array<string, mixed>>
 */
function videochat_call_app_list_sessions_for_call(PDO $pdo, int $tenantId, string $callId, bool $includeRemoved = false): array
{
    $removedWhere = $includeRemoved ? '' : "AND sessions.status <> 'removed'";
    $statement = $pdo->prepare(
        <<<SQL
SELECT
    sessions.*,
    catalog.name,
    catalog.category,
    catalog.mcp_endpoint,
    catalog.iframe_entrypoint,
    catalog.crdt_protocol,
    catalog.health_status,
    catalog.capabilities_json,
    catalog.export_formats_json
FROM call_app_sessions sessions
INNER JOIN call_app_catalog_entries catalog
    ON catalog.app_key = sessions.app_key
   AND catalog.app_version = sessions.app_version
WHERE sessions.tenant_id = :tenant_id
  AND sessions.call_id = :call_id
  {$removedWhere}
ORDER BY
  CASE sessions.status WHEN 'active' THEN 0 WHEN 'inactive' THEN 1 ELSE 2 END ASC,
  sessions.updated_at DESC,
  sessions.id DESC
SQL
    );
    $statement->execute([':tenant_id' => $tenantId, ':call_id' => trim($callId)]);
    $sessions = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sessions[] = videochat_call_app_session_row(
            $row,
            videochat_call_app_fetch_session_grants($pdo, $tenantId, (int) ($row['id'] ?? 0))
        );
    }

    return $sessions;
}

function videochat_call_app_seed_participant_grants(PDO $pdo, int $tenantId, int $sessionRowId, string $callId, int $actorUserId, string $policy): void
{
    $state = videochat_call_app_session_default_grant_state($policy);
    $now = gmdate('c');
    $subjects = videochat_call_app_active_call_subjects($pdo, $callId);

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT OR IGNORE INTO call_app_participant_grants(
    tenant_id, app_session_id, subject_type, user_id, guest_id, grant_state, permission_actions_json,
    source, changed_by_user_id, changed_at, created_at, updated_at
) VALUES(
    :tenant_id, :app_session_id, :subject_type, :user_id, :guest_id, :grant_state, :permission_actions_json,
    'default', :changed_by_user_id, :changed_at, :created_at, :updated_at
)
SQL
    );
    foreach ($subjects as $subject) {
        $insert->execute([
            ':tenant_id' => $tenantId,
            ':app_session_id' => $sessionRowId,
            ':subject_type' => (string) $subject['subject_type'],
            ':user_id' => $subject['user_id'],
            ':guest_id' => (string) $subject['guest_id'],
            ':grant_state' => $state,
            ':permission_actions_json' => videochat_call_app_permission_actions_json(videochat_call_app_default_permission_actions()),
            ':changed_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
            ':changed_at' => $now,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
}

function videochat_call_app_update_default_participant_grants(PDO $pdo, int $tenantId, int $sessionRowId, int $actorUserId, string $policy): void
{
    $state = videochat_call_app_session_default_grant_state($policy);
    $now = gmdate('c');
    $update = $pdo->prepare(
        <<<'SQL'
UPDATE call_app_participant_grants
SET grant_state = :grant_state,
    permission_actions_json = :permission_actions_json,
    changed_by_user_id = :changed_by_user_id,
    changed_at = :changed_at,
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND app_session_id = :app_session_id
  AND source = 'default'
SQL
    );
    $update->execute([
        ':grant_state' => $state,
        ':permission_actions_json' => videochat_call_app_permission_actions_json(videochat_call_app_default_permission_actions()),
        ':changed_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
        ':changed_at' => $now,
        ':updated_at' => $now,
        ':tenant_id' => $tenantId,
        ':app_session_id' => $sessionRowId,
    ]);
}

function videochat_call_app_create_session(PDO $pdo, int $tenantId, string $callId, int $actorUserId, string $appKey, string $defaultPolicy): array
{
    if ($tenantId <= 0 || trim($callId) === '' || $actorUserId <= 0) {
        return ['ok' => false, 'reason' => 'invalid_context'];
    }
    if (!videochat_call_app_session_valid_policy($defaultPolicy)) {
        return ['ok' => false, 'reason' => 'validation_failed', 'errors' => ['default_app_policy' => 'must_be_known_policy']];
    }
    $available = videochat_call_app_fetch_available_installation($pdo, $tenantId, $appKey);
    if (!is_array($available)) {
        return ['ok' => false, 'reason' => 'app_not_available'];
    }

    $app = is_array($available['available_app'] ?? null) ? $available['available_app'] : [];
    $existing = $pdo->prepare(
        <<<'SQL'
SELECT id, public_id, default_app_policy
FROM call_app_sessions
WHERE tenant_id = :tenant_id
  AND call_id = :call_id
  AND app_key = :app_key
  AND app_version = :app_version
  AND status <> 'removed'
ORDER BY updated_at DESC
LIMIT 1
SQL
    );
    $existing->execute([
        ':tenant_id' => $tenantId,
        ':call_id' => trim($callId),
        ':app_key' => (string) ($app['app_key'] ?? ''),
        ':app_version' => (string) ($app['version'] ?? ''),
    ]);
    $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
    $existingPublicId = is_array($existingRow) ? (string) ($existingRow['public_id'] ?? '') : '';
    if ($existingPublicId !== '') {
        $existingPolicy = (string) ($existingRow['default_app_policy'] ?? 'blocked_by_default');
        if ($existingPolicy !== $defaultPolicy) {
            $now = gmdate('c');
            $pdo->prepare(
                'UPDATE call_app_sessions SET default_app_policy = :default_app_policy, updated_at = :updated_at WHERE tenant_id = :tenant_id AND id = :id'
            )->execute([
                ':default_app_policy' => $defaultPolicy,
                ':updated_at' => $now,
                ':tenant_id' => $tenantId,
                ':id' => (int) ($existingRow['id'] ?? 0),
            ]);
            videochat_call_app_seed_participant_grants($pdo, $tenantId, (int) ($existingRow['id'] ?? 0), trim($callId), $actorUserId, $defaultPolicy);
            videochat_call_app_update_default_participant_grants($pdo, $tenantId, (int) ($existingRow['id'] ?? 0), $actorUserId, $defaultPolicy);
        }
        return [
            'ok' => true,
            'state' => 'existing',
            'session' => videochat_call_app_fetch_session($pdo, $tenantId, $existingPublicId),
        ];
    }

    $publicId = videochat_call_app_session_public_id('cas');
    $now = gmdate('c');
    $documentId = videochat_call_app_session_document_id(trim($callId), (string) ($app['app_key'] ?? ''), $publicId);
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_app_sessions(
    public_id, tenant_id, call_id, installation_id, app_key, app_version,
    document_id, status, default_app_policy, created_by_user_id,
    activated_by_user_id, created_at, activated_at, updated_at
) VALUES(
    :public_id, :tenant_id, :call_id, :installation_id, :app_key, :app_version,
    :document_id, 'active', :default_app_policy, :created_by_user_id,
    :activated_by_user_id, :created_at, :activated_at, :updated_at
)
SQL
    );
    $insert->execute([
        ':public_id' => $publicId,
        ':tenant_id' => $tenantId,
        ':call_id' => trim($callId),
        ':installation_id' => (int) ($available['installation_row_id'] ?? 0),
        ':app_key' => (string) ($app['app_key'] ?? ''),
        ':app_version' => (string) ($app['version'] ?? ''),
        ':document_id' => $documentId,
        ':default_app_policy' => $defaultPolicy,
        ':created_by_user_id' => $actorUserId,
        ':activated_by_user_id' => $actorUserId,
        ':created_at' => $now,
        ':activated_at' => $now,
        ':updated_at' => $now,
    ]);
    videochat_call_app_seed_participant_grants($pdo, $tenantId, (int) $pdo->lastInsertId(), trim($callId), $actorUserId, $defaultPolicy);

    return [
        'ok' => true,
        'state' => 'created',
        'session' => videochat_call_app_fetch_session($pdo, $tenantId, $publicId),
    ];
}

function videochat_call_app_update_session(PDO $pdo, int $tenantId, string $sessionId, int $actorUserId, array $payload): array
{
    $record = videochat_call_app_fetch_session_record($pdo, $tenantId, $sessionId);
    if (!is_array($record)) {
        return ['ok' => false, 'reason' => 'session_not_found'];
    }
    if ((string) ($record['status'] ?? '') === 'removed') {
        return ['ok' => false, 'reason' => 'session_removed'];
    }

    $status = strtolower(trim((string) ($payload['status'] ?? ($payload['state'] ?? ''))));
    if (!in_array($status, ['active', 'inactive'], true)) {
        return ['ok' => false, 'reason' => 'validation_failed', 'errors' => ['status' => 'must_be_active_or_inactive']];
    }

    $now = gmdate('c');
    $statement = $pdo->prepare(
        <<<'SQL'
UPDATE call_app_sessions
SET status = :status,
    activated_by_user_id = CASE WHEN :status = 'active' THEN :actor_user_id ELSE activated_by_user_id END,
    activated_at = CASE WHEN :status = 'active' THEN :activated_at ELSE activated_at END,
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND public_id = :public_id
SQL
    );
    $statement->execute([
        ':status' => $status,
        ':actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
        ':activated_at' => $now,
        ':updated_at' => $now,
        ':tenant_id' => $tenantId,
        ':public_id' => trim($sessionId),
    ]);

    return [
        'ok' => true,
        'state' => $status,
        'session' => videochat_call_app_fetch_session($pdo, $tenantId, $sessionId),
    ];
}

function videochat_call_app_remove_session(PDO $pdo, int $tenantId, string $sessionId, int $actorUserId): array
{
    $record = videochat_call_app_fetch_session_record($pdo, $tenantId, $sessionId);
    if (!is_array($record)) {
        return ['ok' => false, 'reason' => 'session_not_found'];
    }
    if ((string) ($record['status'] ?? '') === 'removed') {
        return ['ok' => true, 'state' => 'removed', 'session' => videochat_call_app_fetch_session($pdo, $tenantId, $sessionId)];
    }

    $now = gmdate('c');
    $update = $pdo->prepare(
        <<<'SQL'
UPDATE call_app_sessions
SET status = 'removed',
    removed_by_user_id = :removed_by_user_id,
    removed_at = :removed_at,
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND public_id = :public_id
SQL
    );
    $update->execute([
        ':removed_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
        ':removed_at' => $now,
        ':updated_at' => $now,
        ':tenant_id' => $tenantId,
        ':public_id' => trim($sessionId),
    ]);

    $tokenUpdate = $pdo->prepare(
        <<<'SQL'
UPDATE call_app_launch_tokens
SET revoked_at = :revoked_at,
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND app_session_id = :app_session_id
  AND revoked_at IS NULL
SQL
    );
    $tokenUpdate->execute([
        ':revoked_at' => $now,
        ':updated_at' => $now,
        ':tenant_id' => $tenantId,
        ':app_session_id' => (int) ($record['id'] ?? 0),
    ]);

    return [
        'ok' => true,
        'state' => 'removed',
        'retired_launch_tokens' => $tokenUpdate->rowCount(),
        'session' => videochat_call_app_fetch_session($pdo, $tenantId, $sessionId),
    ];
}

function videochat_call_app_room_snapshot(PDO $pdo, int $tenantId, string $callId): array
{
    $sessions = array_values(array_filter(
        videochat_call_app_list_sessions_for_call($pdo, $tenantId, $callId, false),
        static fn (array $session): bool => (string) ($session['status'] ?? '') === 'active'
    ));

    return [
        'active_sessions' => $sessions,
        'active_session_count' => count($sessions),
        'has_active_session' => $sessions !== [],
    ];
}
