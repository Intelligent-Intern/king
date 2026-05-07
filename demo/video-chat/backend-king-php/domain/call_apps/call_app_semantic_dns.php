<?php

declare(strict_types=1);

function videochat_call_app_package_root(?string $root = null): string
{
    $trimmed = trim((string) ($root ?? ''));
    if ($trimmed !== '') {
        return rtrim($trimmed, DIRECTORY_SEPARATOR);
    }

    return dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'call-app';
}

/**
 * @return array{ok: bool, data?: array<string, mixed>, reason?: string}
 */
function videochat_call_app_read_json_file(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        return ['ok' => false, 'reason' => 'file_not_readable'];
    }

    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        return ['ok' => false, 'reason' => 'file_read_failed'];
    }

    try {
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return ['ok' => false, 'reason' => 'invalid_json'];
    }

    if (!is_array($decoded)) {
        return ['ok' => false, 'reason' => 'json_must_be_object'];
    }

    return ['ok' => true, 'data' => $decoded];
}

/**
 * @return array<int, string>
 */
function videochat_call_app_string_list(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $output = [];
    foreach ($value as $item) {
        $trimmed = trim((string) $item);
        if ($trimmed !== '' && !in_array($trimmed, $output, true)) {
            $output[] = $trimmed;
        }
    }

    return $output;
}

/**
 * @return array<int, string>
 */
function videochat_call_app_mcp_method_names(array $mcpDescriptor): array
{
    $methods = is_array($mcpDescriptor['methods'] ?? null) ? $mcpDescriptor['methods'] : [];
    $names = [];
    foreach ($methods as $method) {
        if (!is_array($method)) {
            continue;
        }
        $name = trim((string) ($method['name'] ?? ''));
        if ($name !== '' && !in_array($name, $names, true)) {
            $names[] = $name;
        }
    }

    return $names;
}

/**
 * @return array<int, string>
 */
function videochat_call_app_export_formats(array $manifest): array
{
    $exports = is_array($manifest['exports'] ?? null) ? $manifest['exports'] : [];
    $formats = [];
    foreach ($exports as $export) {
        if (!is_array($export)) {
            continue;
        }
        $format = trim((string) ($export['format'] ?? ''));
        if ($format !== '' && !in_array($format, $formats, true)) {
            $formats[] = $format;
        }
    }

    return $formats;
}

/**
 * @return array<string, mixed>
 */
function videochat_call_app_load_package(string $packageDir): array
{
    $packageDir = rtrim($packageDir, DIRECTORY_SEPARATOR);
    $files = [
        'manifest' => 'call-app.manifest.json',
        'mcp_descriptor' => 'mcp.descriptor.json',
        'crdt_schema' => 'crdt.schema.json',
        'health_descriptor' => 'health.descriptor.json',
    ];
    $errors = [];
    $data = [];

    foreach ($files as $key => $file) {
        $result = videochat_call_app_read_json_file($packageDir . DIRECTORY_SEPARATOR . $file);
        if (!(bool) ($result['ok'] ?? false)) {
            $errors[$file] = (string) ($result['reason'] ?? 'invalid_file');
            continue;
        }
        $data[$key] = $result['data'] ?? [];
    }

    $manifest = is_array($data['manifest'] ?? null) ? $data['manifest'] : [];
    $mcpDescriptor = is_array($data['mcp_descriptor'] ?? null) ? $data['mcp_descriptor'] : [];
    $crdtSchema = is_array($data['crdt_schema'] ?? null) ? $data['crdt_schema'] : [];
    $healthDescriptor = is_array($data['health_descriptor'] ?? null) ? $data['health_descriptor'] : [];
    $appKey = trim((string) ($manifest['app_key'] ?? basename($packageDir)));
    $version = trim((string) ($manifest['version'] ?? ''));

    if ($appKey === '') {
        $errors['app_key'] = 'required';
    }
    if ($version === '') {
        $errors['version'] = 'required';
    }
    if (trim((string) ($manifest['semantic_dns']['service_type'] ?? '')) !== 'call_app') {
        $errors['semantic_dns.service_type'] = 'must_be_call_app';
    }
    if ((bool) ($manifest['semantic_dns']['mother_node_registration']['required'] ?? false) !== true) {
        $errors['semantic_dns.mother_node_registration.required'] = 'must_be_true';
    }
    if (trim((string) ($manifest['marketplace']['order_scope'] ?? '')) !== 'organization') {
        $errors['marketplace.order_scope'] = 'must_be_organization';
    }
    if (trim((string) ($mcpDescriptor['app_key'] ?? '')) !== $appKey) {
        $errors['mcp_descriptor.app_key'] = 'must_match_manifest';
    }
    if (trim((string) ($crdtSchema['app_key'] ?? '')) !== $appKey) {
        $errors['crdt_schema.app_key'] = 'must_match_manifest';
    }
    if (trim((string) ($healthDescriptor['app_key'] ?? '')) !== $appKey) {
        $errors['health_descriptor.app_key'] = 'must_match_manifest';
    }

    $checks = is_array($healthDescriptor['checks'] ?? null) ? $healthDescriptor['checks'] : [];
    foreach ($checks as $check) {
        if (!is_array($check) || (bool) ($check['required'] ?? false) !== true) {
            continue;
        }
        $path = trim((string) ($check['path'] ?? ''));
        if ($path === '' || !is_file($packageDir . DIRECTORY_SEPARATOR . $path)) {
            $errors['health.' . ($path !== '' ? $path : 'path')] = 'required_check_failed';
        }
    }

    $metadataForHash = [
        'manifest' => $manifest,
        'mcp_descriptor' => $mcpDescriptor,
        'crdt_schema' => $crdtSchema,
        'health_descriptor' => $healthDescriptor,
    ];

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'app_key' => $appKey,
        'version' => $version,
        'package_dir' => $packageDir,
        'manifest' => $manifest,
        'mcp_descriptor' => $mcpDescriptor,
        'crdt_schema' => $crdtSchema,
        'health_descriptor' => $healthDescriptor,
        'health_status' => $errors === [] ? 'healthy' : 'unhealthy',
        'metadata_hash' => hash('sha256', json_encode($metadataForHash, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
    ];
}
/**
 * @return array<int, array<string, mixed>>
 */
function videochat_call_app_scan_packages(?string $packageRoot = null): array
{
    $root = videochat_call_app_package_root($packageRoot);
    if (!is_dir($root)) {
        return [];
    }

    $packages = [];
    $entries = scandir($root);
    if (!is_array($entries)) {
        return [];
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $packageDir = $root . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($packageDir) && is_file($packageDir . DIRECTORY_SEPARATOR . 'call-app.manifest.json')) {
            $packages[] = videochat_call_app_load_package($packageDir);
        }
    }

    usort($packages, static fn (array $left, array $right): int => strcmp((string) ($left['app_key'] ?? ''), (string) ($right['app_key'] ?? '')));
    return $packages;
}

/**
 * @return array<string, mixed>
 */
function videochat_call_app_semantic_dns_service_payload(array $package, array $options = []): array
{
    $manifest = is_array($package['manifest'] ?? null) ? $package['manifest'] : [];
    $mcpDescriptor = is_array($package['mcp_descriptor'] ?? null) ? $package['mcp_descriptor'] : [];
    $crdtSchema = is_array($package['crdt_schema'] ?? null) ? $package['crdt_schema'] : [];
    $appKey = trim((string) ($package['app_key'] ?? ''));
    $version = trim((string) ($package['version'] ?? ''));
    $hostname = trim((string) ($options['hostname'] ?? 'localhost'));
    $port = (int) ($options['port'] ?? 443);
    if ($hostname === '') {
        $hostname = 'localhost';
    }
    if ($port < 1 || $port > 65535) {
        $port = 443;
    }

    $serviceName = trim((string) ($manifest['semantic_dns']['service_name'] ?? ('call_app.' . $appKey)));
    $mcpServiceName = trim((string) ($mcpDescriptor['service_name'] ?? ($serviceName . '.mcp')));
    $iframeEntrypoint = trim((string) ($manifest['iframe']['entrypoint'] ?? ($manifest['entrypoints']['iframe'] ?? 'public/index.html')));
    $mcpEndpoint = trim((string) ($options['mcp_endpoint'] ?? ('mcp://' . $mcpServiceName)));
    $status = (string) ($package['health_status'] ?? 'unknown');

    return [
        'service_id' => 'call_app.' . $appKey . '.' . $version,
        'service_name' => $serviceName,
        'service_type' => 'call_app',
        'hostname' => $hostname,
        'port' => $port,
        'status' => in_array($status, ['healthy', 'degraded', 'unhealthy', 'maintenance', 'unknown'], true) ? $status : 'unknown',
        'current_load_percent' => 0,
        'active_connections' => 0,
        'total_requests' => 0,
        'attributes' => [
            'app_key' => $appKey,
            'app_version' => $version,
            'category' => (string) ($manifest['category'] ?? 'other'),
            'mcp_endpoint' => $mcpEndpoint,
            'mcp_service_name' => $mcpServiceName,
            'iframe_entrypoint' => $iframeEntrypoint,
            'crdt_protocol' => (string) ($crdtSchema['protocol'] ?? ''),
            'marketplace_order_scope' => (string) ($manifest['marketplace']['order_scope'] ?? ''),
            'marketplace_metadata_hash' => (string) ($package['metadata_hash'] ?? ''),
            'mother_node_registration_required' => (bool) ($manifest['semantic_dns']['mother_node_registration']['required'] ?? false),
            'capabilities_csv' => implode(',', videochat_call_app_string_list($manifest['permissions'] ?? [])),
            'mcp_methods_csv' => implode(',', videochat_call_app_mcp_method_names($mcpDescriptor)),
            'export_formats_csv' => implode(',', videochat_call_app_export_formats($manifest)),
            'organization_availability' => (bool) ($manifest['marketplace']['requires_installation'] ?? true) ? 'requires_installation' : 'available',
        ],
    ];
}

/**
 * @return array{ok: bool, errors: array<string, string>}
 */
function videochat_call_app_validate_semantic_dns_payload(array $payload): array
{
    $errors = [];
    foreach (['service_id', 'service_name', 'service_type', 'hostname'] as $field) {
        if (trim((string) ($payload[$field] ?? '')) === '') {
            $errors[$field] = 'required';
        }
    }
    if ((string) ($payload['service_type'] ?? '') !== 'call_app') {
        $errors['service_type'] = 'must_be_call_app';
    }
    $port = (int) ($payload['port'] ?? 0);
    if ($port < 1 || $port > 65535) {
        $errors['port'] = 'must_be_between_1_and_65535';
    }
    $attributes = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
    foreach (['app_key', 'app_version', 'mcp_endpoint', 'crdt_protocol', 'marketplace_metadata_hash'] as $field) {
        if (trim((string) ($attributes[$field] ?? '')) === '') {
            $errors['attributes.' . $field] = 'required';
        }
    }

    return ['ok' => $errors === [], 'errors' => $errors];
}

/**
 * @param array<int, array<string, mixed>> $payloads
 * @return array<string, mixed>
 */
function videochat_call_app_register_semantic_dns_services(array $payloads, ?callable $registerService = null): array
{
    $registrationAvailable = $registerService !== null || function_exists('king_semantic_dns_register_service');
    if ($registerService === null && function_exists('king_semantic_dns_register_service')) {
        $registerService = 'king_semantic_dns_register_service';
    }

    $registered = [];
    $errors = [];
    foreach ($payloads as $payload) {
        $validation = videochat_call_app_validate_semantic_dns_payload($payload);
        if (!(bool) ($validation['ok'] ?? false)) {
            $errors[] = ['service_id' => (string) ($payload['service_id'] ?? ''), 'errors' => $validation['errors'] ?? []];
            continue;
        }
        if ($registerService === null) {
            continue;
        }
        try {
            $ok = (bool) $registerService($payload);
        } catch (Throwable $error) {
            $ok = false;
            $errors[] = ['service_id' => (string) ($payload['service_id'] ?? ''), 'error' => $error->getMessage()];
        }
        if ($ok) {
            $registered[] = (string) ($payload['service_id'] ?? '');
        }
    }

    return [
        'ok' => $errors === [] && ($registerService !== null || !$registrationAvailable),
        'registration_available' => $registrationAvailable,
        'registered' => $registered,
        'errors' => $errors,
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_call_app_refresh_semantic_dns_catalog(?string $packageRoot = null, array $options = []): array
{
    $packages = videochat_call_app_scan_packages($packageRoot);
    $payloads = [];
    $invalidPackages = [];
    foreach ($packages as $package) {
        if (!(bool) ($package['ok'] ?? false)) {
            $invalidPackages[] = [
                'app_key' => (string) ($package['app_key'] ?? ''),
                'errors' => $package['errors'] ?? [],
            ];
            continue;
        }
        $payloads[] = videochat_call_app_semantic_dns_service_payload($package, $options);
    }

    $registration = ['ok' => true, 'registration_available' => function_exists('king_semantic_dns_register_service'), 'registered' => [], 'errors' => []];
    if ((bool) ($options['register'] ?? false)) {
        $registration = videochat_call_app_register_semantic_dns_services(
            $payloads,
            is_callable($options['register_service'] ?? null) ? $options['register_service'] : null
        );
    }

    return [
        'ok' => $invalidPackages === [] && (bool) ($registration['ok'] ?? false),
        'packages' => $packages,
        'service_payloads' => $payloads,
        'invalid_packages' => $invalidPackages,
        'registration' => $registration,
        'time' => gmdate('c'),
    ];
}
