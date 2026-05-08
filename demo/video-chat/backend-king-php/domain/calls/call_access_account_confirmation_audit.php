<?php

declare(strict_types=1);

require_once __DIR__ . '/../audit/audit_events.php';

function videochat_call_access_account_confirmation_delivery_accepted(array $delivery): bool
{
    return (bool) ($delivery['sent'] ?? false) || (bool) ($delivery['queued'] ?? false);
}

function videochat_call_access_account_confirmation_record_email_dispatch_failed(
    PDO $pdo,
    string $token,
    array $accessLink,
    string $accessFingerprint,
    int $authenticatedUserId,
    string $sessionId,
    array $delivery
): void {
    $delete = $pdo->prepare('DELETE FROM call_access_account_update_confirmations WHERE id = :id');
    $delete->execute([':id' => $token]);

    videochat_audit_record_event($pdo, [
        'tenant_id' => is_numeric($accessLink['tenant_id'] ?? null) ? (int) $accessLink['tenant_id'] : null,
        'event_type' => 'call_access_account_update_confirmation_email_dispatch_failed',
        'actor_user_id' => $authenticatedUserId,
        'target_user_id' => $authenticatedUserId,
        'call_id' => (string) ($accessLink['call_id'] ?? ''),
        'resource_type' => 'call_access_account_update_confirmation',
        'resource_fingerprint' => $accessFingerprint,
        'session_fingerprint' => videochat_audit_fingerprint($sessionId),
        'payload' => [
            'delivery_channel' => (string) ($delivery['channel'] ?? 'unknown'),
            'delivery_succeeded' => false,
            'delivery_queued' => false,
            'sent_to_logged_in_account' => true,
            'sent_to_link_account' => false,
            'confirmation_identifier_logged' => false,
            'raw_link_identifier_logged' => false,
            'recipient_email_logged' => false,
        ],
    ]);
}

function videochat_call_access_account_confirmation_record_account_data_changed(
    PDO $pdo,
    array $confirmation,
    int $userId,
    array $updatedFields
): void {
    videochat_audit_record_event($pdo, [
        'tenant_id' => is_numeric($confirmation['tenant_id'] ?? null) ? (int) $confirmation['tenant_id'] : null,
        'event_type' => 'call_access_account_data_changed',
        'actor_user_id' => $userId,
        'target_user_id' => $userId,
        'call_id' => (string) ($confirmation['call_id'] ?? ''),
        'resource_type' => 'call_access_account_update_confirmation',
        'resource_fingerprint' => (string) ($confirmation['access_fingerprint'] ?? ''),
        'session_fingerprint' => '',
        'payload' => [
            'updated_fields' => array_values($updatedFields),
            'manual_confirmation_required' => true,
            'confirmation_identifier_logged' => false,
            'raw_link_identifier_logged' => false,
            'recipient_email_logged' => false,
        ],
    ]);
}

function videochat_call_access_account_confirmation_record_failure(
    PDO $pdo,
    string $reason,
    array $confirmation = [],
    int $authenticatedUserId = 0,
    array $context = []
): void {
    $normalizedReason = strtolower(trim($reason));
    if ($normalizedReason === '') {
        $normalizedReason = 'unknown';
    }
    $confirmationUserId = is_numeric($confirmation['user_id'] ?? null) ? (int) $confirmation['user_id'] : 0;

    videochat_audit_record_event($pdo, [
        'tenant_id' => is_numeric($confirmation['tenant_id'] ?? null) ? (int) $confirmation['tenant_id'] : null,
        'event_type' => 'call_access_account_update_confirmation_failed',
        'actor_user_id' => $authenticatedUserId > 0 ? $authenticatedUserId : null,
        'target_user_id' => $confirmationUserId > 0 ? $confirmationUserId : null,
        'call_id' => (string) ($confirmation['call_id'] ?? ''),
        'resource_type' => 'call_access_account_update_confirmation',
        'resource_fingerprint' => (string) ($confirmation['access_fingerprint'] ?? ($context['resource_fingerprint'] ?? '')),
        'session_fingerprint' => '',
        'payload' => [
            'reason' => $normalizedReason,
            'failure_stage' => strtolower(trim((string) ($context['failure_stage'] ?? 'confirm'))),
            'account_bound' => $confirmationUserId > 0,
            'confirmation_identifier_logged' => false,
            'raw_link_identifier_logged' => false,
            'recipient_email_logged' => false,
        ],
    ]);
}
