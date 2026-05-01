<?php

declare(strict_types=1);

require_once __DIR__ . '/user_email_identity.php';

function videochat_list_user_emails(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    $primary = videochat_ensure_primary_user_email($pdo, $userId);
    if ($primary === null) {
        return [];
    }

    $query = $pdo->prepare(
        <<<'SQL'
SELECT id, email, is_verified, is_primary, verified_at, created_at, updated_at
FROM user_emails
WHERE user_id = :user_id
ORDER BY is_primary DESC, is_verified DESC, id ASC
SQL
    );
    $query->execute([':user_id' => $userId]);

    $rows = [];
    foreach ($query->fetchAll() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rows[] = videochat_normalize_user_email_row($row);
    }
    return $rows;
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   email_row: ?array<string, mixed>,
 *   token: ?string,
 *   expires_at: ?string
 * }
 */
function videochat_create_pending_user_email(PDO $pdo, int $userId, string $email, int $createdByUserId = 0): array
{
    $normalizedEmail = videochat_normalize_user_email_address($email);
    if ($normalizedEmail === '' || filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) === false) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['email' => 'required_valid_email'],
            'email_row' => null,
            'token' => null,
            'expires_at' => null,
        ];
    }
    if (strlen($normalizedEmail) > 320) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['email' => 'email_too_long'],
            'email_row' => null,
            'token' => null,
            'expires_at' => null,
        ];
    }

    $user = videochat_fetch_user_auth_snapshot($pdo, $userId);
    if ($user === null) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'email_row' => null,
            'token' => null,
            'expires_at' => null,
        ];
    }

    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        videochat_ensure_primary_user_email($pdo, $userId);

        $existingQuery = $pdo->prepare(
            'SELECT id, user_id, is_verified, is_primary, verified_at, created_at, updated_at, email FROM user_emails WHERE lower(email) = lower(:email) LIMIT 1'
        );
        $existingQuery->execute([':email' => $normalizedEmail]);
        $existing = $existingQuery->fetch();

        $emailRowId = 0;
        if (is_array($existing)) {
            $existingUserId = (int) ($existing['user_id'] ?? 0);
            if ($existingUserId !== $userId) {
                if ($startedTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return [
                    'ok' => false,
                    'reason' => 'email_conflict',
                    'errors' => ['email' => 'already_in_use'],
                    'email_row' => null,
                    'token' => null,
                    'expires_at' => null,
                ];
            }

            if (((int) ($existing['is_verified'] ?? 0)) === 1) {
                if ($startedTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return [
                    'ok' => false,
                    'reason' => 'email_conflict',
                    'errors' => ['email' => 'already_verified'],
                    'email_row' => null,
                    'token' => null,
                    'expires_at' => null,
                ];
            }

            $emailRowId = (int) ($existing['id'] ?? 0);
        } else {
            $insert = $pdo->prepare(
                <<<'SQL'
INSERT INTO user_emails(user_id, email, is_verified, is_primary, verified_at, created_at, updated_at)
VALUES(:user_id, :email, 0, 0, NULL, :created_at, :updated_at)
SQL
            );
            $nowIso = gmdate('c');
            $insert->execute([
                ':user_id' => $userId,
                ':email' => $normalizedEmail,
                ':created_at' => $nowIso,
                ':updated_at' => $nowIso,
            ]);
            $emailRowId = (int) $pdo->lastInsertId();
        }

        $clearOldTokens = $pdo->prepare(
            'DELETE FROM user_email_change_tokens WHERE user_email_id = :user_email_id AND (consumed_at IS NULL OR trim(consumed_at) = \'\')'
        );
        $clearOldTokens->execute([':user_email_id' => $emailRowId]);

        $token = '';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = videochat_issue_email_change_token();
            if ($candidate !== '') {
                $token = $candidate;
                break;
            }
        }
        if ($token === '') {
            throw new RuntimeException('email_change_token_issue_failed');
        }

        $ttlSeconds = videochat_email_change_token_ttl_seconds();
        $expiresAt = gmdate('c', time() + $ttlSeconds);
        $createdAt = gmdate('c');

        $insertToken = $pdo->prepare(
            <<<'SQL'
INSERT INTO user_email_change_tokens(id, user_email_id, user_id, created_by_user_id, expires_at, consumed_at, created_at)
VALUES(:id, :user_email_id, :user_id, :created_by_user_id, :expires_at, NULL, :created_at)
SQL
        );
        $insertToken->execute([
            ':id' => $token,
            ':user_email_id' => $emailRowId,
            ':user_id' => $userId,
            ':created_by_user_id' => $createdByUserId > 0 ? $createdByUserId : null,
            ':expires_at' => $expiresAt,
            ':created_at' => $createdAt,
        ]);

        $selectEmail = $pdo->prepare(
            'SELECT id, email, is_verified, is_primary, verified_at, created_at, updated_at FROM user_emails WHERE id = :id LIMIT 1'
        );
        $selectEmail->execute([':id' => $emailRowId]);
        $emailRow = $selectEmail->fetch();
        if (!is_array($emailRow)) {
            throw new RuntimeException('email_row_missing_after_insert');
        }

        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }

        return [
            'ok' => true,
            'reason' => 'pending_created',
            'errors' => [],
            'email_row' => videochat_normalize_user_email_row($emailRow),
            'token' => $token,
            'expires_at' => $expiresAt,
        ];
    } catch (Throwable) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'email_row' => null,
            'token' => null,
            'expires_at' => null,
        ];
    }
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   email_row: ?array<string, mixed>
 * }
 */
function videochat_delete_unverified_user_email(PDO $pdo, int $userId, int $userEmailId): array
{
    if ($userId <= 0 || $userEmailId <= 0) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'email_row' => null,
        ];
    }

    $emailQuery = $pdo->prepare(
        'SELECT id, email, is_verified, is_primary, verified_at, created_at, updated_at FROM user_emails WHERE id = :id AND user_id = :user_id LIMIT 1'
    );
    $emailQuery->execute([
        ':id' => $userEmailId,
        ':user_id' => $userId,
    ]);
    $row = $emailQuery->fetch();
    if (!is_array($row)) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'email_row' => null,
        ];
    }

    $normalizedRow = videochat_normalize_user_email_row($row);
    if ($normalizedRow['is_verified']) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['email_id' => 'verified_email_not_deletable'],
            'email_row' => null,
        ];
    }

    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        $deleteTokens = $pdo->prepare('DELETE FROM user_email_change_tokens WHERE user_email_id = :user_email_id');
        $deleteTokens->execute([':user_email_id' => $userEmailId]);

        $deleteRow = $pdo->prepare('DELETE FROM user_emails WHERE id = :id AND user_id = :user_id');
        $deleteRow->execute([
            ':id' => $userEmailId,
            ':user_id' => $userId,
        ]);

        if ($deleteRow->rowCount() !== 1) {
            throw new RuntimeException('user_email_delete_row_mismatch');
        }

        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }

        return [
            'ok' => true,
            'reason' => 'deleted',
            'errors' => [],
            'email_row' => $normalizedRow,
        ];
    } catch (Throwable) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'email_row' => null,
        ];
    }
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   user: ?array<string, mixed>,
 *   email_row: ?array<string, mixed>,
 *   consumed_at: ?string
 * }
 */
function videochat_consume_email_change_token(PDO $pdo, string $token): array
{
    $trimmedToken = trim($token);
    if ($trimmedToken === '') {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['token' => 'required'],
            'user' => null,
            'email_row' => null,
            'consumed_at' => null,
        ];
    }

    $query = $pdo->prepare(
        <<<'SQL'
SELECT
    tokens.id,
    tokens.user_email_id,
    tokens.user_id,
    tokens.expires_at,
    tokens.consumed_at,
    user_emails.email,
    users.status
FROM user_email_change_tokens tokens
INNER JOIN user_emails ON user_emails.id = tokens.user_email_id
INNER JOIN users ON users.id = tokens.user_id
WHERE tokens.id = :token
LIMIT 1
SQL
    );
    $query->execute([':token' => $trimmedToken]);
    $tokenRow = $query->fetch();
    if (!is_array($tokenRow)) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => ['token' => 'invalid_or_unknown'],
            'user' => null,
            'email_row' => null,
            'consumed_at' => null,
        ];
    }

    $existingConsumedAt = is_string($tokenRow['consumed_at'] ?? null) ? trim((string) $tokenRow['consumed_at']) : '';
    if ($existingConsumedAt !== '') {
        return [
            'ok' => false,
            'reason' => 'conflict',
            'errors' => ['token' => 'already_consumed'],
            'user' => null,
            'email_row' => null,
            'consumed_at' => null,
        ];
    }

    $expiresAt = is_string($tokenRow['expires_at'] ?? null) ? (string) $tokenRow['expires_at'] : '';
    $expiresAtUnix = strtotime($expiresAt);
    if (!is_int($expiresAtUnix) || $expiresAtUnix <= time()) {
        return [
            'ok' => false,
            'reason' => 'expired',
            'errors' => ['token' => 'expired'],
            'user' => null,
            'email_row' => null,
            'consumed_at' => null,
        ];
    }

    $userId = (int) ($tokenRow['user_id'] ?? 0);
    $userEmailId = (int) ($tokenRow['user_email_id'] ?? 0);
    if ($userId <= 0 || $userEmailId <= 0) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => ['token' => 'invalid_token_target'],
            'user' => null,
            'email_row' => null,
            'consumed_at' => null,
        ];
    }

    if ((string) ($tokenRow['status'] ?? 'disabled') !== 'active') {
        return [
            'ok' => false,
            'reason' => 'conflict',
            'errors' => ['user' => 'inactive'],
            'user' => null,
            'email_row' => null,
            'consumed_at' => null,
        ];
    }

    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        $consumedAt = gmdate('c');

        $consumeToken = $pdo->prepare(
            'UPDATE user_email_change_tokens SET consumed_at = :consumed_at WHERE id = :id AND (consumed_at IS NULL OR trim(consumed_at) = \'\')'
        );
        $consumeToken->execute([
            ':consumed_at' => $consumedAt,
            ':id' => $trimmedToken,
        ]);
        if ($consumeToken->rowCount() !== 1) {
            throw new RuntimeException('token_already_consumed');
        }

        $verifyEmail = $pdo->prepare(
            <<<'SQL'
UPDATE user_emails
SET is_verified = 1,
    verified_at = COALESCE(verified_at, :verified_at),
    updated_at = :updated_at
WHERE id = :id
SQL
        );
        $verifyEmail->execute([
            ':verified_at' => $consumedAt,
            ':updated_at' => $consumedAt,
            ':id' => $userEmailId,
        ]);

        $clearPrimary = $pdo->prepare('UPDATE user_emails SET is_primary = 0, updated_at = :updated_at WHERE user_id = :user_id');
        $clearPrimary->execute([
            ':updated_at' => $consumedAt,
            ':user_id' => $userId,
        ]);

        $setPrimary = $pdo->prepare('UPDATE user_emails SET is_primary = 1, updated_at = :updated_at WHERE id = :id');
        $setPrimary->execute([
            ':updated_at' => $consumedAt,
            ':id' => $userEmailId,
        ]);

        $emailQuery = $pdo->prepare(
            'SELECT id, email, is_verified, is_primary, verified_at, created_at, updated_at FROM user_emails WHERE id = :id LIMIT 1'
        );
        $emailQuery->execute([':id' => $userEmailId]);
        $emailRow = $emailQuery->fetch();
        if (!is_array($emailRow)) {
            throw new RuntimeException('email_row_missing_after_confirm');
        }
        $normalizedEmailRow = videochat_normalize_user_email_row($emailRow);

        $updateUserEmail = $pdo->prepare('UPDATE users SET email = :email, updated_at = :updated_at WHERE id = :id');
        $updateUserEmail->execute([
            ':email' => $normalizedEmailRow['email'],
            ':updated_at' => $consumedAt,
            ':id' => $userId,
        ]);

        $user = videochat_fetch_user_auth_snapshot($pdo, $userId);
        if (!is_array($user)) {
            throw new RuntimeException('user_missing_after_confirm');
        }

        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }

        return [
            'ok' => true,
            'reason' => 'confirmed',
            'errors' => [],
            'user' => $user,
            'email_row' => $normalizedEmailRow,
            'consumed_at' => $consumedAt,
        ];
    } catch (Throwable) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'user' => null,
            'email_row' => null,
            'consumed_at' => null,
        ];
    }
}
