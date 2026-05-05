<?php

declare(strict_types=1);

require_once __DIR__ . '/call_access.php';

function videochat_appointment_clean_text(mixed $value, int $maxLength): string
{
    $text = preg_replace('/\s+/u', ' ', trim((string) $value));
    if (!is_string($text)) {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength);
    }

    return substr($text, 0, $maxLength);
}

function videochat_appointment_clean_multiline_text(mixed $value, int $maxLength): string
{
    $text = str_replace(["\r\n", "\r"], "\n", trim((string) $value));
    $text = preg_replace('/[^\P{C}\n\t]/u', '', $text);
    if (!is_string($text)) {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength);
    }

    return substr($text, 0, $maxLength);
}

function videochat_appointment_normalize_email(mixed $value): string
{
    $email = strtolower(trim((string) $value));
    if ($email === '' || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return '';
    }

    return $email;
}

function videochat_appointment_truthy(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value === 1;
    }

    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

function videochat_appointment_normalize_public_id(mixed $value): string
{
    $publicId = strtolower(trim((string) $value));
    if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $publicId) !== 1) {
        return '';
    }

    return $publicId;
}

function videochat_appointment_normalize_slot_minutes(mixed $value): int
{
    $minutes = (int) $value;
    return in_array($minutes, [5, 10, 15, 20, 30, 45, 60], true) ? $minutes : 15;
}

function videochat_appointment_normalize_slot_mode(mixed $value): string
{
    $mode = strtolower(trim((string) $value));
    if (in_array($mode, ['recurring_weekly', 'recurring', 'recurring_slots'], true)) {
        return 'recurring_weekly';
    }
    return 'selected_dates';
}

function videochat_appointment_normalize_smtp_port(mixed $value): int
{
    $port = (int) $value;
    return $port >= 1 && $port <= 65535 ? $port : 587;
}

function videochat_appointment_normalize_smtp_encryption(mixed $value): string
{
    $encryption = strtolower(trim((string) $value));
    return in_array($encryption, ['none', 'ssl', 'starttls'], true) ? $encryption : 'starttls';
}

function videochat_default_appointment_email_subject_template(): string
{
    return 'Video call scheduled: {call_title}';
}

function videochat_default_appointment_email_body_template(): string
{
    return "Hello {recipient_name},\n\n"
        . "your video call is scheduled for {starts_at}.\n\n"
        . "Call link:\n{join_link}\n\n"
        . "Google Calendar:\n{google_calendar_url}\n\n"
        . "Participant: {guest_name} ({guest_email})\n"
        . "Owner: {owner_name} ({owner_email})\n";
}

function videochat_appointment_default_settings_row(int $ownerUserId, string $publicId = '', ?int $tenantId = null): array
{
    return [
        'tenant_id' => is_int($tenantId) && $tenantId > 0 ? $tenantId : null,
        'owner_user_id' => $ownerUserId,
        'public_id' => $publicId,
        'slot_minutes' => 15,
        'slot_mode' => 'selected_dates',
        'invitation_text' => '',
        'mail_from_email' => '',
        'mail_from_name' => '',
        'mail_smtp_host' => '',
        'mail_smtp_port' => 587,
        'mail_smtp_encryption' => 'starttls',
        'mail_smtp_username' => '',
        'mail_smtp_password' => '',
        'mail_subject_template' => videochat_default_appointment_email_subject_template(),
        'mail_body_template' => videochat_default_appointment_email_body_template(),
    ];
}

function videochat_appointment_public_settings_payload(array $row): array
{
    return [
        'public_id' => videochat_appointment_normalize_public_id($row['public_id'] ?? ''),
        'slot_minutes' => videochat_appointment_normalize_slot_minutes($row['slot_minutes'] ?? 15),
        'slot_mode' => videochat_appointment_normalize_slot_mode($row['slot_mode'] ?? 'selected_dates'),
        'invitation_text' => videochat_appointment_clean_multiline_text($row['invitation_text'] ?? '', 1200),
    ];
}

function videochat_appointment_owner_settings_payload(array $row): array
{
    return [
        ...videochat_appointment_public_settings_payload($row),
        'mail_subject_template' => videochat_appointment_clean_text(
            $row['mail_subject_template'] ?? videochat_default_appointment_email_subject_template(),
            300
        ) ?: videochat_default_appointment_email_subject_template(),
        'mail_body_template' => videochat_appointment_clean_multiline_text(
            $row['mail_body_template'] ?? videochat_default_appointment_email_body_template(),
            4000
        ) ?: videochat_default_appointment_email_body_template(),
    ];
}

function videochat_get_appointment_settings_row_by_owner(PDO $pdo, int $ownerUserId, ?int $tenantId = null): ?array
{
    $tenantWhere = is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'appointment_calendar_settings', 'tenant_id')
        ? ' AND tenant_id = :tenant_id'
        : '';
    $query = $pdo->prepare('SELECT * FROM appointment_calendar_settings WHERE owner_user_id = :owner_user_id' . $tenantWhere . ' LIMIT 1');
    $params = [':owner_user_id' => $ownerUserId];
    if ($tenantWhere !== '') {
        $params[':tenant_id'] = $tenantId;
    }
    $query->execute($params);
    $row = $query->fetch();
    return is_array($row) ? $row : null;
}

function videochat_get_or_create_appointment_settings(PDO $pdo, int $ownerUserId, ?int $tenantId = null): array
{
    $row = videochat_get_appointment_settings_row_by_owner($pdo, $ownerUserId, $tenantId);
    if (is_array($row)) {
        return videochat_appointment_owner_settings_payload($row);
    }

    $now = gmdate('c');
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $publicId = strtolower(videochat_generate_call_access_uuid());
        $defaults = videochat_appointment_default_settings_row($ownerUserId, $publicId, $tenantId);
        try {
            videochat_insert_or_update_appointment_settings_row($pdo, $defaults, $now);
            return videochat_appointment_owner_settings_payload($defaults);
        } catch (Throwable) {
        }
    }

    return videochat_appointment_owner_settings_payload(videochat_appointment_default_settings_row($ownerUserId, '', $tenantId));
}

function videochat_get_appointment_settings_by_public_id(PDO $pdo, string $publicId): ?array
{
    $publicId = videochat_appointment_normalize_public_id($publicId);
    if ($publicId === '') {
        return null;
    }

    $query = $pdo->prepare(
        <<<'SQL'
SELECT appointment_calendar_settings.*, users.id AS user_id, users.display_name
FROM appointment_calendar_settings
INNER JOIN users
  ON users.id = appointment_calendar_settings.owner_user_id
 AND users.status = 'active'
WHERE appointment_calendar_settings.public_id = :public_id
LIMIT 1
SQL
    );
    $query->execute([':public_id' => $publicId]);
    $row = $query->fetch();
    if (!is_array($row)) {
        return null;
    }

    return [
        'owner_user_id' => (int) ($row['owner_user_id'] ?? $row['user_id'] ?? 0),
        'tenant_id' => is_numeric($row['tenant_id'] ?? null) ? (int) $row['tenant_id'] : null,
        'owner_display_name' => (string) ($row['display_name'] ?? ''),
        'settings' => videochat_appointment_owner_settings_payload($row),
    ];
}

function videochat_validate_appointment_settings_payload(mixed $payload): array
{
    if ($payload === null) {
        return ['ok' => true, 'settings' => null, 'errors' => []];
    }
    if (!is_array($payload)) {
        return ['ok' => false, 'settings' => null, 'errors' => ['settings' => 'must_be_object']];
    }

    $settings = [];
    $errors = [];
    foreach ($payload as $field => $_value) {
        $fieldName = is_string($field) ? trim($field) : (string) $field;
        if (!in_array($fieldName, [
            'slot_minutes',
            'slot_mode',
            'invitation_text',
            'mail_subject_template',
            'mail_body_template',
            'mail_templates_reset',
        ], true)) {
            $errors[$fieldName === '' ? 'settings' : $fieldName] = 'field_not_supported';
        }
    }

    if (array_key_exists('slot_minutes', $payload)) {
        $settings['slot_minutes'] = videochat_appointment_normalize_slot_minutes($payload['slot_minutes']);
    }
    if (array_key_exists('slot_mode', $payload)) {
        $settings['slot_mode'] = videochat_appointment_normalize_slot_mode($payload['slot_mode']);
    }
    if (array_key_exists('invitation_text', $payload)) {
        $settings['invitation_text'] = videochat_appointment_clean_multiline_text($payload['invitation_text'], 1200);
    }
    if (videochat_appointment_truthy($payload['mail_templates_reset'] ?? false)) {
        $settings['mail_subject_template'] = videochat_default_appointment_email_subject_template();
        $settings['mail_body_template'] = videochat_default_appointment_email_body_template();
    } elseif (array_key_exists('mail_subject_template', $payload)) {
        $subject = videochat_appointment_clean_text($payload['mail_subject_template'], 300);
        $settings['mail_subject_template'] = $subject === '' ? videochat_default_appointment_email_subject_template() : $subject;
    }
    if (!videochat_appointment_truthy($payload['mail_templates_reset'] ?? false) && array_key_exists('mail_body_template', $payload)) {
        $body = videochat_appointment_clean_multiline_text($payload['mail_body_template'], 4000);
        $settings['mail_body_template'] = $body === '' ? videochat_default_appointment_email_body_template() : $body;
    }

    return [
        'ok' => $errors === [],
        'settings' => $settings,
        'errors' => $errors,
    ];
}

function videochat_insert_or_update_appointment_settings_row(PDO $pdo, array $row, string $now): void
{
    $hasTenantColumn = videochat_tenant_table_has_column($pdo, 'appointment_calendar_settings', 'tenant_id');
    $tenantColumn = $hasTenantColumn ? 'tenant_id, ' : '';
    $tenantValue = $hasTenantColumn ? ':tenant_id, ' : '';
    $conflict = $hasTenantColumn ? 'tenant_id, owner_user_id' : 'owner_user_id';
    $query = $pdo->prepare(
        <<<SQL
INSERT INTO appointment_calendar_settings(
    {$tenantColumn}owner_user_id, public_id, slot_minutes, slot_mode, invitation_text,
    mail_from_email, mail_from_name, mail_smtp_host, mail_smtp_port, mail_smtp_encryption,
    mail_smtp_username, mail_smtp_password, mail_subject_template, mail_body_template,
    created_at, updated_at
) VALUES(
    {$tenantValue}:owner_user_id, :public_id, :slot_minutes, :slot_mode, :invitation_text,
    :mail_from_email, :mail_from_name, :mail_smtp_host, :mail_smtp_port, :mail_smtp_encryption,
    :mail_smtp_username, :mail_smtp_password, :mail_subject_template, :mail_body_template,
    :created_at, :updated_at
)
ON CONFLICT({$conflict}) DO UPDATE SET
  slot_minutes = excluded.slot_minutes,
  slot_mode = excluded.slot_mode,
  invitation_text = excluded.invitation_text,
  mail_from_email = excluded.mail_from_email,
  mail_from_name = excluded.mail_from_name,
  mail_smtp_host = excluded.mail_smtp_host,
  mail_smtp_port = excluded.mail_smtp_port,
  mail_smtp_encryption = excluded.mail_smtp_encryption,
  mail_smtp_username = excluded.mail_smtp_username,
  mail_smtp_password = excluded.mail_smtp_password,
  mail_subject_template = excluded.mail_subject_template,
  mail_body_template = excluded.mail_body_template,
  updated_at = excluded.updated_at
SQL
    );
    $params = [
        ':owner_user_id' => (int) ($row['owner_user_id'] ?? 0),
        ':public_id' => videochat_appointment_normalize_public_id($row['public_id'] ?? ''),
        ':slot_minutes' => videochat_appointment_normalize_slot_minutes($row['slot_minutes'] ?? 15),
        ':slot_mode' => videochat_appointment_normalize_slot_mode($row['slot_mode'] ?? 'selected_dates'),
        ':invitation_text' => videochat_appointment_clean_multiline_text($row['invitation_text'] ?? '', 1200),
        ':mail_from_email' => videochat_appointment_normalize_email($row['mail_from_email'] ?? ''),
        ':mail_from_name' => videochat_appointment_clean_text($row['mail_from_name'] ?? '', 120),
        ':mail_smtp_host' => videochat_appointment_clean_text($row['mail_smtp_host'] ?? '', 255),
        ':mail_smtp_port' => videochat_appointment_normalize_smtp_port($row['mail_smtp_port'] ?? 587),
        ':mail_smtp_encryption' => videochat_appointment_normalize_smtp_encryption($row['mail_smtp_encryption'] ?? 'starttls'),
        ':mail_smtp_username' => videochat_appointment_clean_text($row['mail_smtp_username'] ?? '', 190),
        ':mail_smtp_password' => (string) ($row['mail_smtp_password'] ?? ''),
        ':mail_subject_template' => videochat_appointment_clean_text(
            $row['mail_subject_template'] ?? videochat_default_appointment_email_subject_template(),
            300
        ) ?: videochat_default_appointment_email_subject_template(),
        ':mail_body_template' => videochat_appointment_clean_multiline_text(
            $row['mail_body_template'] ?? videochat_default_appointment_email_body_template(),
            4000
        ) ?: videochat_default_appointment_email_body_template(),
        ':created_at' => $now,
        ':updated_at' => $now,
    ];
    if ($hasTenantColumn) {
        $tenantId = is_numeric($row['tenant_id'] ?? null) ? (int) $row['tenant_id'] : null;
        $params[':tenant_id'] = $tenantId;
    }
    $query->execute($params);
}

function videochat_save_appointment_settings(PDO $pdo, int $ownerUserId, string $publicId, array $settings, ?int $tenantId = null): array
{
    $existingRow = videochat_get_appointment_settings_row_by_owner($pdo, $ownerUserId, $tenantId);
    $effectivePublicId = videochat_appointment_normalize_public_id($publicId);
    if ($effectivePublicId === '' && is_array($existingRow)) {
        $effectivePublicId = videochat_appointment_normalize_public_id($existingRow['public_id'] ?? '');
    }
    if ($effectivePublicId === '') {
        $effectivePublicId = strtolower(videochat_generate_call_access_uuid());
    }

    $next = is_array($existingRow)
        ? [...videochat_appointment_default_settings_row($ownerUserId, $effectivePublicId, $tenantId), ...$existingRow]
        : videochat_appointment_default_settings_row($ownerUserId, $effectivePublicId, $tenantId);
    $next['owner_user_id'] = $ownerUserId;
    $next['tenant_id'] = is_int($tenantId) && $tenantId > 0 ? $tenantId : ($next['tenant_id'] ?? null);
    $next['public_id'] = $effectivePublicId;

    foreach ($settings as $field => $value) {
        if (array_key_exists((string) $field, $next)) {
            $next[(string) $field] = $value;
        }
    }

    $now = gmdate('c');
    videochat_insert_or_update_appointment_settings_row($pdo, $next, $now);
    $saved = videochat_get_appointment_settings_row_by_owner($pdo, $ownerUserId, $tenantId) ?? $next;
    return videochat_appointment_owner_settings_payload($saved);
}
