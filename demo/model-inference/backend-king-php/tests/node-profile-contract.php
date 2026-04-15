<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/profile/hardware_profile.php';
require_once __DIR__ . '/../http/router.php';

function model_inference_profile_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[node-profile-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    // Direct probe: assert envelope shape against the contract file fields.
    $profile = model_inference_hardware_profile(
        'node_contract_test',
        'http://127.0.0.1:18090/health',
        'ready'
    );

    model_inference_profile_contract_assert(is_array($profile), 'profile must be array');

    // Top-level fields.
    foreach (['node_id', 'king_version', 'platform', 'cpu', 'memory', 'gpu', 'capabilities', 'service', 'published_at'] as $field) {
        model_inference_profile_contract_assert(array_key_exists($field, $profile), "profile missing field '{$field}'");
    }

    model_inference_profile_contract_assert(
        (string) $profile['node_id'] === 'node_contract_test',
        'node_id passthrough mismatch'
    );
    model_inference_profile_contract_assert(
        preg_match('/^\d{4}-\d{2}-\d{2}T/', (string) $profile['published_at']) === 1,
        'published_at must be rfc3339-shaped'
    );

    // Platform section.
    $platform = $profile['platform'];
    model_inference_profile_contract_assert(is_array($platform), 'platform must be object');
    model_inference_profile_contract_assert(
        in_array((string) ($platform['os'] ?? ''), ['darwin', 'linux', 'other'], true),
        'platform.os must be darwin|linux|other; got ' . (string) ($platform['os'] ?? 'null')
    );
    model_inference_profile_contract_assert(
        is_string($platform['arch'] ?? null) && $platform['arch'] !== '',
        'platform.arch must be non-empty string'
    );
    model_inference_profile_contract_assert(
        is_string($platform['kernel'] ?? null) && $platform['kernel'] !== '',
        'platform.kernel must be non-empty string'
    );

    // CPU section: logical_count >= 1 and physical_count <= logical_count.
    $cpu = $profile['cpu'];
    model_inference_profile_contract_assert(is_array($cpu), 'cpu must be object');
    model_inference_profile_contract_assert(
        is_int($cpu['logical_count'] ?? null) && $cpu['logical_count'] >= 1,
        'cpu.logical_count must be int >= 1'
    );
    model_inference_profile_contract_assert(
        is_int($cpu['physical_count'] ?? null) && $cpu['physical_count'] >= 1,
        'cpu.physical_count must be int >= 1'
    );
    model_inference_profile_contract_assert(
        $cpu['physical_count'] <= $cpu['logical_count'],
        'cpu.physical_count must be <= cpu.logical_count'
    );
    model_inference_profile_contract_assert(
        is_string($cpu['brand'] ?? null),
        'cpu.brand must be string'
    );

    // Memory section.
    $memory = $profile['memory'];
    model_inference_profile_contract_assert(is_array($memory), 'memory must be object');
    model_inference_profile_contract_assert(
        is_int($memory['total_bytes'] ?? null) && $memory['total_bytes'] >= 1,
        'memory.total_bytes must be int >= 1 (probe failed to read platform memory)'
    );
    model_inference_profile_contract_assert(
        is_int($memory['available_bytes'] ?? null) && $memory['available_bytes'] >= 0,
        'memory.available_bytes must be int >= 0'
    );
    model_inference_profile_contract_assert(
        $memory['available_bytes'] <= $memory['total_bytes'],
        'memory.available_bytes must be <= total_bytes (available cannot exceed total)'
    );
    model_inference_profile_contract_assert(
        is_int($memory['page_size'] ?? null) && $memory['page_size'] >= 1,
        'memory.page_size must be int >= 1'
    );

    // GPU section: honest probe-driven values only.
    $gpu = $profile['gpu'];
    model_inference_profile_contract_assert(is_array($gpu), 'gpu must be object');
    model_inference_profile_contract_assert(
        is_bool($gpu['present'] ?? null),
        'gpu.present must be bool'
    );
    model_inference_profile_contract_assert(
        in_array((string) ($gpu['kind'] ?? ''), ['none', 'metal', 'cuda', 'rocm', 'other'], true),
        'gpu.kind must be none|metal|cuda|rocm|other; got ' . (string) ($gpu['kind'] ?? 'null')
    );
    model_inference_profile_contract_assert(
        is_int($gpu['device_count'] ?? null) && $gpu['device_count'] >= 0,
        'gpu.device_count must be int >= 0'
    );
    model_inference_profile_contract_assert(
        is_int($gpu['vram_total_bytes'] ?? null) && $gpu['vram_total_bytes'] >= 0,
        'gpu.vram_total_bytes must be int >= 0'
    );
    model_inference_profile_contract_assert(
        is_int($gpu['vram_free_bytes'] ?? null) && $gpu['vram_free_bytes'] >= 0,
        'gpu.vram_free_bytes must be int >= 0'
    );
    // Honesty: present=false forces kind='none' and VRAM=0, and vice versa.
    if ($gpu['present'] === false) {
        model_inference_profile_contract_assert(
            $gpu['kind'] === 'none',
            'gpu.present=false must pair with gpu.kind=none'
        );
        model_inference_profile_contract_assert(
            $gpu['device_count'] === 0,
            'gpu.present=false must pair with gpu.device_count=0'
        );
        model_inference_profile_contract_assert(
            $gpu['vram_total_bytes'] === 0 && $gpu['vram_free_bytes'] === 0,
            'gpu.present=false must pair with vram_total_bytes=0 and vram_free_bytes=0 (no fabricated values)'
        );
    } else {
        model_inference_profile_contract_assert(
            $gpu['kind'] !== 'none',
            'gpu.present=true must pair with gpu.kind != none'
        );
        model_inference_profile_contract_assert(
            $gpu['device_count'] >= 1,
            'gpu.present=true must pair with gpu.device_count >= 1'
        );
    }
    // free <= total always; probe must not fabricate a free value bigger than total.
    model_inference_profile_contract_assert(
        $gpu['vram_free_bytes'] <= $gpu['vram_total_bytes'] || $gpu['vram_total_bytes'] === 0,
        'gpu.vram_free_bytes must be <= vram_total_bytes (when total_bytes > 0)'
    );

    // Capabilities section: empty loadable_models + max_context_tokens=0 until later leaves.
    $capabilities = $profile['capabilities'];
    model_inference_profile_contract_assert(is_array($capabilities), 'capabilities must be object');
    model_inference_profile_contract_assert(
        is_array($capabilities['loadable_models'] ?? null) && $capabilities['loadable_models'] === [],
        'capabilities.loadable_models must be [] until #M-5 registry lands'
    );
    model_inference_profile_contract_assert(
        ($capabilities['max_context_tokens'] ?? null) === 0,
        'capabilities.max_context_tokens must be 0 until #M-7 worker lands'
    );
    model_inference_profile_contract_assert(
        is_bool($capabilities['supports_streaming'] ?? null),
        'capabilities.supports_streaming must be bool'
    );
    model_inference_profile_contract_assert(
        in_array('Q4_K', (array) ($capabilities['supports_quantizations'] ?? []), true)
        && in_array('Q8_0', (array) ($capabilities['supports_quantizations'] ?? []), true),
        'capabilities.supports_quantizations must include at least Q4_K and Q8_0'
    );

    // Service section.
    $service = $profile['service'];
    model_inference_profile_contract_assert(is_array($service), 'service must be object');
    model_inference_profile_contract_assert(
        ($service['service_type'] ?? null) === 'king.inference.v1',
        'service.service_type must be king.inference.v1'
    );
    model_inference_profile_contract_assert(
        (string) ($service['health_url'] ?? '') === 'http://127.0.0.1:18090/health',
        'service.health_url must match the provided value'
    );
    model_inference_profile_contract_assert(
        in_array((string) ($service['status'] ?? ''), ['ready', 'draining', 'starting', 'error'], true),
        'service.status must be ready|draining|starting|error'
    );

    // Dispatcher path: /api/node/profile must serve the same profile shape.
    $jsonResponse = static function (int $status, array $payload): array {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    };
    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        return $jsonResponse($status, [
            'status' => 'error',
            'error' => ['code' => $code, 'message' => $message, 'details' => $details],
            'time' => gmdate('c'),
        ]);
    };
    $methodFromRequest = static function (array $request): string {
        return strtoupper(trim((string) ($request['method'] ?? 'GET')));
    };
    $pathFromRequest = static function (array $request): string {
        return (string) ($request['path'] ?? '/');
    };
    $runtimeEnvelope = static function (): array {
        return [
            'node' => ['node_id' => 'node_dispatch_probe', 'role' => 'inference-serving'],
        ];
    };

    $openDatabase = static function (): PDO {
        throw new RuntimeException('openDatabase should not be reached by /api/node/profile.');
    };
    $dispatchResponse = model_inference_dispatch_request(
        ['method' => 'GET', 'path' => '/api/node/profile', 'uri' => '/api/node/profile', 'headers' => []],
        $jsonResponse,
        $errorResponse,
        $methodFromRequest,
        $pathFromRequest,
        $runtimeEnvelope,
        $openDatabase,
        '/ws',
        '127.0.0.1',
        18090
    );
    model_inference_profile_contract_assert(
        (int) ($dispatchResponse['status'] ?? 0) === 200,
        'dispatcher must serve /api/node/profile with 200'
    );
    $dispatched = json_decode((string) ($dispatchResponse['body'] ?? ''), true);
    model_inference_profile_contract_assert(
        is_array($dispatched) && ($dispatched['node_id'] ?? null) === 'node_dispatch_probe',
        'dispatcher must propagate node_id from runtimeEnvelope().node.node_id'
    );
    model_inference_profile_contract_assert(
        ($dispatched['service']['health_url'] ?? null) === 'http://127.0.0.1:18090/health',
        'dispatcher must compose service.health_url from host+port'
    );

    // Non-GET method must be rejected.
    $postResponse = model_inference_dispatch_request(
        ['method' => 'POST', 'path' => '/api/node/profile', 'uri' => '/api/node/profile', 'headers' => []],
        $jsonResponse,
        $errorResponse,
        $methodFromRequest,
        $pathFromRequest,
        $runtimeEnvelope,
        $openDatabase,
        '/ws',
        '127.0.0.1',
        18090
    );
    model_inference_profile_contract_assert(
        (int) ($postResponse['status'] ?? 0) === 405,
        'dispatcher must reject POST /api/node/profile with 405'
    );
    $postPayload = json_decode((string) ($postResponse['body'] ?? ''), true);
    model_inference_profile_contract_assert(
        is_array($postPayload) && (($postPayload['error'] ?? [])['code'] ?? null) === 'method_not_allowed',
        'dispatcher must return method_not_allowed for POST /api/node/profile'
    );

    fwrite(STDOUT, "[node-profile-contract] PASS (os=" . $platform['os'] . ", gpu.kind=" . $gpu['kind'] . ")\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[node-profile-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
