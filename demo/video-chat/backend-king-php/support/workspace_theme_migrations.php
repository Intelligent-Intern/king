<?php

declare(strict_types=1);

function videochat_workspace_theme_dark_colors(): array
{
    return [
        '--bg-shell' => '#000010',
        '--bg-pane' => '#000010',
        '--brand-bg' => '#000010',
        '--bg-surface' => '#00052d',
        '--bg-surface-strong' => '#00052d',
        '--bg-input' => '#00052d',
        '--bg-action' => '#1582bf',
        '--bg-action-hover' => '#59c7f2',
        '--bg-row' => '#00052d',
        '--bg-row-hover' => '#03275a',
        '--line' => '#03275a',
        '--text-main' => '#ffffff',
        '--text-muted' => '#efefe7',
        '--ok' => '#00652f',
        '--wait' => '#f47221',
        '--danger' => '#ef4423',
        '--bg-sidebar' => '#000010',
        '--bg-main' => '#000010',
        '--bg-tab' => '#00052d',
        '--bg-tab-hover' => '#03275a',
        '--bg-tab-active' => '#1582bf',
        '--bg-ui-chrome' => '#00052d',
        '--bg-ui-chrome-active' => '#03275a',
        '--bg-icon' => '#00052d',
        '--bg-icon-active' => '#1582bf',
        '--border-subtle' => '#03275a',
        '--text-primary' => '#ffffff',
        '--text-secondary' => '#efefe7',
        '--text-dim' => '#efefe7',
        '--warn' => '#f47221',
        '--brand-cyan' => '#1582bf',
        '--brand-cyan-hover' => '#59c7f2',
        '--brand-cyan-active' => '#1582bf',
    ];
}

function videochat_workspace_theme_light_colors(): array
{
    return [
        '--bg-shell' => '#eff4fb',
        '--bg-pane' => '#dce8f6',
        '--brand-bg' => '#e8eff8',
        '--bg-surface' => '#f4f8fd',
        '--bg-surface-strong' => '#ffffff',
        '--bg-input' => '#ffffff',
        '--bg-action' => '#1582bf',
        '--bg-action-hover' => '#59c7f2',
        '--bg-row' => '#b7cdf5',
        '--bg-row-hover' => '#8cabdf',
        '--line' => '#c4d1e3',
        '--text-main' => '#122035',
        '--text-muted' => '#5a6780',
        '--ok' => '#2e8b57',
        '--wait' => '#9a7b00',
        '--danger' => '#c62828',
        '--bg-sidebar' => '#e8eff8',
        '--bg-main' => '#dce8f6',
        '--bg-tab' => '#00052d',
        '--bg-tab-hover' => '#9cbcf3',
        '--bg-tab-active' => '#b7cdf5',
        '--bg-ui-chrome' => '#3d5f98',
        '--bg-ui-chrome-active' => '#2a569f',
        '--bg-icon' => '#dae7f7',
        '--bg-icon-active' => '#9cbcf3',
        '--border-subtle' => '#c4d1e3',
        '--text-primary' => '#122035',
        '--text-secondary' => '#33425d',
        '--text-dim' => '#6d7d96',
        '--warn' => '#4d5011',
        '--brand-cyan' => '#1582bf',
        '--brand-cyan-hover' => '#59c7f2',
        '--brand-cyan-active' => '#1582bf',
    ];
}

function videochat_workspace_theme_colors_json(string $themeId): string
{
    $colors = $themeId === 'light' ? videochat_workspace_theme_light_colors() : videochat_workspace_theme_dark_colors();
    $json = json_encode($colors, JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : '{}';
}

function videochat_workspace_theme_sql_string(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

function videochat_workspace_theme_seed_statement(string $id, string $label): string
{
    $idSql = videochat_workspace_theme_sql_string($id);
    $labelSql = videochat_workspace_theme_sql_string($label);
    $colorsSql = videochat_workspace_theme_sql_string(videochat_workspace_theme_colors_json($id));

    return <<<SQL
INSERT OR IGNORE INTO workspace_theme_presets(id, label, colors_json, is_system, created_by_user_id, created_at, updated_at)
VALUES(
  {$idSql},
  {$labelSql},
  {$colorsSql},
  1,
  NULL,
  strftime('%Y-%m-%dT%H:%M:%fZ', 'now'),
  strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
)
SQL;
}

function videochat_workspace_theme_seed_statements(): array
{
    return [
        videochat_workspace_theme_seed_statement('dark', 'Dark'),
        videochat_workspace_theme_seed_statement('light', 'Light'),
    ];
}

function videochat_workspace_theme_refresh_statements(): array
{
    $colorsSql = videochat_workspace_theme_sql_string(videochat_workspace_theme_colors_json('dark'));

    return [
        <<<SQL
UPDATE workspace_theme_presets
SET colors_json = {$colorsSql},
    updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
WHERE id = 'dark' AND is_system = 1
SQL,
    ];
}
