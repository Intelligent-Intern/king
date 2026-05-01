<?php

declare(strict_types=1);

function videochat_resolve_call_access_public(PDO $pdo, string $accessId): array
{
    $normalizedAccessId = videochat_normalize_call_access_id($accessId);
    if ($normalizedAccessId === '') {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['access_id' => 'invalid_access_id'],
            'access_link' => null,
            'call' => null,
            'target_user' => null,
            'target_hint' => ['participant_email' => null],
        ];
    }

    $accessLink = videochat_fetch_call_access_link($pdo, $normalizedAccessId);
    if (!is_array($accessLink)) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => ['access_id' => 'not_found'],
            'access_link' => null,
            'call' => null,
            'target_user' => null,
            'target_hint' => ['participant_email' => null],
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
                'target_user' => null,
                'target_hint' => ['participant_email' => null],
            ];
        }
    }

    $call = videochat_fetch_call_for_update($pdo, (string) ($accessLink['call_id'] ?? ''));
    if (!is_array($call)) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => ['call_id' => 'call_not_found'],
            'access_link' => null,
            'call' => null,
            'target_user' => null,
            'target_hint' => ['participant_email' => null],
        ];
    }

    $callStatus = (string) ($call['status'] ?? 'scheduled');
    if (!videochat_is_call_joinable_status($callStatus)) {
        return [
            'ok' => false,
            'reason' => 'conflict',
            'errors' => ['call_id' => 'call_not_joinable_from_status'],
            'access_link' => $accessLink,
            'call' => videochat_build_call_payload($pdo, $call, 0),
            'target_user' => null,
            'target_hint' => [
                'participant_email' => videochat_normalize_call_access_email(
                    is_string($accessLink['participant_email'] ?? null) ? (string) $accessLink['participant_email'] : null
                ) ?: null,
            ],
        ];
    }

    $linkedUserId = is_numeric($accessLink['participant_user_id'] ?? null)
        ? (int) $accessLink['participant_user_id']
        : 0;
    $participantEmail = videochat_normalize_call_access_email(
        is_string($accessLink['participant_email'] ?? null) ? (string) $accessLink['participant_email'] : null
    );
    $targetUser = videochat_fetch_active_user_for_call_access(
        $pdo,
        $linkedUserId,
        $participantEmail === '' ? null : $participantEmail
    );

    $touch = $pdo->prepare(
        'UPDATE call_access_links SET last_used_at = :last_used_at WHERE id = :id'
    );
    $touch->execute([
        ':id' => $normalizedAccessId,
        ':last_used_at' => gmdate('c'),
    ]);

    $freshLink = videochat_fetch_call_access_link($pdo, $normalizedAccessId);
    if (!is_array($freshLink)) {
        $freshLink = $accessLink;
    }

    return [
        'ok' => true,
        'reason' => 'resolved',
        'errors' => [],
        'access_link' => $freshLink,
        'call' => videochat_build_call_payload($pdo, $call, is_array($targetUser) ? (int) ($targetUser['id'] ?? 0) : 0),
        'target_user' => $targetUser,
        'target_hint' => ['participant_email' => $participantEmail === '' ? null : $participantEmail],
    ];
}

/**
 * @param callable(): string $issueSessionId
 * @param array{client_ip?: string, user_agent?: string} $requestMeta
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   session: ?array<string, mixed>,
 *   user: ?array<string, mixed>,
 *   access_link: ?array<string, mixed>,
 *   call: ?array<string, mixed>
 * }
 */
