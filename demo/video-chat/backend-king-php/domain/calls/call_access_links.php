<?php

declare(strict_types=1);

function videochat_create_call_access_link_for_user(
    PDO $pdo,
    string $callId,
    int $authUserId,
    string $authRole,
    array $options = [],
    ?int $tenantId = null
): array {
    $normalizedCallId = trim($callId);
    if ($normalizedCallId === '') {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['call_id' => 'required_call_id'],
            'access_link' => null,
            'call' => null,
        ];
    }

    if ($authUserId <= 0) {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => ['auth' => 'invalid_user_context'],
            'access_link' => null,
            'call' => null,
        ];
    }

    $callFetch = videochat_get_call_for_user($pdo, $normalizedCallId, $authUserId, $authRole, $tenantId);
    if (!(bool) ($callFetch['ok'] ?? false)) {
        return [
            'ok' => false,
            'reason' => (string) ($callFetch['reason'] ?? 'forbidden'),
            'errors' => [],
            'access_link' => null,
            'call' => null,
        ];
    }

    $call = is_array($callFetch['call'] ?? null) ? $callFetch['call'] : null;
    if (!is_array($call)) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => ['call_id' => 'call_not_found'],
            'access_link' => null,
            'call' => null,
        ];
    }

    $expiresAt = is_string($call['ends_at'] ?? null) ? trim((string) $call['ends_at']) : '';
    if ($expiresAt === '') {
        $expiresAt = null;
    }

    $callAccessMode = videochat_normalize_call_access_mode((string) ($call['access_mode'] ?? 'invite_only'));
    $linkKindInput = strtolower(trim((string) ($options['link_kind'] ?? '')));
    if ($linkKindInput === '') {
        $linkKind = $callAccessMode === 'free_for_all' ? 'open' : 'personal';
    } elseif (in_array($linkKindInput, ['personal', 'open'], true)) {
        $linkKind = $linkKindInput;
    } else {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['link_kind' => 'must_be_personal_or_open'],
            'access_link' => null,
            'call' => null,
        ];
    }

    if ($callAccessMode === 'free_for_all' && $linkKind !== 'open') {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['link_kind' => 'free_for_all_requires_open_link'],
            'access_link' => null,
            'call' => null,
        ];
    }

    if ($callAccessMode === 'invite_only' && $linkKind !== 'personal') {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['link_kind' => 'invite_only_requires_personal_link'],
            'access_link' => null,
            'call' => null,
        ];
    }

    $isOpenLinkRequest = $linkKind === 'open';

    $targetUserId = $isOpenLinkRequest
        ? 0
        : (is_numeric($options['participant_user_id'] ?? null)
            ? (int) $options['participant_user_id']
            : $authUserId);
    if ($targetUserId < 0) {
        $targetUserId = 0;
    }
    $targetEmail = $isOpenLinkRequest ? '' : videochat_normalize_call_access_email(
        is_string($options['participant_email'] ?? null) ? (string) $options['participant_email'] : null
    );

    $ownerUserId = (int) (($call['owner']['user_id'] ?? 0));
    $actsForAnotherTarget = $isOpenLinkRequest || $targetUserId !== $authUserId || ($targetUserId <= 0 && $targetEmail !== '');
    if ($actsForAnotherTarget && !videochat_can_edit_call($authRole, $authUserId, $ownerUserId)) {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => ['call_id' => 'not_allowed_for_call'],
            'access_link' => null,
            'call' => null,
        ];
    }

    $participantEmail = null;
    if ($isOpenLinkRequest) {
        $participantEmail = null;
    } elseif ($targetUserId > 0) {
        $targetUser = videochat_fetch_active_user_for_call_access($pdo, $targetUserId, null, $tenantId);
        if (!is_array($targetUser)) {
            return [
                'ok' => false,
                'reason' => 'not_found',
                'errors' => ['participant_user_id' => 'user_not_found_or_inactive'],
                'access_link' => null,
                'call' => null,
            ];
        }
        $participantEmail = videochat_normalize_call_access_email((string) ($targetUser['email'] ?? ''));
    } else {
        if ($targetEmail !== '') {
            $participantEmail = $targetEmail;
        } else {
            $authUser = videochat_fetch_active_user_for_call_access($pdo, $authUserId, null, $tenantId);
            $participantEmail = is_array($authUser)
                ? videochat_normalize_call_access_email((string) ($authUser['email'] ?? ''))
                : '';
        }

        if ($participantEmail === '') {
            return [
                'ok' => false,
                'reason' => 'validation_failed',
                'errors' => ['participant_email' => 'required_valid_email'],
                'access_link' => null,
                'call' => null,
            ];
        }
    }

    try {
        $pdo->beginTransaction();

        $existing = false;
        if ($isOpenLinkRequest) {
            $existingQuery = $pdo->prepare(
                <<<'SQL'
SELECT id
FROM call_access_links
WHERE call_id = :call_id
  AND participant_user_id IS NULL
  AND (participant_email IS NULL OR trim(participant_email) = '')
LIMIT 1
SQL
            );
            $existingQuery->execute([
                ':call_id' => $normalizedCallId,
            ]);
            $existing = $existingQuery->fetch();
        } elseif ($targetUserId > 0) {
            $existingQuery = $pdo->prepare(
                <<<'SQL'
SELECT id
FROM call_access_links
WHERE call_id = :call_id
  AND participant_user_id = :participant_user_id
LIMIT 1
SQL
            );
            $existingQuery->execute([
                ':call_id' => $normalizedCallId,
                ':participant_user_id' => $targetUserId,
            ]);
            $existing = $existingQuery->fetch();
        } else {
            $existingQuery = $pdo->prepare(
                <<<'SQL'
SELECT id
FROM call_access_links
WHERE call_id = :call_id
  AND participant_user_id IS NULL
  AND lower(participant_email) = lower(:participant_email)
LIMIT 1
SQL
            );
            $existingQuery->execute([
                ':call_id' => $normalizedCallId,
                ':participant_email' => $participantEmail,
            ]);
            $existing = $existingQuery->fetch();
        }

        $accessId = '';
        if (is_array($existing) && is_string($existing['id'] ?? null)) {
            $accessId = strtolower(trim((string) $existing['id']));
        } else {
            $accessId = videochat_generate_call_access_uuid();
            $tenantColumn = is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'call_access_links', 'tenant_id')
                ? ', tenant_id'
                : '';
            $tenantValue = $tenantColumn !== '' ? ', :tenant_id' : '';
            $insert = $pdo->prepare(
                <<<SQL
INSERT INTO call_access_links(
    id,
    call_id,
    participant_user_id,
    participant_email,
    invite_code_id,
    created_by_user_id,
    created_at,
    expires_at,
    last_used_at,
    consumed_at{$tenantColumn}
) VALUES(
    :id,
    :call_id,
    :participant_user_id,
    :participant_email,
    NULL,
    :created_by_user_id,
    :created_at,
    :expires_at,
    NULL,
    NULL{$tenantValue}
)
SQL
            );
            $insertParams = [
                ':id' => $accessId,
                ':call_id' => $normalizedCallId,
                ':participant_user_id' => $targetUserId > 0 ? $targetUserId : null,
                ':participant_email' => $participantEmail,
                ':created_by_user_id' => $authUserId,
                ':created_at' => gmdate('c'),
                ':expires_at' => $expiresAt,
            ];
            if ($tenantColumn !== '') {
                $insertParams[':tenant_id'] = $tenantId;
            }
            $insert->execute($insertParams);
        }

        $touch = $pdo->prepare(
            'UPDATE call_access_links SET last_used_at = :last_used_at WHERE id = :id'
        );
        $touch->execute([
            ':id' => $accessId,
            ':last_used_at' => gmdate('c'),
        ]);

        $accessLink = videochat_fetch_call_access_link($pdo, $accessId, $tenantId);
        if (!is_array($accessLink)) {
            $pdo->rollBack();
            return [
                'ok' => false,
                'reason' => 'internal_error',
                'errors' => [],
                'access_link' => null,
                'call' => null,
            ];
        }

        $pdo->commit();

        return [
            'ok' => true,
            'reason' => 'ready',
            'errors' => [],
            'access_link' => $accessLink,
            'call' => $call,
        ];
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'access_link' => null,
            'call' => null,
        ];
    }
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   access_link: ?array<string, mixed>,
 *   call: ?array<string, mixed>
 * }
 */
function videochat_resolve_call_access_for_user(
    PDO $pdo,
    string $accessId,
    int $authUserId,
    string $authRole,
    ?int $tenantId = null
): array {
    $normalizedAccessId = videochat_normalize_call_access_id($accessId);
    if ($normalizedAccessId === '') {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['access_id' => 'invalid_access_id'],
            'access_link' => null,
            'call' => null,
        ];
    }

    if ($authUserId <= 0) {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => ['auth' => 'invalid_user_context'],
            'access_link' => null,
            'call' => null,
        ];
    }

    $accessLink = videochat_fetch_call_access_link($pdo, $normalizedAccessId, $tenantId);
    if (!is_array($accessLink)) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => ['access_id' => 'not_found'],
            'access_link' => null,
            'call' => null,
        ];
    }

    $expiresAt = is_string($accessLink['expires_at'] ?? null) ? (string) $accessLink['expires_at'] : '';
    if ($expiresAt !== '') {
        $expiresAtUnix = strtotime($expiresAt);
        if (!is_int($expiresAtUnix) || $expiresAtUnix <= time()) {
            return [
                'ok' => false,
                'reason' => 'expired',
                'errors' => ['access_id' => 'expired'],
                'access_link' => null,
                'call' => null,
            ];
        }
    }

    $linkedUserId = is_numeric($accessLink['participant_user_id'] ?? null)
        ? (int) $accessLink['participant_user_id']
        : 0;
    if ($linkedUserId > 0 && $linkedUserId !== $authUserId && videochat_normalize_role_slug($authRole) !== 'admin') {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => ['access_id' => 'not_bound_to_current_user'],
            'access_link' => null,
            'call' => null,
        ];
    }

    $callId = trim((string) ($accessLink['call_id'] ?? ''));
    $callFetch = videochat_get_call_for_user($pdo, $callId, $authUserId, $authRole, $tenantId);
    if (!(bool) ($callFetch['ok'] ?? false)) {
        return [
            'ok' => false,
            'reason' => (string) ($callFetch['reason'] ?? 'forbidden'),
            'errors' => [],
            'access_link' => null,
            'call' => null,
        ];
    }

    $call = is_array($callFetch['call'] ?? null) ? $callFetch['call'] : null;
    if (!is_array($call)) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => ['call_id' => 'call_not_found'],
            'access_link' => null,
            'call' => null,
        ];
    }

    $touch = $pdo->prepare(
        'UPDATE call_access_links SET last_used_at = :last_used_at WHERE id = :id'
    );
    $touch->execute([
        ':id' => $normalizedAccessId,
        ':last_used_at' => gmdate('c'),
    ]);

    $freshLink = videochat_fetch_call_access_link($pdo, $normalizedAccessId, $tenantId);
    if (!is_array($freshLink)) {
        $freshLink = $accessLink;
    }

    return [
        'ok' => true,
        'reason' => 'resolved',
        'errors' => [],
        'access_link' => $freshLink,
        'call' => $call,
    ];
}
