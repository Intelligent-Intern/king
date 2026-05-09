<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/realtime/realtime_sputnik_dev.php';

function sputnik_contract_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[realtime-sputnik-dev-peer-contract] FAIL: {$message}\n");
        exit(1);
    }
}

putenv('VIDEOCHAT_ENABLE_SPUTNIK_PEERS');
$admin = [
    'id' => 7,
    'email' => 'admin@example.test',
    'display_name' => 'Admin',
    'role' => 'admin',
    'tenant' => ['id' => 3],
];
$disabled = videochat_realtime_apply_sputnik_dev_identity(
    ['dev_sputnik_peer_id' => 'sputnik-1'],
    $admin
);
sputnik_contract_assert((int) ($disabled['id'] ?? 0) === 7, 'disabled flag must leave authenticated identity unchanged');

putenv('VIDEOCHAT_ENABLE_SPUTNIK_PEERS=true');
$nonAdmin = videochat_realtime_apply_sputnik_dev_identity(
    ['dev_sputnik_peer_id' => 'sputnik-1'],
    [...$admin, 'role' => 'user']
);
sputnik_contract_assert((int) ($nonAdmin['id'] ?? 0) === 7, 'non-privileged users must not spawn dev Sputnik peers');

$sputnik = videochat_realtime_apply_sputnik_dev_identity(
    ['dev_sputnik_peer_id' => 'sputnik-2'],
    $admin
);
sputnik_contract_assert((int) ($sputnik['id'] ?? 0) === VIDEOCHAT_SPUTNIK_DEV_USER_ID_BASE + 2, 'sputnik-2 must map to the reserved numeric user id');
sputnik_contract_assert((string) ($sputnik['display_name'] ?? '') === 'Sputnik 2', 'sputnik-2 display name mismatch');
sputnik_contract_assert((string) ($sputnik['email'] ?? '') === 'sputnik-2@sputnik.local', 'sputnik-2 email mismatch');
sputnik_contract_assert((string) ($sputnik['dev_sputnik_peer_id'] ?? '') === 'sputnik-2', 'sputnik-2 logical peer id must be preserved');
sputnik_contract_assert((int) ($sputnik['dev_sputnik_controller_user_id'] ?? 0) === 7, 'controller user id must be retained for auditability');
sputnik_contract_assert((int) (($sputnik['tenant'] ?? [])['id'] ?? 0) === 3, 'tenant context must be preserved');

$alice = videochat_realtime_apply_sputnik_dev_identity(
    ['dev_sputnik_peer_id' => 'alice'],
    $admin
);
sputnik_contract_assert((int) ($alice['id'] ?? 0) === VIDEOCHAT_SPUTNIK_DEV_USER_ID_BASE, 'Alice must map to the reserved base user id');
sputnik_contract_assert((string) ($alice['display_name'] ?? '') === 'Alice', 'Alice display name mismatch');

$invalid = videochat_realtime_apply_sputnik_dev_identity(
    ['dev_sputnik_peer_id' => 'sputnik-500'],
    $admin
);
sputnik_contract_assert((int) ($invalid['id'] ?? 0) === 7, 'out-of-range Sputnik ids must be rejected');

echo "[realtime-sputnik-dev-peer-contract] PASS\n";
