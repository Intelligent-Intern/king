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
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   onboarding: array{completed_tours: array<int, string>, badges: array<int, array{tour_key: string, completed_at: string}>}
 * }
 */
function videochat_complete_onboarding_tour(PDO $pdo, int $userId, mixed $tourKey, ?string $completedAt = null): array
{
    $normalizedTourKey = videochat_onboarding_normalize_tour_key($tourKey);
    if ($userId <= 0) {
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

    $statement = $pdo->prepare('SELECT onboarding_progress_json FROM users WHERE id = :id LIMIT 1');
    $statement->execute([':id' => $userId]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        return ['ok' => false, 'reason' => 'not_found', 'errors' => [], 'onboarding' => videochat_onboarding_progress_payload('{}')];
    }

    $progress = videochat_onboarding_decode_progress($row['onboarding_progress_json'] ?? '{}');
    $alreadyCompleted = array_key_exists($normalizedTourKey, $progress);
    if (!$alreadyCompleted) {
        $progress[$normalizedTourKey] = videochat_onboarding_clean_completed_at($completedAt ?? gmdate('c')) ?: gmdate('c');
        $update = $pdo->prepare(
            'UPDATE users SET onboarding_progress_json = :progress_json, updated_at = :updated_at WHERE id = :id'
        );
        $update->execute([
            ':progress_json' => videochat_onboarding_encode_progress($progress),
            ':updated_at' => gmdate('c'),
            ':id' => $userId,
        ]);
    }

    return [
        'ok' => true,
        'reason' => $alreadyCompleted ? 'already_completed' : 'completed',
        'errors' => [],
        'onboarding' => videochat_onboarding_progress_payload(videochat_onboarding_encode_progress($progress)),
    ];
}
