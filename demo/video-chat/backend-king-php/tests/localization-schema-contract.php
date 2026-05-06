<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/localization.php';

function videochat_localization_schema_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[localization-schema-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-localization-schema-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    $runtime = videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    videochat_localization_schema_assert(
        in_array('supported_locales', $runtime['table_names'] ?? [], true),
        'supported_locales table missing'
    );
    videochat_localization_schema_assert(
        in_array('translation_resources', $runtime['table_names'] ?? [], true),
        'translation_resources table missing'
    );

    $expectedLocales = array_keys(videochat_supported_locale_definitions());
    sort($expectedLocales);
    $localeRows = $pdo->query('SELECT code, direction, is_default FROM supported_locales ORDER BY code ASC')->fetchAll(PDO::FETCH_ASSOC);
    $actualLocales = array_map(static fn (array $row): string => (string) $row['code'], $localeRows);
    videochat_localization_schema_assert($actualLocales === $expectedLocales, 'seeded locale set must match website locales');

    $directions = [];
    foreach ($localeRows as $row) {
        $directions[(string) $row['code']] = (string) $row['direction'];
    }
    foreach (['ar', 'fa', 'ps', 'sgd'] as $rtlLocale) {
        videochat_localization_schema_assert(
            ($directions[$rtlLocale] ?? '') === 'rtl',
            "RTL locale {$rtlLocale} direction mismatch"
        );
    }
    videochat_localization_schema_assert(($directions['en'] ?? '') === 'ltr', 'English direction mismatch');

    $defaultLocaleCount = (int) $pdo->query("SELECT COUNT(*) FROM supported_locales WHERE is_default = 1 AND code = 'en'")->fetchColumn();
    videochat_localization_schema_assert($defaultLocaleCount === 1, 'English must be the backend default locale');

    $userLocales = $pdo->query('SELECT DISTINCT locale FROM users ORDER BY locale ASC')->fetchAll(PDO::FETCH_COLUMN);
    videochat_localization_schema_assert($userLocales === ['en'], 'existing users must be backfilled to en locale');

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    videochat_localization_schema_assert($tenantId > 0, 'default tenant missing');

    $insertResource = $pdo->prepare(
        <<<'SQL'
INSERT INTO translation_resources(tenant_id, locale, namespace, resource_key, value, source)
VALUES(:tenant_id, :locale, :namespace, :resource_key, :value, :source)
SQL
    );
    $insertResource->execute([
        ':tenant_id' => null,
        ':locale' => 'de',
        ':namespace' => 'common',
        ':resource_key' => 'save',
        ':value' => 'Global Speichern',
        ':source' => 'contract',
    ]);
    $insertResource->execute([
        ':tenant_id' => $tenantId,
        ':locale' => 'de',
        ':namespace' => 'common',
        ':resource_key' => 'save',
        ':value' => 'Tenant Speichern',
        ':source' => 'contract',
    ]);
    $insertResource->execute([
        ':tenant_id' => null,
        ':locale' => 'de',
        ':namespace' => 'auth',
        ':resource_key' => 'login',
        ':value' => 'Anmelden',
        ':source' => 'contract',
    ]);

    $tenantResources = videochat_fetch_translation_resources($pdo, 'de', $tenantId, ['common']);
    videochat_localization_schema_assert(
        ($tenantResources['common.save'] ?? '') === 'Tenant Speichern',
        'tenant translation resource must override global resource'
    );
    videochat_localization_schema_assert(
        !isset($tenantResources['auth.login']),
        'namespace filter must exclude unrelated translation resources'
    );

    $globalResources = videochat_fetch_translation_resources($pdo, 'de', null, ['common']);
    videochat_localization_schema_assert(
        ($globalResources['common.save'] ?? '') === 'Global Speichern',
        'global translation resource lookup mismatch'
    );

    @unlink($databasePath);
    fwrite(STDOUT, "[localization-schema-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[localization-schema-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
