<?php
declare(strict_types=1);

$args = array_slice($argv, 1);
$jsonOutput = false;
$requireGpu = false;

foreach ($args as $arg) {
    if ($arg === '--json') {
        $jsonOutput = true;
        continue;
    }
    if ($arg === '--require-gpu') {
        $requireGpu = true;
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: king-inference-status [--json] [--require-gpu]\n");
        fwrite(STDOUT, "\n");
        fwrite(STDOUT, "Reports local King inference readiness from the active PHP INI profile.\n");
        fwrite(STDOUT, "--json         Emit the full machine-readable readiness report.\n");
        fwrite(STDOUT, "--require-gpu  Exit 2 unless the selected runtime profile is king_native_gpu.\n");
        exit(0);
    }
    fwrite(STDERR, "Unknown option: {$arg}\n");
    exit(64);
}

function king_status_bool(mixed $value): bool
{
    return is_bool($value) ? $value : !empty($value);
}

function king_status_string(mixed $value, string $fallback = ''): string
{
    return is_string($value) && $value !== '' ? $value : $fallback;
}

function king_status_bytes_to_gib(mixed $value): ?float
{
    return is_int($value) || is_float($value) ? ((float) $value) / 1024 / 1024 / 1024 : null;
}

function king_status_bytes_to_mib(mixed $value): ?float
{
    return is_int($value) || is_float($value) ? ((float) $value) / 1024 / 1024 : null;
}

function king_status_line(string $label, string $value): void
{
    fwrite(STDOUT, str_pad($label . ':', 34) . $value . "\n");
}

function king_status_yes_no(bool $value): string
{
    return $value ? 'yes' : 'no';
}

function king_status_report_human(array $report): void
{
    fwrite(STDOUT, "King inference readiness\n");
    fwrite(STDOUT, "========================\n");
    king_status_line('Status', $report['ready'] ? 'READY' : 'NOT READY');
    king_status_line('Exit code', (string) $report['exit_code']);
    if ($report['reason'] !== '') {
        king_status_line('Reason', $report['reason']);
    }
    if ($report['refusal_reasons'] !== []) {
        king_status_line('Refusal reasons', implode(', ', $report['refusal_reasons']));
    }

    fwrite(STDOUT, "\nRuntime profile\n");
    king_status_line('Requested profile', $report['runtime']['requested_profile']);
    king_status_line('Selected profile', $report['runtime']['selected_profile']);
    king_status_line('Selected backend', $report['runtime']['backend']);
    king_status_line('Selected model', $report['runtime']['model']);
    king_status_line('Require GPU', king_status_yes_no($report['require_gpu']));

    fwrite(STDOUT, "\nModel\n");
    king_status_line('Loaded', king_status_yes_no($report['model']['loaded']));
    king_status_line('Artifact', $report['model']['artifact_path']);
    king_status_line('Silent CPU fallback', king_status_yes_no($report['model']['silent_cpu_fallback']));
    king_status_line('Generation ready', king_status_yes_no($report['model']['generation_ready']));

    if ($report['gpu']['present']) {
        fwrite(STDOUT, "\nGPU\n");
        king_status_line('Backend', $report['gpu']['backend']);
        king_status_line('Config ready', king_status_yes_no($report['gpu']['config_ready']));
        king_status_line('Generation ready', king_status_yes_no($report['gpu']['generation_ready']));
        king_status_line('Thermal monitored', king_status_yes_no($report['gpu']['thermal_monitored']));
        king_status_line('VRAM admitted', king_status_yes_no($report['gpu']['runtime_vram_fits_free']));
        king_status_line('System RAM offload', $report['gpu']['system_ram_offload_status']);
        if ($report['gpu']['system_ram_offload_required_mib'] !== null) {
            king_status_line(
                'Offload required',
                sprintf('%.2f MiB', $report['gpu']['system_ram_offload_required_mib'])
            );
        }
        king_status_line('Offload max', $report['gpu']['system_ram_offload_max_mb'] . ' MiB');
        king_status_line('Offload min free RAM', $report['gpu']['system_ram_offload_min_free_mb'] . ' MiB');
        if ($report['gpu']['system_ram_offload_error'] !== 'none') {
            king_status_line('Offload error', $report['gpu']['system_ram_offload_error']);
        }
        if ($report['gpu']['device_name'] !== '') {
            king_status_line('Device', $report['gpu']['device_name']);
        }
        if ($report['gpu']['free_vram_after_reserve_gib'] !== null) {
            king_status_line(
                'Free VRAM after reserve',
                sprintf('%.2f GiB', $report['gpu']['free_vram_after_reserve_gib'])
            );
        }
    }

    fwrite(STDOUT, "\nRouter\n");
    king_status_line('Model listing ready', king_status_yes_no($report['router']['model_listing_ready']));
    king_status_line('OpenAI chat ready', king_status_yes_no($report['router']['openai_chat_ready']));
    king_status_line('GPU generation ready', king_status_yes_no($report['router']['gpu_generation_ready']));

    if ($report['error'] !== '') {
        fwrite(STDOUT, "\nError\n");
        fwrite(STDOUT, $report['error'] . "\n");
    }
}

$report = [
    'ready' => false,
    'exit_code' => 1,
    'reason' => 'not_evaluated',
    'refusal_reasons' => [],
    'require_gpu' => $requireGpu,
    'runtime' => [
        'requested_profile' => '',
        'selected_profile' => '',
        'backend' => '',
        'model' => '',
        'gpu_profile_available' => false,
    ],
    'model' => [
        'loaded' => false,
        'name' => '',
        'backend' => '',
        'artifact_path' => '',
        'artifact_bytes' => 0,
        'silent_cpu_fallback' => false,
        'generation_ready' => false,
    ],
    'gpu' => [
        'present' => false,
        'backend' => '',
        'config_ready' => false,
        'generation_ready' => false,
        'thermal_monitored' => false,
        'runtime_vram_fits_free' => false,
        'system_ram_offload_allowed' => false,
        'system_ram_offload_required' => false,
        'system_ram_offload_required_mib' => null,
        'system_ram_offload_max_mb' => 0,
        'system_ram_offload_min_free_mb' => 0,
        'system_ram_offload_status' => 'unknown',
        'system_ram_offload_error' => 'unknown',
        'device_name' => '',
        'free_vram_after_reserve_gib' => null,
        'reason' => '',
        'refusal_reasons' => [],
    ],
    'router' => [
        'model_listing_ready' => false,
        'openai_chat_ready' => false,
        'gpu_generation_ready' => false,
    ],
    'error' => '',
];

try {
    $gpuStatus = king_inference_gpu_runtime_status();
    $modelConfig = king_inference_runtime_model_config();

    $report['runtime']['requested_profile'] = king_status_string($modelConfig['runtime_requested_profile'] ?? null);
    $report['runtime']['selected_profile'] = king_status_string($modelConfig['runtime_profile'] ?? null);
    $report['runtime']['backend'] = king_status_string($modelConfig['backend'] ?? null);
    $report['runtime']['model'] = king_status_string($modelConfig['name'] ?? null);
    $report['runtime']['gpu_profile_available'] = king_status_bool($modelConfig['runtime_gpu_profile_available'] ?? false);

    $report['gpu']['present'] = true;
    $report['gpu']['backend'] = king_status_string($gpuStatus['backend'] ?? null);
    $report['gpu']['config_ready'] = king_status_bool($gpuStatus['config_ready'] ?? false);
    $report['gpu']['generation_ready'] = king_status_bool($gpuStatus['generation_ready'] ?? false);
    $report['gpu']['thermal_monitored'] = king_status_bool($gpuStatus['thermal']['monitored'] ?? false);
    $report['gpu']['runtime_vram_fits_free'] = king_status_bool($gpuStatus['runtime_vram_fits_free'] ?? false);
    $report['gpu']['system_ram_offload_allowed'] = king_status_bool($gpuStatus['system_ram_offload_allowed'] ?? false);
    $report['gpu']['system_ram_offload_required'] = king_status_bool($gpuStatus['system_ram_offload_required'] ?? false);
    $report['gpu']['system_ram_offload_required_mib'] = king_status_bytes_to_mib($gpuStatus['system_ram_offload_required_bytes'] ?? null);
    $report['gpu']['system_ram_offload_max_mb'] = is_int($gpuStatus['system_ram_offload_max_mb'] ?? null) ? $gpuStatus['system_ram_offload_max_mb'] : 0;
    $report['gpu']['system_ram_offload_min_free_mb'] = is_int($gpuStatus['system_ram_offload_min_free_mb'] ?? null) ? $gpuStatus['system_ram_offload_min_free_mb'] : 0;
    $report['gpu']['system_ram_offload_status'] = king_status_string($gpuStatus['system_ram_offload_status'] ?? null, 'unknown');
    $report['gpu']['system_ram_offload_error'] = king_status_string($gpuStatus['system_ram_offload_error'] ?? null, 'unknown');
    $report['gpu']['device_name'] = king_status_string($gpuStatus['cuda_driver']['device_name'] ?? null);
    $report['gpu']['free_vram_after_reserve_gib'] = king_status_bytes_to_gib(
        $gpuStatus['free_vram_after_reserve_bytes'] ?? null
    );
    $report['gpu']['reason'] = king_status_string($gpuStatus['reason'] ?? null);
    $report['gpu']['refusal_reasons'] = is_array($gpuStatus['refusal_reasons'] ?? null)
        ? array_values($gpuStatus['refusal_reasons'])
        : [];

    if ($requireGpu && $report['runtime']['backend'] !== 'king_native_gpu') {
        $report['exit_code'] = 2;
        $report['reason'] = 'gpu_profile_not_selected';
        $report['refusal_reasons'] = ['gpu_profile_not_selected'];
    } else {
        $model = king_inference_runtime_model_load();
        $info = king_inference_model_info($model);

        $report['model']['loaded'] = true;
        $report['model']['name'] = king_status_string($info['name'] ?? null);
        $report['model']['backend'] = king_status_string($info['backend'] ?? null);
        $report['model']['artifact_path'] = king_status_string($info['artifact_path'] ?? null);
        $report['model']['artifact_bytes'] = is_int($info['artifact_bytes'] ?? null) ? $info['artifact_bytes'] : 0;
        $report['model']['silent_cpu_fallback'] = king_status_bool($info['silent_cpu_fallback'] ?? false);

        if ($report['model']['backend'] === 'king_native_gpu') {
            $runtime = is_array($info['gpu_runtime'] ?? null) ? $info['gpu_runtime'] : [];
            $report['gpu']['present'] = true;
            $report['gpu']['backend'] = king_status_string($runtime['backend'] ?? null, $report['gpu']['backend']);
            $report['gpu']['config_ready'] = king_status_bool($runtime['config_ready'] ?? false);
            $report['gpu']['generation_ready'] = king_status_bool($runtime['generation_ready'] ?? false);
            $report['gpu']['thermal_monitored'] = king_status_bool($runtime['thermal']['monitored'] ?? false);
            $report['gpu']['runtime_vram_fits_free'] = king_status_bool($runtime['runtime_vram_fits_free'] ?? false);
            $report['gpu']['system_ram_offload_allowed'] = king_status_bool($runtime['system_ram_offload_allowed'] ?? false);
            $report['gpu']['system_ram_offload_required'] = king_status_bool($runtime['system_ram_offload_required'] ?? false);
            $report['gpu']['system_ram_offload_required_mib'] = king_status_bytes_to_mib($runtime['system_ram_offload_required_bytes'] ?? null);
            $report['gpu']['system_ram_offload_max_mb'] = is_int($runtime['system_ram_offload_max_mb'] ?? null) ? $runtime['system_ram_offload_max_mb'] : 0;
            $report['gpu']['system_ram_offload_min_free_mb'] = is_int($runtime['system_ram_offload_min_free_mb'] ?? null) ? $runtime['system_ram_offload_min_free_mb'] : 0;
            $report['gpu']['system_ram_offload_status'] = king_status_string($runtime['system_ram_offload_status'] ?? null, 'unknown');
            $report['gpu']['system_ram_offload_error'] = king_status_string($runtime['system_ram_offload_error'] ?? null, 'unknown');
            $report['gpu']['device_name'] = king_status_string(
                $runtime['cuda_driver']['device_name'] ?? null,
                $report['gpu']['device_name']
            );
            $report['gpu']['free_vram_after_reserve_gib'] = king_status_bytes_to_gib(
                $runtime['free_vram_after_reserve_bytes'] ?? null
            );
            $report['gpu']['reason'] = king_status_string($runtime['reason'] ?? null);
            $report['gpu']['refusal_reasons'] = is_array($runtime['refusal_reasons'] ?? null)
                ? array_values($runtime['refusal_reasons'])
                : [];
            $report['model']['generation_ready'] = $report['gpu']['generation_ready'];
        } else {
            $report['model']['generation_ready'] = king_status_bool(
                $info['openai_generation'] ?? ($info['token_generation_ready'] ?? false)
            );
        }

        $response = king_inference_openai_http_response(
            [$report['model']['name'] => $model],
            ['method' => 'GET', 'path' => '/v1/models']
        );
        $payload = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
        $listed = $payload['data'][0]['x_king'] ?? [];
        $client = is_array($listed['client_capabilities'] ?? null) ? $listed['client_capabilities'] : [];

        $report['router']['model_listing_ready'] = ($response['status'] ?? 0) === 200;
        $report['router']['openai_chat_ready'] = king_status_bool($client['openai_chat_completions'] ?? false);
        $report['router']['gpu_generation_ready'] = king_status_bool($client['gpu_generation_ready'] ?? false);

        if ($report['model']['backend'] === 'king_native_gpu') {
            $ready = $report['model']['generation_ready']
                && $report['router']['gpu_generation_ready']
                && !$report['model']['silent_cpu_fallback'];
        } else {
            $ready = $report['model']['generation_ready']
                && $report['router']['openai_chat_ready']
                && !$report['model']['silent_cpu_fallback'];
        }

        $report['ready'] = $ready;
        $report['exit_code'] = $ready ? 0 : 3;
        $report['reason'] = $ready ? 'ready' : ($report['gpu']['reason'] !== '' ? $report['gpu']['reason'] : 'generation_not_ready');
        $report['refusal_reasons'] = $ready ? [] : (
            $report['gpu']['refusal_reasons'] !== [] ? $report['gpu']['refusal_reasons'] : [$report['reason']]
        );
    }
} catch (Throwable $exception) {
    $report['ready'] = false;
    $report['exit_code'] = 1;
    $report['reason'] = 'runtime_error';
    $report['refusal_reasons'] = ['runtime_error'];
    $report['error'] = $exception::class . ': ' . $exception->getMessage();
}

if ($jsonOutput) {
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
} else {
    king_status_report_human($report);
}

exit($report['exit_code']);
