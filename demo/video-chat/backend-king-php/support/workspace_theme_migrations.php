<?php

declare(strict_types=1);

function videochat_workspace_theme_seed_statements(): array
{
    return [
        <<<'SQL'
INSERT OR IGNORE INTO workspace_theme_presets(id, label, colors_json, is_system, created_by_user_id, created_at, updated_at)
VALUES(
  'dark',
  'Dark',
  '{"--bg-shell":"#101b33","--bg-pane":"#101b33","--brand-bg":"#1482be","--bg-surface":"#003c93","--bg-surface-strong":"#0c1c33","--bg-input":"#d8dadd","--bg-action":"#0b1324","--bg-action-hover":"#5696ef","--bg-row":"#2a569f","--bg-row-hover":"#163260","--line":"#1d315c","--text-main":"#edf3ff","--text-muted":"#8490a1","--ok":"#177f22","--wait":"#8d9500","--danger":"#ff0000","--bg-sidebar":"#1482be","--bg-main":"#101b33","--bg-tab":"#003c93","--bg-tab-hover":"#5696ef","--bg-tab-active":"#2a569f","--bg-ui-chrome":"#3d5f98","--bg-ui-chrome-active":"#2a569f","--bg-icon":"#162e51","--bg-icon-active":"#5696ef","--border-subtle":"#1d315c","--text-primary":"#edf3ff","--text-secondary":"#c6d4eb","--text-dim":"#5e6d86","--warn":"#4d5011","--brand-cyan":"#1482be","--brand-cyan-hover":"#1a96d8","--brand-cyan-active":"#0f6ea8"}',
  1,
  NULL,
  strftime('%Y-%m-%dT%H:%M:%fZ', 'now'),
  strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
)
SQL,
        <<<'SQL'
INSERT OR IGNORE INTO workspace_theme_presets(id, label, colors_json, is_system, created_by_user_id, created_at, updated_at)
VALUES(
  'light',
  'Light',
  '{"--bg-shell":"#eff4fb","--bg-pane":"#dce8f6","--brand-bg":"#e8eff8","--bg-surface":"#f4f8fd","--bg-surface-strong":"#ffffff","--bg-input":"#ffffff","--bg-action":"#0b1324","--bg-action-hover":"#9cbcf3","--bg-row":"#b7cdf5","--bg-row-hover":"#8cabdf","--line":"#c4d1e3","--text-main":"#122035","--text-muted":"#5a6780","--ok":"#2e8b57","--wait":"#9a7b00","--danger":"#c62828","--bg-sidebar":"#e8eff8","--bg-main":"#dce8f6","--bg-tab":"#003c93","--bg-tab-hover":"#9cbcf3","--bg-tab-active":"#b7cdf5","--bg-ui-chrome":"#3d5f98","--bg-ui-chrome-active":"#2a569f","--bg-icon":"#dae7f7","--bg-icon-active":"#9cbcf3","--border-subtle":"#c4d1e3","--text-primary":"#122035","--text-secondary":"#33425d","--text-dim":"#6d7d96","--warn":"#4d5011","--brand-cyan":"#1482be","--brand-cyan-hover":"#1a96d8","--brand-cyan-active":"#0f6ea8"}',
  1,
  NULL,
  strftime('%Y-%m-%dT%H:%M:%fZ', 'now'),
  strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
)
SQL,
    ];
}

function videochat_workspace_theme_refresh_statements(): array
{
    return [
        <<<'SQL'
UPDATE workspace_theme_presets
SET colors_json = '{"--bg-shell":"#101b33","--bg-pane":"#101b33","--brand-bg":"#1482be","--bg-surface":"#003c93","--bg-surface-strong":"#0c1c33","--bg-input":"#d8dadd","--bg-action":"#0b1324","--bg-action-hover":"#5696ef","--bg-row":"#2a569f","--bg-row-hover":"#163260","--line":"#1d315c","--text-main":"#edf3ff","--text-muted":"#8490a1","--ok":"#177f22","--wait":"#8d9500","--danger":"#ff0000","--bg-sidebar":"#1482be","--bg-main":"#101b33","--bg-tab":"#003c93","--bg-tab-hover":"#5696ef","--bg-tab-active":"#2a569f","--bg-ui-chrome":"#3d5f98","--bg-ui-chrome-active":"#2a569f","--bg-icon":"#162e51","--bg-icon-active":"#5696ef","--border-subtle":"#1d315c","--text-primary":"#edf3ff","--text-secondary":"#c6d4eb","--text-dim":"#5e6d86","--warn":"#4d5011","--brand-cyan":"#1482be","--brand-cyan-hover":"#1a96d8","--brand-cyan-active":"#0f6ea8"}',
    updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
WHERE id = 'dark' AND is_system = 1
SQL,
    ];
}
