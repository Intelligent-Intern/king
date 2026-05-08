<?php

declare(strict_types=1);

$contract = 'call-guest-cleanup-lifecycle-remaining-contract';

require_once __DIR__ . '/call-guest-cleanup-lifecycle-helper.php';

function videochat_call_guest_cleanup_remaining_count(PDO $pdo, string $sql, array $params = []): int
{
    $query = $pdo->prepare($sql);
    $query->execute($params);
    return max(0, (int) ($query->fetchColumn() ?: 0));
}

function videochat_call_guest_cleanup_remaining_participant(PDO $pdo, string $callId, int $userId): array
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT invite_state, joined_at, left_at
FROM call_participants
WHERE call_id = :call_id
  AND user_id = :user_id
LIMIT 1
SQL
    );
    $query->execute([':call_id' => $callId, ':user_id' => $userId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function videochat_call_guest_cleanup_remaining_add_temp_guest(PDO $pdo, string $callId, int $tenantId, string $name, string $state, bool $joined, string $contract): int
{
    $guestCreate = videochat_create_guest_user_for_call_access($pdo, $name, $tenantId);
    videochat_call_guest_cleanup_assert((bool) ($guestCreate['ok'] ?? false), "{$name} should be created", $contract);
    $guest = is_array($guestCreate['user'] ?? null) ? $guestCreate['user'] : [];
    $guestUserId = (int) ($guest['id'] ?? 0);
    videochat_call_guest_cleanup_assert($guestUserId > 0, "{$name} id missing", $contract);
    videochat_ensure_internal_call_participant(
        $pdo,
        $callId,
        $guestUserId,
        (string) ($guest['email'] ?? ''),
        (string) ($guest['display_name'] ?? ''),
        $state
    );
    if ($joined) {
        $update = $pdo->prepare(
            <<<'SQL'
UPDATE call_participants
SET joined_at = :joined_at,
    left_at = NULL
WHERE call_id = :call_id
  AND user_id = :user_id
SQL
        );
        $update->execute([
            ':joined_at' => '2026-10-02T09:10:00Z',
            ':call_id' => $callId,
            ':user_id' => $guestUserId,
        ]);
    }

    return $guestUserId;
}

/**
 * @return array<string, mixed>
 */
function videochat_call_guest_cleanup_remaining_create_unjoined_fixture(PDO $pdo, array $context, string $contract): array
{
    $tenantId = (int) $context['tenant_id'];
    $adminUserId = (int) $context['admin_user_id'];
    $registeredUserId = (int) $context['registered_user_id'];

    $created = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Delete Before Guest Joins Cleanup',
        'starts_at' => '2026-10-04T09:00:00Z',
        'ends_at' => '2026-10-04T10:00:00Z',
        'internal_participant_user_ids' => [$registeredUserId],
        'external_participants' => [],
    ], $tenantId);
    videochat_call_guest_cleanup_assert((bool) ($created['ok'] ?? false), 'delete-before-join call should be created', $contract);
    $callId = (string) (($created['call'] ?? [])['id'] ?? '');
    videochat_call_guest_cleanup_assert($callId !== '', 'delete-before-join call id missing', $contract);

    $guestCreate = videochat_create_guest_user_for_call_access($pdo, 'Delete Before Join Guest', $tenantId);
    videochat_call_guest_cleanup_assert((bool) ($guestCreate['ok'] ?? false), 'delete-before-join temporary guest should be created', $contract);
    $guest = is_array($guestCreate['user'] ?? null) ? $guestCreate['user'] : [];
    $guestUserId = (int) ($guest['id'] ?? 0);
    videochat_call_guest_cleanup_assert($guestUserId > 0 && (bool) ($guest['is_guest'] ?? false), 'delete-before-join temporary guest id missing', $contract);
    videochat_ensure_internal_call_participant(
        $pdo,
        $callId,
        $guestUserId,
        (string) ($guest['email'] ?? ''),
        (string) ($guest['display_name'] ?? ''),
        'allowed'
    );

    $access = videochat_create_call_access_link_for_user($pdo, $callId, $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $guestUserId,
    ], $tenantId);
    videochat_call_guest_cleanup_assert((bool) ($access['ok'] ?? false), 'delete-before-join personalized link should be created', $contract);
    $accessId = (string) (($access['access_link'] ?? [])['id'] ?? '');
    videochat_call_guest_cleanup_assert($accessId !== '', 'delete-before-join access id missing', $contract);

    $participant = videochat_call_guest_cleanup_remaining_participant($pdo, $callId, $guestUserId);
    videochat_call_guest_cleanup_assert(trim((string) ($participant['joined_at'] ?? '')) === '', 'delete-before-join guest must not have joined', $contract);
    videochat_call_guest_cleanup_assert(
        videochat_call_guest_cleanup_remaining_count($pdo, 'SELECT COUNT(*) FROM call_access_sessions WHERE access_id = :access_id', [':access_id' => $accessId]) === 0,
        'delete-before-join guest must not have a call-access session',
        $contract
    );

    return [
        'call_id' => $callId,
        'guest_user_id' => $guestUserId,
        'guest_email' => (string) ($guest['email'] ?? ''),
        'guest_access_id' => $accessId,
        'registered_user_id' => $registeredUserId,
    ];
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[{$contract}] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $context = videochat_call_guest_cleanup_bootstrap($contract);
    $pdo = $context['pdo'];
    videochat_call_guest_cleanup_assert($pdo instanceof PDO, 'pdo fixture missing', $contract);
    $tenantId = (int) $context['tenant_id'];
    $adminUserId = (int) $context['admin_user_id'];
    $registeredUserId = (int) $context['registered_user_id'];

    $deleteBeforeJoinFixture = videochat_call_guest_cleanup_remaining_create_unjoined_fixture($pdo, $context, $contract);
    $deleteBeforeJoinCallId = (string) $deleteBeforeJoinFixture['call_id'];
    $deleteBeforeJoinGuestId = (int) $deleteBeforeJoinFixture['guest_user_id'];
    $deleteBeforeJoinAccessId = (string) $deleteBeforeJoinFixture['guest_access_id'];
    $deleteBeforeJoin = videochat_delete_call($pdo, $deleteBeforeJoinCallId, $adminUserId, 'admin', $tenantId);
    videochat_call_guest_cleanup_assert((bool) ($deleteBeforeJoin['ok'] ?? false), 'owner delete before any guest joins should succeed', $contract);
    $deleteBeforeJoinCleanup = is_array($deleteBeforeJoin['guest_cleanup'] ?? null) ? $deleteBeforeJoin['guest_cleanup'] : [];
    videochat_call_guest_cleanup_assert((bool) ($deleteBeforeJoinCleanup['ok'] ?? false), 'delete-before-join guest cleanup should succeed', $contract);
    videochat_call_guest_cleanup_assert((int) ($deleteBeforeJoinCleanup['invalidated_guests'] ?? 0) === 1, 'delete-before-join should invalidate the unjoined temporary guest', $contract);
    videochat_call_guest_cleanup_assert((int) ($deleteBeforeJoinCleanup['revoked_sessions'] ?? -1) === 0, 'delete-before-join should not revoke nonexistent guest sessions', $contract);
    videochat_call_guest_cleanup_assert(in_array($deleteBeforeJoinGuestId, $deleteBeforeJoinCleanup['guest_user_ids'] ?? [], true), 'delete-before-join cleanup should include unjoined temporary guest', $contract);
    videochat_call_guest_cleanup_assert((string) (videochat_call_guest_cleanup_user($pdo, $deleteBeforeJoinGuestId)['status'] ?? '') === 'disabled', 'delete-before-join should disable temporary guest', $contract);
    videochat_call_guest_cleanup_assert((string) (videochat_call_guest_cleanup_user($pdo, $registeredUserId)['status'] ?? '') === 'active', 'delete-before-join must preserve registered users', $contract);
    videochat_call_guest_cleanup_assert(videochat_call_guest_cleanup_remaining_count($pdo, 'SELECT COUNT(*) FROM calls WHERE id = :call_id', [':call_id' => $deleteBeforeJoinCallId]) === 0, 'delete-before-join should remove the call row', $contract);
    videochat_call_guest_cleanup_assert(videochat_call_guest_cleanup_remaining_count($pdo, 'SELECT COUNT(*) FROM call_participants WHERE call_id = :call_id', [':call_id' => $deleteBeforeJoinCallId]) === 0, 'delete-before-join should clear participant state', $contract);
    videochat_call_guest_cleanup_assert(videochat_call_guest_cleanup_remaining_count($pdo, 'SELECT COUNT(*) FROM call_access_links WHERE call_id = :call_id', [':call_id' => $deleteBeforeJoinCallId]) === 0, 'delete-before-join should clear stale personalized links', $contract);
    $deleteBeforeJoinStaleLink = videochat_issue_session_for_call_access(
        $pdo,
        $deleteBeforeJoinAccessId,
        static fn (): string => 'sess_guest_cleanup_delete_before_join_stale',
        ['client_ip' => '127.0.0.1', 'user_agent' => $contract . '-delete-before-join-stale']
    );
    videochat_call_guest_cleanup_assert(!(bool) ($deleteBeforeJoinStaleLink['ok'] ?? true), 'delete-before-join stale personalized link must not issue a session', $contract);
    $deleteBeforeJoinEvents = videochat_call_guest_cleanup_events($pdo, $tenantId, $deleteBeforeJoinCallId);
    videochat_call_guest_cleanup_assert(count($deleteBeforeJoinEvents) === 1, 'delete-before-join should write one guest cleanup audit event', $contract);
    $deleteBeforeJoinPayload = is_array(($deleteBeforeJoinEvents[0] ?? [])['payload'] ?? null) ? $deleteBeforeJoinEvents[0]['payload'] : [];
    videochat_call_guest_cleanup_assert((string) ($deleteBeforeJoinPayload['cleanup_result'] ?? '') === 'invalidated', 'delete-before-join audit result mismatch', $contract);
    videochat_call_guest_cleanup_assert((int) ($deleteBeforeJoinPayload['guest_user_count'] ?? 0) === 1, 'delete-before-join audit guest count mismatch', $contract);
    videochat_call_guest_cleanup_assert((int) ($deleteBeforeJoinPayload['invalidated_guest_count'] ?? 0) === 1, 'delete-before-join audit invalidated count mismatch', $contract);
    videochat_call_guest_cleanup_assert((int) ($deleteBeforeJoinPayload['revoked_session_count'] ?? -1) === 0, 'delete-before-join audit revoked session count mismatch', $contract);
    videochat_call_guest_cleanup_assert((bool) ($deleteBeforeJoinPayload['had_effect'] ?? false), 'delete-before-join audit should record cleanup effect', $contract);
    videochat_call_guest_cleanup_assert((bool) ($deleteBeforeJoinPayload['idempotent_safe'] ?? false), 'delete-before-join audit should pin idempotence', $contract);
    $deleteBeforeJoinAuditText = json_encode($deleteBeforeJoinEvents, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    videochat_call_guest_cleanup_assert(is_string($deleteBeforeJoinAuditText), 'delete-before-join audit should encode', $contract);
    foreach ([$deleteBeforeJoinFixture['guest_email'] ?? '', $deleteBeforeJoinAccessId] as $forbiddenText) {
        $text = trim((string) $forbiddenText);
        if ($text === '') {
            continue;
        }
        videochat_call_guest_cleanup_assert(!str_contains($deleteBeforeJoinAuditText, $text), 'delete-before-join audit must not leak raw guest/access identifiers', $contract);
    }
    videochat_call_guest_cleanup_assert(str_contains($deleteBeforeJoinAuditText, videochat_audit_fingerprint($deleteBeforeJoinCallId)), 'delete-before-join audit should keep call fingerprint', $contract);

    $deleteFixture = videochat_call_guest_cleanup_create_personal_fixture($pdo, $context, 'delete lobby registered active', $contract);
    $deleteCallId = (string) $deleteFixture['call_id'];
    $pendingGuestId = (int) $deleteFixture['guest_user_id'];
    $admittedGuestId = videochat_call_guest_cleanup_remaining_add_temp_guest($pdo, $deleteCallId, $tenantId, 'Delete Admitted Temporary Guest', 'allowed', true, $contract);
    $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = 'pending',
    joined_at = NULL,
    left_at = NULL
WHERE call_id = :call_id
  AND user_id = :user_id
SQL
    )->execute([':call_id' => $deleteCallId, ':user_id' => $pendingGuestId]);
    $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = 'accepted',
    joined_at = :joined_at,
    left_at = NULL
WHERE call_id = :call_id
  AND user_id = :user_id
SQL
    )->execute([
        ':joined_at' => '2026-10-02T09:05:00Z',
        ':call_id' => $deleteCallId,
        ':user_id' => $registeredUserId,
    ]);

    $delete = videochat_delete_call($pdo, $deleteCallId, $adminUserId, 'admin', $tenantId);
    videochat_call_guest_cleanup_assert((bool) ($delete['ok'] ?? false), 'delete with lobby, admitted guest, and registered participant should succeed', $contract);
    $deleteCleanup = is_array($delete['guest_cleanup'] ?? null) ? $delete['guest_cleanup'] : [];
    videochat_call_guest_cleanup_assert((int) ($deleteCleanup['invalidated_guests'] ?? 0) === 2, 'delete should invalidate pending and admitted temporary guests', $contract);
    videochat_call_guest_cleanup_assert(in_array($pendingGuestId, $deleteCleanup['guest_user_ids'] ?? [], true), 'delete cleanup should include pending lobby guest', $contract);
    videochat_call_guest_cleanup_assert(in_array($admittedGuestId, $deleteCleanup['guest_user_ids'] ?? [], true), 'delete cleanup should include admitted temporary guest', $contract);
    videochat_call_guest_cleanup_assert((string) (videochat_call_guest_cleanup_user($pdo, $pendingGuestId)['status'] ?? '') === 'disabled', 'delete should disable pending lobby guest', $contract);
    videochat_call_guest_cleanup_assert((string) (videochat_call_guest_cleanup_user($pdo, $admittedGuestId)['status'] ?? '') === 'disabled', 'delete should disable admitted temporary guest', $contract);
    videochat_call_guest_cleanup_assert((string) (videochat_call_guest_cleanup_user($pdo, $registeredUserId)['status'] ?? '') === 'active', 'delete must preserve registered participant account', $contract);
    videochat_call_guest_cleanup_assert(videochat_call_guest_cleanup_remaining_count($pdo, 'SELECT COUNT(*) FROM calls WHERE id = :call_id', [':call_id' => $deleteCallId]) === 0, 'deleted call row should be removed', $contract);
    videochat_call_guest_cleanup_assert(videochat_call_guest_cleanup_remaining_count($pdo, 'SELECT COUNT(*) FROM call_participants WHERE call_id = :call_id', [':call_id' => $deleteCallId]) === 0, 'delete should clear lobby and participant state rows', $contract);
    videochat_call_guest_cleanup_assert(videochat_call_guest_cleanup_remaining_count($pdo, 'SELECT COUNT(*) FROM call_access_links WHERE call_id = :call_id', [':call_id' => $deleteCallId]) === 0, 'delete should clear call access links', $contract);

    $openCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'End Anonymous Active Guest Cleanup',
        'access_mode' => 'free_for_all',
        'starts_at' => '2026-10-03T09:00:00Z',
        'ends_at' => '2026-10-03T10:00:00Z',
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ], $tenantId);
    videochat_call_guest_cleanup_assert((bool) ($openCall['ok'] ?? false), 'anonymous end call should be created', $contract);
    $openCallId = (string) (($openCall['call'] ?? [])['id'] ?? '');
    $openAccess = videochat_create_call_access_link_for_user($pdo, $openCallId, $adminUserId, 'admin', ['link_kind' => 'open'], $tenantId);
    videochat_call_guest_cleanup_assert((bool) ($openAccess['ok'] ?? false), 'anonymous open link should be created', $contract);
    $openAccessId = (string) (($openAccess['access_link'] ?? [])['id'] ?? '');
    $openSessionId = 'sess_guest_cleanup_remaining_open';
    $openSession = videochat_issue_session_for_call_access(
        $pdo,
        $openAccessId,
        static fn (): string => $openSessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => $contract . '-anonymous'],
        ['guest_name' => 'Anonymous Active Guest']
    );
    videochat_call_guest_cleanup_assert((bool) ($openSession['ok'] ?? false), 'anonymous guest session should issue', $contract);
    $anonymousGuestId = (int) (($openSession['user'] ?? [])['id'] ?? 0);
    videochat_call_guest_cleanup_assert($anonymousGuestId > 0, 'anonymous guest id should be present', $contract);
    $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = 'allowed',
    joined_at = :joined_at,
    left_at = NULL
WHERE call_id = :call_id
  AND user_id = :user_id
SQL
    )->execute([
        ':joined_at' => '2026-10-03T09:05:00Z',
        ':call_id' => $openCallId,
        ':user_id' => $anonymousGuestId,
    ]);

    $end = videochat_end_call($pdo, $openCallId, $adminUserId, 'admin', $tenantId);
    videochat_call_guest_cleanup_assert((bool) ($end['ok'] ?? false), 'explicit end with anonymous active guest should succeed', $contract);
    $anonymousParticipant = videochat_call_guest_cleanup_remaining_participant($pdo, $openCallId, $anonymousGuestId);
    videochat_call_guest_cleanup_assert((string) ($anonymousParticipant['invite_state'] ?? '') === 'cancelled', 'ended anonymous active guest should receive cancelled state', $contract);
    videochat_call_guest_cleanup_assert(trim((string) ($anonymousParticipant['left_at'] ?? '')) !== '', 'ended anonymous active guest should receive left_at state', $contract);
    videochat_call_guest_cleanup_assert((string) (videochat_call_guest_cleanup_user($pdo, $anonymousGuestId)['status'] ?? '') === 'disabled', 'end should disable anonymous temporary guest', $contract);
    videochat_call_guest_cleanup_assert(videochat_call_guest_cleanup_remaining_count($pdo, 'SELECT COUNT(*) FROM call_access_links WHERE call_id = :call_id', [':call_id' => $openCallId]) === 0, 'end should clear anonymous access link', $contract);
    videochat_call_guest_cleanup_assert_guest_blocked($pdo, [
        'call_id' => $openCallId,
        'guest_user_id' => $anonymousGuestId,
        'guest_access_id' => $openAccessId,
        'guest_session_id' => $openSessionId,
    ], $contract);

    fwrite(STDOUT, "[{$contract}] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, "[{$contract}] ERROR: " . $error->getMessage() . "\n");
    exit(1);
} finally {
    if (isset($context['database_path']) && is_string($context['database_path']) && is_file($context['database_path'])) {
        @unlink($context['database_path']);
    }
}
