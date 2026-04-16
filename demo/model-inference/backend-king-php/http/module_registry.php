<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/registry/model_registry.php';

/**
 * @param array<string, mixed> $request
 * @return array<string, string>
 */
function model_inference_registry_request_headers(array $request): array
{
    $headers = [];
    $raw = $request['headers'] ?? [];
    if (is_array($raw)) {
        foreach ($raw as $name => $value) {
            if (!is_string($name)) {
                continue;
            }
            $headers[strtolower($name)] = is_array($value) ? (string) reset($value) : (string) $value;
        }
    }
    return $headers;
}

/**
 * Extract X-Model-* header metadata into a raw associative array consumable
 * by model_inference_registry_validate_metadata.
 *
 * @param array<string, string> $headers
 * @return array<string, mixed>
 */
function model_inference_registry_metadata_from_headers(array $headers): array
{
    $prefersGpu = false;
    if (isset($headers['x-model-prefers-gpu'])) {
        $val = strtolower(trim($headers['x-model-prefers-gpu']));
        $prefersGpu = in_array($val, ['1', 'true', 'yes', 'on'], true);
    }

    return [
        'model_name' => $headers['x-model-name'] ?? '',
        'family' => $headers['x-model-family'] ?? '',
        'parameter_count' => isset($headers['x-model-parameter-count']) ? (int) $headers['x-model-parameter-count'] : 0,
        'quantization' => $headers['x-model-quantization'] ?? '',
        'context_length' => isset($headers['x-model-context-length']) ? (int) $headers['x-model-context-length'] : 0,
        'license' => $headers['x-model-license'] ?? '',
        'min_ram_bytes' => isset($headers['x-model-min-ram-bytes']) ? (int) $headers['x-model-min-ram-bytes'] : 0,
        'min_vram_bytes' => isset($headers['x-model-min-vram-bytes']) ? (int) $headers['x-model-min-vram-bytes'] : 0,
        'prefers_gpu' => $prefersGpu,
        'source_url' => $headers['x-model-source-url'] ?? null,
    ];
}

function model_inference_handle_registry_routes(
    string $path,
    string $method,
    array $request,
    callable $jsonResponse,
    callable $errorResponse,
    callable $openDatabase
): ?array {
    if ($path === '/api/models') {
        if ($method === 'GET') {
            $pdo = $openDatabase();
            $items = model_inference_registry_list($pdo);
            return $jsonResponse(200, [
                'status' => 'ok',
                'items' => $items,
                'count' => count($items),
                'time' => gmdate('c'),
            ]);
        }
        if ($method === 'POST') {
            $headers = model_inference_registry_request_headers($request);
            $rawMeta = model_inference_registry_metadata_from_headers($headers);

            $body = (string) ($request['body'] ?? '');
            if ($body === '') {
                return $errorResponse(400, 'invalid_request_envelope', 'POST /api/models requires a raw artifact body.', [
                    'field' => 'body',
                    'reason' => 'empty',
                ]);
            }
            if (strlen($body) < 16) {
                return $errorResponse(400, 'invalid_request_envelope', 'Artifact is too small to be a valid GGUF.', [
                    'field' => 'body',
                    'reason' => 'below_minimum_bytes',
                    'received_bytes' => strlen($body),
                ]);
            }

            try {
                $metadata = model_inference_registry_validate_metadata($rawMeta);
            } catch (InvalidArgumentException $validation) {
                [$field, $reason] = array_pad(explode(':', $validation->getMessage(), 2), 2, '');
                return $errorResponse(400, 'invalid_request_envelope', 'Missing or invalid model metadata header.', [
                    'field' => $field,
                    'reason' => $reason,
                ]);
            }

            $stream = fopen('php://memory', 'r+');
            if (!is_resource($stream)) {
                return $errorResponse(500, 'model_artifact_write_failed', 'unable to open in-memory stream.', []);
            }
            fwrite($stream, $body);
            rewind($stream);

            try {
                $pdo = $openDatabase();
                $envelope = model_inference_registry_create_from_stream($pdo, $metadata, $stream);
            } catch (InvalidArgumentException $validation) {
                [$field, $reason] = array_pad(explode(':', $validation->getMessage(), 2), 2, '');
                return $errorResponse(400, 'invalid_request_envelope', 'Invalid model metadata.', [
                    'field' => $field,
                    'reason' => $reason,
                ]);
            } catch (RuntimeException $runtime) {
                [$code, $reason] = array_pad(explode(':', $runtime->getMessage(), 2), 2, '');
                if ($code === 'model_registry_conflict') {
                    return $errorResponse(409, 'model_registry_conflict', 'A model with this name and quantization already exists.', [
                        'reason' => $reason,
                    ]);
                }
                return $errorResponse(500, 'model_artifact_write_failed', 'Failed to persist model artifact.', [
                    'reason' => $runtime->getMessage(),
                ]);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            return $jsonResponse(201, [
                'status' => 'created',
                'model' => $envelope,
                'time' => gmdate('c'),
            ]);
        }
        return $errorResponse(405, 'method_not_allowed', 'GET or POST required.', [
            'path' => $path,
            'method' => $method,
            'allowed' => ['GET', 'POST'],
        ]);
    }

    if (preg_match('#^/api/models/(mdl-[a-f0-9]{16})$#', $path, $m)) {
        $modelId = $m[1];
        $pdo = $openDatabase();
        if ($method === 'GET') {
            $row = model_inference_registry_get($pdo, $modelId);
            if ($row === null) {
                return $errorResponse(404, 'model_not_found', 'No model with that model_id.', [
                    'model_id' => $modelId,
                ]);
            }
            return $jsonResponse(200, [
                'status' => 'ok',
                'model' => $row,
                'time' => gmdate('c'),
            ]);
        }
        if ($method === 'DELETE') {
            try {
                $deleted = model_inference_registry_delete($pdo, $modelId);
            } catch (RuntimeException $error) {
                return $errorResponse(500, 'model_artifact_write_failed', 'Failed to delete model artifact.', [
                    'reason' => $error->getMessage(),
                    'model_id' => $modelId,
                ]);
            }
            if (!$deleted) {
                return $errorResponse(404, 'model_not_found', 'No model with that model_id.', [
                    'model_id' => $modelId,
                ]);
            }
            return $jsonResponse(200, [
                'status' => 'deleted',
                'model_id' => $modelId,
                'time' => gmdate('c'),
            ]);
        }
        return $errorResponse(405, 'method_not_allowed', 'GET or DELETE required.', [
            'path' => $path,
            'method' => $method,
            'allowed' => ['GET', 'DELETE'],
        ]);
    }

    return null;
}
