<?php

declare(strict_types=1);

require_once __DIR__ . '/audit_events.php';

function videochat_audit_lobby_context_role(array $context, string $key, string $fallback = 'user'): string
{
    $role = strtolower(trim((string) ($context[$key] ?? $fallback)));
    if ($role === '') {
        return $fallback;
    }

    if ($key === 'actor_call_role' && function_exists('videochat_normalize_call_participant_role')) {
        return videochat_normalize_call_participant_role($role);
    }
    if ($key === 'actor_role' && function_exists('videochat_normalize_role_slug')) {
        return videochat_normalize_role_slug($role);
    }

    return preg_match('/^[a-z0-9_-]{1,80}$/', $role) === 1 ? $role : $fallback;
}

function videochat_audit_record_call_lobby_event(
    PDO $pdo,
    string $eventType,
    ?int $tenantId,
    string $callId,
    int $targetUserId,
    ?int $actorUserId,
    array $context = []
): array {
    $normalizedEventType = strtolower(trim($eventType));
    $actionsByType = [
        'call_lobby_entry_created' => 'enter_lobby',
        'call_lobby_admission_granted' => 'admit_from_lobby',
        'call_lobby_rejection_recorded' => 'reject_from_lobby',
        'call_lobby_moderation_denied' => 'deny_lobby_moderation',
    ];
    if (!isset($actionsByType[$normalizedEventType])) {
        $normalizedEventType = 'call_lobby_entry_created';
    }

    $sessionId = trim((string) ($context['session_id'] ?? ''));
    $roomId = trim((string) ($context['room_id'] ?? ''));
    $attemptedAction = strtolower(trim((string) ($context['attempted_action'] ?? '')));
    if ($attemptedAction === '' || preg_match('/^lobby\/[a-z_]+$/', $attemptedAction) !== 1) {
        $attemptedAction = '';
    }

    $payload = [
        'audit_scope' => 'iam_lobby',
        'action' => $actionsByType[$normalizedEventType],
        'attempted_action' => $attemptedAction,
        'previous_state' => strtolower(trim((string) ($context['previous_state'] ?? 'unknown'))) ?: 'unknown',
        'next_state' => strtolower(trim((string) ($context['next_state'] ?? 'unknown'))) ?: 'unknown',
        'moderation_authorized' => (bool) ($context['moderation_authorized'] ?? false),
        'actor_role' => videochat_audit_lobby_context_role($context, 'actor_role'),
        'actor_call_role' => videochat_audit_lobby_context_role($context, 'actor_call_role', 'participant'),
        'denial_reason' => strtolower(trim((string) ($context['denial_reason'] ?? ''))) ?: '',
        'room_fingerprint' => $roomId === '' ? '' : videochat_audit_fingerprint($roomId),
        'raw_room_identifier_logged' => false,
        'raw_credential_identifier_logged' => false,
        'raw_guest_identity_logged' => false,
    ];
    if ($normalizedEventType !== 'call_lobby_moderation_denied') {
        unset($payload['denial_reason']);
    }

    return videochat_audit_record_event($pdo, [
        'tenant_id' => is_int($tenantId) && $tenantId > 0 ? $tenantId : null,
        'event_type' => $normalizedEventType,
        'actor_user_id' => is_int($actorUserId) && $actorUserId > 0 ? $actorUserId : null,
        'target_user_id' => $targetUserId > 0 ? $targetUserId : null,
        'call_id' => trim($callId),
        'resource_type' => 'call_lobby_entry',
        'resource_id' => $targetUserId > 0 ? (string) $targetUserId : '',
        'resource_fingerprint' => videochat_audit_fingerprint(trim($callId) . ':' . $targetUserId),
        'session_fingerprint' => $sessionId === '' ? '' : videochat_audit_fingerprint($sessionId),
        'payload' => $payload,
    ]);
}

function videochat_audit_record_call_lobby_entry(
    PDO $pdo,
    ?int $tenantId,
    string $callId,
    int $targetUserId,
    array $context = []
): array {
    return videochat_audit_record_call_lobby_event(
        $pdo,
        'call_lobby_entry_created',
        $tenantId,
        $callId,
        $targetUserId,
        $targetUserId,
        [
            'previous_state' => 'invited',
            'next_state' => 'pending',
        ] + $context
    );
}

function videochat_audit_record_call_lobby_admission(
    PDO $pdo,
    ?int $tenantId,
    string $callId,
    int $actorUserId,
    int $targetUserId,
    array $context = []
): array {
    return videochat_audit_record_call_lobby_event(
        $pdo,
        'call_lobby_admission_granted',
        $tenantId,
        $callId,
        $targetUserId,
        $actorUserId,
        [
            'previous_state' => 'pending',
            'next_state' => 'allowed',
            'moderation_authorized' => true,
        ] + $context
    );
}

function videochat_audit_record_call_lobby_rejection(
    PDO $pdo,
    ?int $tenantId,
    string $callId,
    int $actorUserId,
    int $targetUserId,
    array $context = []
): array {
    return videochat_audit_record_call_lobby_event(
        $pdo,
        'call_lobby_rejection_recorded',
        $tenantId,
        $callId,
        $targetUserId,
        $actorUserId,
        [
            'previous_state' => 'pending',
            'next_state' => 'invited',
            'moderation_authorized' => true,
        ] + $context
    );
}

function videochat_audit_record_call_lobby_moderation_denied(
    PDO $pdo,
    ?int $tenantId,
    string $callId,
    int $actorUserId,
    int $targetUserId,
    array $context = []
): array {
    return videochat_audit_record_call_lobby_event(
        $pdo,
        'call_lobby_moderation_denied',
        $tenantId,
        $callId,
        $targetUserId,
        $actorUserId,
        [
            'previous_state' => 'unchanged',
            'next_state' => 'unchanged',
            'moderation_authorized' => false,
            'denial_reason' => 'forbidden',
        ] + $context
    );
}
