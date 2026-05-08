<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';

function videochat_call_access_decision_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-decision-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-decision-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-decision-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $standardUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_call_access_decision_assert($tenantId > 0, 'expected default tenant');
    videochat_call_access_decision_assert($adminUserId > 0, 'expected seeded admin user');
    videochat_call_access_decision_assert($standardUserId > 0, 'expected seeded standard user');

    $createInviteOnly = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Call Access Decision Invite Only',
        'starts_at' => '2026-09-05T09:00:00Z',
        'ends_at' => '2026-09-05T10:00:00Z',
        'internal_participant_user_ids' => [$standardUserId],
        'external_participants' => [],
    ], $tenantId);
    videochat_call_access_decision_assert((bool) ($createInviteOnly['ok'] ?? false), 'invite-only call should be created');
    $inviteOnlyCallId = (string) (($createInviteOnly['call'] ?? [])['id'] ?? '');
    videochat_call_access_decision_assert($inviteOnlyCallId !== '', 'invite-only call id should be present');

    $adminDecision = videochat_decide_call_access_for_user($pdo, $inviteOnlyCallId, $adminUserId, 'admin', $tenantId);
    videochat_call_access_decision_assert((bool) ($adminDecision['allowed'] ?? false), 'admin should be allowed');
    videochat_call_access_decision_assert((string) ($adminDecision['source'] ?? '') === 'system_admin', 'admin decision source should be system_admin');
    videochat_call_access_decision_assert((string) ($adminDecision['scope'] ?? '') === 'system', 'admin decision scope should stay system');
    videochat_call_access_decision_assert((string) ($adminDecision['effective_call_role'] ?? '') === 'owner', 'admin effective call role should be owner');
    videochat_call_access_decision_assert((bool) ($adminDecision['can_administer'] ?? false), 'admin decision should administer');

    $ownerDecision = videochat_decide_call_access_for_user($pdo, $inviteOnlyCallId, $adminUserId, 'user', $tenantId);
    videochat_call_access_decision_assert((bool) ($ownerDecision['allowed'] ?? false), 'owner should be allowed without global admin role');
    videochat_call_access_decision_assert((string) ($ownerDecision['source'] ?? '') === 'owner', 'owner decision source mismatch');
    videochat_call_access_decision_assert((string) ($ownerDecision['scope'] ?? '') === 'call', 'owner decision should be call-scoped');
    videochat_call_access_decision_assert((bool) ($ownerDecision['can_manage_owner'] ?? false), 'owner should manage owner-scoped call state');

    $participantDecision = videochat_decide_call_access_for_user($pdo, $inviteOnlyCallId, $standardUserId, 'user', $tenantId);
    videochat_call_access_decision_assert((bool) ($participantDecision['allowed'] ?? false), 'internal participant should be allowed');
    videochat_call_access_decision_assert((string) ($participantDecision['source'] ?? '') === 'internal_participant', 'participant decision source mismatch');
    videochat_call_access_decision_assert((string) ($participantDecision['scope'] ?? '') === 'call', 'participant decision should be call-scoped');
    videochat_call_access_decision_assert((string) ($participantDecision['call_role'] ?? '') === 'participant', 'participant call role mismatch');
    videochat_call_access_decision_assert((string) ($participantDecision['invite_state'] ?? '') === 'invited', 'participant invite state should be preserved');
    videochat_call_access_decision_assert(!(bool) ($participantDecision['can_administer'] ?? true), 'plain participant must not administer');

    $access = videochat_create_call_access_link_for_user($pdo, $inviteOnlyCallId, $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $standardUserId,
    ], $tenantId);
    videochat_call_access_decision_assert((bool) ($access['ok'] ?? false), 'personal access link should be created');
    $accessId = (string) (($access['access_link'] ?? [])['id'] ?? '');
    videochat_call_access_decision_assert($accessId !== '', 'personal access id should be present');

    $removedAt = gmdate('c');
    $pdo->prepare('UPDATE tenant_memberships SET status = \'disabled\', updated_at = :updated_at WHERE tenant_id = :tenant_id AND user_id = :user_id')->execute([
        ':updated_at' => $removedAt,
        ':tenant_id' => $tenantId,
        ':user_id' => $standardUserId,
    ]);
    videochat_call_access_decision_assert(
        !videochat_tenant_user_is_member($pdo, $standardUserId, $tenantId),
        'removed tenant member should lose tenant membership before call-scoped decision'
    );

    $removedMemberDecision = videochat_decide_call_access_for_user($pdo, $inviteOnlyCallId, $standardUserId, 'user', $tenantId);
    videochat_call_access_decision_assert((bool) ($removedMemberDecision['allowed'] ?? false), 'removed tenant member should keep call-scoped participant access');
    videochat_call_access_decision_assert((string) ($removedMemberDecision['source'] ?? '') === 'internal_participant', 'removed member should be allowed only by participant row');
    videochat_call_access_decision_assert((string) ($removedMemberDecision['scope'] ?? '') === 'call', 'removed member decision should stay call-scoped');

    $resolvedAfterTenantRemoval = videochat_resolve_call_access_for_user($pdo, $accessId, $standardUserId, 'user', $tenantId);
    videochat_call_access_decision_assert((bool) ($resolvedAfterTenantRemoval['ok'] ?? false), 'authenticated personal link should resolve after tenant membership removal');
    videochat_call_access_decision_assert(
        (string) (($resolvedAfterTenantRemoval['call'] ?? [])['id'] ?? '') === $inviteOnlyCallId,
        'resolved call should still match personal access call after membership removal'
    );

    $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = 'cancelled'
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
    )->execute([
        ':call_id' => $inviteOnlyCallId,
        ':user_id' => $standardUserId,
    ]);

    $cancelledParticipantDecision = videochat_decide_call_access_for_user($pdo, $inviteOnlyCallId, $standardUserId, 'user', $tenantId);
    videochat_call_access_decision_assert(!(bool) ($cancelledParticipantDecision['allowed'] ?? true), 'cancelled guest-list entry should not retain invite-only access');
    videochat_call_access_decision_assert((string) ($cancelledParticipantDecision['reason'] ?? '') === 'guest_list_entry_inactive', 'cancelled participant denial reason should be explicit');

    $cancelledParticipantFetch = videochat_get_call_for_user($pdo, $inviteOnlyCallId, $standardUserId, 'user', $tenantId);
    videochat_call_access_decision_assert(!(bool) ($cancelledParticipantFetch['ok'] ?? true), 'cancelled guest-list entry should not fetch invite-only call directly');
    videochat_call_access_decision_assert((string) ($cancelledParticipantFetch['reason'] ?? '') === 'forbidden', 'cancelled participant fetch denial reason mismatch');

    $resolveCancelledLink = videochat_resolve_call_access_for_user($pdo, $accessId, $standardUserId, 'user', $tenantId);
    videochat_call_access_decision_assert(!(bool) ($resolveCancelledLink['ok'] ?? true), 'cancelled guest-list personal link should not resolve');
    videochat_call_access_decision_assert((string) ($resolveCancelledLink['reason'] ?? '') === 'not_found', 'cancelled guest-list personal link should be hidden as not_found');

    $pdo->prepare(
        <<<'SQL'
DELETE FROM call_participants
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
    )->execute([
        ':call_id' => $inviteOnlyCallId,
        ':user_id' => $standardUserId,
    ]);

    $removedParticipantDecision = videochat_decide_call_access_for_user($pdo, $inviteOnlyCallId, $standardUserId, 'user', $tenantId);
    videochat_call_access_decision_assert(!(bool) ($removedParticipantDecision['allowed'] ?? true), 'removed call participant should not retain invite-only access');
    videochat_call_access_decision_assert((string) ($removedParticipantDecision['reason'] ?? '') === 'forbidden', 'removed participant denial reason should be forbidden');
    videochat_call_access_decision_assert((string) ($removedParticipantDecision['scope'] ?? '') === 'none', 'removed participant denial should not claim a call scope');

    $resolvedAfterParticipantRemoval = videochat_resolve_call_access_for_user($pdo, $accessId, $standardUserId, 'user', $tenantId);
    videochat_call_access_decision_assert(!(bool) ($resolvedAfterParticipantRemoval['ok'] ?? true), 'personal link should not override call participant removal');
    videochat_call_access_decision_assert((string) ($resolvedAfterParticipantRemoval['reason'] ?? '') === 'forbidden', 'personal link denial after participant removal should be forbidden');

    $createOpen = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Call Access Decision Open',
        'access_mode' => 'free_for_all',
        'starts_at' => '2026-09-06T09:00:00Z',
        'ends_at' => '2026-09-06T10:00:00Z',
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ], $tenantId);
    videochat_call_access_decision_assert((bool) ($createOpen['ok'] ?? false), 'free-for-all call should be created');
    $openCallId = (string) (($createOpen['call'] ?? [])['id'] ?? '');
    videochat_call_access_decision_assert($openCallId !== '', 'free-for-all call id should be present');

    $freeForAllDecision = videochat_decide_call_access_for_user($pdo, $openCallId, $standardUserId, 'user', $tenantId);
    videochat_call_access_decision_assert((bool) ($freeForAllDecision['allowed'] ?? false), 'free-for-all call should allow a nonparticipant user');
    videochat_call_access_decision_assert((string) ($freeForAllDecision['source'] ?? '') === 'free_for_all', 'free-for-all decision source mismatch');
    videochat_call_access_decision_assert((string) ($freeForAllDecision['scope'] ?? '') === 'call', 'free-for-all decision should be call-scoped');
    videochat_call_access_decision_assert((string) ($freeForAllDecision['invite_state'] ?? '') === 'allowed', 'free-for-all decision should be immediately allowed');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-access-decision-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-decision-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
