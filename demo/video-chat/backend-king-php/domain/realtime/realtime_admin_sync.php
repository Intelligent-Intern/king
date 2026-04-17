<?php

declare(strict_types=1);

/**
 * @return array{
 *   ok: bool,
 *   type: string,
 *   topic?: string,
 *   reason?: string,
 *   error?: string
 * }
 */
function videochat_admin_sync_decode_client_frame(string $frame): array
{
    $decoded = json_decode($frame, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'type' => '',
            'error' => 'invalid_json',
        ];
    }

    $type = strtolower(trim((string) ($decoded['type'] ?? '')));
    if ($type === '') {
        return [
            'ok' => false,
            'type' => '',
            'error' => 'missing_type',
        ];
    }

    if ($type !== 'admin/sync/publish') {
        return [
            'ok' => false,
            'type' => $type,
            'error' => 'unsupported_type',
        ];
    }

    $topic = strtolower(trim((string) ($decoded['topic'] ?? 'all')));
    if (!in_array($topic, ['all', 'calls', 'users', 'overview'], true)) {
        $topic = 'all';
    }

    $reason = trim((string) ($decoded['reason'] ?? 'updated'));
    if ($reason === '') {
        $reason = 'updated';
    }
    if (strlen($reason) > 80) {
        $reason = substr($reason, 0, 80);
    }

    return [
        'ok' => true,
        'type' => $type,
        'topic' => $topic,
        'reason' => $reason,
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   sent_count?: int,
 *   event?: array<string, mixed>,
 *   error?: string
 * }
 */
function videochat_admin_sync_publish(
    array $presenceState,
    array $connection,
    array $command,
    ?callable $sender = null
): array {
    $sourceUserId = (int) ($connection['user_id'] ?? 0);
    if ($sourceUserId <= 0) {
        return [
            'ok' => false,
            'error' => 'unauthorized',
        ];
    }

    $sourceRole = videochat_normalize_role_slug((string) ($connection['role'] ?? ''));
    if ($sourceRole !== 'admin') {
        return [
            'ok' => false,
            'error' => 'unauthorized',
        ];
    }

    $topic = strtolower(trim((string) ($command['topic'] ?? 'all')));
    if (!in_array($topic, ['all', 'calls', 'users', 'overview'], true)) {
        $topic = 'all';
    }

    $reason = trim((string) ($command['reason'] ?? 'updated'));
    if ($reason === '') {
        $reason = 'updated';
    }
    if (strlen($reason) > 80) {
        $reason = substr($reason, 0, 80);
    }

    $event = [
        'type' => 'admin/sync',
        'topic' => $topic,
        'reason' => $reason,
        'source_user_id' => $sourceUserId,
        'source_role' => $sourceRole,
        'source_session_id' => trim((string) ($connection['session_id'] ?? '')),
        'time' => gmdate('c'),
    ];

    $sentCount = 0;
    foreach (($presenceState['connections'] ?? []) as $candidateConnection) {
        if (!is_array($candidateConnection)) {
            continue;
        }

        $candidateRole = videochat_normalize_role_slug((string) ($candidateConnection['role'] ?? ''));
        if (!in_array($candidateRole, ['admin', 'user'], true)) {
            continue;
        }

        if ((int) ($candidateConnection['user_id'] ?? 0) <= 0) {
            continue;
        }

        if (videochat_presence_send_frame($candidateConnection['socket'] ?? null, $event, $sender)) {
            $sentCount++;
        }
    }

    return [
        'ok' => true,
        'sent_count' => $sentCount,
        'event' => $event,
    ];
}
