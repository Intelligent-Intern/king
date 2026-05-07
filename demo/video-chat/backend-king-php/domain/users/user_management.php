<?php

declare(strict_types=1);

require_once __DIR__ . '/user_management_contract.php';

function videochat_admin_create_user(PDO $pdo, array $payload, ?int $tenantId = null): array
{
    $validation = videochat_admin_validate_create_user_payload($payload);
    if (!(bool) $validation['ok']) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => $validation['errors'],
            'user' => null,
        ];
    }

    $data = $validation['data'];
    $roleMap = videochat_admin_role_id_map($pdo);
    $roleId = (int) ($roleMap[(string) $data['role']] ?? 0);
    if ($roleId <= 0) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['role' => 'required_valid_role'],
            'user' => null,
        ];
    }

    $existingQuery = $pdo->prepare('SELECT id FROM users WHERE lower(email) = lower(:email) LIMIT 1');
    $existingQuery->execute([':email' => (string) $data['email']]);
    if ($existingQuery->fetch() !== false) {
        return [
            'ok' => false,
            'reason' => 'email_conflict',
            'errors' => ['email' => 'already_exists'],
            'user' => null,
        ];
    }

    $passwordHash = password_hash((string) $data['password'], PASSWORD_DEFAULT);
    if (!is_string($passwordHash) || $passwordHash === '') {
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'user' => null,
        ];
    }

    try {
        $insert = $pdo->prepare(
            <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, theme_editor_enabled, avatar_path, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, :status, :time_format, :theme, :theme_editor_enabled, :avatar_path, :updated_at)
SQL
        );
        $insert->execute([
            ':email' => (string) $data['email'],
            ':display_name' => (string) $data['display_name'],
            ':password_hash' => $passwordHash,
            ':role_id' => $roleId,
            ':status' => (string) $data['status'],
            ':time_format' => (string) $data['time_format'],
            ':theme' => (string) $data['theme'],
            ':theme_editor_enabled' => (bool) ($data['theme_editor_enabled'] ?? false) ? 1 : 0,
            ':avatar_path' => $data['avatar_path'],
            ':updated_at' => gmdate('c'),
        ]);
        $createdUserId = (int) $pdo->lastInsertId();
        if (is_int($tenantId) && $tenantId > 0) {
            videochat_tenant_attach_user($pdo, $createdUserId, $tenantId);
        }
    } catch (PDOException $error) {
        $message = strtolower($error->getMessage());
        if (str_contains($message, 'unique') && str_contains($message, 'users.email')) {
            return [
                'ok' => false,
                'reason' => 'email_conflict',
                'errors' => ['email' => 'already_exists'],
                'user' => null,
            ];
        }

        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'user' => null,
        ];
    }

    $created = videochat_admin_fetch_user_by_id($pdo, $createdUserId, $tenantId);
    if ($created === null) {
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'user' => null,
        ];
    }

    if (function_exists('videochat_ensure_primary_user_email')) {
        try {
            videochat_ensure_primary_user_email($pdo, $createdUserId);
        } catch (Throwable) {
            // Ignore sync failures here; create already succeeded and table may not exist in legacy test fixtures.
        }
    }

    return [
        'ok' => true,
        'reason' => 'created',
        'errors' => [],
        'user' => $created,
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   user: ?array<string, mixed>
 * }
 */
function videochat_admin_update_user(PDO $pdo, int $userId, array $payload, ?int $tenantId = null): array
{
    if ($userId <= 0) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'user' => null,
        ];
    }

    $existing = videochat_admin_fetch_user_by_id($pdo, $userId, $tenantId);
    if ($existing === null) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'user' => null,
        ];
    }

    $validation = videochat_admin_validate_update_user_payload($payload);
    if (!(bool) $validation['ok']) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => $validation['errors'],
            'user' => null,
        ];
    }

    $data = $validation['data'];
    $roleMap = videochat_admin_role_id_map($pdo);
    $nextRole = array_key_exists('role', $data) ? (string) $data['role'] : (string) $existing['role'];
    $nextRoleId = (int) ($roleMap[$nextRole] ?? 0);
    if ($nextRoleId <= 0) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['role' => 'required_valid_role'],
            'user' => null,
        ];
    }

    $passwordHash = null;
    if (array_key_exists('password', $data)) {
        $passwordHash = password_hash((string) $data['password'], PASSWORD_DEFAULT);
        if (!is_string($passwordHash) || $passwordHash === '') {
            return [
                'ok' => false,
                'reason' => 'internal_error',
                'errors' => [],
                'user' => null,
            ];
        }
    }

    $update = $pdo->prepare(
        <<<'SQL'
UPDATE users
SET display_name = :display_name,
    role_id = :role_id,
    status = :status,
    time_format = :time_format,
    theme = :theme,
    theme_editor_enabled = :theme_editor_enabled,
    avatar_path = :avatar_path,
    password_hash = COALESCE(:password_hash, password_hash),
    updated_at = :updated_at
WHERE id = :id
SQL
    );
    $update->execute([
        ':display_name' => array_key_exists('display_name', $data) ? (string) $data['display_name'] : (string) $existing['display_name'],
        ':role_id' => $nextRoleId,
        ':status' => array_key_exists('status', $data) ? (string) $data['status'] : (string) $existing['status'],
        ':time_format' => array_key_exists('time_format', $data) ? (string) $data['time_format'] : (string) $existing['time_format'],
        ':theme' => array_key_exists('theme', $data) ? (string) $data['theme'] : (string) $existing['theme'],
        ':theme_editor_enabled' => array_key_exists('theme_editor_enabled', $data)
            ? ((bool) $data['theme_editor_enabled'] ? 1 : 0)
            : (((bool) ($existing['theme_editor_enabled'] ?? false)) ? 1 : 0),
        ':avatar_path' => array_key_exists('avatar_path', $data) ? $data['avatar_path'] : $existing['avatar_path'],
        ':password_hash' => $passwordHash,
        ':updated_at' => gmdate('c'),
        ':id' => $userId,
    ]);

    $updated = videochat_admin_fetch_user_by_id($pdo, $userId, $tenantId);
    if ($updated === null) {
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'user' => null,
        ];
    }

    return [
        'ok' => true,
        'reason' => 'updated',
        'errors' => [],
        'user' => $updated,
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   user: ?array<string, mixed>,
 *   revoked_sessions: int
 * }
 */
function videochat_admin_deactivate_user(PDO $pdo, int $userId, ?int $tenantId = null): array
{
    if ($userId <= 0) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'user' => null,
            'revoked_sessions' => 0,
        ];
    }

    $existing = videochat_admin_fetch_user_by_id($pdo, $userId, $tenantId);
    if ($existing === null) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'user' => null,
            'revoked_sessions' => 0,
        ];
    }

    $revokedSessions = 0;
    if ((string) $existing['status'] !== 'disabled') {
        $disable = $pdo->prepare('UPDATE users SET status = :status, updated_at = :updated_at WHERE id = :id');
        $disable->execute([
            ':status' => 'disabled',
            ':updated_at' => gmdate('c'),
            ':id' => $userId,
        ]);

        $revokeSessions = $pdo->prepare(
            'UPDATE sessions SET revoked_at = :revoked_at WHERE user_id = :user_id AND (revoked_at IS NULL OR revoked_at = \'\')'
        );
        $revokeSessions->execute([
            ':revoked_at' => gmdate('c'),
            ':user_id' => $userId,
        ]);
        $revokedSessions = $revokeSessions->rowCount();
    }

    $updated = videochat_admin_fetch_user_by_id($pdo, $userId, $tenantId);
    if ($updated === null) {
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'user' => null,
            'revoked_sessions' => 0,
        ];
    }

    return [
        'ok' => true,
        'reason' => (string) $existing['status'] === 'disabled' ? 'already_disabled' : 'deactivated',
        'errors' => [],
        'user' => $updated,
        'revoked_sessions' => $revokedSessions,
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   user: ?array<string, mixed>
 * }
 */
function videochat_admin_reactivate_user(PDO $pdo, int $userId, ?int $tenantId = null): array
{
    if ($userId <= 0) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'user' => null,
        ];
    }

    $existing = videochat_admin_fetch_user_by_id($pdo, $userId, $tenantId);
    if ($existing === null) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'user' => null,
        ];
    }

    if ((string) $existing['status'] !== 'active') {
        $reactivate = $pdo->prepare('UPDATE users SET status = :status, updated_at = :updated_at WHERE id = :id');
        $reactivate->execute([
            ':status' => 'active',
            ':updated_at' => gmdate('c'),
            ':id' => $userId,
        ]);
    }

    $updated = videochat_admin_fetch_user_by_id($pdo, $userId, $tenantId);
    if ($updated === null) {
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'user' => null,
        ];
    }

    return [
        'ok' => true,
        'reason' => (string) $existing['status'] === 'active' ? 'already_active' : 'reactivated',
        'errors' => [],
        'user' => $updated,
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   user: ?array<string, mixed>,
 *   deleted_calls: int,
 *   deleted_invite_codes: int
 * }
 */
function videochat_admin_delete_user(PDO $pdo, int $userId, ?int $tenantId = null): array
{
    if ($userId <= 0) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'user' => null,
            'deleted_calls' => 0,
            'deleted_invite_codes' => 0,
        ];
    }

    $existing = videochat_admin_fetch_user_by_id($pdo, $userId, $tenantId);
    if ($existing === null) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'user' => null,
            'deleted_calls' => 0,
            'deleted_invite_codes' => 0,
        ];
    }

    $deletedCalls = 0;
    $deletedInviteCodes = 0;
    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        $callTenantPredicate = is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'calls', 'tenant_id')
            ? ' AND tenant_id = :tenant_id'
            : '';
        $deleteCalls = $pdo->prepare('DELETE FROM calls WHERE owner_user_id = :owner_user_id' . $callTenantPredicate);
        $callParams = [':owner_user_id' => $userId];
        if ($callTenantPredicate !== '') {
            $callParams[':tenant_id'] = $tenantId;
        }
        $deleteCalls->execute($callParams);
        $deletedCalls = $deleteCalls->rowCount();

        $inviteTenantPredicate = is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'invite_codes', 'tenant_id')
            ? ' AND tenant_id = :tenant_id'
            : '';
        $deleteInviteCodes = $pdo->prepare('DELETE FROM invite_codes WHERE issued_by_user_id = :issued_by_user_id' . $inviteTenantPredicate);
        $inviteParams = [':issued_by_user_id' => $userId];
        if ($inviteTenantPredicate !== '') {
            $inviteParams[':tenant_id'] = $tenantId;
        }
        $deleteInviteCodes->execute($inviteParams);
        $deletedInviteCodes = $deleteInviteCodes->rowCount();

        if (is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'tenant_memberships', 'tenant_id')) {
            $deleteMembership = $pdo->prepare('DELETE FROM tenant_memberships WHERE user_id = :user_id AND tenant_id = :tenant_id');
            $deleteMembership->execute([':user_id' => $userId, ':tenant_id' => $tenantId]);
            $remainingMemberships = $pdo->prepare('SELECT COUNT(*) FROM tenant_memberships WHERE user_id = :user_id');
            $remainingMemberships->execute([':user_id' => $userId]);
            if ((int) $remainingMemberships->fetchColumn() > 0) {
                if ($startedTransaction && $pdo->inTransaction()) {
                    $pdo->commit();
                }

                return [
                    'ok' => true,
                    'reason' => 'removed_from_tenant',
                    'errors' => [],
                    'user' => $existing,
                    'deleted_calls' => $deletedCalls,
                    'deleted_invite_codes' => $deletedInviteCodes,
                ];
            }
        }

        $deleteUser = $pdo->prepare('DELETE FROM users WHERE id = :id');
        $deleteUser->execute([':id' => $userId]);

        if ($deleteUser->rowCount() !== 1) {
            throw new RuntimeException('delete_user_row_count_mismatch');
        }

        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'user' => null,
            'deleted_calls' => 0,
            'deleted_invite_codes' => 0,
        ];
    }

    return [
        'ok' => true,
        'reason' => 'deleted',
        'errors' => [],
        'user' => $existing,
        'deleted_calls' => $deletedCalls,
        'deleted_invite_codes' => $deletedInviteCodes,
    ];
}
