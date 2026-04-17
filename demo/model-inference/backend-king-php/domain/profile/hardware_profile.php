<?php

declare(strict_types=1);

/**
 * Real hardware profile probes for a King inference-serving node.
 *
 * Honesty rules (enforced in code + asserted by tests/node-profile-contract):
 * - vram_total_bytes / vram_free_bytes are 0 unless a live probe returned a value.
 * - physical_count falls back to logical_count when the platform does not expose
 *   a distinct physical core count; code MUST NOT invent a ratio.
 * - Every call re-observes the platform; there is no cached snapshot.
 *
 * See demo/model-inference/contracts/v1/node-profile.contract.json for the
 * envelope that these functions are required to produce.
 */

/**
 * Run a short-lived command with a fixed argv list and collect stdout.
 * Returns null when the binary is missing, the call times out, or exit != 0.
 */
function model_inference_probe_run(array $argv, int $timeoutMs = 1500): ?string
{
    if ($argv === []) {
        return null;
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open($argv, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return null;
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $deadline = microtime(true) + ($timeoutMs / 1000.0);
    $stdout = '';
    while (true) {
        $status = proc_get_status($process);
        $chunk = (string) stream_get_contents($pipes[1]);
        if ($chunk !== '') {
            $stdout .= $chunk;
        }
        if (!$status['running']) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            break;
        }
        if (microtime(true) >= $deadline) {
            proc_terminate($process, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            return null;
        }
        usleep(10_000);
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        return null;
    }
    return $stdout;
}

/** @return array{os: string, arch: string, kernel: string} */
function model_inference_probe_platform(): array
{
    $osRaw = strtolower(PHP_OS_FAMILY);
    $os = match (true) {
        str_starts_with($osRaw, 'darwin') => 'darwin',
        str_starts_with($osRaw, 'linux') => 'linux',
        default => 'other',
    };

    $arch = trim((string) php_uname('m'));
    if ($arch === '') {
        $arch = 'unknown';
    }
    $kernel = trim((string) php_uname('r'));
    if ($kernel === '') {
        $kernel = 'unknown';
    }

    return [
        'os' => $os,
        'arch' => $arch,
        'kernel' => $kernel,
    ];
}

/**
 * @param string $os
 * @return array{logical_count: int, physical_count: int, brand: string}
 */
function model_inference_probe_cpu(string $os): array
{
    $logical = 1;
    $physical = 1;
    $brand = 'unknown';

    if ($os === 'darwin') {
        $logicalRaw = model_inference_probe_run(['/usr/sbin/sysctl', '-n', 'hw.logicalcpu']);
        if ($logicalRaw !== null && is_numeric(trim($logicalRaw))) {
            $logical = max(1, (int) trim($logicalRaw));
        }
        $physicalRaw = model_inference_probe_run(['/usr/sbin/sysctl', '-n', 'hw.physicalcpu']);
        if ($physicalRaw !== null && is_numeric(trim($physicalRaw))) {
            $physical = max(1, (int) trim($physicalRaw));
        } else {
            $physical = $logical;
        }
        $brandRaw = model_inference_probe_run(['/usr/sbin/sysctl', '-n', 'machdep.cpu.brand_string']);
        if ($brandRaw !== null && trim($brandRaw) !== '') {
            $brand = trim($brandRaw);
        }
    } elseif ($os === 'linux') {
        $cpuInfo = @file_get_contents('/proc/cpuinfo');
        if (is_string($cpuInfo) && $cpuInfo !== '') {
            $logicalMatches = preg_match_all('/^processor\s*:/m', $cpuInfo);
            if (is_int($logicalMatches) && $logicalMatches > 0) {
                $logical = $logicalMatches;
            }
            if (preg_match_all('/^core id\s*:\s*(\d+)/m', $cpuInfo, $coreIds) && isset($coreIds[1])) {
                $unique = array_unique($coreIds[1]);
                if (count($unique) > 0) {
                    $physical = count($unique);
                }
            }
            if ($physical === 1 && $logical > 1) {
                // Unknown topology: do not invent a hyperthreading ratio.
                $physical = $logical;
            }
            if (preg_match('/^model name\s*:\s*(.+)$/m', $cpuInfo, $modelMatch)) {
                $brand = trim($modelMatch[1]);
            }
        }
    }

    return [
        'logical_count' => $logical,
        'physical_count' => $physical,
        'brand' => $brand,
    ];
}

/**
 * @param string $os
 * @return array{total_bytes: int, available_bytes: int, page_size: int}
 */
function model_inference_probe_memory(string $os): array
{
    $total = 0;
    $available = 0;
    $pageSize = 4096;

    if ($os === 'darwin') {
        $totalRaw = model_inference_probe_run(['/usr/sbin/sysctl', '-n', 'hw.memsize']);
        if ($totalRaw !== null && is_numeric(trim($totalRaw))) {
            $total = (int) trim($totalRaw);
        }
        $pageRaw = model_inference_probe_run(['/usr/sbin/sysctl', '-n', 'hw.pagesize']);
        if ($pageRaw !== null && is_numeric(trim($pageRaw))) {
            $pageSize = max(1, (int) trim($pageRaw));
        }
        $vmStat = model_inference_probe_run(['/usr/bin/vm_stat']);
        if ($vmStat !== null) {
            $freePages = 0;
            if (preg_match('/^Pages free:\s*(\d+)\./m', $vmStat, $free)) {
                $freePages += (int) $free[1];
            }
            if (preg_match('/^Pages inactive:\s*(\d+)\./m', $vmStat, $inactive)) {
                $freePages += (int) $inactive[1];
            }
            if (preg_match('/^Pages speculative:\s*(\d+)\./m', $vmStat, $spec)) {
                $freePages += (int) $spec[1];
            }
            if ($freePages > 0) {
                $available = $freePages * $pageSize;
            }
        }
    } elseif ($os === 'linux') {
        $memInfo = @file_get_contents('/proc/meminfo');
        if (is_string($memInfo)) {
            if (preg_match('/^MemTotal:\s*(\d+)\s*kB/m', $memInfo, $m)) {
                $total = ((int) $m[1]) * 1024;
            }
            if (preg_match('/^MemAvailable:\s*(\d+)\s*kB/m', $memInfo, $m)) {
                $available = ((int) $m[1]) * 1024;
            }
        }
        $pageRaw = model_inference_probe_run(['/usr/bin/getconf', 'PAGESIZE']);
        if ($pageRaw !== null && is_numeric(trim($pageRaw))) {
            $pageSize = max(1, (int) trim($pageRaw));
        }
    }

    if ($available === 0 && $total > 0) {
        // Fall back explicitly to total rather than fabricating a ratio.
        $available = $total;
    }

    return [
        'total_bytes' => max(0, $total),
        'available_bytes' => max(0, $available),
        'page_size' => max(1, $pageSize),
    ];
}

/**
 * @param string $os
 * @param string $arch
 * @return array{present: bool, kind: string, device_count: int, vram_total_bytes: int, vram_free_bytes: int}
 */
function model_inference_probe_gpu(string $os, string $arch): array
{
    $none = [
        'present' => false,
        'kind' => 'none',
        'device_count' => 0,
        'vram_total_bytes' => 0,
        'vram_free_bytes' => 0,
    ];

    if ($os === 'darwin') {
        // Apple Silicon GPUs share system memory and expose Metal; Intel Macs
        // still expose Metal via the discrete GPU family. We detect Metal
        // presence via system_profiler which ships with every macOS install.
        $raw = model_inference_probe_run(['/usr/sbin/system_profiler', 'SPDisplaysDataType', '-json'], 4000);
        if ($raw !== null) {
            $decoded = json_decode($raw, true);
            $displays = [];
            if (is_array($decoded) && isset($decoded['SPDisplaysDataType']) && is_array($decoded['SPDisplaysDataType'])) {
                $displays = $decoded['SPDisplaysDataType'];
            }
            if ($displays !== []) {
                $deviceCount = count($displays);
                $vramTotal = 0;
                foreach ($displays as $display) {
                    if (!is_array($display)) {
                        continue;
                    }
                    $vramRaw = (string) ($display['sppci_vram'] ?? $display['_spdisplays_vram'] ?? $display['spdisplays_vram_shared'] ?? '');
                    if ($vramRaw !== '' && preg_match('/^(\d+)\s*(MB|GB)$/i', trim($vramRaw), $m)) {
                        $value = (int) $m[1];
                        $unit = strtoupper($m[2]);
                        $bytes = $unit === 'GB' ? $value * 1024 * 1024 * 1024 : $value * 1024 * 1024;
                        $vramTotal += $bytes;
                    }
                }
                return [
                    'present' => true,
                    'kind' => 'metal',
                    'device_count' => $deviceCount,
                    'vram_total_bytes' => $vramTotal,
                    'vram_free_bytes' => 0, // Metal exposes no free VRAM query via system_profiler.
                ];
            }
        }
        // Metal is always present on Apple Silicon even if system_profiler
        // output is unparseable; we still refuse to fabricate VRAM numbers.
        if (str_contains($arch, 'arm64')) {
            return [
                'present' => true,
                'kind' => 'metal',
                'device_count' => 1,
                'vram_total_bytes' => 0,
                'vram_free_bytes' => 0,
            ];
        }
        return $none;
    }

    if ($os === 'linux') {
        // NVIDIA via nvidia-smi.
        $nvRaw = model_inference_probe_run([
            '/usr/bin/nvidia-smi',
            '--query-gpu=memory.total,memory.free',
            '--format=csv,noheader,nounits',
        ], 2000);
        if ($nvRaw === null) {
            $nvRaw = model_inference_probe_run([
                'nvidia-smi',
                '--query-gpu=memory.total,memory.free',
                '--format=csv,noheader,nounits',
            ], 2000);
        }
        if ($nvRaw !== null && trim($nvRaw) !== '') {
            $lines = array_filter(array_map('trim', explode("\n", $nvRaw)), static fn ($l) => $l !== '');
            if ($lines !== []) {
                $vramTotal = 0;
                $vramFree = 0;
                foreach ($lines as $line) {
                    $cols = array_map('trim', explode(',', $line));
                    if (count($cols) >= 2 && is_numeric($cols[0]) && is_numeric($cols[1])) {
                        $vramTotal += ((int) $cols[0]) * 1024 * 1024; // MiB -> bytes
                        $vramFree += ((int) $cols[1]) * 1024 * 1024;
                    }
                }
                return [
                    'present' => true,
                    'kind' => 'cuda',
                    'device_count' => count($lines),
                    'vram_total_bytes' => $vramTotal,
                    'vram_free_bytes' => $vramFree,
                ];
            }
        }

        // AMD via rocminfo (exit-code probe; parsing VRAM here is best-effort).
        $rocmRaw = model_inference_probe_run(['/opt/rocm/bin/rocminfo'], 2000)
            ?? model_inference_probe_run(['rocminfo'], 2000);
        if ($rocmRaw !== null && stripos($rocmRaw, 'HSA_AGENT_TYPE_GPU') !== false) {
            $deviceCount = substr_count($rocmRaw, 'HSA_AGENT_TYPE_GPU');
            return [
                'present' => true,
                'kind' => 'rocm',
                'device_count' => $deviceCount,
                'vram_total_bytes' => 0,
                'vram_free_bytes' => 0,
            ];
        }
    }

    return $none;
}

/**
 * Build the full node-profile envelope for the current process.
 *
 * @param string $nodeId
 * @param string $serviceHealthUrl
 * @param string $status
 * @return array<string, mixed>
 */
function model_inference_hardware_profile(string $nodeId, string $serviceHealthUrl, string $status = 'ready'): array
{
    $platform = model_inference_probe_platform();
    $cpu = model_inference_probe_cpu($platform['os']);
    $memory = model_inference_probe_memory($platform['os']);
    $gpu = model_inference_probe_gpu($platform['os'], $platform['arch']);

    return [
        'node_id' => $nodeId,
        'king_version' => function_exists('king_version') ? (string) king_version() : 'n/a',
        'platform' => $platform,
        'cpu' => $cpu,
        'memory' => $memory,
        'gpu' => $gpu,
        'capabilities' => [
            'loadable_models' => [], // filled by M-5 registry
            'max_context_tokens' => 0, // filled by M-7 worker
            'supports_streaming' => true, // #M-11 will verify via WS
            'supports_quantizations' => ['Q2_K', 'Q3_K', 'Q4_0', 'Q4_K', 'Q5_K', 'Q6_K', 'Q8_0', 'F16'],
        ],
        'service' => [
            'service_type' => 'king.inference.v1',
            'health_url' => $serviceHealthUrl,
            'status' => in_array($status, ['ready', 'draining', 'starting', 'error'], true) ? $status : 'error',
        ],
        'published_at' => gmdate('c'),
    ];
}
