<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/workspace/workspace_app_configuration.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDOUT, "[workspace-background-seed-contract] SKIP: PDO sqlite driver is not available\n");
    exit(0);
}

function videochat_workspace_background_seed_contract_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[workspace-background-seed-contract] FAIL: {$message}\n");
        exit(1);
    }
}

function videochat_workspace_background_seed_contract_remove_dir(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $entries = scandir($path);
    foreach ($entries === false ? [] : $entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child)) {
            videochat_workspace_background_seed_contract_remove_dir($child);
            continue;
        }
        @unlink($child);
    }
    @rmdir($path);
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

$storageRoot = sys_get_temp_dir() . '/king-bg-seed-contract-' . bin2hex(random_bytes(6));

try {
    $manifest = videochat_workspace_background_seed_manifest();
    videochat_workspace_background_seed_contract_assert(count($manifest) >= 7, 'seed manifest must preserve the current production background catalog');

    $firstSeed = videochat_workspace_seed_background_images($pdo, 1, $storageRoot);
    videochat_workspace_background_seed_contract_assert((int) ($firstSeed['seeded'] ?? 0) >= 7, 'seed must insert repo-owned backgrounds');
    videochat_workspace_background_seed_contract_assert((int) ($firstSeed['skipped'] ?? 0) === 0, 'seed manifest assets must all be present in the repo');

    $listing = videochat_workspace_list_background_images($pdo, 1, '', 1, 100);
    $rows = is_array($listing['rows'] ?? null) ? $listing['rows'] : [];
    videochat_workspace_background_seed_contract_assert(count($rows) >= 7, 'background list must include seeded rows');

    $labels = array_map(static fn (array $row): string => (string) ($row['label'] ?? ''), $rows);
    foreach (['Dino background', 'Castle background', 'Rushmore background'] as $label) {
        videochat_workspace_background_seed_contract_assert(in_array($label, $labels, true), "missing seeded label {$label}");
    }

    foreach ($rows as $row) {
        $filePath = (string) ($row['file_path'] ?? '');
        $filename = basename($filePath);
        videochat_workspace_background_seed_contract_assert(
            is_file($storageRoot . DIRECTORY_SEPARATOR . 'backgrounds' . DIRECTORY_SEPARATOR . $filename),
            "seeded file missing from storage {$filename}"
        );
    }

    $secondSeed = videochat_workspace_seed_background_images($pdo, 1, $storageRoot);
    videochat_workspace_background_seed_contract_assert((int) ($secondSeed['seeded'] ?? 0) >= 7, 'seed must remain idempotent on repeat');
    $countAfterRepeat = (int) $pdo->query('SELECT COUNT(*) FROM workspace_background_images WHERE tenant_id = 1')->fetchColumn();
    videochat_workspace_background_seed_contract_assert($countAfterRepeat === count($rows), 'repeat seed must not duplicate rows');

    $castleFile = $storageRoot . DIRECTORY_SEPARATOR . 'backgrounds' . DIRECTORY_SEPARATOR . 'background-4b6c85963ef58386.png';
    videochat_workspace_background_seed_contract_assert(is_file($castleFile), 'castle seed fixture should exist before response recovery check');
    @unlink($castleFile);
    videochat_workspace_background_seed_contract_assert(!is_file($castleFile), 'castle seed fixture should be removed for response recovery check');

    $response = videochat_workspace_background_file_response(
        'background-4b6c85963ef58386.png',
        $storageRoot,
        static fn (int $status, string $code, string $message, array $details = []): array => [
            'status' => $status,
            'code' => $code,
            'message' => $message,
            'details' => $details,
        ]
    );
    videochat_workspace_background_seed_contract_assert((int) ($response['status'] ?? 0) === 200, 'file response must recover missing seeded assets from the repo');
    videochat_workspace_background_seed_contract_assert(is_file($castleFile), 'file response must restore missing seeded asset');
} finally {
    videochat_workspace_background_seed_contract_remove_dir($storageRoot);
}

fwrite(STDOUT, "[workspace-background-seed-contract] PASS\n");
