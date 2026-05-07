<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/workspace/workspace_administration.php';

function videochat_mail_template_placeholder_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[mail-template-placeholder-contract] FAIL: {$message}\n");
    exit(1);
}

$defaultSubjectMissing = videochat_appointment_missing_template_placeholders(
    videochat_default_appointment_email_subject_template(),
    videochat_required_appointment_email_subject_placeholders()
);
$defaultBodyMissing = videochat_appointment_missing_template_placeholders(
    videochat_default_appointment_email_body_template(),
    videochat_required_appointment_email_body_placeholders()
);
videochat_mail_template_placeholder_assert($defaultSubjectMissing === [], 'default appointment subject must keep required placeholders');
videochat_mail_template_placeholder_assert($defaultBodyMissing === [], 'default appointment body must keep required placeholders');

$invalidAppointment = videochat_validate_appointment_settings_payload([
    'mail_subject_template' => 'Scheduled call',
    'mail_body_template' => "Hello {recipient_name}\n{starts_at}",
]);
videochat_mail_template_placeholder_assert($invalidAppointment['ok'] === false, 'appointment templates missing required placeholders must fail');
videochat_mail_template_placeholder_assert(
    ($invalidAppointment['errors']['mail_subject_template'] ?? '') === 'missing_required_placeholders:call_title',
    'appointment subject should report the missing call_title placeholder'
);
videochat_mail_template_placeholder_assert(
    ($invalidAppointment['errors']['mail_body_template'] ?? '') === 'missing_required_placeholders:join_link',
    'appointment body should report the missing join_link placeholder'
);

$validAppointment = videochat_validate_appointment_settings_payload([
    'mail_subject_template' => 'Scheduled call: {call_title}',
    'mail_body_template' => "Hello {recipient_name}\n{starts_at}\n{join_link}",
]);
videochat_mail_template_placeholder_assert($validAppointment['ok'] === true, 'appointment templates with required placeholders should pass');

$resetAppointment = videochat_validate_appointment_settings_payload([
    'mail_templates_reset' => true,
    'mail_subject_template' => 'Broken',
    'mail_body_template' => 'Broken',
]);
videochat_mail_template_placeholder_assert($resetAppointment['ok'] === true, 'appointment template reset should restore safe defaults');
videochat_mail_template_placeholder_assert(
    ($resetAppointment['settings']['mail_body_template'] ?? '') === videochat_default_appointment_email_body_template(),
    'appointment reset should return the default body template'
);

$defaultLeadSubjectMissing = videochat_appointment_missing_template_placeholders(
    videochat_workspace_default_lead_subject_template(),
    videochat_workspace_required_lead_subject_placeholders()
);
$defaultLeadBodyMissing = videochat_appointment_missing_template_placeholders(
    videochat_workspace_default_lead_body_template(),
    videochat_workspace_required_lead_body_placeholders()
);
videochat_mail_template_placeholder_assert($defaultLeadSubjectMissing === [], 'default lead subject must keep required placeholders');
videochat_mail_template_placeholder_assert($defaultLeadBodyMissing === [], 'default lead body must keep required placeholders');

$invalidLead = videochat_workspace_validate_admin_payload([
    'lead_subject_template' => 'New lead',
    'lead_body_template' => 'Lead from {name}',
], sys_get_temp_dir(), 512000);
videochat_mail_template_placeholder_assert($invalidLead['ok'] === false, 'lead templates missing required placeholders must fail');
videochat_mail_template_placeholder_assert(
    ($invalidLead['errors']['lead_subject_template'] ?? '') === 'missing_required_placeholders:name',
    'lead subject should report the missing name placeholder'
);
videochat_mail_template_placeholder_assert(
    ($invalidLead['errors']['lead_body_template'] ?? '') === 'missing_required_placeholders:email',
    'lead body should report the missing email placeholder'
);

$validLead = videochat_workspace_validate_admin_payload([
    'lead_subject_template' => 'New lead: {name}',
    'lead_body_template' => "Name: {name}\nEmail: {email}",
], sys_get_temp_dir(), 512000);
videochat_mail_template_placeholder_assert($validLead['ok'] === true, 'lead templates with required placeholders should pass');

$resetLead = videochat_workspace_validate_admin_payload([
    'lead_templates_reset' => true,
    'lead_subject_template' => 'Broken',
    'lead_body_template' => 'Broken',
], sys_get_temp_dir(), 512000);
videochat_mail_template_placeholder_assert($resetLead['ok'] === true, 'lead template reset should restore safe defaults');
videochat_mail_template_placeholder_assert(
    ($resetLead['settings']['lead_body_template'] ?? '') === videochat_workspace_default_lead_body_template(),
    'lead reset should return the default body template'
);

fwrite(STDOUT, "[mail-template-placeholder-contract] PASS\n");
