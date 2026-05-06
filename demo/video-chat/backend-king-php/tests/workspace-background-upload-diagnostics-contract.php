<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/users/avatar_upload.php';
require_once __DIR__ . '/../domain/workspace/workspace_app_configuration.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDOUT, "[workspace-background-upload-diagnostics-contract] SKIP: PDO sqlite driver is not available\n");
    exit(0);
}

function videochat_workspace_background_upload_diagnostics_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[workspace-background-upload-diagnostics-contract] FAIL: {$message}\n");
        exit(1);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    <<<'SQL'
CREATE TABLE workspace_background_images (
    id TEXT PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    label TEXT NOT NULL,
    file_path TEXT NOT NULL,
    original_file_path TEXT NOT NULL DEFAULT '',
    mime_type TEXT NOT NULL,
    file_size INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'disabled')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(tenant_id, file_path)
)
SQL
);

$storageRoot = sys_get_temp_dir() . '/king-bg-upload-contract-' . bin2hex(random_bytes(6));
$objectStoreCalls = [];
$GLOBALS['videochat_workspace_background_object_store_put'] = static function (
    string $objectKey,
    string $binary,
    string $contentType
) use (&$objectStoreCalls): bool {
    $objectStoreCalls[] = [
        'object_key' => $objectKey,
        'bytes' => strlen($binary),
        'content_type' => $contentType,
    ];
    return true;
};

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true);
videochat_workspace_background_upload_diagnostics_assert(is_string($png), 'fixture PNG should decode');

$result = videochat_workspace_create_background_images($pdo, 1, [
    'client_trace_id' => 'bgup_contract_trace',
    'client_batch_index' => 1,
    'client_batch_count' => 1,
    'files' => [[
        'file_name' => 'contract.png',
        'label' => 'Contract',
        'data_url' => 'data:image/png;base64,' . base64_encode($png),
        'original_data_url' => 'data:image/png;base64,' . base64_encode($png),
    ]],
], $storageRoot, 1024 * 1024);

videochat_workspace_background_upload_diagnostics_assert((bool) ($result['ok'] ?? false), 'upload should succeed');
videochat_workspace_background_upload_diagnostics_assert((string) ($result['trace_id'] ?? '') === 'bgup_contract_trace', 'trace id should round-trip');
videochat_workspace_background_upload_diagnostics_assert(count($objectStoreCalls) === 2, 'object store put should be attempted for cropped and original images');

$diagnostics = is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : [];
$stages = array_map(static fn (array $entry): string => (string) ($entry['stage'] ?? ''), $diagnostics);
foreach ([
    'create_started',
    'image_parse_started',
    'image_parse_ok',
    'object_store_put_started',
    'object_store_put_ok',
    'local_file_write_ok',
    'db_insert_started',
    'db_insert_ok',
    'create_finished',
] as $stage) {
    videochat_workspace_background_upload_diagnostics_assert(in_array($stage, $stages, true), "missing diagnostic stage {$stage}");
}

$rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
videochat_workspace_background_upload_diagnostics_assert(count($rows) === 1, 'one background row should be returned');
videochat_workspace_background_upload_diagnostics_assert(is_file($storageRoot . '/backgrounds/' . basename((string) $rows[0]['file_path'])), 'public cache file should be written');
videochat_workspace_background_upload_diagnostics_assert(is_file($storageRoot . '/backgrounds/' . basename((string) $rows[0]['original_file_path'])), 'original cache file should be written');
videochat_workspace_background_upload_diagnostics_assert(
    videochat_workspace_background_upload_max_body_bytes(5 * 1024 * 1024) > 7 * 1024 * 1024,
    'request body limit should accept one max-size base64 image'
);

unset($GLOBALS['videochat_workspace_background_object_store_put']);

fwrite(STDOUT, "[workspace-background-upload-diagnostics-contract] PASS\n");
