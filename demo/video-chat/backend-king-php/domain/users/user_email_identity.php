<?php

declare(strict_types=1);

/**
 * @return array{
 *   id: int,
 *   email: string,
 *   is_verified: bool,
 *   is_primary: bool,
 *   verified_at: ?string,
 *   created_at: string,
 *   updated_at: string
 * }
 */
function videochat_normalize_user_email_row(array $row): array
{
    $verifiedAt = is_string($row['verified_at'] ?? null) ? trim((string) $row['verified_at']) : '';
    return [
        'id' => (int) ($row['id'] ?? 0),
        'email' => strtolower(trim((string) ($row['email'] ?? ''))),
        'is_verified' => ((int) ($row['is_verified'] ?? 0)) === 1,
        'is_primary' => ((int) ($row['is_primary'] ?? 0)) === 1,
        'verified_at' => $verifiedAt === '' ? null : $verifiedAt,
        'created_at' => is_string($row['created_at'] ?? null) ? (string) $row['created_at'] : '',
        'updated_at' => is_string($row['updated_at'] ?? null) ? (string) $row['updated_at'] : '',
    ];
}

function videochat_normalize_user_email_address(?string $value): string
{
    return strtolower(trim((string) ($value ?? '')));
}

function videochat_primary_admin_user_id(PDO $pdo): int
{
    $query = $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE lower(roles.slug) = 'admin'
ORDER BY users.id ASC
LIMIT 1
SQL
    );
    return (int) $query->fetchColumn();
}

function videochat_email_change_token_ttl_seconds(): int
{
    $ttl = (int) (getenv('VIDEOCHAT_EMAIL_CHANGE_TOKEN_TTL_SECONDS') ?: 1800);
    if ($ttl < 60) {
        return 60;
    }
    if ($ttl > 86_400) {
        return 86_400;
    }
    return $ttl;
}

function videochat_issue_email_change_token(): string
{
    try {
        return 'emc_' . bin2hex(random_bytes(24));
    } catch (Throwable) {
        return 'emc_' . hash('sha256', uniqid('emc', true) . microtime(true));
    }
}

/**
 * @return array{
 *   id: int,
 *   email: string,
 *   display_name: string,
 *   role: string,
 *   status: string,
 *   time_format: string,
 *   date_format: string,
 *   theme: string,
 *   avatar_path: ?string,
 *   post_logout_landing_url: string,
 *   account_type: string,
 *   is_guest: bool
 * }|null
 */
function videochat_fetch_user_auth_snapshot(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $query = $pdo->prepare(
        <<<'SQL'
SELECT
    users.id,
    users.email,
    users.display_name,
    users.status,
    users.password_hash,
    users.time_format,
    users.date_format,
    users.theme,
    users.avatar_path,
    users.post_logout_landing_url,
    roles.slug AS role_slug
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE users.id = :id
LIMIT 1
SQL
    );
    $query->execute([':id' => $userId]);
    $row = $query->fetch();
    if (!is_array($row)) {
        return null;
    }

    $accountType = videochat_user_account_type(
        is_string($row['email'] ?? null) ? (string) $row['email'] : '',
        $row['password_hash'] ?? null
    );

    return [
        'id' => (int) ($row['id'] ?? 0),
        'email' => videochat_normalize_user_email_address((string) ($row['email'] ?? '')),
        'display_name' => (string) ($row['display_name'] ?? ''),
        'role' => is_string($row['role_slug'] ?? null) ? (string) $row['role_slug'] : 'user',
        'status' => is_string($row['status'] ?? null) ? (string) $row['status'] : 'disabled',
        'time_format' => is_string($row['time_format'] ?? null) ? (string) $row['time_format'] : '24h',
        'date_format' => is_string($row['date_format'] ?? null) ? (string) $row['date_format'] : 'dmy_dot',
        'theme' => is_string($row['theme'] ?? null) ? (string) $row['theme'] : 'dark',
        'avatar_path' => is_string($row['avatar_path'] ?? null) ? (string) $row['avatar_path'] : null,
        'post_logout_landing_url' => is_string($row['post_logout_landing_url'] ?? null)
            ? trim((string) $row['post_logout_landing_url'])
            : '',
        'account_type' => $accountType,
        'is_guest' => $accountType === 'guest',
    ];
}

/**
 * @return array{
 *   id: int,
 *   email: string,
 *   is_verified: bool,
 *   is_primary: bool,
 *   verified_at: ?string,
 *   created_at: string,
 *   updated_at: string
 * }|null
 */
function videochat_ensure_primary_user_email(PDO $pdo, int $userId): ?array
{
    $user = videochat_fetch_user_auth_snapshot($pdo, $userId);
    if ($user === null) {
        return null;
    }

    $userEmail = videochat_normalize_user_email_address((string) ($user['email'] ?? ''));
    if ($userEmail === '') {
        return null;
    }

    $queryRows = $pdo->prepare(
        <<<'SQL'
SELECT id, email, is_verified, is_primary, verified_at, created_at, updated_at
FROM user_emails
WHERE user_id = :user_id
ORDER BY is_primary DESC, is_verified DESC, id ASC
SQL
    );
    $queryRows->execute([':user_id' => $userId]);
    $rows = $queryRows->fetchAll();

    $nowIso = gmdate('c');
    if (!is_array($rows) || $rows === []) {
        $insert = $pdo->prepare(
            <<<'SQL'
INSERT INTO user_emails(user_id, email, is_verified, is_primary, verified_at, created_at, updated_at)
VALUES(:user_id, :email, 1, 1, :verified_at, :created_at, :updated_at)
SQL
        );
        $insert->execute([
            ':user_id' => $userId,
            ':email' => $userEmail,
            ':verified_at' => $nowIso,
            ':created_at' => $nowIso,
            ':updated_at' => $nowIso,
        ]);
        $createdId = (int) $pdo->lastInsertId();

        $select = $pdo->prepare(
            'SELECT id, email, is_verified, is_primary, verified_at, created_at, updated_at FROM user_emails WHERE id = :id LIMIT 1'
        );
        $select->execute([':id' => $createdId]);
        $createdRow = $select->fetch();
        return is_array($createdRow) ? videochat_normalize_user_email_row($createdRow) : null;
    }

    $normalizedRows = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $normalizedRows[] = videochat_normalize_user_email_row($row);
        }
    }
    if ($normalizedRows === []) {
        return null;
    }

    $primary = null;
    foreach ($normalizedRows as $row) {
        if ($row['is_primary']) {
            $primary = $row;
            break;
        }
    }

    if ($primary === null) {
        $candidate = null;
        foreach ($normalizedRows as $row) {
            if ($row['is_verified']) {
                $candidate = $row;
                break;
            }
        }
        if ($candidate === null) {
            $candidate = $normalizedRows[0];
        }

        $clearPrimary = $pdo->prepare('UPDATE user_emails SET is_primary = 0, updated_at = :updated_at WHERE user_id = :user_id');
        $clearPrimary->execute([
            ':updated_at' => $nowIso,
            ':user_id' => $userId,
        ]);
        $setPrimary = $pdo->prepare('UPDATE user_emails SET is_primary = 1, updated_at = :updated_at WHERE id = :id');
        $setPrimary->execute([
            ':updated_at' => $nowIso,
            ':id' => (int) $candidate['id'],
        ]);

        $candidate['is_primary'] = true;
        $primary = $candidate;
    }

    if ($primary['email'] !== $userEmail) {
        $syncUser = $pdo->prepare('UPDATE users SET email = :email, updated_at = :updated_at WHERE id = :id');
        $syncUser->execute([
            ':email' => $primary['email'],
            ':updated_at' => $nowIso,
            ':id' => $userId,
        ]);
    }

    return $primary;
}

/**
 * @return array<int, array{
 *   id: int,
 *   email: string,
 *   is_verified: bool,
 *   is_primary: bool,
 *   verified_at: ?string,
 *   created_at: string,
 *   updated_at: string
 * }>
 */
