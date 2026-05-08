<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/audit/audit_events.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';

function videochat_audit_call_access_membership_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[audit-call-access-membership-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_audit_call_access_membership_event_types(array $events): array
{
    $types = [];
    foreach ($events as $event) {
        if (is_array($event)) {
            $types[(string) ($event['event_type'] ?? '')] = true;
        }
    }

    return $types;
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[audit-call-access-membership-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-audit-call-access-membership-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $invitedUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_audit_call_access_membership_assert($tenantId > 0, 'default tenant should exist');
    videochat_audit_call_access_membership_assert($adminUserId > 0, 'admin user should exist');
    videochat_audit_call_access_membership_assert($invitedUserId > 0, 'invited user should exist');

    $createCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Audit Membership Removal Access',
        'starts_at' => '2026-09-04T09:00:00Z',
        'ends_at' => '2026-09-04T10:00:00Z',
        'internal_participant_user_ids' => [$invitedUserId],
        'external_participants' => [],
    ], $tenantId);
    videochat_audit_call_access_membership_assert((bool) ($createCall['ok'] ?? false), 'call should be created');
    $callId = (string) (($createCall['call'] ?? [])['id'] ?? '');
    videochat_audit_call_access_membership_assert($callId !== '', 'call id should be present');

    $access = videochat_create_call_access_link_for_user($pdo, $callId, $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $invitedUserId,
    ], $tenantId);
    videochat_audit_call_access_membership_assert((bool) ($access['ok'] ?? false), 'personal access link should be created');
    $accessId = (string) (($access['access_link'] ?? [])['id'] ?? '');
    videochat_audit_call_access_membership_assert($accessId !== '', 'access id should be present');

    $probe = videochat_audit_record_event($pdo, [
        'tenant_id' => $tenantId,
        'event_type' => 'audit_sanitizer_probe',
        'actor_user_id' => $adminUserId,
        'target_user_id' => $invitedUserId,
        'call_id' => $callId,
        'resource_type' => 'audit_probe',
        'payload' => [
            'safe_reason' => 'contract_probe',
            'session_id' => 'sess_raw_should_not_persist',
            'token' => 'raw-token-should-not-persist',
            'sdp' => "v=0\r\no=- 1 2 IN IP4 127.0.0.1",
            'media' => ['frame' => 'raw-frame-should-not-persist'],
            'nested' => [
                'safe_value' => 'kept',
                'ice_candidate' => 'candidate:1 1 udp 1 127.0.0.1 9 typ host',
            ],
        ],
    ]);
    videochat_audit_call_access_membership_assert((bool) ($probe['ok'] ?? false), 'sanitizer probe should be audit-loggable');

    $removedAt = gmdate('c');
    $pdo->prepare('UPDATE group_memberships SET status = \'disabled\', updated_at = :updated_at WHERE tenant_id = :tenant_id AND user_id = :user_id')->execute([
        ':updated_at' => $removedAt,
        ':tenant_id' => $tenantId,
        ':user_id' => $invitedUserId,
    ]);
    $pdo->prepare('UPDATE organization_memberships SET status = \'disabled\', updated_at = :updated_at WHERE tenant_id = :tenant_id AND user_id = :user_id')->execute([
        ':updated_at' => $removedAt,
        ':tenant_id' => $tenantId,
        ':user_id' => $invitedUserId,
    ]);
    $pdo->prepare('UPDATE tenant_memberships SET status = \'disabled\', updated_at = :updated_at WHERE tenant_id = :tenant_id AND user_id = :user_id')->execute([
        ':updated_at' => $removedAt,
        ':tenant_id' => $tenantId,
        ':user_id' => $invitedUserId,
    ]);

    videochat_audit_call_access_membership_assert(
        !videochat_tenant_user_is_member($pdo, $invitedUserId, $tenantId),
        'invited user should be removed from tenant before link open'
    );
    $membershipAudit = videochat_audit_record_membership_removal($pdo, $tenantId, $invitedUserId, $adminUserId, [
        'removed_scopes' => ['tenant', 'organization', 'group'],
        'call_id' => $callId,
        'access_id' => $accessId,
        'call_scoped_invitation_preserved' => true,
    ]);
    videochat_audit_call_access_membership_assert((bool) ($membershipAudit['ok'] ?? false), 'membership removal should be audit-loggable');

    $publicResolution = videochat_resolve_call_access_public($pdo, $accessId);
    videochat_audit_call_access_membership_assert((bool) ($publicResolution['ok'] ?? false), 'link open should still resolve after membership removal');

    $sessionId = 'sess_audit_call_scoped_removed_member';
    $session = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static fn (): string => $sessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => 'audit-call-access-membership-contract']
    );
    videochat_audit_call_access_membership_assert((bool) ($session['ok'] ?? false), 'removed member should receive call-scoped session');

    $events = videochat_audit_fetch_events($pdo, ['tenant_id' => $tenantId, 'call_id' => $callId, 'limit' => 50]);
    $eventTypes = videochat_audit_call_access_membership_event_types($events);
    videochat_audit_call_access_membership_assert(isset($eventTypes['audit_sanitizer_probe']), 'sanitizer probe audit event missing');
    videochat_audit_call_access_membership_assert(isset($eventTypes['membership_removed']), 'membership removal audit event missing');
    videochat_audit_call_access_membership_assert(isset($eventTypes['call_access_link_opened']), 'link open audit event missing');
    videochat_audit_call_access_membership_assert(isset($eventTypes['call_scoped_access_continued']), 'continued call-scoped access audit event missing');

    $encodedEvents = json_encode($events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    videochat_audit_call_access_membership_assert(is_string($encodedEvents), 'audit events should encode');
    foreach ([
        $accessId,
        $sessionId,
        'sess_raw_should_not_persist',
        'raw-token-should-not-persist',
        'raw-frame-should-not-persist',
        'candidate:1',
        'v=0',
    ] as $forbiddenText) {
        videochat_audit_call_access_membership_assert(
            !str_contains($encodedEvents, $forbiddenText),
            'audit events must not leak sensitive text: ' . $forbiddenText
        );
    }
    videochat_audit_call_access_membership_assert(
        str_contains($encodedEvents, videochat_audit_fingerprint($accessId)),
        'audit events should retain access-link fingerprint'
    );
    videochat_audit_call_access_membership_assert(
        str_contains($encodedEvents, videochat_audit_fingerprint($sessionId)),
        'continued-access audit event should retain session fingerprint'
    );

    @unlink($databasePath);
    fwrite(STDOUT, "[audit-call-access-membership-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[audit-call-access-membership-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
