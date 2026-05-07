<?php

declare(strict_types=1);

require_once __DIR__ . '/call_app_availability.php';

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

function videochat_call_app_session_guest_id(string $email): string
{
    return 'guest_' . substr(hash('sha256', strtolower(trim($email))), 0, 32);
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
    return [
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
        $grants[] = [
            'subject_type' => (string) ($row['subject_type'] ?? ''),
            'user_id' => is_numeric($row['user_id'] ?? null) ? (int) $row['user_id'] : null,
            'guest_id' => (string) ($row['guest_id'] ?? ''),
            'grant_state' => (string) ($row['grant_state'] ?? 'denied'),
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
        $events[] = [
            'id' => (string) ($row['public_id'] ?? ''),
            'event_type' => (string) ($row['event_type'] ?? ''),
            'subject_type' => (string) ($row['subject_type'] ?? ''),
            'user_id' => is_numeric($row['user_id'] ?? null) ? (int) $row['user_id'] : null,
            'guest_id' => (string) ($row['guest_id'] ?? ''),
            'grant_state' => (string) ($row['grant_state'] ?? ''),
            'actor_user_id' => is_numeric($row['actor_user_id'] ?? null) ? (int) $row['actor_user_id'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
    return $events;
}

function videochat_call_app_grant_subject_in_call(PDO $pdo, string $callId, string $subjectType, ?int $userId, string $guestId): bool
{
    if ($subjectType === 'user') {
        $normalizedUserId = (int) ($userId ?? 0);
        if ($normalizedUserId <= 0) {
            return false;
        }
        $statement = $pdo->prepare(
            <<<'SQL'
SELECT 1
FROM calls
WHERE id = :call_id AND owner_user_id = :user_id
UNION
SELECT 1
FROM call_participants
WHERE call_id = :call_id AND user_id = :user_id
LIMIT 1
SQL
        );
        $statement->execute([':call_id' => trim($callId), ':user_id' => $normalizedUserId]);
        return (bool) $statement->fetchColumn();
    }

    if ($subjectType !== 'guest' || trim($guestId) === '') {
        return false;
    }
    $statement = $pdo->prepare('SELECT email FROM call_participants WHERE call_id = :call_id AND user_id IS NULL');
    $statement->execute([':call_id' => trim($callId)]);
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $email) {
        if (videochat_call_app_session_guest_id((string) $email) === trim($guestId)) {
            return true;
        }
    }
    return false;
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

        $key = $subjectType === 'user' ? 'user:' . $userId : 'guest:' . $guestId;
        $grants[$key] = [
            'subject_type' => $subjectType,
            'user_id' => $subjectType === 'user' ? $userId : null,
            'guest_id' => $subjectType === 'guest' ? $guestId : '',
            'grant_state' => $grantState,
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
        'actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
        'created_at' => $now,
    ];
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
SELECT id
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
    tenant_id, app_session_id, subject_type, user_id, guest_id, grant_state,
    source, changed_by_user_id, changed_at, created_at, updated_at
) VALUES(
    :tenant_id, :app_session_id, :subject_type, :user_id, :guest_id, :grant_state,
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
        $existingId = (int) $select->fetchColumn();
        $params = [
            ':grant_state' => (string) $grant['grant_state'],
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
        $changed[] = $grant;
        $auditEvents[] = videochat_call_app_write_grant_audit_event($pdo, $tenantId, $record, $actorUserId, $grant);
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
    $participants = $pdo->prepare(
        <<<'SQL'
SELECT DISTINCT user_id, email
FROM call_participants
WHERE call_id = :call_id
SQL
    );
    $participants->execute([':call_id' => $callId]);
    $subjects = [];
    foreach ($participants->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $userId = is_numeric($row['user_id'] ?? null) ? (int) $row['user_id'] : 0;
        if ($userId > 0) {
            $subjects['user:' . $userId] = ['subject_type' => 'user', 'user_id' => $userId, 'guest_id' => ''];
            continue;
        }
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if ($email !== '') {
            $guestId = videochat_call_app_session_guest_id($email);
            $subjects['guest:' . $guestId] = ['subject_type' => 'guest', 'user_id' => null, 'guest_id' => $guestId];
        }
    }

    $owner = $pdo->prepare('SELECT owner_user_id FROM calls WHERE id = :call_id LIMIT 1');
    $owner->execute([':call_id' => $callId]);
    $ownerUserId = (int) $owner->fetchColumn();
    if ($ownerUserId > 0) {
        $subjects['user:' . $ownerUserId] = ['subject_type' => 'user', 'user_id' => $ownerUserId, 'guest_id' => ''];
    }

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT OR IGNORE INTO call_app_participant_grants(
    tenant_id, app_session_id, subject_type, user_id, guest_id, grant_state,
    source, changed_by_user_id, changed_at, created_at, updated_at
) VALUES(
    :tenant_id, :app_session_id, :subject_type, :user_id, :guest_id, :grant_state,
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
            ':changed_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
            ':changed_at' => $now,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
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
SELECT public_id
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
    $existingPublicId = (string) $existing->fetchColumn();
    if ($existingPublicId !== '') {
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
