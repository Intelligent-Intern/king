<?php
declare(strict_types=1);

require_once __DIR__ . '/../domain/workspace/workspace_administration.php';
require_once __DIR__ . '/../support/workspace_theme_migrations.php';

function assert_palette(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[workspace-theme-palette-contract] FAIL: {$message}\n");
        exit(1);
    }
}

$dark = videochat_workspace_default_theme_colors('dark');
foreach ([
    '--bg-shell' => '#101b33',
    '--bg-main' => '#101b33',
    '--brand-bg' => '#1482be',
    '--bg-sidebar' => '#1482be',
    '--border-subtle' => '#1d315c',
] as $key => $expected) {
    assert_palette(($dark[$key] ?? '') === $expected, "{$key} should default to {$expected}");
}

$refreshSql = implode("\n", videochat_workspace_theme_refresh_statements());
assert_palette(str_contains($refreshSql, '"--bg-shell":"#101b33"'), 'migration should refresh dark shell color');
assert_palette(str_contains($refreshSql, '"--brand-bg":"#1482be"'), 'migration should refresh sidebar color');
assert_palette(str_contains($refreshSql, '"--border-subtle":"#1d315c"'), 'migration should refresh border color');

fwrite(STDOUT, "[workspace-theme-palette-contract] PASS\n");
