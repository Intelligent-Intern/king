<?php

declare(strict_types=1);

function videochat_call_app_session_installation_available(PDO $pdo, int $tenantId, array $sessionRecord): bool
{
    $installationId = (int) ($sessionRecord['installation_id'] ?? 0);
    $appKey = trim((string) ($sessionRecord['app_key'] ?? ''));
    $appVersion = trim((string) ($sessionRecord['app_version'] ?? ''));
    if ($tenantId <= 0 || $installationId <= 0 || $appKey === '' || $appVersion === '') {
        return false;
    }

    $statement = $pdo->prepare(
        <<<'SQL'
SELECT 1
FROM organization_call_app_installations installations
INNER JOIN organization_call_app_entitlements entitlements ON entitlements.id = installations.entitlement_id
INNER JOIN call_app_catalog_entries catalog
    ON catalog.app_key = installations.app_key
   AND catalog.app_version = installations.app_version
WHERE installations.id = :installation_id
  AND installations.tenant_id = :tenant_id
  AND installations.app_key = :app_key
  AND installations.app_version = :app_version
  AND installations.status = 'enabled'
  AND entitlements.tenant_id = :tenant_id
  AND entitlements.app_key = :app_key
  AND entitlements.app_version = :app_version
  AND entitlements.status = 'active'
  AND (entitlements.expires_at IS NULL OR trim(entitlements.expires_at) = '' OR entitlements.expires_at > :now)
  AND catalog.health_status = 'healthy'
LIMIT 1
SQL
    );
    $statement->execute([
        ':installation_id' => $installationId,
        ':tenant_id' => $tenantId,
        ':app_key' => $appKey,
        ':app_version' => $appVersion,
        ':now' => gmdate('c'),
    ]);

    return (bool) $statement->fetchColumn();
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
    if ($status === 'active' && !videochat_call_app_session_installation_available($pdo, $tenantId, $record)) {
        return ['ok' => false, 'reason' => 'app_not_available'];
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
