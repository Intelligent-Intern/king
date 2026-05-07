<?php

declare(strict_types=1);

function videochat_workspace_theme_dark_colors(): array
{
    return [
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
    ];
}

function videochat_workspace_theme_light_colors(): array
{
    $colors = videochat_workspace_theme_dark_colors();
    $colors['--color-primary-navy'] = '#efefe7';
    $colors['--color-surface-navy'] = '#ffffff';
    $colors['--color-heading'] = '#000010';
    $colors['--color-text-primary'] = '#000010';
    return $colors;
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
    $darkColorsSql = videochat_workspace_theme_sql_string(videochat_workspace_theme_colors_json('dark'));
    $lightColorsSql = videochat_workspace_theme_sql_string(videochat_workspace_theme_colors_json('light'));

    return [
        <<<SQL
UPDATE workspace_theme_presets
SET colors_json = {$darkColorsSql},
    updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
WHERE id = 'dark' AND is_system = 1
SQL,
        <<<SQL
UPDATE workspace_theme_presets
SET colors_json = {$lightColorsSql},
    updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
WHERE id = 'light' AND is_system = 1
SQL,
    ];
}
