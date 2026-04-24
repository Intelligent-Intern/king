<?php
declare(strict_types=1);

namespace King\Voltron;

use RuntimeException;

/**
 * @return array{uri:string,object_id:string,size_bytes:int,sha256:string,backend:string,iibin_ref_size_bytes:?int}
 */
function voltron_artifact_put(string $artifactUri, mixed $payload, string $producerStep): array
{
    voltron_artifact_store_init();

    $objectId = voltron_artifact_object_id_from_uri($artifactUri);
    $serialized = serialize($payload);
    $sha256 = hash('sha256', $serialized);

    $stored = false;
    if (voltron_artifact_store_ready()) {
        try {
            $stored = king_object_store_put(
                $objectId,
                $serialized,
                [
                    'content_type' => 'application/x-php-serialized',
                    'object_type' => 'voltron.activation',
                    'integrity_sha256' => $sha256,
                    'cache_policy' => 'etag',
                    'producer_step' => $producerStep,
                ]
            ) === true;
        } catch (\Throwable) {
            $stored = false;
        }
    }

    if (!$stored) {
        $path = voltron_artifact_fallback_path($objectId);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create fallback artifact directory.');
        }
        if (@file_put_contents($path, $serialized) === false) {
            throw new RuntimeException('Failed to persist fallback artifact payload.');
        }
    }

    $backend = $stored ? 'object_store' : 'fallback_fs';
    $ref = [
        'artifact_uri' => $artifactUri,
        'object_id' => $objectId,
        'sha256' => $sha256,
        'size_bytes' => strlen($serialized),
        'producer_step' => $producerStep,
        'backend' => $backend,
    ];
    $encodedRef = voltron_artifact_ref_encode_iibin($ref);

    return [
        'uri' => $artifactUri,
        'object_id' => $objectId,
        'size_bytes' => strlen($serialized),
        'sha256' => $sha256,
        'backend' => $backend,
        'iibin_ref_size_bytes' => is_string($encodedRef) ? strlen($encodedRef) : null,
    ];
}

function voltron_artifact_get(string $artifactUri): mixed
{
    voltron_artifact_store_init();

    $objectId = voltron_artifact_object_id_from_uri($artifactUri);

    if (voltron_artifact_store_ready()) {
        try {
            $raw = king_object_store_get($objectId);
            if (is_string($raw) && $raw !== '') {
                return unserialize($raw, ['allowed_classes' => false]);
            }
        } catch (\Throwable) {
            // Fall through to fallback storage.
        }
    }

    $path = voltron_artifact_fallback_path($objectId);
    if (!is_file($path)) {
        throw new RuntimeException("Activation artifact not found: {$artifactUri}");
    }
    $raw = (string) file_get_contents($path);

    return unserialize($raw, ['allowed_classes' => false]);
}

function voltron_model_blob_get(string $objectId): string
{
    voltron_artifact_store_init();

    if (!voltron_artifact_store_ready()) {
        throw new RuntimeException('King object store runtime is not available for GGUF object retrieval.');
    }

    try {
        $payload = king_object_store_get($objectId);
    } catch (\Throwable $e) {
        throw new RuntimeException('Failed to read GGUF object from store: ' . $e->getMessage());
    }

    if (!is_string($payload) || $payload === '') {
        throw new RuntimeException('GGUF object payload is empty.');
    }

    return $payload;
}

function voltron_artifact_store_ready(): bool
{
    static $ready = null;
    if (is_bool($ready)) {
        return $ready;
    }

    $ready = function_exists('king_object_store_put')
        && function_exists('king_object_store_get')
        && function_exists('king_object_store_init');

    return $ready;
}

function voltron_artifact_store_init(): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }
    $initialized = true;

    if (!voltron_artifact_store_ready()) {
        return;
    }

    $root = getenv('VOLTRON_OBJECT_STORE_ROOT');
    if (!is_string($root) || trim($root) === '') {
        $root = sys_get_temp_dir() . '/voltron-object-store';
    }
    if (!is_dir($root)) {
        @mkdir($root, 0777, true);
    }

    try {
        king_object_store_init([
            'primary_backend' => 'local_fs',
            'storage_root_path' => $root,
            'max_storage_size_bytes' => 4 * 1024 * 1024 * 1024,
            'chunk_size_kb' => 256,
        ]);
    } catch (\Throwable) {
        // ignore init failure; fallback filesystem path remains available.
    }
}

function voltron_artifact_object_id_from_uri(string $artifactUri): string
{
    $trimmed = trim($artifactUri);
    if ($trimmed === '') {
        throw new RuntimeException('Artifact URI must be non-empty.');
    }

    foreach (['object://', 'memory://'] as $prefix) {
        if (str_starts_with($trimmed, $prefix)) {
            $id = substr($trimmed, strlen($prefix));
            if ($id === false || $id === '') {
                throw new RuntimeException('Artifact URI must include object id after scheme.');
            }
            return voltron_artifact_canonical_object_id($id);
        }
    }

    return voltron_artifact_canonical_object_id($trimmed);
}

function voltron_artifact_fallback_path(string $objectId): string
{
    $root = getenv('VOLTRON_ARTIFACT_FALLBACK_ROOT');
    if (!is_string($root) || trim($root) === '') {
        $root = sys_get_temp_dir() . '/voltron-artifact-fallback';
    }

    $safe = preg_replace('/[^a-zA-Z0-9._\/-]+/', '_', $objectId);
    if (!is_string($safe) || $safe === '') {
        $safe = sha1($objectId);
    }

    return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($safe, DIRECTORY_SEPARATOR);
}

/**
 * @param array<string,mixed> $artifact
 */
function voltron_artifact_ref_encode_iibin(array $artifact): ?string
{
    if (!function_exists('king_proto_define_schema') || !function_exists('king_proto_encode')) {
        return null;
    }

    if (function_exists('king_proto_is_schema_defined') && !king_proto_is_schema_defined('VoltronArtifactRef')) {
        king_proto_define_schema('VoltronArtifactRef', [
            'artifact_uri' => ['tag' => 1, 'type' => 'string', 'required' => true],
            'object_id' => ['tag' => 2, 'type' => 'string', 'required' => true],
            'sha256' => ['tag' => 3, 'type' => 'string', 'required' => true],
            'size_bytes' => ['tag' => 4, 'type' => 'int64', 'required' => true],
            'producer_step' => ['tag' => 5, 'type' => 'string', 'required' => true],
            'backend' => ['tag' => 6, 'type' => 'string', 'required' => true],
        ]);
    }

    try {
        return king_proto_encode('VoltronArtifactRef', $artifact);
    } catch (\Throwable) {
        return null;
    }
}

function voltron_artifact_canonical_object_id(string $rawId): string
{
    $candidate = trim($rawId);
    if ($candidate === '') {
        throw new RuntimeException('Artifact object id cannot be empty.');
    }

    if (preg_match('/^[A-Za-z0-9._-]+$/', $candidate) === 1) {
        return $candidate;
    }

    $normalized = preg_replace('/[^A-Za-z0-9._-]+/', '_', $candidate);
    if (!is_string($normalized) || $normalized === '') {
        $normalized = 'obj';
    }
    $normalized = trim($normalized, '._-');
    if ($normalized === '') {
        $normalized = 'obj';
    }
    if (strlen($normalized) > 80) {
        $normalized = substr($normalized, 0, 80);
    }

    return 'voltron_' . $normalized . '_' . substr(sha1($candidate), 0, 16);
}
