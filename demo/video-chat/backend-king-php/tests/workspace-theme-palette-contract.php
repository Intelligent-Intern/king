<?php
declare(strict_types=1);

require_once __DIR__ . '/../domain/workspace/workspace_administration.php';
require_once __DIR__ . '/../support/workspace_theme_migrations.php';
require_once __DIR__ . '/../support/tenant_migrations.php';

function assert_palette(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[workspace-theme-palette-contract] FAIL: {$message}\n");
        exit(1);
    }
}

$dark = videochat_workspace_default_theme_colors('dark');
foreach ([
    '--color-primary-navy' => '#000010',
    '--color-surface-navy' => '#00052d',
    '--color-cyan-primary' => '#1582bf',
    '--color-cyan-hover' => '#59c7f2',
    '--color-heading' => '#efefe7',
    '--color-text-primary' => '#ffffff',
    '--color-text-link' => '#1582bf',
    '--color-text-link-hover' => '#59c7f2',
    '--color-border' => '#03275a',
    '--color-success' => '#00652f',
    '--color-warning' => '#f47221',
    '--color-error' => '#ef4423',
] as $key => $expected) {
    assert_palette(($dark[$key] ?? '') === $expected, "{$key} should default to {$expected}");
}
assert_palette(count($dark) === 12, 'backend theme defaults should expose exactly 12 root styleguide slots');

$refreshSql = implode("\n", videochat_workspace_theme_refresh_statements());
assert_palette(str_contains($refreshSql, '"--color-primary-navy":"#000010"'), 'migration should refresh primary navy color');
assert_palette(str_contains($refreshSql, '"--color-surface-navy":"#00052d"'), 'migration should refresh surface navy color');
assert_palette(str_contains($refreshSql, '"--color-cyan-primary":"#1582bf"'), 'migration should refresh cyan primary color');
assert_palette(str_contains($refreshSql, '"--color-border":"#03275a"'), 'migration should refresh border color');
assert_palette(str_contains($refreshSql, "WHERE id = 'light' AND is_system = 1"), 'migration should refresh light system theme too');

$tenantMigrations = videochat_sqlite_tenant_migrations();
assert_palette(isset($tenantMigrations[43]), 'tenant migrations should include a corrective styleguide palette refresh');
assert_palette(($tenantMigrations[43]['name'] ?? '') === '0043_workspace_theme_styleguide_palette', 'corrective palette migration should be v43');

fwrite(STDOUT, "[workspace-theme-palette-contract] PASS\n");
