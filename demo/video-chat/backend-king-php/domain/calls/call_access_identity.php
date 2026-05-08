<?php

declare(strict_types=1);

function videochat_call_access_identity_fold_name(string $value): string
{
    $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    if ($normalized === '') {
        return '';
    }

    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
        if (is_string($ascii) && trim($ascii) !== '') {
            $normalized = strtolower(trim($ascii));
        }
    }

    $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;
    return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
}

/**
 * @return array{first: string, last: string, full: string}
 */
function videochat_call_access_identity_name_parts(string $displayName): array
{
    $full = videochat_call_access_identity_fold_name($displayName);
    if ($full === '') {
        return ['first' => '', 'last' => '', 'full' => ''];
    }

    $tokens = preg_split('/\s+/', $full) ?: [];
    $tokens = array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));
    $first = (string) ($tokens[0] ?? '');
    $last = count($tokens) > 1 ? (string) $tokens[count($tokens) - 1] : '';

    return ['first' => $first, 'last' => $last, 'full' => $full];
}

/**
 * @return array{state: string, strong: bool, first_name_differs: bool, last_name_differs: bool}
 */
function videochat_call_access_identity_mismatch(array $linkUser, array $currentUser): array
{
    $linkName = videochat_call_access_identity_name_parts((string) ($linkUser['display_name'] ?? ''));
    $currentName = videochat_call_access_identity_name_parts((string) ($currentUser['display_name'] ?? ''));

    if ($linkName['full'] !== '' && hash_equals($linkName['full'], $currentName['full'])) {
        return ['state' => 'no_mismatch', 'strong' => false, 'first_name_differs' => false, 'last_name_differs' => false];
    }

    $firstDiffers = $linkName['first'] !== ''
        && $currentName['first'] !== ''
        && !hash_equals($linkName['first'], $currentName['first']);
    $lastDiffers = $linkName['last'] !== ''
        && $currentName['last'] !== ''
        && !hash_equals($linkName['last'], $currentName['last']);
    $strong = $firstDiffers || $lastDiffers;

    return [
        'state' => $strong ? 'strong_mismatch' : 'light_mismatch',
        'strong' => $strong,
        'first_name_differs' => $firstDiffers,
        'last_name_differs' => $lastDiffers,
    ];
}

function videochat_call_access_normalize_host_name(string $value): string
{
    return videochat_call_access_identity_fold_name($value);
}

function videochat_call_access_host_name_matches_call_owner(string $hostName, array $call): bool
{
    $presented = videochat_call_access_normalize_host_name($hostName);
    if ($presented === '') {
        return false;
    }

    $owner = is_array($call['owner'] ?? null) ? $call['owner'] : [];
    $ownerName = videochat_call_access_normalize_host_name((string) ($owner['display_name'] ?? $call['owner_display_name'] ?? ''));
    return $ownerName !== '' && hash_equals($ownerName, $presented);
}

function videochat_call_access_has_prior_different_session(PDO $pdo, string $accessId, int $actorUserId): bool
{
    $normalizedAccessId = trim($accessId);
    if ($normalizedAccessId === '' || $actorUserId <= 0 || !videochat_tenant_table_has_column($pdo, 'call_access_sessions', 'session_id')) {
        return false;
    }

    try {
        $query = $pdo->prepare(
            <<<'SQL'
SELECT 1
FROM call_access_sessions
WHERE access_id = :access_id
  AND user_id <> :actor_user_id
LIMIT 1
SQL
        );
        $query->execute([
            ':access_id' => $normalizedAccessId,
            ':actor_user_id' => $actorUserId,
        ]);
        return $query->fetchColumn() !== false;
    } catch (Throwable) {
        return false;
    }
}

/**
 * @return array{ok: bool, reason: string, current_user: ?array<string, mixed>, mismatch: array<string, mixed>}
 */
function videochat_call_access_identity_decision_for_authenticated_user(
    PDO $pdo,
    array $linkUser,
    int $authenticatedUserId,
    ?int $tenantId
): array {
    if ($authenticatedUserId <= 0) {
        return ['ok' => false, 'reason' => 'invalid_user_context', 'current_user' => null, 'mismatch' => []];
    }

    $currentUser = videochat_fetch_active_user_for_call_access($pdo, $authenticatedUserId, null, $tenantId, false);
    if (!is_array($currentUser)) {
        return ['ok' => false, 'reason' => 'invalid_user_context', 'current_user' => null, 'mismatch' => []];
    }

    return [
        'ok' => true,
        'reason' => 'compared',
        'current_user' => $currentUser,
        'mismatch' => videochat_call_access_identity_mismatch($linkUser, $currentUser),
    ];
}

function videochat_call_access_bind_authenticated_personalized_user(
    PDO $pdo,
    array $call,
    array $currentUser
): void {
    videochat_ensure_internal_call_participant(
        $pdo,
        (string) ($call['id'] ?? ''),
        (int) ($currentUser['id'] ?? 0),
        (string) ($currentUser['email'] ?? ''),
        (string) ($currentUser['display_name'] ?? ''),
        'invited'
    );
}

/**
 * @return array{access_link: array<string, mixed>, call: array<string, mixed>}
 */
function videochat_call_access_sanitize_authenticated_personalized_payload(
    array $accessLink,
    array $call,
    array $currentUser
): array {
    $sanitizedLink = $accessLink;
    $sanitizedLink['participant_user_id'] = (int) ($currentUser['id'] ?? 0) > 0 ? (int) $currentUser['id'] : null;
    $currentEmail = strtolower(trim((string) ($currentUser['email'] ?? '')));
    $sanitizedLink['participant_email'] = $currentEmail !== '' ? $currentEmail : null;

    $sanitizedCall = $call;
    if (isset($sanitizedCall['participants']) && is_array($sanitizedCall['participants'])) {
        unset($sanitizedCall['participants']);
    }
    unset($sanitizedCall['target_user'], $sanitizedCall['target_hint']);

    return ['access_link' => $sanitizedLink, 'call' => $sanitizedCall];
}

/**
 * @return array{ok: bool, reason: string, resolve_result: array<string, mixed>, status: int, code: string, message: string, details: array<string, mixed>}
 */
function videochat_call_access_public_join_identity_result(
    PDO $pdo,
    array $resolveResult,
    int $authenticatedUserId,
    string $authenticatedSessionId
): array {
    $accessLink = is_array($resolveResult['access_link'] ?? null) ? $resolveResult['access_link'] : [];
    $call = is_array($resolveResult['call'] ?? null) ? $resolveResult['call'] : [];
    $targetUser = is_array($resolveResult['target_user'] ?? null) ? $resolveResult['target_user'] : [];
    $linkKind = videochat_call_access_link_kind($accessLink);
    $targetUserId = is_numeric($targetUser['id'] ?? null) ? (int) $targetUser['id'] : 0;
    if ($authenticatedUserId <= 0 || $linkKind !== 'personal' || $targetUserId <= 0 || $targetUserId === $authenticatedUserId) {
        return [
            'ok' => true,
            'reason' => 'not_applicable',
            'resolve_result' => $resolveResult,
            'status' => 200,
            'code' => '',
            'message' => '',
            'details' => [],
        ];
    }

    $tenantId = is_numeric($accessLink['tenant_id'] ?? null) ? (int) $accessLink['tenant_id'] : null;
    $identityDecision = videochat_call_access_identity_decision_for_authenticated_user($pdo, $targetUser, $authenticatedUserId, $tenantId);
    if (!(bool) ($identityDecision['ok'] ?? false) || !is_array($identityDecision['current_user'] ?? null)) {
        return [
            'ok' => false,
            'reason' => 'invalid_user_context',
            'resolve_result' => $resolveResult,
            'status' => 403,
            'code' => 'call_access_forbidden',
            'message' => 'Call access link is not available for your session.',
            'details' => ['fields' => ['auth' => 'invalid_user_context']],
        ];
    }

    $currentUser = $identityDecision['current_user'];
    $mismatch = is_array($identityDecision['mismatch'] ?? null) ? $identityDecision['mismatch'] : [];
    $priorDifferentSession = videochat_call_access_has_prior_different_session(
        $pdo,
        (string) ($accessLink['id'] ?? ''),
        $authenticatedUserId
    );
    if ($priorDifferentSession || (bool) ($mismatch['strong'] ?? false)) {
        videochat_call_access_record_duplicate_personalized_link_review(
            $pdo,
            $accessLink,
            $call,
            $targetUser,
            $authenticatedUserId,
            'join_opened',
            [
                'session_id' => $authenticatedSessionId,
                'mismatch_state' => (string) ($mismatch['state'] ?? 'unknown'),
            ]
        );
    }
    if ((bool) ($mismatch['strong'] ?? false)) {
        videochat_audit_record_call_access_strong_mismatch(
            $pdo,
            $accessLink,
            $call,
            $targetUser,
            $authenticatedUserId,
            'join_opened',
            [
                'session_id' => $authenticatedSessionId,
                'denial_reason' => 'host_name_not_verified',
                'host_name_verified' => false,
            ]
        );
    }

    if ($priorDifferentSession || (bool) ($mismatch['strong'] ?? false)) {
        return [
            'ok' => false,
            'reason' => $priorDifferentSession ? 'manual_review_required' : 'strong_mismatch',
            'resolve_result' => $resolveResult,
            'status' => 403,
            'code' => 'call_access_forbidden',
            'message' => 'Call access link is not available for your session.',
            'details' => [
                'mismatch' => 'strong_personalized_link',
                'fields' => [
                    'auth' => 'not_bound_to_current_user',
                    'host_name' => 'not_verified',
                ],
            ],
        ];
    }

    $sanitized = videochat_call_access_sanitize_authenticated_personalized_payload($accessLink, $call, $currentUser);
    $resolveResult['access_link'] = $sanitized['access_link'];
    $resolveResult['call'] = $sanitized['call'];
    $resolveResult['target_user'] = [
        'id' => (int) ($currentUser['id'] ?? 0),
        'email' => (string) ($currentUser['email'] ?? ''),
        'display_name' => (string) ($currentUser['display_name'] ?? ''),
        'role' => (string) ($currentUser['role'] ?? 'user'),
    ];
    $resolveResult['target_hint'] = ['participant_email' => null];
    $resolveResult['identity_mismatch'] = [
        'state' => (string) ($mismatch['state'] ?? 'light_mismatch'),
        'requires_host_verification' => false,
    ];

    return [
        'ok' => true,
        'reason' => 'authenticated_user_allowed',
        'resolve_result' => $resolveResult,
        'status' => 200,
        'code' => '',
        'message' => '',
        'details' => [],
    ];
}
