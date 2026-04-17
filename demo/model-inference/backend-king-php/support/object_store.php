<?php

declare(strict_types=1);

/**
 * One-time King object-store bootstrap for the model-inference backend.
 *
 * This must be called after extension_loaded('king') is confirmed and before
 * the registry writes its first GGUF. The ini flag
 * `king.security_allow_config_override=1` must be set (see run-dev.sh +
 * Dockerfile) for the init call to be accepted by the runtime.
 *
 * Only the local_fs primary backend is wired here. Cloud primaries (cloud_s3,
 * cloud_gcs, cloud_azure) ARE supported by the King kernel but belong to a
 * later operational hardening leaf; wiring them into this demo without
 * credential-policy context would fall into the target-shape trap.
 */
function model_inference_object_store_init(string $storageRootPath, int $maxStorageBytes): void
{
    if (!function_exists('king_object_store_init')) {
        throw new RuntimeException('king_object_store_init not available; load the King extension.');
    }
    if ($storageRootPath === '') {
        throw new InvalidArgumentException('storage_root_path must be non-empty.');
    }
    if (!is_dir($storageRootPath)) {
        if (!mkdir($storageRootPath, 0775, true) && !is_dir($storageRootPath)) {
            throw new RuntimeException('unable to create object-store root: ' . $storageRootPath);
        }
    }
    if ($maxStorageBytes <= 0) {
        throw new InvalidArgumentException('max_storage_size_bytes must be > 0.');
    }

    king_object_store_init([
        'primary_backend' => 'local_fs',
        'storage_root_path' => $storageRootPath,
        'max_storage_size_bytes' => $maxStorageBytes,
    ]);
}

/**
 * Write a stream into the King object store under a flat object id and return
 * the authoritative metadata (integrity_sha256, content_length, modified_at).
 *
 * The caller is responsible for ensuring the object id matches the flat-id
 * rule (no '/' separators) — King refuses slash-containing ids.
 *
 * @param resource $stream
 * @return array<string, mixed>
 */
function model_inference_object_store_put_stream(string $objectId, $stream, string $contentType = 'application/octet-stream'): array
{
    if (!is_resource($stream)) {
        throw new InvalidArgumentException('stream must be a valid resource.');
    }
    $ok = king_object_store_put_from_stream($objectId, $stream, [
        'content_type' => $contentType,
    ]);
    if ($ok !== true) {
        $error = function_exists('king_get_last_error') ? (string) king_get_last_error() : '';
        throw new RuntimeException('king_object_store_put_from_stream failed: ' . $error);
    }
    $meta = king_object_store_get_metadata($objectId);
    if (!is_array($meta)) {
        throw new RuntimeException('king_object_store_get_metadata returned non-array after successful put.');
    }
    return $meta;
}
