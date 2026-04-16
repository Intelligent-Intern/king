<?php

declare(strict_types=1);

require_once __DIR__ . '/../../support/object_store.php';

/**
 * Allowed GGUF quantization tags; mirrors the list published by the contract
 * at demo/model-inference/contracts/v1/model-registry-entry.contract.json.
 *
 * @return array<int, string>
 */
function model_inference_registry_allowed_quantizations(): array
{
    return ['Q2_K', 'Q3_K', 'Q4_0', 'Q4_K', 'Q5_K', 'Q6_K', 'Q8_0', 'F16'];
}

/**
 * Apply the M-5 schema: one models table keyed by flat model_id with a
 * uniqueness index on (model_name, quantization) so the same logical model
 * cannot be registered twice under the same quantization.
 */
function model_inference_registry_schema_migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS models (
        model_id TEXT PRIMARY KEY,
        model_name TEXT NOT NULL,
        family TEXT NOT NULL,
        parameter_count INTEGER NOT NULL,
        quantization TEXT NOT NULL,
        context_length INTEGER NOT NULL,
        object_store_key TEXT NOT NULL UNIQUE,
        byte_length INTEGER NOT NULL,
        sha256_hex TEXT NOT NULL,
        uploaded_at TEXT NOT NULL,
        license TEXT NOT NULL,
        source_url TEXT,
        min_ram_bytes INTEGER NOT NULL,
        min_vram_bytes INTEGER NOT NULL DEFAULT 0,
        prefers_gpu INTEGER NOT NULL DEFAULT 0,
        registered_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_models_name_quantization ON models(model_name, quantization)');
}

/**
 * Parse and validate model metadata supplied by a client (headers or body).
 * Returns a normalized metadata array on success, or throws InvalidArgumentException
 * with a machine-readable message ("field:reason") so the dispatcher can
 * project it into a typed error envelope.
 *
 * @param array<string, mixed> $raw
 * @return array<string, mixed>
 */
function model_inference_registry_validate_metadata(array $raw): array
{
    $modelName = trim((string) ($raw['model_name'] ?? ''));
    if ($modelName === '' || strlen($modelName) > 128) {
        throw new InvalidArgumentException('model_name:must be non-empty and <=128 chars');
    }

    $family = trim((string) ($raw['family'] ?? ''));
    if ($family === '' || strlen($family) > 64) {
        throw new InvalidArgumentException('family:must be non-empty and <=64 chars');
    }

    $parameterCount = (int) ($raw['parameter_count'] ?? 0);
    if ($parameterCount < 1) {
        throw new InvalidArgumentException('parameter_count:must be >= 1');
    }

    $quantization = trim((string) ($raw['quantization'] ?? ''));
    if (!in_array($quantization, model_inference_registry_allowed_quantizations(), true)) {
        throw new InvalidArgumentException('quantization:must be one of ' . implode(',', model_inference_registry_allowed_quantizations()));
    }

    $contextLength = (int) ($raw['context_length'] ?? 0);
    if ($contextLength < 1) {
        throw new InvalidArgumentException('context_length:must be >= 1');
    }

    $license = trim((string) ($raw['license'] ?? ''));
    if ($license === '' || strlen($license) > 128) {
        throw new InvalidArgumentException('license:must be non-empty and <=128 chars');
    }

    $minRamBytes = (int) ($raw['min_ram_bytes'] ?? 0);
    if ($minRamBytes < 1) {
        throw new InvalidArgumentException('min_ram_bytes:must be >= 1');
    }

    $minVramBytes = (int) ($raw['min_vram_bytes'] ?? 0);
    if ($minVramBytes < 0) {
        throw new InvalidArgumentException('min_vram_bytes:must be >= 0');
    }

    $prefersGpu = (bool) ($raw['prefers_gpu'] ?? false);

    $sourceUrl = null;
    if (isset($raw['source_url']) && $raw['source_url'] !== '' && $raw['source_url'] !== null) {
        $sourceUrl = trim((string) $raw['source_url']);
        if (strlen($sourceUrl) > 512) {
            throw new InvalidArgumentException('source_url:must be <=512 chars');
        }
    }

    return [
        'model_name' => $modelName,
        'family' => $family,
        'parameter_count' => $parameterCount,
        'quantization' => $quantization,
        'context_length' => $contextLength,
        'license' => $license,
        'min_ram_bytes' => $minRamBytes,
        'min_vram_bytes' => $minVramBytes,
        'prefers_gpu' => $prefersGpu,
        'source_url' => $sourceUrl,
    ];
}

function model_inference_registry_generate_model_id(): string
{
    return 'mdl-' . bin2hex(random_bytes(8));
}

/**
 * Persist a model artifact from a PHP stream resource. Streams through the
 * King object store and trusts the kernel-computed integrity_sha256 as the
 * authoritative checksum. Inserts the metadata row only after the object
 * has been persisted, so a failed write leaves no orphan index entry.
 *
 * @param resource             $sourceStream  readable stream positioned at start
 * @param array<string, mixed> $rawMetadata
 * @return array<string, mixed> the persisted envelope shape
 */
function model_inference_registry_create_from_stream(PDO $pdo, array $rawMetadata, $sourceStream): array
{
    $metadata = model_inference_registry_validate_metadata($rawMetadata);

    // Uniqueness check before we write to king; avoids an orphan artifact.
    $conflict = $pdo->prepare('SELECT model_id FROM models WHERE model_name = :n AND quantization = :q LIMIT 1');
    $conflict->execute([':n' => $metadata['model_name'], ':q' => $metadata['quantization']]);
    if ($conflict->fetch() !== false) {
        throw new RuntimeException('model_registry_conflict:model_name+quantization already registered');
    }

    $modelId = model_inference_registry_generate_model_id();
    $meta = model_inference_object_store_put_stream($modelId, $sourceStream, 'application/octet-stream');

    $byteLength = (int) ($meta['content_length'] ?? 0);
    $sha256 = (string) ($meta['integrity_sha256'] ?? '');
    if ($byteLength < 1) {
        throw new RuntimeException('model_artifact_write_failed:object_store_content_length_missing');
    }
    if (!preg_match('/^[0-9a-f]{64}$/', $sha256)) {
        throw new RuntimeException('model_artifact_write_failed:object_store_integrity_sha256_missing');
    }

    $uploadedAt = gmdate('c');
    $registeredAt = $uploadedAt;

    $insert = $pdo->prepare('INSERT INTO models (
        model_id, model_name, family, parameter_count, quantization, context_length,
        object_store_key, byte_length, sha256_hex, uploaded_at,
        license, source_url,
        min_ram_bytes, min_vram_bytes, prefers_gpu,
        registered_at
    ) VALUES (
        :model_id, :model_name, :family, :parameter_count, :quantization, :context_length,
        :object_store_key, :byte_length, :sha256_hex, :uploaded_at,
        :license, :source_url,
        :min_ram_bytes, :min_vram_bytes, :prefers_gpu,
        :registered_at
    )');
    $insert->execute([
        ':model_id' => $modelId,
        ':model_name' => $metadata['model_name'],
        ':family' => $metadata['family'],
        ':parameter_count' => $metadata['parameter_count'],
        ':quantization' => $metadata['quantization'],
        ':context_length' => $metadata['context_length'],
        ':object_store_key' => $modelId,
        ':byte_length' => $byteLength,
        ':sha256_hex' => $sha256,
        ':uploaded_at' => $uploadedAt,
        ':license' => $metadata['license'],
        ':source_url' => $metadata['source_url'],
        ':min_ram_bytes' => $metadata['min_ram_bytes'],
        ':min_vram_bytes' => $metadata['min_vram_bytes'],
        ':prefers_gpu' => $metadata['prefers_gpu'] ? 1 : 0,
        ':registered_at' => $registeredAt,
    ]);

    return model_inference_registry_row_to_envelope([
        'model_id' => $modelId,
        'model_name' => $metadata['model_name'],
        'family' => $metadata['family'],
        'parameter_count' => $metadata['parameter_count'],
        'quantization' => $metadata['quantization'],
        'context_length' => $metadata['context_length'],
        'object_store_key' => $modelId,
        'byte_length' => $byteLength,
        'sha256_hex' => $sha256,
        'uploaded_at' => $uploadedAt,
        'license' => $metadata['license'],
        'source_url' => $metadata['source_url'],
        'min_ram_bytes' => $metadata['min_ram_bytes'],
        'min_vram_bytes' => $metadata['min_vram_bytes'],
        'prefers_gpu' => $metadata['prefers_gpu'] ? 1 : 0,
        'registered_at' => $registeredAt,
    ]);
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function model_inference_registry_row_to_envelope(array $row): array
{
    return [
        'model_id' => (string) $row['model_id'],
        'model_name' => (string) $row['model_name'],
        'family' => (string) $row['family'],
        'parameter_count' => (int) $row['parameter_count'],
        'quantization' => (string) $row['quantization'],
        'context_length' => (int) $row['context_length'],
        'artifact' => [
            'object_store_key' => (string) $row['object_store_key'],
            'byte_length' => (int) $row['byte_length'],
            'sha256_hex' => (string) $row['sha256_hex'],
            'uploaded_at' => (string) $row['uploaded_at'],
        ],
        'requirements' => [
            'min_ram_bytes' => (int) $row['min_ram_bytes'],
            'min_vram_bytes' => (int) $row['min_vram_bytes'],
            'prefers_gpu' => ((int) $row['prefers_gpu']) === 1,
        ],
        'license' => (string) $row['license'],
        'source_url' => $row['source_url'] === null ? null : (string) $row['source_url'],
        'registered_at' => (string) $row['registered_at'],
    ];
}

/** @return array<int, array<string, mixed>> */
function model_inference_registry_list(PDO $pdo): array
{
    $rows = $pdo->query('SELECT * FROM models ORDER BY registered_at DESC, model_id ASC')->fetchAll();
    $envelopes = [];
    foreach ($rows as $row) {
        $envelopes[] = model_inference_registry_row_to_envelope((array) $row);
    }
    return $envelopes;
}

function model_inference_registry_get(PDO $pdo, string $modelId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM models WHERE model_id = :id');
    $stmt->execute([':id' => $modelId]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    return model_inference_registry_row_to_envelope((array) $row);
}

function model_inference_registry_delete(PDO $pdo, string $modelId): bool
{
    $existing = model_inference_registry_get($pdo, $modelId);
    if ($existing === null) {
        return false;
    }
    $ok = king_object_store_delete($modelId);
    if ($ok !== true) {
        // Preserve the row if the object-store delete failed so the operator
        // can retry without a dangling-row disaster.
        throw new RuntimeException('model_artifact_write_failed:object_store_delete_failed');
    }
    $stmt = $pdo->prepare('DELETE FROM models WHERE model_id = :id');
    $stmt->execute([':id' => $modelId]);
    return $stmt->rowCount() === 1;
}
