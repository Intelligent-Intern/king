<?php
declare(strict_types=1);
require_once __DIR__ . '/../calls/appointment_calendar_settings.php';
require_once __DIR__ . '/../calls/appointment_calendar_mail.php';
require_once __DIR__ . '/../users/avatar_upload.php';
require_once __DIR__ . '/../../support/tenant_context.php';
function videochat_workspace_primary_admin_user_id(): int
{
    return 1;
}
function videochat_workspace_user_can_edit_themes(PDO $pdo, array $authUser): bool
{
    $role = strtolower(trim((string) ($authUser['role'] ?? '')));
    if ($role === 'admin') {
        return true;
    }
    $userId = (int) ($authUser['id'] ?? 0);
    if ($userId <= 0) {
        return false;
    }
    $query = $pdo->prepare('SELECT theme_editor_enabled FROM users WHERE id = :id LIMIT 1');
    $query->execute([':id' => $userId]);
    return ((int) ($query->fetchColumn() ?: 0)) === 1;
}
function videochat_workspace_payload_has_only_theme(array $payload): bool
{
    return $payload !== [] && count($payload) === 1 && array_key_exists('theme', $payload);
}
function videochat_workspace_default_sidebar_logo_path(): string
{
    return '/assets/orgas/kingrt/logo.svg';
}
function videochat_workspace_default_modal_logo_path(): string
{
    return '/assets/orgas/kingrt/logo.svg';
}
function videochat_workspace_default_lead_subject_template(): string
{
    return 'New website lead: {name}';
}
function videochat_workspace_default_lead_body_template(): string
{
    return "A new website lead was submitted.\n\n"
        . "Name: {name}\n"
        . "Email: {email}\n"
        . "Company: {company}\n"
        . "Participants: {participants}\n"
        . "Role: {role}\n"
        . "Use case: {use_case}\n"
        . "Timing: {timing}\n\n"
        . "Notes:\n{notes}\n\n"
        . "Remote address: {remote_addr}\n"
        . "User agent: {user_agent}\n";
}
function videochat_workspace_required_lead_subject_placeholders(): array
{
    return ['name'];
}
function videochat_workspace_required_lead_body_placeholders(): array
{
    return ['name', 'email'];
}
function videochat_workspace_default_theme_colors(string $themeId = 'dark'): array
{
    if ($themeId === 'light') {
        return [
            '--bg-shell' => '#eff4fb',
            '--bg-pane' => '#dce8f6',
            '--brand-bg' => '#e8eff8',
            '--bg-surface' => '#f4f8fd',
            '--bg-surface-strong' => '#ffffff',
            '--bg-input' => '#ffffff',
            '--bg-action' => '#0b1324',
            '--bg-action-hover' => '#9cbcf3',
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
            '--bg-tab' => '#003c93',
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
            '--brand-cyan' => '#1482be',
            '--brand-cyan-hover' => '#1a96d8',
            '--brand-cyan-active' => '#0f6ea8',
        ];
    }
    return [
        '--bg-shell' => '#09111e',
        '--bg-pane' => '#182c4d',
        '--brand-bg' => '#09111e',
        '--bg-surface' => '#003c93',
        '--bg-surface-strong' => '#0c1c33',
        '--bg-input' => '#d8dadd',
        '--bg-action' => '#0b1324',
        '--bg-action-hover' => '#5696ef',
        '--bg-row' => '#2a569f',
        '--bg-row-hover' => '#163260',
        '--line' => '#09111e',
        '--text-main' => '#edf3ff',
        '--text-muted' => '#8490a1',
        '--ok' => '#177f22',
        '--wait' => '#8d9500',
        '--danger' => '#ff0000',
        '--bg-sidebar' => '#09111e',
        '--bg-main' => '#182c4d',
        '--bg-tab' => '#003c93',
        '--bg-tab-hover' => '#5696ef',
        '--bg-tab-active' => '#2a569f',
        '--bg-ui-chrome' => '#3d5f98',
        '--bg-ui-chrome-active' => '#2a569f',
        '--bg-icon' => '#162e51',
        '--bg-icon-active' => '#5696ef',
        '--border-subtle' => '#09111e',
        '--text-primary' => '#edf3ff',
        '--text-secondary' => '#c6d4eb',
        '--text-dim' => '#5e6d86',
        '--warn' => '#4d5011',
        '--brand-cyan' => '#1482be',
        '--brand-cyan-hover' => '#1a96d8',
        '--brand-cyan-active' => '#0f6ea8',
    ];
}
function videochat_workspace_normalize_theme_id(mixed $value): string
{
    $id = strtolower(trim((string) $value));
    $id = preg_replace('/[^a-z0-9._-]+/', '-', $id) ?? '';
    $id = trim($id, '.-_');
    if ($id === '' || strlen($id) > 64) {
        return '';
    }
    return $id;
}
function videochat_workspace_normalize_theme_label(mixed $value): string
{
    $label = videochat_appointment_clean_text($value, 80);
    return $label !== '' ? $label : 'Custom theme';
}
function videochat_workspace_normalize_hex_color(mixed $value, string $fallback): string
{
    $color = strtolower(trim((string) $value));
    if (preg_match('/^#[a-f0-9]{6}$/', $color) === 1) {
        return $color;
    }
    if (preg_match('/^[a-f0-9]{6}$/', $color) === 1) {
        return '#' . $color;
    }
    if (preg_match('/^#[a-f0-9]{3}$/', $color) === 1) {
        return '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
    }
    if (preg_match('/^[a-f0-9]{3}$/', $color) === 1) {
        return '#' . $color[0] . $color[0] . $color[1] . $color[1] . $color[2] . $color[2];
    }
    return $fallback;
}
function videochat_workspace_normalize_theme_colors(mixed $value, string $baseThemeId = 'dark'): array
{
    $defaults = videochat_workspace_default_theme_colors($baseThemeId);
    $payload = is_array($value) ? $value : [];
    $colors = [];
    foreach ($defaults as $key => $fallback) {
        $colors[$key] = videochat_workspace_normalize_hex_color($payload[$key] ?? $fallback, $fallback);
    }
    return $colors;
}
function videochat_workspace_default_admin_settings_row(): array
{
    return [
        'id' => 1,
        'mail_from_email' => '',
        'mail_from_name' => '',
        'mail_smtp_host' => '',
        'mail_smtp_port' => 587,
        'mail_smtp_encryption' => 'starttls',
        'mail_smtp_username' => '',
        'mail_smtp_password' => '',
        'lead_recipients' => '[]',
        'lead_subject_template' => videochat_workspace_default_lead_subject_template(),
        'lead_body_template' => videochat_workspace_default_lead_body_template(),
        'sidebar_logo_path' => videochat_workspace_default_sidebar_logo_path(),
        'modal_logo_path' => videochat_workspace_default_modal_logo_path(),
    ];
}
function videochat_workspace_normalize_email_list(mixed $value): array
{
    $items = [];
    if (is_array($value)) {
        $items = $value;
    } elseif (is_string($value)) {
        $decoded = json_decode($value, true);
        $items = is_array($decoded) ? $decoded : preg_split('/[\r\n,;]+/', $value);
    }
    $emails = [];
    foreach ($items ?: [] as $item) {
        $email = videochat_appointment_normalize_email($item);
        if ($email !== '') {
            $emails[$email] = $email;
        }
    }
    return array_values($emails);
}
function videochat_workspace_settings_payload(array $row, bool $includeSecrets = false): array
{
    $payload = [
        'mail_from_email' => videochat_appointment_normalize_email($row['mail_from_email'] ?? ''),
        'mail_from_name' => videochat_appointment_clean_text($row['mail_from_name'] ?? '', 120),
        'mail_smtp_host' => videochat_appointment_clean_text($row['mail_smtp_host'] ?? '', 255),
        'mail_smtp_port' => videochat_appointment_normalize_smtp_port($row['mail_smtp_port'] ?? 587),
        'mail_smtp_encryption' => videochat_appointment_normalize_smtp_encryption($row['mail_smtp_encryption'] ?? 'starttls'),
        'mail_smtp_username' => videochat_appointment_clean_text($row['mail_smtp_username'] ?? '', 190),
        'mail_smtp_password_set' => trim((string) ($row['mail_smtp_password'] ?? '')) !== '',
        'lead_recipients' => videochat_workspace_normalize_email_list($row['lead_recipients'] ?? ''),
        'lead_subject_template' => videochat_appointment_clean_text(
            $row['lead_subject_template'] ?? videochat_workspace_default_lead_subject_template(),
            300
        ) ?: videochat_workspace_default_lead_subject_template(),
        'lead_body_template' => videochat_appointment_clean_multiline_text(
            $row['lead_body_template'] ?? videochat_workspace_default_lead_body_template(),
            4000
        ) ?: videochat_workspace_default_lead_body_template(),
        'sidebar_logo_path' => videochat_workspace_safe_logo_path($row['sidebar_logo_path'] ?? '', videochat_workspace_default_sidebar_logo_path()),
        'modal_logo_path' => videochat_workspace_safe_logo_path($row['modal_logo_path'] ?? '', videochat_workspace_default_modal_logo_path()),
    ];
    if ($includeSecrets) {
        $payload['mail_smtp_password'] = (string) ($row['mail_smtp_password'] ?? '');
    }
    return $payload;
}
function videochat_workspace_safe_logo_path(mixed $value, string $fallback): string
{
    $path = trim((string) $value);
    if ($path === '') {
        return $fallback;
    }
    if ($path === videochat_workspace_default_sidebar_logo_path()) {
        return $path;
    }
    if (!str_starts_with($path, '/api/workspace/branding-files/')) {
        return $fallback;
    }
    return $path;
}
function videochat_workspace_effective_tenant_id(PDO $pdo, ?int $tenantId = null): int
{
    return is_int($tenantId) && $tenantId > 0 ? $tenantId : videochat_tenant_default_id($pdo);
}
function videochat_workspace_insert_defaults(PDO $pdo, ?int $tenantId = null): void
{
    $now = gmdate('c');
    $settings = videochat_workspace_default_admin_settings_row();
    $effectiveTenantId = videochat_workspace_effective_tenant_id($pdo, $tenantId);
    $hasSettingsTenant = $effectiveTenantId > 0 && videochat_tenant_table_has_column($pdo, 'workspace_administration_settings', 'tenant_id');
    $settingsTenantColumn = $hasSettingsTenant ? ', tenant_id' : '';
    $settingsTenantValue = $hasSettingsTenant ? ', :tenant_id' : '';
    $settingsConflict = $hasSettingsTenant ? 'ON CONFLICT(tenant_id) DO NOTHING' : '';
    $settingsInsertVerb = $hasSettingsTenant ? 'INSERT INTO' : 'INSERT OR IGNORE INTO';
    $settingsIdColumn = $hasSettingsTenant ? '' : 'id, ';
    $settingsIdValue = $hasSettingsTenant ? '' : '1, ';
    $query = $pdo->prepare(
        <<<SQL
{$settingsInsertVerb} workspace_administration_settings(
    {$settingsIdColumn}mail_from_email, mail_from_name, mail_smtp_host, mail_smtp_port, mail_smtp_encryption,
    mail_smtp_username, mail_smtp_password, lead_recipients, lead_subject_template, lead_body_template,
    sidebar_logo_path, modal_logo_path, created_at, updated_at{$settingsTenantColumn}
) VALUES(
    {$settingsIdValue}:mail_from_email, :mail_from_name, :mail_smtp_host, :mail_smtp_port, :mail_smtp_encryption,
    :mail_smtp_username, :mail_smtp_password, :lead_recipients, :lead_subject_template, :lead_body_template,
    :sidebar_logo_path, :modal_logo_path, :created_at, :updated_at{$settingsTenantValue}
)
{$settingsConflict}
SQL
    );
    $settingsParams = [
        ':mail_from_email' => $settings['mail_from_email'],
        ':mail_from_name' => $settings['mail_from_name'],
        ':mail_smtp_host' => $settings['mail_smtp_host'],
        ':mail_smtp_port' => $settings['mail_smtp_port'],
        ':mail_smtp_encryption' => $settings['mail_smtp_encryption'],
        ':mail_smtp_username' => $settings['mail_smtp_username'],
        ':mail_smtp_password' => $settings['mail_smtp_password'],
        ':lead_recipients' => $settings['lead_recipients'],
        ':lead_subject_template' => $settings['lead_subject_template'],
        ':lead_body_template' => $settings['lead_body_template'],
        ':sidebar_logo_path' => $settings['sidebar_logo_path'],
        ':modal_logo_path' => $settings['modal_logo_path'],
        ':created_at' => $now,
        ':updated_at' => $now,
    ];
    if ($hasSettingsTenant) {
        $settingsParams[':tenant_id'] = $effectiveTenantId;
    }
    $query->execute($settingsParams);
    foreach (['dark' => 'Dark', 'light' => 'Light'] as $id => $label) {
        $themeTenantColumn = $effectiveTenantId > 0 && videochat_tenant_table_has_column($pdo, 'workspace_theme_presets', 'tenant_id') ? ', tenant_id' : '';
        $themeTenantValue = $themeTenantColumn !== '' ? ', :tenant_id' : '';
        $themeConflict = $themeTenantColumn !== '' ? 'ON CONFLICT(tenant_id, id) DO NOTHING' : '';
        $themeInsertVerb = $themeTenantColumn !== '' ? 'INSERT INTO' : 'INSERT OR IGNORE INTO';
        $themeQuery = $pdo->prepare(
            <<<SQL
{$themeInsertVerb} workspace_theme_presets(id, label, colors_json, is_system, created_by_user_id, created_at, updated_at{$themeTenantColumn})
VALUES(:id, :label, :colors_json, 1, 1, :created_at, :updated_at{$themeTenantValue})
{$themeConflict}
SQL
        );
        $themeParams = [
            ':id' => $id,
            ':label' => $label,
            ':colors_json' => json_encode(videochat_workspace_default_theme_colors($id), JSON_UNESCAPED_SLASHES),
            ':created_at' => $now,
            ':updated_at' => $now,
        ];
        if ($themeTenantColumn !== '') {
            $themeParams[':tenant_id'] = $effectiveTenantId;
        }
        $themeQuery->execute($themeParams);
    }
}
function videochat_workspace_get_admin_settings_row(PDO $pdo, ?int $tenantId = null): array
{
    videochat_workspace_insert_defaults($pdo, $tenantId);
    $effectiveTenantId = videochat_workspace_effective_tenant_id($pdo, $tenantId);
    if ($effectiveTenantId > 0 && videochat_tenant_table_has_column($pdo, 'workspace_administration_settings', 'tenant_id')) {
        $query = $pdo->prepare('SELECT * FROM workspace_administration_settings WHERE tenant_id = :tenant_id LIMIT 1');
        $query->execute([':tenant_id' => $effectiveTenantId]);
        $row = $query->fetch();
    } else {
        $query = $pdo->query('SELECT * FROM workspace_administration_settings WHERE id = 1 LIMIT 1');
        $row = $query ? $query->fetch() : false;
    }
    return is_array($row) ? $row : videochat_workspace_default_admin_settings_row();
}
function videochat_workspace_list_theme_presets(PDO $pdo, ?int $tenantId = null): array
{
    videochat_workspace_insert_defaults($pdo, $tenantId);
    $effectiveTenantId = videochat_workspace_effective_tenant_id($pdo, $tenantId);
    if ($effectiveTenantId > 0 && videochat_tenant_table_has_column($pdo, 'workspace_theme_presets', 'tenant_id')) {
        $query = $pdo->prepare('SELECT * FROM workspace_theme_presets WHERE tenant_id = :tenant_id ORDER BY is_system DESC, label COLLATE NOCASE ASC');
        $query->execute([':tenant_id' => $effectiveTenantId]);
    } else {
        $query = $pdo->query('SELECT * FROM workspace_theme_presets ORDER BY is_system DESC, label COLLATE NOCASE ASC');
    }
    $themes = [];
    foreach (($query ? $query->fetchAll() : []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = videochat_workspace_normalize_theme_id($row['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $decoded = json_decode((string) ($row['colors_json'] ?? ''), true);
        $themes[] = [
            'id' => $id,
            'label' => videochat_workspace_normalize_theme_label($row['label'] ?? $id),
            'colors' => videochat_workspace_normalize_theme_colors(is_array($decoded) ? $decoded : [], $id),
            'is_system' => ((int) ($row['is_system'] ?? 0)) === 1,
        ];
    }
    return $themes;
}
function videochat_workspace_public_appearance(PDO $pdo, ?int $tenantId = null): array
{
    $settings = videochat_workspace_settings_payload(videochat_workspace_get_admin_settings_row($pdo, $tenantId), false);
    return [
        'sidebar_logo_path' => (string) $settings['sidebar_logo_path'],
        'modal_logo_path' => (string) $settings['modal_logo_path'],
        'themes' => videochat_workspace_list_theme_presets($pdo, $tenantId),
        'defaults' => [
            'sidebar_logo_path' => videochat_workspace_default_sidebar_logo_path(),
            'modal_logo_path' => videochat_workspace_default_modal_logo_path(),
            'lead_subject_template' => videochat_workspace_default_lead_subject_template(),
            'lead_body_template' => videochat_workspace_default_lead_body_template(),
        ],
    ];
}
function videochat_workspace_validate_admin_payload(array $payload, string $storageRoot, int $maxBytes): array
{
    $settings = [];
    $errors = [];
    $allowed = [
        'mail_from_email', 'mail_from_name', 'mail_smtp_host', 'mail_smtp_port', 'mail_smtp_encryption',
        'mail_smtp_username', 'mail_smtp_password', 'mail_smtp_password_clear', 'lead_recipients',
        'lead_subject_template', 'lead_body_template', 'lead_templates_reset', 'sidebar_logo_data_url',
        'modal_logo_data_url', 'sidebar_logo_reset', 'modal_logo_reset', 'theme',
    ];
    foreach ($payload as $field => $_value) {
        $fieldName = is_string($field) ? trim($field) : (string) $field;
        if (!in_array($fieldName, $allowed, true)) {
            $errors[$fieldName === '' ? 'payload' : $fieldName] = 'field_not_supported';
        }
    }
    if (array_key_exists('mail_from_email', $payload)) {
        $email = videochat_appointment_normalize_email($payload['mail_from_email']);
        if (trim((string) $payload['mail_from_email']) !== '' && $email === '') {
            $errors['mail_from_email'] = 'required_valid_email';
        } else {
            $settings['mail_from_email'] = $email;
        }
    }
    if (array_key_exists('mail_from_name', $payload)) {
        $settings['mail_from_name'] = videochat_appointment_clean_text($payload['mail_from_name'], 120);
    }
    if (array_key_exists('mail_smtp_host', $payload)) {
        $settings['mail_smtp_host'] = videochat_appointment_clean_text($payload['mail_smtp_host'], 255);
    }
    if (array_key_exists('mail_smtp_port', $payload)) {
        $settings['mail_smtp_port'] = videochat_appointment_normalize_smtp_port($payload['mail_smtp_port']);
    }
    if (array_key_exists('mail_smtp_encryption', $payload)) {
        $settings['mail_smtp_encryption'] = videochat_appointment_normalize_smtp_encryption($payload['mail_smtp_encryption']);
    }
    if (array_key_exists('mail_smtp_username', $payload)) {
        $settings['mail_smtp_username'] = videochat_appointment_clean_text($payload['mail_smtp_username'], 190);
    }
    if (array_key_exists('mail_smtp_password', $payload) && trim((string) $payload['mail_smtp_password']) !== '') {
        $settings['mail_smtp_password'] = videochat_appointment_clean_multiline_text($payload['mail_smtp_password'], 512);
    }
    if (videochat_appointment_truthy($payload['mail_smtp_password_clear'] ?? false)) {
        $settings['mail_smtp_password'] = '';
    }
    if (array_key_exists('lead_recipients', $payload)) {
        $settings['lead_recipients'] = json_encode(videochat_workspace_normalize_email_list($payload['lead_recipients']), JSON_UNESCAPED_SLASHES);
    }
    if (videochat_appointment_truthy($payload['lead_templates_reset'] ?? false)) {
        $settings['lead_subject_template'] = videochat_workspace_default_lead_subject_template();
        $settings['lead_body_template'] = videochat_workspace_default_lead_body_template();
    } else {
        if (array_key_exists('lead_subject_template', $payload)) {
            $subject = videochat_appointment_clean_text($payload['lead_subject_template'], 300);
            $subject = $subject === '' ? videochat_workspace_default_lead_subject_template() : $subject;
            $missing = videochat_appointment_missing_template_placeholders($subject, videochat_workspace_required_lead_subject_placeholders());
            if ($missing !== []) {
                $errors['lead_subject_template'] = videochat_appointment_missing_placeholder_error($missing);
            } else {
                $settings['lead_subject_template'] = $subject;
            }
        }
        if (array_key_exists('lead_body_template', $payload)) {
            $body = videochat_appointment_clean_multiline_text($payload['lead_body_template'], 4000);
            $body = $body === '' ? videochat_workspace_default_lead_body_template() : $body;
            $missing = videochat_appointment_missing_template_placeholders($body, videochat_workspace_required_lead_body_placeholders());
            if ($missing !== []) {
                $errors['lead_body_template'] = videochat_appointment_missing_placeholder_error($missing);
            } else {
                $settings['lead_body_template'] = $body;
            }
        }
    }
    foreach (['sidebar' => 'sidebar_logo', 'modal' => 'modal_logo'] as $kind => $fieldPrefix) {
        if (videochat_appointment_truthy($payload[$fieldPrefix . '_reset'] ?? false)) {
            $settings[$fieldPrefix . '_path'] = $kind === 'sidebar'
                ? videochat_workspace_default_sidebar_logo_path()
                : videochat_workspace_default_modal_logo_path();
            continue;
        }
        $dataUrlKey = $fieldPrefix . '_data_url';
        if (array_key_exists($dataUrlKey, $payload) && trim((string) $payload[$dataUrlKey]) !== '') {
            $upload = videochat_workspace_store_logo_upload((string) $payload[$dataUrlKey], $kind, $storageRoot, $maxBytes);
            if (!(bool) ($upload['ok'] ?? false)) {
                $errors[$dataUrlKey] = (string) ($upload['reason'] ?? 'invalid_upload');
            } else {
                $settings[$fieldPrefix . '_path'] = (string) ($upload['path'] ?? '');
            }
        }
    }
    $theme = null;
    if (array_key_exists('theme', $payload)) {
        $theme = videochat_workspace_validate_theme_payload($payload['theme']);
        if (!(bool) ($theme['ok'] ?? false)) {
            $errors['theme'] = (string) ($theme['reason'] ?? 'invalid_theme');
        }
    }
    return [
        'ok' => $errors === [],
        'settings' => $settings,
        'theme' => $theme,
        'errors' => $errors,
    ];
}
function videochat_workspace_validate_theme_payload(mixed $payload): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'reason' => 'must_be_object'];
    }
    $createNew = videochat_appointment_truthy($payload['create_new'] ?? false);
    $label = videochat_workspace_normalize_theme_label($payload['label'] ?? '');
    $id = $createNew ? '' : videochat_workspace_normalize_theme_id($payload['id'] ?? '');
    if (!$createNew && $id === '') {
        return ['ok' => false, 'reason' => 'required_theme_id'];
    }
    $colors = videochat_workspace_normalize_theme_colors($payload['colors'] ?? [], videochat_workspace_normalize_theme_id($payload['base_theme'] ?? 'dark') ?: 'dark');
    return [
        'ok' => true,
        'reason' => 'ok',
        'create_new' => $createNew,
        'id' => $id,
        'label' => $label,
        'colors' => $colors,
    ];
}
function videochat_workspace_store_logo_upload(string $dataUrl, string $kind, string $storageRoot, int $maxBytes): array
{
    $parsed = videochat_avatar_parse_upload_payload(['data_url' => $dataUrl], $maxBytes);
    if (!(bool) ($parsed['ok'] ?? false) || !is_array($parsed['data'] ?? null)) {
        return ['ok' => false, 'reason' => 'invalid_logo_upload'];
    }
    $data = (array) $parsed['data'];
    $dir = rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'branding';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'reason' => 'storage_unavailable'];
    }
    $extension = (string) ($data['extension'] ?? 'png');
    $binary = (string) ($data['binary'] ?? '');
    $filename = 'brand-' . ($kind === 'modal' ? 'modal' : 'sidebar') . '-' . substr(hash('sha256', $binary), 0, 16) . '.' . $extension;
    $path = $dir . DIRECTORY_SEPARATOR . $filename;
    if (@file_put_contents($path, $binary, LOCK_EX) === false) {
        return ['ok' => false, 'reason' => 'write_failed'];
    }
    return ['ok' => true, 'reason' => 'stored', 'path' => '/api/workspace/branding-files/' . rawurlencode($filename)];
}
function videochat_workspace_save_admin_settings(PDO $pdo, array $settings, ?array $theme, ?int $tenantId = null): array
{
    $existing = videochat_workspace_settings_payload(videochat_workspace_get_admin_settings_row($pdo, $tenantId), true);
    $next = [...$existing, ...$settings];
    $now = gmdate('c');
    $effectiveTenantId = videochat_workspace_effective_tenant_id($pdo, $tenantId);
    $tenantWhere = $effectiveTenantId > 0 && videochat_tenant_table_has_column($pdo, 'workspace_administration_settings', 'tenant_id')
        ? 'tenant_id = :tenant_id'
        : 'id = 1';
    $query = $pdo->prepare(
        <<<SQL
UPDATE workspace_administration_settings
SET mail_from_email = :mail_from_email,
    mail_from_name = :mail_from_name,
    mail_smtp_host = :mail_smtp_host,
    mail_smtp_port = :mail_smtp_port,
    mail_smtp_encryption = :mail_smtp_encryption,
    mail_smtp_username = :mail_smtp_username,
    mail_smtp_password = :mail_smtp_password,
    lead_recipients = :lead_recipients,
    lead_subject_template = :lead_subject_template,
    lead_body_template = :lead_body_template,
    sidebar_logo_path = :sidebar_logo_path,
    modal_logo_path = :modal_logo_path,
    updated_at = :updated_at
WHERE {$tenantWhere}
SQL
    );
    $params = [
        ':mail_from_email' => videochat_appointment_normalize_email($next['mail_from_email'] ?? ''),
        ':mail_from_name' => videochat_appointment_clean_text($next['mail_from_name'] ?? '', 120),
        ':mail_smtp_host' => videochat_appointment_clean_text($next['mail_smtp_host'] ?? '', 255),
        ':mail_smtp_port' => videochat_appointment_normalize_smtp_port($next['mail_smtp_port'] ?? 587),
        ':mail_smtp_encryption' => videochat_appointment_normalize_smtp_encryption($next['mail_smtp_encryption'] ?? 'starttls'),
        ':mail_smtp_username' => videochat_appointment_clean_text($next['mail_smtp_username'] ?? '', 190),
        ':mail_smtp_password' => (string) ($next['mail_smtp_password'] ?? ''),
        ':lead_recipients' => json_encode(videochat_workspace_normalize_email_list($next['lead_recipients'] ?? []), JSON_UNESCAPED_SLASHES),
        ':lead_subject_template' => videochat_appointment_clean_text($next['lead_subject_template'] ?? videochat_workspace_default_lead_subject_template(), 300)
            ?: videochat_workspace_default_lead_subject_template(),
        ':lead_body_template' => videochat_appointment_clean_multiline_text($next['lead_body_template'] ?? videochat_workspace_default_lead_body_template(), 4000)
            ?: videochat_workspace_default_lead_body_template(),
        ':sidebar_logo_path' => videochat_workspace_safe_logo_path($next['sidebar_logo_path'] ?? '', videochat_workspace_default_sidebar_logo_path()),
        ':modal_logo_path' => videochat_workspace_safe_logo_path($next['modal_logo_path'] ?? '', videochat_workspace_default_modal_logo_path()),
        ':updated_at' => $now,
    ];
    if ($tenantWhere === 'tenant_id = :tenant_id') {
        $params[':tenant_id'] = $effectiveTenantId;
    }
    $query->execute($params);
    $savedTheme = null;
    if (is_array($theme) && (bool) ($theme['ok'] ?? false)) {
        $savedTheme = videochat_workspace_save_theme($pdo, $theme, $now, $tenantId);
    }
    return [
        'settings' => videochat_workspace_settings_payload(videochat_workspace_get_admin_settings_row($pdo, $tenantId), false),
        'saved_theme' => $savedTheme,
    ];
}
function videochat_workspace_save_theme(PDO $pdo, array $theme, string $now, ?int $tenantId = null): array
{
    $id = (string) ($theme['id'] ?? '');
    if ((bool) ($theme['create_new'] ?? false) || $id === '') {
        $id = 'custom-' . strtolower(videochat_generate_call_access_uuid());
    }
    $effectiveTenantId = videochat_workspace_effective_tenant_id($pdo, $tenantId);
    $tenantColumn = $effectiveTenantId > 0 && videochat_tenant_table_has_column($pdo, 'workspace_theme_presets', 'tenant_id') ? ', tenant_id' : '';
    $tenantValue = $tenantColumn !== '' ? ', :tenant_id' : '';
    $conflict = $tenantColumn !== '' ? 'tenant_id, id' : 'id';
    $query = $pdo->prepare(
        <<<SQL
INSERT INTO workspace_theme_presets(id, label, colors_json, is_system, created_by_user_id, created_at, updated_at{$tenantColumn})
VALUES(:id, :label, :colors_json, 0, 1, :created_at, :updated_at{$tenantValue})
ON CONFLICT({$conflict}) DO UPDATE SET
  label = excluded.label,
  colors_json = excluded.colors_json,
  updated_at = excluded.updated_at
SQL
    );
    $params = [
        ':id' => $id,
        ':label' => videochat_workspace_normalize_theme_label($theme['label'] ?? ''),
        ':colors_json' => json_encode(videochat_workspace_normalize_theme_colors($theme['colors'] ?? []), JSON_UNESCAPED_SLASHES),
        ':created_at' => $now,
        ':updated_at' => $now,
    ];
    if ($tenantColumn !== '') {
        $params[':tenant_id'] = $effectiveTenantId;
    }
    $query->execute($params);
    return ['id' => $id];
}
function videochat_workspace_delete_theme(PDO $pdo, string $themeId, ?int $tenantId = null): array
{
    videochat_workspace_insert_defaults($pdo, $tenantId);
    $id = videochat_workspace_normalize_theme_id($themeId);
    if ($id === '') {
        return ['ok' => false, 'reason' => 'invalid_theme_id'];
    }
    $effectiveTenantId = videochat_workspace_effective_tenant_id($pdo, $tenantId);
    $tenantWhere = $effectiveTenantId > 0 && videochat_tenant_table_has_column($pdo, 'workspace_theme_presets', 'tenant_id')
        ? ' AND tenant_id = :tenant_id'
        : '';
    $lookup = $pdo->prepare('SELECT id, is_system FROM workspace_theme_presets WHERE id = :id' . $tenantWhere . ' LIMIT 1');
    $lookupParams = [':id' => $id];
    if ($tenantWhere !== '') {
        $lookupParams[':tenant_id'] = $effectiveTenantId;
    }
    $lookup->execute($lookupParams);
    $row = $lookup->fetch();
    if (!is_array($row)) {
        return ['ok' => false, 'reason' => 'theme_not_found'];
    }
    if (((int) ($row['is_system'] ?? 0)) === 1) {
        return ['ok' => false, 'reason' => 'system_theme_locked'];
    }
    $now = gmdate('c');
    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $fallbackUsers = $pdo->prepare('UPDATE users SET theme = :fallback, updated_at = :updated_at WHERE theme = :id');
        $fallbackUsers->execute([
            ':fallback' => 'dark',
            ':updated_at' => $now,
            ':id' => $id,
        ]);
        $delete = $pdo->prepare('DELETE FROM workspace_theme_presets WHERE id = :id AND is_system = 0' . $tenantWhere);
        $delete->execute($lookupParams);
        if ($startedTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $error) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    return [
        'ok' => true,
        'reason' => 'deleted',
        'id' => $id,
        'fallback_theme' => 'dark',
    ];
}
function videochat_workspace_mail_transport_settings(PDO $pdo, ?int $tenantId = null): array
{
    return videochat_workspace_settings_payload(videochat_workspace_get_admin_settings_row($pdo, $tenantId), true);
}
function videochat_workspace_validate_public_lead_payload(array $payload, array $request = []): array
{
    $name = videochat_appointment_clean_text($payload['name'] ?? '', 120);
    $email = videochat_appointment_normalize_email($payload['email'] ?? '');
    $company = videochat_appointment_clean_text($payload['company'] ?? '', 180);
    $participants = (int) ($payload['participants'] ?? 0);
    $privacy = videochat_appointment_truthy($payload['privacy'] ?? false) || (string) ($payload['privacy'] ?? '') === 'accepted';
    $errors = [];
    if (strlen($name) < 2) {
        $errors['name'] = 'required_name';
    }
    if ($email === '') {
        $errors['email'] = 'required_valid_email';
    }
    if (strlen($company) < 2) {
        $errors['company'] = 'required_company';
    }
    if ($participants < 2) {
        $errors['participants'] = 'required_minimum_participants';
    }
    if (!$privacy) {
        $errors['privacy'] = 'required_privacy_acceptance';
    }
    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'data' => [
            'name' => $name,
            'email' => $email,
            'company' => $company,
            'participants' => $participants,
            'role' => videochat_appointment_clean_text($payload['role'] ?? '', 120),
            'use_case' => videochat_appointment_clean_text($payload['use_case'] ?? '', 80),
            'timing' => videochat_appointment_clean_text($payload['timing'] ?? '', 80),
            'notes' => videochat_appointment_clean_text($payload['notes'] ?? '', 500),
            'locale' => videochat_normalize_locale_code($payload['locale'] ?? ''),
            'user_agent' => videochat_appointment_clean_text($request['headers']['user-agent'] ?? '', 300),
            'remote_addr' => videochat_appointment_clean_text($request['headers']['x-forwarded-for'] ?? ($request['remote_addr'] ?? ''), 120),
        ],
    ];
}
function videochat_workspace_store_public_lead(PDO $pdo, array $lead, ?int $tenantId = null): string
{
    $id = videochat_generate_call_id();
    $effectiveTenantId = videochat_workspace_effective_tenant_id($pdo, $tenantId);
    $tenantColumn = $effectiveTenantId > 0 && videochat_tenant_table_has_column($pdo, 'website_leads', 'tenant_id') ? ', tenant_id' : '';
    $tenantValue = $tenantColumn !== '' ? ', :tenant_id' : '';
    $query = $pdo->prepare(
        <<<SQL
INSERT INTO website_leads(id, created_at, name, email, company, participants, role, use_case, timing, notes, user_agent, remote_addr{$tenantColumn})
VALUES(:id, :created_at, :name, :email, :company, :participants, :role, :use_case, :timing, :notes, :user_agent, :remote_addr{$tenantValue})
SQL
    );
    $params = [
        ':id' => $id,
        ':created_at' => gmdate('c'),
        ':name' => (string) $lead['name'],
        ':email' => (string) $lead['email'],
        ':company' => (string) $lead['company'],
        ':participants' => (int) $lead['participants'],
        ':role' => (string) $lead['role'],
        ':use_case' => (string) $lead['use_case'],
        ':timing' => (string) $lead['timing'],
        ':notes' => (string) $lead['notes'],
        ':user_agent' => (string) $lead['user_agent'],
        ':remote_addr' => (string) $lead['remote_addr'],
    ];
    if ($tenantColumn !== '') {
        $params[':tenant_id'] = $effectiveTenantId;
    }
    $query->execute($params);
    return $id;
}
function videochat_workspace_send_public_lead_notifications(PDO $pdo, array $lead, ?int $tenantId = null): array
{
    $settings = videochat_workspace_mail_transport_settings($pdo, $tenantId);
    $recipients = videochat_workspace_normalize_email_list($settings['lead_recipients'] ?? []);
    $subjectTemplate = (string) ($settings['lead_subject_template'] ?? videochat_workspace_default_lead_subject_template());
    $bodyTemplate = (string) ($settings['lead_body_template'] ?? videochat_workspace_default_lead_body_template());
    $templates = videochat_resolve_localized_email_templates(
        $pdo,
        $tenantId,
        (string) ($lead['locale'] ?? ''),
        'emails.public_lead.subject',
        'emails.public_lead.body',
        $subjectTemplate,
        $bodyTemplate,
        videochat_workspace_required_lead_subject_placeholders(),
        videochat_workspace_required_lead_body_placeholders()
    );
    $variables = [
        'name' => (string) ($lead['name'] ?? ''),
        'email' => (string) ($lead['email'] ?? ''),
        'company' => (string) ($lead['company'] ?? ''),
        'participants' => (string) ($lead['participants'] ?? ''),
        'role' => (string) ($lead['role'] ?? ''),
        'use_case' => (string) ($lead['use_case'] ?? ''),
        'timing' => (string) ($lead['timing'] ?? ''),
        'notes' => (string) ($lead['notes'] ?? ''),
        'user_agent' => (string) ($lead['user_agent'] ?? ''),
        'remote_addr' => (string) ($lead['remote_addr'] ?? ''),
    ];
    $subject = videochat_appointment_render_mail_template((string) ($templates['subject_template'] ?? $subjectTemplate), $variables);
    $body = videochat_appointment_render_mail_template((string) ($templates['body_template'] ?? $bodyTemplate), $variables);
    $results = [];
    foreach ($recipients as $recipient) {
        $results[$recipient] = [
            ...videochat_appointment_send_mail($settings, $recipient, $recipient, $subject, $body),
            'template_locale' => (string) ($templates['locale'] ?? ''),
            'subject_locale' => (string) ($templates['subject_locale'] ?? ''),
            'body_locale' => (string) ($templates['body_locale'] ?? ''),
        ];
    }
    return $results;
}
