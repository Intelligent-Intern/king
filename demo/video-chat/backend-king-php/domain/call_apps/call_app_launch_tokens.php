<?php

declare(strict_types=1);

require_once __DIR__ . '/call_app_sessions.php';

function videochat_call_app_launch_token_ttl_seconds(): int
{
    $ttl = (int) (getenv('VIDEOCHAT_CALL_APP_LAUNCH_TOKEN_TTL_SECONDS') ?: 120);
    if ($ttl < 30) {
        return 30;
    }
    if ($ttl > 600) {
        return 600;
    }
    return $ttl;
}

function videochat_call_app_launch_token_secret(): string
{
    return 'cat_' . bin2hex(random_bytes(32));
}

function videochat_call_app_launch_token_hash(string $token): string
{
    return hash('sha256', trim($token));
}

function videochat_call_app_launch_subject_grant_state(PDO $pdo, int $tenantId, array $sessionRecord, string $subjectType, ?int $userId, string $guestId): string
{
    $normalizedSubjectType = strtolower(trim($subjectType));
    $normalizedUserId = (int) ($userId ?? 0);
    $normalizedGuestId = trim($guestId);
    if ($normalizedSubjectType === 'user' && $normalizedUserId <= 0) {
        return 'denied';
    }
    if ($normalizedSubjectType === 'guest' && $normalizedGuestId === '') {
        return 'denied';
    }
    if (!in_array($normalizedSubjectType, ['user', 'guest'], true)) {
        return 'denied';
    }

    $statement = $pdo->prepare(
        <<<'SQL'
SELECT grant_state
FROM call_app_participant_grants
WHERE tenant_id = :tenant_id
  AND app_session_id = :app_session_id
  AND subject_type = :subject_type
  AND (
      (:subject_type = 'user' AND user_id = :user_id)
      OR (:subject_type = 'guest' AND guest_id = :guest_id)
  )
ORDER BY CASE source WHEN 'explicit' THEN 0 ELSE 1 END ASC, updated_at DESC, id DESC
LIMIT 1
SQL
    );
    $statement->execute([
        ':tenant_id' => $tenantId,
        ':app_session_id' => (int) ($sessionRecord['id'] ?? 0),
        ':subject_type' => $normalizedSubjectType,
        ':user_id' => $normalizedUserId > 0 ? $normalizedUserId : null,
        ':guest_id' => $normalizedGuestId,
    ]);
    $state = strtolower(trim((string) $statement->fetchColumn()));
    if (in_array($state, ['allowed', 'denied'], true)) {
        return $state;
    }

    return videochat_call_app_session_default_grant_state((string) ($sessionRecord['default_app_policy'] ?? 'blocked_by_default'));
}

function videochat_call_app_launch_user_grant_state(PDO $pdo, int $tenantId, array $sessionRecord, int $userId): string
{
    return videochat_call_app_launch_subject_grant_state($pdo, $tenantId, $sessionRecord, 'user', $userId, '');
}

function videochat_call_app_launch_guest_grant_state(PDO $pdo, int $tenantId, array $sessionRecord, string $guestId): string
{
    return videochat_call_app_launch_subject_grant_state($pdo, $tenantId, $sessionRecord, 'guest', null, $guestId);
}

function videochat_call_app_launch_capabilities(array $session, string $grantState): array
{
    $app = is_array($session['app'] ?? null) ? $session['app'] : [];
    $declared = is_array($app['capabilities'] ?? null) ? $app['capabilities'] : [];
    $base = ['call_apps.launch'];
    if ($grantState !== 'allowed') {
        return array_values(array_unique(array_intersect($base, array_merge($base, $declared))));
    }

    $allowed = [
        'call_apps.launch',
        'call_apps.crdt.read',
        'call_apps.crdt.append',
        'call_apps.crdt.replay',
        'call_apps.presence.publish',
        'call_apps.export.request',
    ];
    return array_values(array_unique(array_filter(
        array_merge($base, $declared),
        static fn (string $capability): bool => in_array($capability, $allowed, true)
    )));
}

function videochat_call_app_launch_context(array $session, int $userId, string $grantState, array $tokenRow = []): array
{
    $app = is_array($session['app'] ?? null) ? $session['app'] : [];
    return [
        'session_id' => (string) ($session['id'] ?? ''),
        'call_id' => (string) ($session['call_id'] ?? ''),
        'app_key' => (string) ($session['app_key'] ?? ''),
        'app_version' => (string) ($session['version'] ?? ''),
        'document_id' => (string) ($session['document_id'] ?? ''),
        'bridge_protocol' => 'king.call_app.iframe.v1',
        'iframe_origin_policy' => 'sandbox_opaque_origin',
        'expected_message_origin' => 'null',
        'grant_state' => $grantState,
        'capabilities' => videochat_call_app_launch_capabilities($session, $grantState),
        'participant' => [
            'subject_type' => 'user',
            'actor_id' => 'user_' . hash('sha256', (string) $userId),
        ],
        'app' => [
            'name' => (string) ($app['name'] ?? ''),
            'category' => (string) ($app['category'] ?? ''),
            'iframe_entrypoint' => (string) ($app['iframe_entrypoint'] ?? ''),
            'crdt_protocol' => (string) ($app['crdt_protocol'] ?? ''),
            'export_formats' => is_array($app['export_formats'] ?? null) ? $app['export_formats'] : [],
        ],
        'token' => [
            'id' => (string) ($tokenRow['public_id'] ?? ''),
            'issued_at' => (string) ($tokenRow['issued_at'] ?? ''),
            'expires_at' => (string) ($tokenRow['expires_at'] ?? ''),
        ],
    ];
}

function videochat_call_app_mint_launch_token(PDO $pdo, int $tenantId, string $sessionId, int $actorUserId): array
{
    $record = videochat_call_app_fetch_session_record($pdo, $tenantId, $sessionId);
    if (!is_array($record)) {
        return ['ok' => false, 'reason' => 'session_not_found'];
    }
    if ((string) ($record['status'] ?? '') !== 'active') {
        return ['ok' => false, 'reason' => 'session_not_active'];
    }
    if (!videochat_call_app_grant_subject_in_call($pdo, (string) ($record['call_id'] ?? ''), 'user', $actorUserId, '')) {
        return ['ok' => false, 'reason' => 'participant_not_in_call'];
    }

    $grantState = videochat_call_app_launch_user_grant_state($pdo, $tenantId, $record, $actorUserId);
    $session = videochat_call_app_fetch_session($pdo, $tenantId, $sessionId);
    if (!is_array($session)) {
        return ['ok' => false, 'reason' => 'session_not_found'];
    }

    $token = videochat_call_app_launch_token_secret();
    $publicId = videochat_call_app_session_public_id('ctl');
    $issuedAt = gmdate('c');
    $expiresAt = gmdate('c', time() + videochat_call_app_launch_token_ttl_seconds());
    $statement = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_app_launch_tokens(
    public_id, tenant_id, app_session_id, token_hash, issued_to_user_id,
    issued_at, expires_at, created_at, updated_at
) VALUES(
    :public_id, :tenant_id, :app_session_id, :token_hash, :issued_to_user_id,
    :issued_at, :expires_at, :created_at, :updated_at
)
SQL
    );
    $statement->execute([
        ':public_id' => $publicId,
        ':tenant_id' => $tenantId,
        ':app_session_id' => (int) ($record['id'] ?? 0),
        ':token_hash' => videochat_call_app_launch_token_hash($token),
        ':issued_to_user_id' => $actorUserId,
        ':issued_at' => $issuedAt,
        ':expires_at' => $expiresAt,
        ':created_at' => $issuedAt,
        ':updated_at' => $issuedAt,
    ]);

    $context = videochat_call_app_launch_context($session, $actorUserId, $grantState, [
        'public_id' => $publicId,
        'issued_at' => $issuedAt,
        'expires_at' => $expiresAt,
    ]);
    return [
        'ok' => true,
        'state' => 'issued',
        'launch_token' => $token,
        'launch_token_id' => $publicId,
        'expires_at' => $expiresAt,
        'context' => $context,
    ];
}

function videochat_call_app_validate_launch_token(PDO $pdo, string $sessionId, string $token): array
{
    $trimmedToken = trim($token);
    if ($trimmedToken === '') {
        return ['ok' => false, 'reason' => 'validation_failed', 'errors' => ['launch_token' => 'required']];
    }

    $statement = $pdo->prepare(
        <<<'SQL'
SELECT
    tokens.*,
    sessions.public_id AS session_public_id,
    sessions.tenant_id AS session_tenant_id,
    sessions.status AS session_status
FROM call_app_launch_tokens tokens
INNER JOIN call_app_sessions sessions ON sessions.id = tokens.app_session_id
WHERE sessions.public_id = :session_id
  AND tokens.token_hash = :token_hash
LIMIT 1
SQL
    );
    $statement->execute([
        ':session_id' => trim($sessionId),
        ':token_hash' => videochat_call_app_launch_token_hash($trimmedToken),
    ]);
    $tokenRow = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($tokenRow)) {
        return ['ok' => false, 'reason' => 'token_not_found'];
    }
    if ((string) ($tokenRow['revoked_at'] ?? '') !== '') {
        return ['ok' => false, 'reason' => 'token_revoked'];
    }
    if (strtotime((string) ($tokenRow['expires_at'] ?? '')) <= time()) {
        return ['ok' => false, 'reason' => 'token_expired'];
    }
    if ((string) ($tokenRow['session_status'] ?? '') !== 'active') {
        return ['ok' => false, 'reason' => 'session_not_active'];
    }

    $tenantId = (int) ($tokenRow['tenant_id'] ?? ($tokenRow['session_tenant_id'] ?? 0));
    $userId = (int) ($tokenRow['issued_to_user_id'] ?? 0);
    $record = videochat_call_app_fetch_session_record($pdo, $tenantId, $sessionId);
    $session = videochat_call_app_fetch_session($pdo, $tenantId, $sessionId);
    if (!is_array($record) || !is_array($session)) {
        return ['ok' => false, 'reason' => 'session_not_found'];
    }

    $grantState = videochat_call_app_launch_user_grant_state($pdo, $tenantId, $record, $userId);
    return [
        'ok' => true,
        'state' => 'valid',
        'context' => videochat_call_app_launch_context($session, $userId, $grantState, $tokenRow),
    ];
}
