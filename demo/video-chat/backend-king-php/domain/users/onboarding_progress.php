<?php

declare(strict_types=1);

function videochat_onboarding_normalize_tour_key(mixed $value): string
{
    $key = strtolower(trim((string) $value));
    if ($key === '' || strlen($key) > 160) {
        return '';
    }
    return preg_match('/^[a-z0-9][a-z0-9_.:-]{0,159}$/', $key) === 1 ? $key : '';
}

function videochat_onboarding_clean_completed_at(mixed $value): string
{
    $completedAt = trim((string) $value);
    if ($completedAt === '' || strlen($completedAt) > 64) {
        return '';
    }
    return preg_match('/[\x00-\x1F\x7F]/', $completedAt) === 1 ? '' : $completedAt;
}

/**
 * @return array<string, string>
 */
function videochat_onboarding_decode_progress(mixed $value): array
{
    $decoded = json_decode(is_string($value) && trim($value) !== '' ? $value : '{}', true);
    if (!is_array($decoded)) {
        return [];
    }

    $progress = [];
    if (array_is_list($decoded)) {
        foreach ($decoded as $row) {
            $source = is_array($row) ? $row : ['tour_key' => $row, 'completed_at' => ''];
            $key = videochat_onboarding_normalize_tour_key($source['tour_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $progress[$key] = videochat_onboarding_clean_completed_at($source['completed_at'] ?? '');
        }
    } else {
        foreach ($decoded as $key => $completedAt) {
            $normalizedKey = videochat_onboarding_normalize_tour_key($key);
            if ($normalizedKey === '') {
                continue;
            }
            $progress[$normalizedKey] = videochat_onboarding_clean_completed_at($completedAt);
        }
    }

    ksort($progress);
    return $progress;
}

/**
 * @param array<string, string> $progress
 */
function videochat_onboarding_encode_progress(array $progress): string
{
    $normalized = [];
    foreach ($progress as $key => $completedAt) {
        $normalizedKey = videochat_onboarding_normalize_tour_key($key);
        if ($normalizedKey === '') {
            continue;
        }
        $normalized[$normalizedKey] = videochat_onboarding_clean_completed_at($completedAt);
    }
    ksort($normalized);

    return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
}

/**
 * @return array{completed_tours: array<int, string>, badges: array<int, array{tour_key: string, completed_at: string}>}
 */
function videochat_onboarding_progress_payload(mixed $value): array
{
    $progress = videochat_onboarding_decode_progress($value);
    $badges = [];
    foreach ($progress as $tourKey => $completedAt) {
        $badges[] = [
            'tour_key' => $tourKey,
            'completed_at' => $completedAt,
        ];
    }

    return [
        'completed_tours' => array_keys($progress),
        'badges' => $badges,
    ];
}

/**
 * @param array<string, string> $progress
 * @return array{completed_tours: array<int, string>, badges: array<int, array{tour_key: string, completed_at: string}>}
 */
function videochat_onboarding_progress_payload_from_map(array $progress): array
{
    ksort($progress);
    $badges = [];
    foreach ($progress as $tourKey => $completedAt) {
        $badges[] = [
            'tour_key' => $tourKey,
            'completed_at' => $completedAt,
        ];
    }

    return [
        'completed_tours' => array_keys($progress),
        'badges' => $badges,
    ];
}

/**
 * @return array{completed_tours: array<int, string>, badges: array<int, array{tour_key: string, completed_at: string}>}
 */
function videochat_fetch_onboarding_progress(PDO $pdo, int $userId, int $tenantId): array
{
    if ($userId <= 0 || $tenantId <= 0) {
        return videochat_onboarding_progress_payload('{}');
    }

    $statement = $pdo->prepare(
        <<<'SQL'
SELECT tour_key, completed_at
FROM user_onboarding_progress
WHERE user_id = :user_id
  AND tenant_id = :tenant_id
ORDER BY tour_key ASC
SQL
    );
    $statement->execute([
        ':user_id' => $userId,
        ':tenant_id' => $tenantId,
    ]);

    $progress = [];
    while (($row = $statement->fetch()) !== false) {
        if (!is_array($row)) {
            continue;
        }
        $tourKey = videochat_onboarding_normalize_tour_key($row['tour_key'] ?? '');
        if ($tourKey === '') {
            continue;
        }
        $progress[$tourKey] = videochat_onboarding_clean_completed_at($row['completed_at'] ?? '');
    }

    return videochat_onboarding_progress_payload_from_map($progress);
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   onboarding: array{completed_tours: array<int, string>, badges: array<int, array{tour_key: string, completed_at: string}>}
 * }
 */
function videochat_complete_onboarding_tour(PDO $pdo, int $userId, int $tenantId, mixed $tourKey, ?string $completedAt = null): array
{
    $normalizedTourKey = videochat_onboarding_normalize_tour_key($tourKey);
    if ($userId <= 0 || $tenantId <= 0) {
        return ['ok' => false, 'reason' => 'not_found', 'errors' => [], 'onboarding' => videochat_onboarding_progress_payload('{}')];
    }
    if ($normalizedTourKey === '') {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['tour_key' => 'invalid_tour_key'],
            'onboarding' => videochat_onboarding_progress_payload('{}'),
        ];
    }

    $membership = $pdo->prepare(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN tenant_memberships ON tenant_memberships.user_id = users.id
WHERE users.id = :user_id
  AND tenant_memberships.tenant_id = :tenant_id
  AND tenant_memberships.status = 'active'
LIMIT 1
SQL
    );
    $membership->execute([
        ':user_id' => $userId,
        ':tenant_id' => $tenantId,
    ]);
    if (!is_array($membership->fetch())) {
        return ['ok' => false, 'reason' => 'not_found', 'errors' => [], 'onboarding' => videochat_onboarding_progress_payload('{}')];
    }

    $existing = $pdo->prepare(
        <<<'SQL'
SELECT completed_at
FROM user_onboarding_progress
WHERE user_id = :user_id
  AND tenant_id = :tenant_id
  AND tour_key = :tour_key
LIMIT 1
SQL
    );
    $existing->execute([
        ':user_id' => $userId,
        ':tenant_id' => $tenantId,
        ':tour_key' => $normalizedTourKey,
    ]);
    $existingCompletedAt = $existing->fetchColumn();
    $alreadyCompleted = is_string($existingCompletedAt) && trim($existingCompletedAt) !== '';
    if (!$alreadyCompleted) {
        $insert = $pdo->prepare(
            <<<'SQL'
INSERT INTO user_onboarding_progress(user_id, tenant_id, tour_key, completed_at, created_at, updated_at)
VALUES(:user_id, :tenant_id, :tour_key, :completed_at, :created_at, :updated_at)
SQL
        );
        $now = gmdate('c');
        $insert->execute([
            ':user_id' => $userId,
            ':tenant_id' => $tenantId,
            ':tour_key' => $normalizedTourKey,
            ':completed_at' => videochat_onboarding_clean_completed_at($completedAt ?? $now) ?: $now,
            ':created_at' => $now,
            ':updated_at' => gmdate('c'),
        ]);
    }

    return [
        'ok' => true,
        'reason' => $alreadyCompleted ? 'already_completed' : 'completed',
        'errors' => [],
        'onboarding' => videochat_fetch_onboarding_progress($pdo, $userId, $tenantId),
    ];
}
