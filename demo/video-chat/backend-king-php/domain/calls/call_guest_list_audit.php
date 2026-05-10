<?php

declare(strict_types=1);

require_once __DIR__ . '/../audit/audit_events.php';
require_once __DIR__ . '/call_management_contract.php';

function videochat_guest_list_audit_normalize_entry(array $entry): array
{
    $userId = (int) ($entry['user_id'] ?? 0);
    $source = $userId > 0 ? 'internal' : strtolower(trim((string) ($entry['source'] ?? 'external')));
    if (!in_array($source, ['internal', 'external'], true)) {
        $source = $userId > 0 ? 'internal' : 'external';
    }

    $email = strtolower(trim((string) ($entry['email'] ?? '')));
    $role = videochat_normalize_call_participant_role((string) ($entry['call_role'] ?? 'participant'));
    $inviteState = videochat_normalize_call_invite_state($entry['invite_state'] ?? 'invited');

    return [
        'key' => $userId > 0 ? 'user:' . $userId : 'email:' . $email,
        'user_id' => $userId,
        'source' => $source,
        'email_fingerprint' => videochat_audit_fingerprint($email),
        'display_name_present' => trim((string) ($entry['display_name'] ?? '')) !== '',
        'call_role' => $role,
        'invite_state' => $inviteState,
        'is_owner' => (bool) ($entry['is_owner'] ?? false) || $role === 'owner',
    ];
}

function videochat_guest_list_audit_event_type(string $mutation, array $before, array $after): string
{
    if ($mutation === 'added') {
        return 'guest_list_entry_added';
    }
    if ($mutation === 'merged') {
        return 'guest_list_entry_merged';
    }
    if ($mutation === 'removed') {
        return 'guest_list_entry_removed';
    }
    if ($mutation === 'restored') {
        return 'guest_list_entry_restored';
    }
    if (($before['call_role'] ?? '') !== ($after['call_role'] ?? '')) {
        return 'guest_list_permission_changed';
    }

    return 'guest_list_entry_updated';
}

function videochat_guest_list_audit_record_change(
    PDO $pdo,
    ?int $tenantId,
    string $callId,
    int $actorUserId,
    string $mutation,
    array $before,
    array $after
): array {
    $subject = $after !== [] ? $after : $before;
    $targetUserId = (int) ($subject['user_id'] ?? 0);
    $resourceId = $targetUserId > 0 ? ('user:' . $targetUserId) : '';
    $fingerprintBasis = $targetUserId > 0
        ? ($callId . ':user:' . $targetUserId)
        : ($callId . ':' . (string) ($subject['email_fingerprint'] ?? ''));

    return videochat_audit_record_event($pdo, [
        'tenant_id' => $tenantId,
        'event_type' => videochat_guest_list_audit_event_type($mutation, $before, $after),
        'actor_user_id' => $actorUserId,
        'target_user_id' => $targetUserId > 0 ? $targetUserId : null,
        'call_id' => $callId,
        'resource_type' => 'call_guest_list_entry',
        'resource_id' => $resourceId,
        'resource_fingerprint' => videochat_audit_fingerprint($fingerprintBasis),
        'payload' => [
            'audit_scope' => 'iam_guest_list',
            'mutation' => $mutation,
            'subject_type' => $targetUserId > 0 ? 'registered_user' : 'external_invitation',
            'source' => (string) ($subject['source'] ?? ''),
            'email_fingerprint' => (string) ($subject['email_fingerprint'] ?? ''),
            'before' => $before === [] ? null : [
                'call_role' => (string) ($before['call_role'] ?? ''),
                'invite_state' => (string) ($before['invite_state'] ?? ''),
                'display_name_present' => (bool) ($before['display_name_present'] ?? false),
            ],
            'after' => $after === [] ? null : [
                'call_role' => (string) ($after['call_role'] ?? ''),
                'invite_state' => (string) ($after['invite_state'] ?? ''),
                'display_name_present' => (bool) ($after['display_name_present'] ?? false),
            ],
            'raw_email_logged' => false,
        ],
    ]);
}

function videochat_guest_list_audit_record_changes(PDO $pdo, ?int $tenantId, string $callId, int $actorUserId, array $changes): bool
{
    foreach ($changes as $change) {
        if (!is_array($change)) {
            continue;
        }
        $audit = videochat_guest_list_audit_record_change(
            $pdo,
            $tenantId,
            $callId,
            $actorUserId,
            (string) ($change['mutation'] ?? ''),
            is_array($change['before'] ?? null) ? $change['before'] : [],
            is_array($change['after'] ?? null) ? $change['after'] : []
        );
        if (!(bool) ($audit['ok'] ?? false)) {
            return false;
        }
    }

    return true;
}

function videochat_guest_list_audit_initial_changes(array $internalParticipants, array $externalParticipants): array
{
    $changes = [];
    foreach ($internalParticipants as $participant) {
        if (!is_array($participant) || (bool) ($participant['is_owner'] ?? false)) {
            continue;
        }
        $changes[] = [
            'mutation' => 'added',
            'before' => [],
            'after' => videochat_guest_list_audit_normalize_entry($participant),
        ];
    }
    foreach ($externalParticipants as $participant) {
        if (!is_array($participant)) {
            continue;
        }
        $participant['source'] = 'external';
        $changes[] = [
            'mutation' => 'added',
            'before' => [],
            'after' => videochat_guest_list_audit_normalize_entry($participant),
        ];
    }

    return $changes;
}

function videochat_guest_list_audit_diff(array $currentInternal, array $currentExternal, array $nextInternal, array $nextExternal): array
{
    $before = [];
    foreach (array_merge($currentInternal, $currentExternal) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $normalized = videochat_guest_list_audit_normalize_entry($entry);
        if ((bool) ($normalized['is_owner'] ?? false)) {
            continue;
        }
        $before[(string) $normalized['key']] = $normalized;
    }

    $after = [];
    foreach (array_merge($nextInternal, $nextExternal) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $normalized = videochat_guest_list_audit_normalize_entry($entry);
        if ((bool) ($normalized['is_owner'] ?? false)) {
            continue;
        }
        $after[(string) $normalized['key']] = $normalized;
    }

    $changes = [];
    foreach ($after as $key => $next) {
        if (!isset($before[$key])) {
            $changes[] = ['mutation' => 'added', 'before' => [], 'after' => $next];
            continue;
        }
        $previous = $before[$key];
        if (
            (string) ($previous['call_role'] ?? '') !== (string) ($next['call_role'] ?? '')
            || (string) ($previous['invite_state'] ?? '') !== (string) ($next['invite_state'] ?? '')
            || (bool) ($previous['display_name_present'] ?? false) !== (bool) ($next['display_name_present'] ?? false)
        ) {
            $changes[] = ['mutation' => 'updated', 'before' => $previous, 'after' => $next];
        }
    }
    foreach ($before as $key => $previous) {
        if (!isset($after[$key])) {
            $changes[] = ['mutation' => 'removed', 'before' => $previous, 'after' => []];
        }
    }

    return $changes;
}
