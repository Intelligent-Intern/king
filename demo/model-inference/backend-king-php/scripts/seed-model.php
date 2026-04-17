<?php

declare(strict_types=1);

/**
 * Admin-side CLI helper: register a GGUF file into the model-inference
 * registry without going through the HTTP upload path.
 *
 * Why this exists:
 * The POST /api/models HTTP surface is capped by king_http1_server_listen_once
 * at 1 MiB per request body. Real GGUF artifacts are tens or hundreds of
 * MiB, so they are admitted via this script (streaming directly into
 * king_object_store_put_from_stream through the same domain function the
 * HTTP route would call). Same validation, same bit-identical SHA-256
 * round-trip; just no HTTP body limit in the way.
 *
 * Usage:
 *   php -d extension=.../king.so -d king.security_allow_config_override=1 \
 *     demo/model-inference/backend-king-php/scripts/seed-model.php \
 *     --gguf /path/to/model.gguf \
 *     --name SmolLM2-135M-Instruct --family smollm2 --quantization Q4_K \
 *     --parameter-count 135000000 --context-length 2048 \
 *     --license apache-2.0 --min-ram-bytes 268435456 \
 *     [--min-vram-bytes 0] [--prefers-gpu 0] [--source-url https://...]
 *
 * Env vars (fall back to server.php defaults):
 *   MODEL_INFERENCE_KING_DB_PATH
 *   MODEL_INFERENCE_KING_OBJECT_STORE_ROOT
 *   MODEL_INFERENCE_KING_OBJECT_STORE_MAX_BYTES
 */

if (!extension_loaded('king')) {
    fwrite(STDERR, "[seed-model] king extension is not loaded (pass -d extension=.../king.so)\n");
    exit(1);
}

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/object_store.php';
require_once __DIR__ . '/../domain/registry/model_registry.php';

$options = getopt('', [
    'gguf:',
    'name:',
    'family:',
    'quantization:',
    'parameter-count:',
    'context-length:',
    'license:',
    'min-ram-bytes:',
    'min-vram-bytes::',
    'prefers-gpu::',
    'source-url::',
    'help',
]);

if (isset($options['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 2000));
    exit(0);
}

$required = ['gguf', 'name', 'family', 'quantization', 'parameter-count', 'context-length', 'license', 'min-ram-bytes'];
foreach ($required as $flag) {
    if (!isset($options[$flag])) {
        fwrite(STDERR, "[seed-model] missing required --{$flag}\n");
        exit(2);
    }
}

$ggufPath = (string) $options['gguf'];
if (!is_file($ggufPath)) {
    fwrite(STDERR, "[seed-model] GGUF file not found: {$ggufPath}\n");
    exit(2);
}

$dbPath = (string) (getenv('MODEL_INFERENCE_KING_DB_PATH') ?: (__DIR__ . '/../.local/model-inference.sqlite'));
$storeRoot = (string) (getenv('MODEL_INFERENCE_KING_OBJECT_STORE_ROOT') ?: (dirname($dbPath) . '/object-store'));
$maxStoreBytes = (int) (getenv('MODEL_INFERENCE_KING_OBJECT_STORE_MAX_BYTES') ?: (string) (4 * 1024 * 1024 * 1024));

try {
    $pdo = model_inference_open_sqlite_pdo($dbPath);
    model_inference_registry_schema_migrate($pdo);
    model_inference_object_store_init($storeRoot, $maxStoreBytes);
} catch (Throwable $error) {
    fwrite(STDERR, "[seed-model] bootstrap failed: {$error->getMessage()}\n");
    exit(3);
}

$metadata = [
    'model_name' => (string) $options['name'],
    'family' => (string) $options['family'],
    'quantization' => (string) $options['quantization'],
    'parameter_count' => (int) $options['parameter-count'],
    'context_length' => (int) $options['context-length'],
    'license' => (string) $options['license'],
    'min_ram_bytes' => (int) $options['min-ram-bytes'],
    'min_vram_bytes' => isset($options['min-vram-bytes']) ? (int) $options['min-vram-bytes'] : 0,
    'prefers_gpu' => isset($options['prefers-gpu'])
        ? in_array(strtolower((string) $options['prefers-gpu']), ['1', 'true', 'yes', 'on'], true)
        : false,
    'source_url' => isset($options['source-url']) && $options['source-url'] !== '' ? (string) $options['source-url'] : null,
];

$stream = @fopen($ggufPath, 'rb');
if (!is_resource($stream)) {
    fwrite(STDERR, "[seed-model] failed to open {$ggufPath}\n");
    exit(4);
}

try {
    $envelope = model_inference_registry_create_from_stream($pdo, $metadata, $stream);
} catch (RuntimeException $error) {
    fclose($stream);
    $message = $error->getMessage();
    if (str_starts_with($message, 'model_registry_conflict:')) {
        fwrite(STDERR, "[seed-model] conflict — a model with this (name, quantization) is already registered.\n");
        exit(5);
    }
    fwrite(STDERR, "[seed-model] persistence failed: {$message}\n");
    exit(6);
} catch (InvalidArgumentException $error) {
    fclose($stream);
    fwrite(STDERR, "[seed-model] invalid metadata: {$error->getMessage()}\n");
    exit(7);
}
fclose($stream);

fwrite(STDOUT, json_encode([
    'status' => 'seeded',
    'model' => $envelope,
    'db_path' => $dbPath,
    'object_store_root' => $storeRoot,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
exit(0);
