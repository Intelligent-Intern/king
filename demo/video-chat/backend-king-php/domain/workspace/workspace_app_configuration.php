<?php

declare(strict_types=1);

require_once __DIR__ . '/workspace_administration.php';
require_once __DIR__ . '/../calls/call_access_contract.php';

function videochat_workspace_app_configuration_text_id(): string
{
    return strtolower(videochat_generate_call_access_uuid());
}

function videochat_workspace_background_filename_from_path(string $path): string
{
    $decoded = rawurldecode(basename(parse_url($path, PHP_URL_PATH) ?: ''));
    if (preg_match('/^background-[a-f0-9]{16}\.(png|jpg|webp)$/', $decoded) !== 1) {
        return '';
    }
    return $decoded;
}

function videochat_workspace_background_file_response(
    string $filename,
    string $storageRoot,
    callable $errorResponse
): array {
    $decoded = rawurldecode($filename);
    if (preg_match('/^background-[a-f0-9]{16}\.(png|jpg|webp)$/', $decoded) !== 1) {
        return $errorResponse(404, 'background_image_not_found', 'Background image could not be found.', [
            'reason' => 'invalid_filename',
        ]);
    }

    $dir = rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'backgrounds';
    $path = $dir . DIRECTORY_SEPARATOR . $decoded;
    $realDir = realpath($dir);
    $realPath = realpath($path);
    if (!is_string($realDir) || !is_string($realPath) || !is_file($realPath) || !str_starts_with($realPath, $realDir . DIRECTORY_SEPARATOR)) {
        return $errorResponse(404, 'background_image_not_found', 'Background image could not be found.', [
            'reason' => 'not_found',
        ]);
    }

    $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
    $contentType = match ($extension) {
        'jpg' => 'image/jpeg',
        'webp' => 'image/webp',
        default => 'image/png',
    };

    return [
        'status' => 200,
        'headers' => [
            'content-type' => $contentType,
            'cache-control' => 'public, max-age=31536000, immutable',
            'x-content-type-options' => 'nosniff',
        ],
        'body' => (string) @file_get_contents($realPath),
    ];
}

function videochat_workspace_app_filters(array $queryParams, int $defaultPageSize = 10): array
{
    $query = videochat_appointment_clean_text($queryParams['query'] ?? ($queryParams['q'] ?? ''), 120);
    $page = filter_var($queryParams['page'] ?? '1', FILTER_VALIDATE_INT);
    $pageSize = filter_var($queryParams['page_size'] ?? (string) $defaultPageSize, FILTER_VALIDATE_INT);
    $errors = [];
    if (!is_int($page) || $page < 1) {
        $errors['page'] = 'must_be_integer_greater_than_zero';
        $page = 1;
    }
    if (!is_int($pageSize) || $pageSize < 1 || $pageSize > 100) {
        $errors['page_size'] = 'must_be_integer_between_1_and_100';
        $pageSize = $defaultPageSize;
    }
    return [
        'ok' => $errors === [],
        'query' => $query,
        'page' => $page,
        'page_size' => $pageSize,
        'errors' => $errors,
    ];
}

function videochat_workspace_normalize_template_key(mixed $value, string $fallbackLabel = ''): string
{
    $key = strtolower(trim((string) $value));
    if ($key === '' && $fallbackLabel !== '') {
        $key = strtolower(trim($fallbackLabel));
    }
    $key = preg_replace('/[^a-z0-9._-]+/', '-', $key) ?? '';
    $key = trim($key, '.-_');
    return strlen($key) > 80 ? substr($key, 0, 80) : $key;
}

function videochat_workspace_email_text_row(array $row): array
{
    return [
        'id' => (string) ($row['id'] ?? ''),
        'template_key' => (string) ($row['template_key'] ?? ''),
        'label' => (string) ($row['label'] ?? ''),
        'subject_template' => (string) ($row['subject_template'] ?? ''),
        'body_template' => (string) ($row['body_template'] ?? ''),
        'is_system' => ((int) ($row['is_system'] ?? 0)) === 1,
        'status' => (string) ($row['status'] ?? 'active'),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
}

function videochat_workspace_seed_email_texts(PDO $pdo, int $tenantId): void
{
    $settings = videochat_workspace_settings_payload(videochat_workspace_get_admin_settings_row($pdo, $tenantId), false);
    $defaults = [
        [
            'template_key' => 'website-lead-notification',
            'label' => 'Website lead notification',
            'subject_template' => (string) ($settings['lead_subject_template'] ?? videochat_workspace_default_lead_subject_template()),
            'body_template' => (string) ($settings['lead_body_template'] ?? videochat_workspace_default_lead_body_template()),
        ],
        [
            'template_key' => 'calendar-booking-confirmation',
            'label' => 'Calendar booking confirmation',
            'subject_template' => 'Video call scheduled: {call_title}',
            'body_template' => "Hello {recipient_name},\n\n"
                . "your video call is scheduled for {starts_at}.\n\n"
                . "Call link:\n{join_link}\n\n"
                . "Google Calendar:\n{google_calendar_url}\n\n"
                . "Participant: {guest_name} ({guest_email})\n"
                . "Owner: {owner_name} ({owner_email})\n",
        ],
    ];
    $now = gmdate('c');
    $query = $pdo->prepare(
        <<<'SQL'
INSERT INTO workspace_email_texts(id, tenant_id, template_key, label, subject_template, body_template, is_system, status, created_at, updated_at)
VALUES(:id, :tenant_id, :template_key, :label, :subject_template, :body_template, 1, 'active', :created_at, :updated_at)
ON CONFLICT(tenant_id, template_key) DO NOTHING
SQL
    );
    foreach ($defaults as $row) {
        $query->execute([
            ':id' => videochat_workspace_app_configuration_text_id(),
            ':tenant_id' => $tenantId,
            ':template_key' => $row['template_key'],
            ':label' => $row['label'],
            ':subject_template' => $row['subject_template'],
            ':body_template' => $row['body_template'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
}

function videochat_workspace_list_email_texts(PDO $pdo, int $tenantId, string $query, int $page, int $pageSize): array
{
    videochat_workspace_seed_email_texts($pdo, $tenantId);
    $where = 'tenant_id = :tenant_id';
    $params = [':tenant_id' => $tenantId];
    if ($query !== '') {
        $where .= ' AND (lower(label) LIKE :query OR lower(template_key) LIKE :query OR lower(subject_template) LIKE :query)';
        $params[':query'] = '%' . strtolower($query) . '%';
    }

    $count = $pdo->prepare('SELECT COUNT(*) FROM workspace_email_texts WHERE ' . $where);
    foreach ($params as $key => $value) {
        $count->bindValue($key, $value, $key === ':tenant_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $count->execute();
    $total = (int) $count->fetchColumn();
    $pageCount = max(1, (int) ceil($total / max(1, $pageSize)));
    $safePage = min(max(1, $page), $pageCount);
    $offset = ($safePage - 1) * $pageSize;

    $select = $pdo->prepare(
        'SELECT * FROM workspace_email_texts WHERE ' . $where . ' ORDER BY is_system DESC, lower(label) ASC LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $key => $value) {
        $select->bindValue($key, $value, $key === ':tenant_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $select->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $select->bindValue(':offset', $offset, PDO::PARAM_INT);
    $select->execute();

    return [
        'rows' => array_map('videochat_workspace_email_text_row', $select->fetchAll() ?: []),
        'page' => $safePage,
        'page_size' => $pageSize,
        'total' => $total,
        'page_count' => $pageCount,
    ];
}

function videochat_workspace_validate_email_text_payload(array $payload, bool $isCreate): array
{
    $label = videochat_appointment_clean_text($payload['label'] ?? '', 120);
    $templateKey = videochat_workspace_normalize_template_key($payload['template_key'] ?? '', $label);
    $subject = videochat_appointment_clean_text($payload['subject_template'] ?? '', 300);
    $body = videochat_appointment_clean_multiline_text($payload['body_template'] ?? '', 6000);
    $status = strtolower(trim((string) ($payload['status'] ?? 'active')));
    $errors = [];
    if ($label === '') {
        $errors['label'] = 'required_non_empty_label';
    }
    if ($templateKey === '') {
        $errors['template_key'] = 'required_non_empty_key';
    }
    if ($subject === '') {
        $errors['subject_template'] = 'required_non_empty_subject';
    }
    if ($body === '') {
        $errors['body_template'] = 'required_non_empty_body';
    }
    if (!in_array($status, ['active', 'disabled'], true)) {
        $errors['status'] = 'must_be_active_or_disabled';
        $status = 'active';
    }
    if (!$isCreate && array_key_exists('template_key', $payload) && $templateKey === '') {
        $errors['template_key'] = 'required_non_empty_key';
    }
    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'data' => [
            'label' => $label,
            'template_key' => $templateKey,
            'subject_template' => $subject,
            'body_template' => $body,
            'status' => $status,
        ],
    ];
}

function videochat_workspace_sync_email_text_usage(PDO $pdo, int $tenantId, array $row): void
{
    if ((string) ($row['template_key'] ?? '') !== 'website-lead-notification') {
        return;
    }
    $query = $pdo->prepare(
        <<<'SQL'
UPDATE workspace_administration_settings
SET lead_subject_template = :subject_template,
    lead_body_template = :body_template,
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
SQL
    );
    $query->execute([
        ':subject_template' => (string) ($row['subject_template'] ?? ''),
        ':body_template' => (string) ($row['body_template'] ?? ''),
        ':updated_at' => gmdate('c'),
        ':tenant_id' => $tenantId,
    ]);
}

function videochat_workspace_save_email_text(PDO $pdo, int $tenantId, array $payload, ?string $id = null): array
{
    videochat_workspace_seed_email_texts($pdo, $tenantId);
    $existing = null;
    $normalizedId = strtolower(trim((string) ($id ?? '')));
    if ($normalizedId !== '') {
        $lookup = $pdo->prepare('SELECT * FROM workspace_email_texts WHERE tenant_id = :tenant_id AND id = :id LIMIT 1');
        $lookup->execute([':tenant_id' => $tenantId, ':id' => $normalizedId]);
        $candidate = $lookup->fetch();
        if (!is_array($candidate)) {
            return ['ok' => false, 'reason' => 'not_found', 'row' => null, 'errors' => []];
        }
        $existing = $candidate;
    }
    $validation = videochat_workspace_validate_email_text_payload($payload, $existing === null);
    if (!(bool) ($validation['ok'] ?? false)) {
        return ['ok' => false, 'reason' => 'validation_failed', 'row' => null, 'errors' => $validation['errors'] ?? []];
    }
    $data = (array) ($validation['data'] ?? []);
    $rowId = $existing === null ? videochat_workspace_app_configuration_text_id() : (string) $existing['id'];
    $now = gmdate('c');
    $query = $pdo->prepare(
        <<<'SQL'
INSERT INTO workspace_email_texts(id, tenant_id, template_key, label, subject_template, body_template, is_system, status, created_at, updated_at)
VALUES(:id, :tenant_id, :template_key, :label, :subject_template, :body_template, :is_system, :status, :created_at, :updated_at)
ON CONFLICT(tenant_id, template_key) DO UPDATE SET
  label = excluded.label,
  subject_template = excluded.subject_template,
  body_template = excluded.body_template,
  status = excluded.status,
  updated_at = excluded.updated_at
SQL
    );
    try {
        $query->execute([
            ':id' => $rowId,
            ':tenant_id' => $tenantId,
            ':template_key' => (string) $data['template_key'],
            ':label' => (string) $data['label'],
            ':subject_template' => (string) $data['subject_template'],
            ':body_template' => (string) $data['body_template'],
            ':is_system' => (int) ($existing['is_system'] ?? 0),
            ':status' => (string) $data['status'],
            ':created_at' => (string) ($existing['created_at'] ?? $now),
            ':updated_at' => $now,
        ]);
    } catch (Throwable) {
        return ['ok' => false, 'reason' => 'duplicate_key', 'row' => null, 'errors' => ['template_key' => 'must_be_unique']];
    }
    $select = $pdo->prepare('SELECT * FROM workspace_email_texts WHERE tenant_id = :tenant_id AND template_key = :template_key LIMIT 1');
    $select->execute([':tenant_id' => $tenantId, ':template_key' => (string) $data['template_key']]);
    $row = $select->fetch();
    $normalized = is_array($row) ? videochat_workspace_email_text_row($row) : [];
    if ($normalized !== []) {
        videochat_workspace_sync_email_text_usage($pdo, $tenantId, $normalized);
    }
    return ['ok' => true, 'reason' => 'saved', 'row' => $normalized, 'errors' => []];
}

function videochat_workspace_delete_email_text(PDO $pdo, int $tenantId, string $id): array
{
    $lookup = $pdo->prepare('SELECT * FROM workspace_email_texts WHERE tenant_id = :tenant_id AND id = :id LIMIT 1');
    $lookup->execute([':tenant_id' => $tenantId, ':id' => strtolower(trim($id))]);
    $row = $lookup->fetch();
    if (!is_array($row)) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    if (((int) ($row['is_system'] ?? 0)) === 1) {
        return ['ok' => false, 'reason' => 'system_row_locked'];
    }
    $delete = $pdo->prepare('DELETE FROM workspace_email_texts WHERE tenant_id = :tenant_id AND id = :id');
    $delete->execute([':tenant_id' => $tenantId, ':id' => (string) $row['id']]);
    return ['ok' => true, 'reason' => 'deleted'];
}

function videochat_workspace_background_image_row(array $row): array
{
    return [
        'id' => (string) ($row['id'] ?? ''),
        'label' => (string) ($row['label'] ?? ''),
        'file_path' => (string) ($row['file_path'] ?? ''),
        'mime_type' => (string) ($row['mime_type'] ?? ''),
        'file_size' => (int) ($row['file_size'] ?? 0),
        'status' => (string) ($row['status'] ?? 'active'),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
}

function videochat_workspace_list_background_images(PDO $pdo, int $tenantId, string $query, int $page, int $pageSize): array
{
    $where = 'tenant_id = :tenant_id';
    $params = [':tenant_id' => $tenantId];
    if ($query !== '') {
        $where .= ' AND (lower(label) LIKE :query OR lower(file_path) LIKE :query)';
        $params[':query'] = '%' . strtolower($query) . '%';
    }
    $count = $pdo->prepare('SELECT COUNT(*) FROM workspace_background_images WHERE ' . $where);
    foreach ($params as $key => $value) {
        $count->bindValue($key, $value, $key === ':tenant_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $count->execute();
    $total = (int) $count->fetchColumn();
    $pageCount = max(1, (int) ceil($total / max(1, $pageSize)));
    $safePage = min(max(1, $page), $pageCount);
    $offset = ($safePage - 1) * $pageSize;
    $select = $pdo->prepare(
        'SELECT * FROM workspace_background_images WHERE ' . $where . ' ORDER BY updated_at DESC, label COLLATE NOCASE ASC LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $key => $value) {
        $select->bindValue($key, $value, $key === ':tenant_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $select->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $select->bindValue(':offset', $offset, PDO::PARAM_INT);
    $select->execute();
    return [
        'rows' => array_map('videochat_workspace_background_image_row', $select->fetchAll() ?: []),
        'page' => $safePage,
        'page_size' => $pageSize,
        'total' => $total,
        'page_count' => $pageCount,
    ];
}

function videochat_workspace_store_background_upload(array $file, string $storageRoot, int $maxBytes): array
{
    $parsed = videochat_avatar_parse_upload_payload(['data_url' => (string) ($file['data_url'] ?? '')], $maxBytes);
    if (!(bool) ($parsed['ok'] ?? false) || !is_array($parsed['data'] ?? null)) {
        return ['ok' => false, 'reason' => 'invalid_image_upload', 'errors' => $parsed['errors'] ?? []];
    }
    $data = (array) $parsed['data'];
    $dir = rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'backgrounds';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'reason' => 'storage_unavailable', 'errors' => []];
    }
    $binary = (string) ($data['binary'] ?? '');
    $extension = (string) ($data['extension'] ?? 'png');
    $filename = 'background-' . substr(hash('sha256', $binary), 0, 16) . '.' . $extension;
    $path = $dir . DIRECTORY_SEPARATOR . $filename;
    if (@file_put_contents($path, $binary, LOCK_EX) === false) {
        return ['ok' => false, 'reason' => 'write_failed', 'errors' => []];
    }
    $label = videochat_appointment_clean_text($file['label'] ?? '', 120);
    if ($label === '') {
        $label = videochat_appointment_clean_text(pathinfo((string) ($file['file_name'] ?? $filename), PATHINFO_FILENAME), 120);
    }
    return [
        'ok' => true,
        'reason' => 'stored',
        'label' => $label === '' ? 'Background image' : $label,
        'file_path' => '/api/workspace/background-images/' . rawurlencode($filename),
        'mime_type' => (string) ($data['mime'] ?? ''),
        'file_size' => (int) ($data['bytes'] ?? 0),
    ];
}

function videochat_workspace_create_background_images(PDO $pdo, int $tenantId, array $payload, string $storageRoot, int $maxBytes): array
{
    $files = is_array($payload['files'] ?? null) ? array_values($payload['files']) : [$payload];
    $files = array_slice(array_filter($files, 'is_array'), 0, 50);
    if ($files === []) {
        return ['ok' => false, 'reason' => 'validation_failed', 'rows' => [], 'errors' => ['files' => 'required_non_empty_files']];
    }
    $rows = [];
    $errors = [];
    $now = gmdate('c');
    $query = $pdo->prepare(
        <<<'SQL'
INSERT INTO workspace_background_images(id, tenant_id, label, file_path, mime_type, file_size, status, created_at, updated_at)
VALUES(:id, :tenant_id, :label, :file_path, :mime_type, :file_size, 'active', :created_at, :updated_at)
ON CONFLICT(tenant_id, file_path) DO UPDATE SET
  label = excluded.label,
  mime_type = excluded.mime_type,
  file_size = excluded.file_size,
  status = 'active',
  updated_at = excluded.updated_at
SQL
    );
    foreach ($files as $index => $file) {
        $stored = videochat_workspace_store_background_upload($file, $storageRoot, $maxBytes);
        if (!(bool) ($stored['ok'] ?? false)) {
            $errors['files.' . $index] = (string) ($stored['reason'] ?? 'invalid_upload');
            continue;
        }
        $query->execute([
            ':id' => videochat_workspace_app_configuration_text_id(),
            ':tenant_id' => $tenantId,
            ':label' => (string) ($stored['label'] ?? 'Background image'),
            ':file_path' => (string) ($stored['file_path'] ?? ''),
            ':mime_type' => (string) ($stored['mime_type'] ?? ''),
            ':file_size' => (int) ($stored['file_size'] ?? 0),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $select = $pdo->prepare('SELECT * FROM workspace_background_images WHERE tenant_id = :tenant_id AND file_path = :file_path LIMIT 1');
        $select->execute([':tenant_id' => $tenantId, ':file_path' => (string) ($stored['file_path'] ?? '')]);
        $row = $select->fetch();
        if (is_array($row)) {
            $rows[] = videochat_workspace_background_image_row($row);
        }
    }
    return [
        'ok' => $rows !== [],
        'reason' => $rows !== [] ? 'stored' : 'validation_failed',
        'rows' => $rows,
        'errors' => $errors,
    ];
}

function videochat_workspace_delete_background_image(PDO $pdo, int $tenantId, string $id, string $storageRoot): array
{
    $lookup = $pdo->prepare('SELECT * FROM workspace_background_images WHERE tenant_id = :tenant_id AND id = :id LIMIT 1');
    $lookup->execute([':tenant_id' => $tenantId, ':id' => strtolower(trim($id))]);
    $row = $lookup->fetch();
    if (!is_array($row)) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    $delete = $pdo->prepare('DELETE FROM workspace_background_images WHERE tenant_id = :tenant_id AND id = :id');
    $delete->execute([':tenant_id' => $tenantId, ':id' => (string) $row['id']]);
    $filename = videochat_workspace_background_filename_from_path((string) ($row['file_path'] ?? ''));
    if ($filename !== '') {
        $count = $pdo->prepare('SELECT COUNT(*) FROM workspace_background_images WHERE file_path = :file_path');
        $count->execute([':file_path' => (string) ($row['file_path'] ?? '')]);
        if ((int) $count->fetchColumn() === 0) {
            $path = rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'backgrounds' . DIRECTORY_SEPARATOR . $filename;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
    return ['ok' => true, 'reason' => 'deleted'];
}
