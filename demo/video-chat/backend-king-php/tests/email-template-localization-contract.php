<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/calls/appointment_calendar_mail.php';
require_once __DIR__ . '/../domain/workspace/workspace_administration.php';

function videochat_email_template_localization_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[email-template-localization-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_email_template_localization_insert(PDO $pdo, ?int $tenantId, string $locale, string $key, string $value): void
{
    $parts = explode('.', $key, 2);
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO translation_resources(tenant_id, locale, namespace, resource_key, value, source)
VALUES(:tenant_id, :locale, :namespace, :resource_key, :value, 'email-template-contract')
SQL
    );
    $insert->execute([
        ':tenant_id' => $tenantId,
        ':locale' => $locale,
        ':namespace' => (string) ($parts[0] ?? ''),
        ':resource_key' => (string) ($parts[1] ?? ''),
        ':value' => $value,
    ]);
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-email-template-localization-' . bin2hex(random_bytes(6)) . '.sqlite';
    $outboxPath = sys_get_temp_dir() . '/videochat-email-template-localization-' . bin2hex(random_bytes(6)) . '.log';
    @unlink($databasePath);
    @unlink($outboxPath);
    putenv('VIDEOCHAT_EMAIL_OUTBOX_PATH=' . $outboxPath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    videochat_email_template_localization_assert($tenantId > 0, 'default tenant should exist');

    videochat_email_template_localization_insert($pdo, null, 'en', 'emails.appointment_booking.subject', 'EN scheduled: {call_title}');
    videochat_email_template_localization_insert($pdo, null, 'en', 'emails.appointment_booking.body', 'EN body {recipient_name} {starts_at} {join_link}');
    videochat_email_template_localization_insert($pdo, null, 'de', 'emails.appointment_booking.subject', 'DE Termin: {call_title}');
    videochat_email_template_localization_insert($pdo, null, 'de', 'emails.appointment_booking.body', 'DE body {recipient_name} {starts_at} {join_link}');
    videochat_email_template_localization_insert($pdo, null, 'ar', 'emails.appointment_booking.subject', 'Broken subject');
    videochat_email_template_localization_insert($pdo, null, 'ar', 'emails.appointment_booking.body', 'Broken body');
    videochat_email_template_localization_insert($pdo, null, 'en', 'emails.public_lead.subject', 'EN lead: {name}');
    videochat_email_template_localization_insert($pdo, null, 'en', 'emails.public_lead.body', 'EN lead {name} {email}');
    videochat_email_template_localization_insert($pdo, null, 'de', 'emails.public_lead.subject', 'DE Lead: {name}');
    videochat_email_template_localization_insert($pdo, null, 'de', 'emails.public_lead.body', 'DE Lead {name} {email}');

    $invalidFallback = videochat_resolve_localized_email_templates(
        $pdo,
        $tenantId,
        'ar',
        'emails.appointment_booking.subject',
        'emails.appointment_booking.body',
        'Configured {call_title}',
        'Configured {recipient_name} {starts_at} {join_link}',
        videochat_required_appointment_email_subject_placeholders(),
        videochat_required_appointment_email_body_placeholders()
    );
    videochat_email_template_localization_assert((string) $invalidFallback['subject_locale'] === 'en', 'invalid localized subject should fall back to English');
    videochat_email_template_localization_assert((string) $invalidFallback['body_locale'] === 'en', 'invalid localized body should fall back to English');

    $bookingResults = videochat_send_appointment_booking_notifications(
        [
            'mail_subject_template' => 'Configured {call_title}',
            'mail_body_template' => 'Configured {recipient_name} {starts_at} {join_link}',
        ],
        ['email' => 'owner@example.test', 'display_name' => 'Owner User', 'locale' => 'de'],
        ['first_name' => 'Guest', 'last_name' => 'Person', 'email' => 'guest@example.test', 'locale' => 'de', 'message' => ''],
        [
            'tenant_id' => $tenantId,
            'call_id' => 'call_locale_contract',
            'access_id' => 'access-locale-contract',
            'call_title' => 'Locale Call',
            'starts_at' => '2026-01-02T14:05:00Z',
            'ends_at' => '2026-01-02T15:05:00Z',
            'timezone' => 'UTC',
        ],
        $pdo
    );
    videochat_email_template_localization_assert((string) ($bookingResults['guest']['subject_locale'] ?? '') === 'de', 'guest selected locale should use German template');
    videochat_email_template_localization_assert((string) ($bookingResults['owner']['subject_locale'] ?? '') === 'de', 'owner locale should use German template');

    videochat_workspace_save_admin_settings($pdo, ['lead_recipients' => ['lead@example.test']], null, $tenantId);
    $leadResults = videochat_workspace_send_public_lead_notifications($pdo, [
        'name' => 'Lead User',
        'email' => 'lead-user@example.test',
        'company' => 'Lead Co',
        'participants' => 4,
        'role' => '',
        'use_case' => '',
        'timing' => '',
        'notes' => '',
        'locale' => 'de',
        'user_agent' => '',
        'remote_addr' => '',
    ], $tenantId);
    videochat_email_template_localization_assert((string) ($leadResults['lead@example.test']['subject_locale'] ?? '') === 'de', 'lead locale should use German template');

    $outbox = is_file($outboxPath) ? (string) file_get_contents($outboxPath) : '';
    videochat_email_template_localization_assert(substr_count($outbox, 'SUBJECT=DE Termin: Locale Call') >= 2, 'guest and owner localized booking subjects missing from outbox');
    videochat_email_template_localization_assert(str_contains($outbox, 'SUBJECT=DE Lead: Lead User'), 'lead localized subject missing from outbox');

    @unlink($databasePath);
    @unlink($outboxPath);
    fwrite(STDOUT, "[email-template-localization-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[email-template-localization-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
