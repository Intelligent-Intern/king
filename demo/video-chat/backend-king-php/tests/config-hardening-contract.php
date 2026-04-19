<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/config_hardening.php';
require_once __DIR__ . '/../support/database.php';

function videochat_config_hardening_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[config-hardening-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_config_hardening_contract_clear_env(): void
{
    foreach ([
        'VIDEOCHAT_KING_ENV',
        'VIDEOCHAT_REQUIRE_SECRET_SOURCES',
        'VIDEOCHAT_DEMO_ADMIN_PASSWORD',
        'VIDEOCHAT_DEMO_ADMIN_PASSWORD_FILE',
        'VIDEOCHAT_DEMO_USER_PASSWORD',
        'VIDEOCHAT_DEMO_USER_PASSWORD_FILE',
        'VIDEOCHAT_DEMO_SEED_CALLS',
        'VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET',
        'VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET_FILE',
    ] as $name) {
        putenv($name);
    }
}

try {
    videochat_config_hardening_contract_clear_env();

    $localReport = videochat_config_hardening_report();
    videochat_config_hardening_contract_assert((bool) $localReport['ok'], 'local development defaults should remain allowed');
    videochat_config_hardening_contract_assert((bool) $localReport['required'] === false, 'local defaults should not require hardening');
    videochat_config_hardening_contract_assert(count($localReport['warnings']) >= 2, 'local defaults should be visible as warnings');

    putenv('VIDEOCHAT_KING_ENV=production');
    $productionDefaultReport = videochat_config_hardening_report();
    videochat_config_hardening_contract_assert(!$productionDefaultReport['ok'], 'production defaults must fail closed');
    $productionErrors = implode("\n", $productionDefaultReport['errors']);
    videochat_config_hardening_contract_assert(str_contains($productionErrors, 'VIDEOCHAT_DEMO_ADMIN_PASSWORD'), 'admin default must be reported');
    videochat_config_hardening_contract_assert(str_contains($productionErrors, 'VIDEOCHAT_DEMO_USER_PASSWORD'), 'user default must be reported');
    videochat_config_hardening_contract_assert(str_contains($productionErrors, 'VIDEOCHAT_DEMO_SEED_CALLS'), 'demo seed calls must be reported');

    putenv('VIDEOCHAT_DEMO_ADMIN_PASSWORD=Admin-contract-secret-123');
    putenv('VIDEOCHAT_DEMO_USER_PASSWORD=User-contract-secret-456');
    putenv('VIDEOCHAT_DEMO_SEED_CALLS=0');
    $productionEnvReport = videochat_config_hardening_report();
    videochat_config_hardening_contract_assert((bool) $productionEnvReport['ok'], 'production with explicit strong env secrets should pass');

    putenv('VIDEOCHAT_DEMO_ADMIN_PASSWORD=admin123');
    $productionExplicitDefault = videochat_config_hardening_report();
    videochat_config_hardening_contract_assert(!$productionExplicitDefault['ok'], 'explicit known demo secret must fail');
    putenv('VIDEOCHAT_DEMO_ADMIN_PASSWORD');

    $adminSecretPath = tempnam(sys_get_temp_dir(), 'videochat-admin-secret-');
    $userSecretPath = tempnam(sys_get_temp_dir(), 'videochat-user-secret-');
    videochat_config_hardening_contract_assert(is_string($adminSecretPath) && is_string($userSecretPath), 'temp secret files should be created');
    file_put_contents($adminSecretPath, "Admin-file-secret-123\n");
    file_put_contents($userSecretPath, "User-file-secret-456\n");
    putenv('VIDEOCHAT_DEMO_ADMIN_PASSWORD_FILE=' . $adminSecretPath);
    putenv('VIDEOCHAT_DEMO_USER_PASSWORD_FILE=' . $userSecretPath);
    putenv('VIDEOCHAT_DEMO_USER_PASSWORD');

    $productionFileReport = videochat_config_hardening_report();
    videochat_config_hardening_contract_assert((bool) $productionFileReport['ok'], 'production with file-backed secrets should pass');
    $blueprint = videochat_demo_user_blueprint();
    videochat_config_hardening_contract_assert(($blueprint[0]['password'] ?? '') === 'Admin-file-secret-123', 'admin password should come from secret file');
    videochat_config_hardening_contract_assert(($blueprint[1]['password'] ?? '') === 'User-file-secret-456', 'user password should come from secret file');

    putenv('VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET=secret');
    $badTurnReport = videochat_config_hardening_report();
    videochat_config_hardening_contract_assert(!$badTurnReport['ok'], 'short/default TURN static auth secret should fail');
    putenv('VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET=turn-contract-secret-123456');
    $goodTurnReport = videochat_config_hardening_report();
    videochat_config_hardening_contract_assert((bool) $goodTurnReport['ok'], 'strong TURN static auth secret should pass');

    @unlink($adminSecretPath);
    @unlink($userSecretPath);
    videochat_config_hardening_contract_clear_env();

    fwrite(STDOUT, "[config-hardening-contract] PASS\n");
} catch (Throwable $error) {
    videochat_config_hardening_contract_clear_env();
    fwrite(STDERR, "[config-hardening-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
