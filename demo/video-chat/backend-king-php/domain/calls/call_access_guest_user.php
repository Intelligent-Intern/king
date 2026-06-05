<?php

declare(strict_types=1);

require_once __DIR__ . '/call_access_contract.php';
require_once __DIR__ . '/call_guest_lifecycle.php';
require_once __DIR__ . '/invite_code_contract.php';

function videochat_create_guest_user_for_call_access(
    PDO $pdo,
    string $displayName,
    ?int $tenantId = null,
    bool $attachTenantMembership = true
): array {
    $name = trim($displayName);
    if ($name === '') {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['guest_name' => 'required_guest_name'],
            'user' => null,
        ];
    }
    if (strlen($name) > 96) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['guest_name' => 'guest_name_too_long'],
            'user' => null,
        ];
    }

    videochat_prune_expired_guest_users($pdo);

    $roleIdQuery = $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1");
    $roleIdRow = $roleIdQuery !== false ? $roleIdQuery->fetch() : false;
    $roleId = is_array($roleIdRow) ? (int) ($roleIdRow['id'] ?? 0) : 0;
    if ($roleId <= 0) {
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => ['role' => 'user_role_not_found'],
            'user' => null,
        ];
    }

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, date_format, theme, avatar_path, updated_at)
VALUES(:email, :display_name, NULL, :role_id, 'active', '24h', 'dmy_dot', 'dark', NULL, :updated_at)
SQL
    );

    $createdUserId = 0;
    for ($attempt = 0; $attempt < 6; $attempt += 1) {
        $guestEmail = 'guest+' . str_replace('-', '', videochat_generate_call_access_uuid()) . '@videochat.local';
        try {
            $insert->execute([
                ':email' => $guestEmail,
                ':display_name' => $name,
                ':role_id' => $roleId,
                ':updated_at' => gmdate('c'),
            ]);
            $createdUserId = (int) $pdo->lastInsertId();
            if ($attachTenantMembership && is_int($tenantId) && $tenantId > 0) {
                videochat_tenant_attach_user($pdo, $createdUserId, $tenantId);
            }
            break;
        } catch (Throwable $error) {
            if (videochat_is_sqlite_unique_constraint_error($error)) {
                continue;
            }
            return [
                'ok' => false,
                'reason' => 'internal_error',
                'errors' => [],
                'user' => null,
            ];
        }
    }

    if ($createdUserId <= 0) {
        return [
            'ok' => false,
            'reason' => 'conflict',
            'errors' => ['guest_user' => 'could_not_allocate_unique_guest_identity'],
            'user' => null,
        ];
    }

    $user = videochat_fetch_active_user_for_call_access($pdo, $createdUserId, null, $tenantId, $attachTenantMembership);
    if (!is_array($user)) {
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => ['guest_user' => 'guest_user_lookup_failed'],
            'user' => null,
        ];
    }

    return [
        'ok' => true,
        'reason' => 'created',
        'errors' => [],
        'user' => $user,
    ];
}
