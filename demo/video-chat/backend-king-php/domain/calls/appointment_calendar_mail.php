<?php

declare(strict_types=1);

require_once __DIR__ . '/appointment_calendar_settings.php';
require_once __DIR__ . '/../localization/email_templates.php';

function videochat_appointment_frontend_origin(): string
{
    $configured = trim((string) (getenv('VIDEOCHAT_FRONTEND_ORIGIN') ?: ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    return 'http://127.0.0.1:5176';
}

function videochat_appointment_absolute_frontend_url(string $path): string
{
    $trimmed = trim($path);
    if ($trimmed === '') {
        return videochat_appointment_frontend_origin();
    }
    if (preg_match('/^https?:\/\//i', $trimmed) === 1) {
        return $trimmed;
    }

    return videochat_appointment_frontend_origin() . '/' . ltrim($trimmed, '/');
}

function videochat_appointment_format_mail_time(string $value, string $timezone): string
{
    try {
        $date = new DateTimeImmutable($value);
        $zone = new DateTimeZone($timezone !== '' ? $timezone : 'UTC');
        return $date->setTimezone($zone)->format('Y-m-d H:i T');
    } catch (Throwable) {
        return $value;
    }
}

function videochat_appointment_google_calendar_url(array $context): string
{
    $start = gmdate('Ymd\THis\Z', strtotime((string) ($context['starts_at'] ?? '')) ?: time());
    $end = gmdate('Ymd\THis\Z', strtotime((string) ($context['ends_at'] ?? '')) ?: time());
    $query = http_build_query([
        'action' => 'TEMPLATE',
        'text' => (string) ($context['call_title'] ?? 'Video call'),
        'dates' => $start . '/' . $end,
        'details' => "Video call link:\n" . (string) ($context['join_link'] ?? ''),
        'location' => (string) ($context['join_link'] ?? ''),
    ]);

    return 'https://calendar.google.com/calendar/render?' . $query;
}

function videochat_appointment_render_mail_template(string $template, array $variables): string
{
    $replacements = [];
    foreach ($variables as $key => $value) {
        $replacements['{' . $key . '}'] = (string) $value;
    }

    return strtr($template, $replacements);
}

function videochat_appointment_mail_headers(string $fromEmail, string $fromName): string
{
    $safeName = trim(str_replace(["\r", "\n"], '', $fromName));
    $from = $safeName !== '' ? sprintf('"%s" <%s>', addcslashes($safeName, '"\\'), $fromEmail) : $fromEmail;
    return "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . 'From: ' . $from . "\r\n";
}

function videochat_appointment_outbox_path(): string
{
    return trim((string) (getenv('VIDEOCHAT_EMAIL_OUTBOX_PATH') ?: (__DIR__ . '/../../.local/email-outbox.log')));
}

function videochat_appointment_write_outbox(string $toEmail, string $subject, string $body): array
{
    $outboxPath = videochat_appointment_outbox_path();
    $outboxDir = dirname($outboxPath);
    if (!is_dir($outboxDir)) {
        @mkdir($outboxDir, 0775, true);
    }

    $entry = '[' . gmdate('c') . "] TO={$toEmail}\nSUBJECT={$subject}\n{$body}\n---\n";
    @file_put_contents($outboxPath, $entry, FILE_APPEND | LOCK_EX);
    return ['sent' => false, 'channel' => 'outbox'];
}

function videochat_appointment_smtp_response($stream): array
{
    $line = '';
    while (!feof($stream)) {
        $part = fgets($stream, 4096);
        if (!is_string($part)) {
            break;
        }
        $line .= $part;
        if (preg_match('/^\d{3}\s/m', $part) === 1) {
            break;
        }
    }

    return [
        'code' => (int) substr($line, 0, 3),
        'line' => trim($line),
    ];
}

function videochat_appointment_smtp_command($stream, string $command, array $expectedCodes): array
{
    if ($command !== '') {
        fwrite($stream, $command . "\r\n");
    }
    $response = videochat_appointment_smtp_response($stream);
    if (!in_array((int) ($response['code'] ?? 0), $expectedCodes, true)) {
        throw new RuntimeException('smtp_unexpected_response');
    }

    return $response;
}

function videochat_appointment_smtp_send_text_mail(array $settings, string $toEmail, string $subject, string $body): bool
{
    $host = trim((string) ($settings['mail_smtp_host'] ?? ''));
    $port = videochat_appointment_normalize_smtp_port($settings['mail_smtp_port'] ?? 587);
    $encryption = videochat_appointment_normalize_smtp_encryption($settings['mail_smtp_encryption'] ?? 'starttls');
    $fromEmail = videochat_appointment_normalize_email($settings['mail_from_email'] ?? '');
    if ($host === '' || $fromEmail === '') {
        return false;
    }

    $target = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $stream = @stream_socket_client($target, $errno, $errstr, 10, STREAM_CLIENT_CONNECT);
    if (!is_resource($stream)) {
        return false;
    }

    try {
        stream_set_timeout($stream, 10);
        videochat_appointment_smtp_command($stream, '', [220]);
        videochat_appointment_smtp_command($stream, 'EHLO app.kingrt.com', [250]);
        if ($encryption === 'starttls') {
            videochat_appointment_smtp_command($stream, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('smtp_starttls_failed');
            }
            videochat_appointment_smtp_command($stream, 'EHLO app.kingrt.com', [250]);
        }

        $username = trim((string) ($settings['mail_smtp_username'] ?? ''));
        $password = (string) ($settings['mail_smtp_password'] ?? '');
        if ($username !== '') {
            videochat_appointment_smtp_command($stream, 'AUTH LOGIN', [334]);
            videochat_appointment_smtp_command($stream, base64_encode($username), [334]);
            videochat_appointment_smtp_command($stream, base64_encode($password), [235]);
        }

        videochat_appointment_smtp_command($stream, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        videochat_appointment_smtp_command($stream, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
        videochat_appointment_smtp_command($stream, 'DATA', [354]);

        $fromName = videochat_appointment_clean_text($settings['mail_from_name'] ?? '', 120);
        $headers = videochat_appointment_mail_headers($fromEmail, $fromName);
        $message = "Subject: {$subject}\r\nTo: <{$toEmail}>\r\n{$headers}\r\n" . str_replace("\n.", "\n..", $body);
        fwrite($stream, $message . "\r\n.\r\n");
        videochat_appointment_smtp_command($stream, '', [250]);
        videochat_appointment_smtp_command($stream, 'QUIT', [221]);
        fclose($stream);
        return true;
    } catch (Throwable) {
        fclose($stream);
        return false;
    }
}

function videochat_appointment_send_mail(array $settings, string $toEmail, string $toName, string $subject, string $body): array
{
    $recipient = videochat_appointment_normalize_email($toEmail);
    if ($recipient === '') {
        return ['sent' => false, 'channel' => 'none'];
    }

    $fromEmail = videochat_appointment_normalize_email($settings['mail_from_email'] ?? '');
    if (videochat_appointment_smtp_send_text_mail($settings, $recipient, $subject, $body)) {
        return ['sent' => true, 'channel' => 'smtp'];
    }

    if ($fromEmail !== '' && function_exists('mail')) {
        $headers = videochat_appointment_mail_headers($fromEmail, videochat_appointment_clean_text($settings['mail_from_name'] ?? '', 120));
        try {
            if (@mail($recipient, $subject, $body, $headers)) {
                return ['sent' => true, 'channel' => 'mail'];
            }
        } catch (Throwable) {
        }
    }

    return videochat_appointment_write_outbox($recipient, $subject, $body);
}

function videochat_send_appointment_booking_notifications(array $settings, array $owner, array $bookingData, array $context, ?PDO $pdo = null): array
{
    $guestName = trim((string) ($bookingData['first_name'] ?? '') . ' ' . (string) ($bookingData['last_name'] ?? ''));
    $ownerName = trim((string) ($owner['display_name'] ?? ''));
    $ownerEmail = videochat_appointment_normalize_email($owner['email'] ?? '');
    $guestEmail = videochat_appointment_normalize_email($bookingData['email'] ?? '');
    $timezone = (string) ($context['timezone'] ?? 'UTC');
    $callId = (string) ($context['call_id'] ?? '');
    $accessId = (string) ($context['access_id'] ?? '');
    $callTitle = (string) ($context['call_title'] ?? 'Video call');
    $ownerJoinLink = videochat_appointment_absolute_frontend_url('/workspace/call/' . rawurlencode($callId));
    $guestJoinLink = videochat_appointment_absolute_frontend_url('/join/' . rawurlencode($accessId));
    $subjectTemplate = (string) ($settings['mail_subject_template'] ?? videochat_default_appointment_email_subject_template());
    $bodyTemplate = (string) ($settings['mail_body_template'] ?? videochat_default_appointment_email_body_template());

    $common = [
        'owner_name' => $ownerName,
        'owner_email' => $ownerEmail,
        'guest_name' => $guestName,
        'guest_email' => $guestEmail,
        'call_title' => $callTitle,
        'starts_at' => videochat_appointment_format_mail_time((string) ($context['starts_at'] ?? ''), $timezone),
        'ends_at' => videochat_appointment_format_mail_time((string) ($context['ends_at'] ?? ''), $timezone),
        'message' => (string) ($bookingData['message'] ?? ''),
    ];

    $recipients = [
        'guest' => [
            'email' => $guestEmail,
            'name' => $guestName,
            'join_link' => $guestJoinLink,
            'locale' => (string) ($bookingData['locale'] ?? ($context['guest_locale'] ?? '')),
        ],
        'owner' => [
            'email' => $ownerEmail,
            'name' => $ownerName,
            'join_link' => $ownerJoinLink,
            'locale' => (string) ($owner['locale'] ?? ($context['owner_locale'] ?? '')),
        ],
    ];
    $results = [];
    foreach ($recipients as $role => $recipient) {
        $templates = $pdo instanceof PDO
            ? videochat_resolve_localized_email_templates(
                $pdo,
                is_numeric($context['tenant_id'] ?? null) ? (int) $context['tenant_id'] : null,
                (string) ($recipient['locale'] ?? ''),
                'emails.appointment_booking.subject',
                'emails.appointment_booking.body',
                $subjectTemplate,
                $bodyTemplate,
                videochat_required_appointment_email_subject_placeholders(),
                videochat_required_appointment_email_body_placeholders()
            )
            : ['subject_template' => $subjectTemplate, 'body_template' => $bodyTemplate];
        $variables = [
            ...$common,
            'recipient_role' => $role,
            'recipient_name' => (string) ($recipient['name'] ?: $recipient['email']),
            'join_link' => (string) $recipient['join_link'],
        ];
        $variables['google_calendar_url'] = videochat_appointment_google_calendar_url([
            ...$context,
            'call_title' => $callTitle,
            'join_link' => $variables['join_link'],
        ]);
        $subject = videochat_appointment_render_mail_template((string) ($templates['subject_template'] ?? $subjectTemplate), $variables);
        $body = videochat_appointment_render_mail_template((string) ($templates['body_template'] ?? $bodyTemplate), $variables);
        $results[$role] = [
            ...videochat_appointment_send_mail($settings, (string) $recipient['email'], (string) $recipient['name'], $subject, $body),
            'template_locale' => (string) ($templates['locale'] ?? ''),
            'subject_locale' => (string) ($templates['subject_locale'] ?? ''),
            'body_locale' => (string) ($templates['body_locale'] ?? ''),
        ];
    }

    return $results;
}
