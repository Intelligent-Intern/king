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
    '--bg-shell' => '#000010',
    '--bg-main' => '#000010',
    '--brand-bg' => '#000010',
    '--bg-sidebar' => '#000010',
    '--bg-surface' => '#00052d',
    '--bg-action' => '#1582bf',
    '--bg-action-hover' => '#59c7f2',
    '--border-subtle' => '#03275a',
    '--text-main' => '#ffffff',
    '--ok' => '#00652f',
    '--wait' => '#f47221',
    '--danger' => '#ef4423',
] as $key => $expected) {
    assert_palette(($dark[$key] ?? '') === $expected, "{$key} should default to {$expected}");
}

$refreshSql = implode("\n", videochat_workspace_theme_refresh_statements());
assert_palette(str_contains($refreshSql, '"--bg-shell":"#000010"'), 'migration should refresh primary navy shell color');
assert_palette(str_contains($refreshSql, '"--bg-surface":"#00052d"'), 'migration should refresh surface navy color');
assert_palette(str_contains($refreshSql, '"--bg-action":"#1582bf"'), 'migration should refresh cyan button color');
assert_palette(str_contains($refreshSql, '"--border-subtle":"#03275a"'), 'migration should refresh border color');

$tenantMigrations = videochat_sqlite_tenant_migrations();
assert_palette(isset($tenantMigrations[43]), 'tenant migrations should include a corrective styleguide palette refresh');
assert_palette(($tenantMigrations[43]['name'] ?? '') === '0043_workspace_theme_styleguide_palette', 'corrective palette migration should be v43');

fwrite(STDOUT, "[workspace-theme-palette-contract] PASS\n");
