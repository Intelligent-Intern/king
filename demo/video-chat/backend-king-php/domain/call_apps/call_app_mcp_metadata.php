<?php

declare(strict_types=1);

require_once __DIR__ . '/call_app_semantic_dns.php';

/**
 * @return array<int, string>
 */
function videochat_call_app_mcp_supported_methods(): array
{
    return [
        'call_app.describe',
        'call_app.capabilities',
        'call_app.crdt_schema',
        'call_app.launch_contract',
        'call_app.health',
        'call_app.export_formats',
        'call_app.marketplace_listing',
    ];
}

/**
 * @return array<string, string>
 */
function videochat_call_app_mcp_response_schemas(): array
{
    return [
        'call_app.describe' => 'king.call_app.describe.v1',
        'call_app.capabilities' => 'king.call_app.capabilities.v1',
        'call_app.crdt_schema' => 'king.call_app.crdt_schema.v1',
        'call_app.launch_contract' => 'king.call_app.launch_contract.v1',
        'call_app.health' => 'king.call_app.health.v1',
        'call_app.export_formats' => 'king.call_app.export_formats.v1',
        'call_app.marketplace_listing' => 'king.call_app.marketplace_listing.v1',
    ];
}

/**
 * @return array<int, string>
 */
function videochat_call_app_mcp_manifest_export_formats(array $manifest): array
{
    return videochat_call_app_export_formats($manifest);
}

/**
 * @return array<int, string>
 */
function videochat_call_app_mcp_crdt_export_formats(array $crdtSchema): array
{
    return videochat_call_app_string_list($crdtSchema['exports'] ?? []);
}

/**
 * @return array<int, string>
 */
function videochat_call_app_mcp_health_check_paths(array $healthDescriptor): array
{
    $checks = is_array($healthDescriptor['checks'] ?? null) ? $healthDescriptor['checks'] : [];
    $paths = [];
    foreach ($checks as $check) {
        if (!is_array($check)) {
            continue;
        }
        $path = trim((string) ($check['path'] ?? ''));
        if ($path !== '' && !in_array($path, $paths, true)) {
            $paths[] = $path;
        }
    }

    return $paths;
}

/**
 * @return array{ok: bool, errors: array<string, string>}
 */
function videochat_call_app_mcp_validate_package(array $package): array
{
    $errors = [];
    if (!(bool) ($package['ok'] ?? false)) {
        $packageErrors = is_array($package['errors'] ?? null) ? $package['errors'] : [];
        foreach ($packageErrors as $field => $reason) {
            $errors['package.' . (string) $field] = (string) $reason;
        }
        if ($errors === []) {
            $errors['package'] = 'invalid';
        }
    }

    $manifest = is_array($package['manifest'] ?? null) ? $package['manifest'] : [];
    $mcpDescriptor = is_array($package['mcp_descriptor'] ?? null) ? $package['mcp_descriptor'] : [];
    $crdtSchema = is_array($package['crdt_schema'] ?? null) ? $package['crdt_schema'] : [];
    $healthDescriptor = is_array($package['health_descriptor'] ?? null) ? $package['health_descriptor'] : [];
    $appKey = trim((string) ($package['app_key'] ?? ''));
    $version = trim((string) ($package['version'] ?? ''));
    $serviceName = trim((string) ($manifest['semantic_dns']['service_name'] ?? ''));
    $mcpServiceName = trim((string) ($mcpDescriptor['service_name'] ?? ''));

    if ($appKey === '') {
        $errors['app_key'] = 'required';
    }
    if ($version === '') {
        $errors['version'] = 'required';
    }
    if ((string) ($mcpDescriptor['schema_version'] ?? '') !== 'king.call_app.mcp_descriptor.v1') {
        $errors['mcp.schema_version'] = 'must_be_king_call_app_mcp_descriptor_v1';
    }
    if (trim((string) ($mcpDescriptor['app_key'] ?? '')) !== $appKey) {
        $errors['mcp.app_key'] = 'must_match_package';
    }
    if ((string) ($mcpDescriptor['protocol'] ?? '') !== 'king.mcp.v1') {
        $errors['mcp.protocol'] = 'must_be_king_mcp_v1';
    }
    if ($serviceName === '' || $mcpServiceName !== $serviceName . '.mcp') {
        $errors['mcp.service_name'] = 'must_match_semantic_dns_service_name';
    }

    $methodNames = videochat_call_app_mcp_method_names($mcpDescriptor);
    $schemasByMethod = [];
    $methods = is_array($mcpDescriptor['methods'] ?? null) ? $mcpDescriptor['methods'] : [];
    foreach ($methods as $method) {
        if (!is_array($method)) {
            continue;
        }
        $methodName = trim((string) ($method['name'] ?? ''));
        if ($methodName !== '') {
            $schemasByMethod[$methodName] = trim((string) ($method['response_schema'] ?? ''));
        }
    }

    foreach (videochat_call_app_mcp_supported_methods() as $methodName) {
        if (!in_array($methodName, $methodNames, true)) {
            $errors['mcp.methods.' . $methodName] = 'required';
            continue;
        }
        $expectedSchema = videochat_call_app_mcp_response_schemas()[$methodName] ?? '';
        if (($schemasByMethod[$methodName] ?? '') !== $expectedSchema) {
            $errors['mcp.methods.' . $methodName . '.response_schema'] = 'must_match_contract';
        }
    }

    $manifestPermissions = videochat_call_app_string_list($manifest['permissions'] ?? []);
    $mcpCapabilities = videochat_call_app_string_list($mcpDescriptor['capabilities'] ?? []);
    if ($mcpCapabilities === []) {
        $errors['mcp.capabilities'] = 'required';
    }
    foreach ($mcpCapabilities as $capability) {
        if (!in_array($capability, $manifestPermissions, true)) {
            $errors['mcp.capabilities.' . $capability] = 'must_be_manifest_permission';
        }
    }

    $launchContract = is_array($mcpDescriptor['launch_contract'] ?? null) ? $mcpDescriptor['launch_contract'] : [];
    $manifestIframe = is_array($manifest['iframe'] ?? null) ? $manifest['iframe'] : [];
    $manifestEntrypoint = trim((string) ($manifestIframe['entrypoint'] ?? ($manifest['entrypoints']['iframe'] ?? '')));
    if (trim((string) ($launchContract['iframe_entrypoint'] ?? '')) !== $manifestEntrypoint) {
        $errors['launch_contract.iframe_entrypoint'] = 'must_match_manifest';
    }
    if (trim((string) ($launchContract['bridge_protocol'] ?? '')) !== trim((string) ($manifestIframe['bridge_protocol'] ?? ''))) {
        $errors['launch_contract.bridge_protocol'] = 'must_match_manifest';
    }
    if ((bool) ($launchContract['launch_token_required'] ?? false) !== true) {
        $errors['launch_contract.launch_token_required'] = 'must_be_true';
    }
    if ((bool) ($launchContract['primary_session_token_allowed'] ?? true) !== false) {
        $errors['launch_contract.primary_session_token_allowed'] = 'must_be_false';
    }
    if ((bool) ($manifestIframe['receives_primary_session_token'] ?? true) !== false) {
        $errors['iframe.receives_primary_session_token'] = 'must_be_false';
    }

    if ((string) ($crdtSchema['schema_version'] ?? '') !== 'king.call_app.crdt_schema.v1') {
        $errors['crdt.schema_version'] = 'must_be_king_call_app_crdt_schema_v1';
    }
    if (trim((string) ($crdtSchema['app_key'] ?? '')) !== $appKey) {
        $errors['crdt.app_key'] = 'must_match_package';
    }
    if (trim((string) ($crdtSchema['protocol'] ?? '')) !== trim((string) ($manifest['crdt']['protocol'] ?? ''))) {
        $errors['crdt.protocol'] = 'must_match_manifest';
    }
    if (!is_array($crdtSchema['documents'] ?? null) || $crdtSchema['documents'] === []) {
        $errors['crdt.documents'] = 'required';
    }
    $manifestFormats = videochat_call_app_mcp_manifest_export_formats($manifest);
    $crdtFormats = videochat_call_app_mcp_crdt_export_formats($crdtSchema);
    sort($manifestFormats);
    sort($crdtFormats);
    if ($manifestFormats === [] || $manifestFormats !== $crdtFormats) {
        $errors['exports'] = 'manifest_and_crdt_exports_must_match';
    }

    if ((string) ($healthDescriptor['schema_version'] ?? '') !== 'king.call_app.health_descriptor.v1') {
        $errors['health.schema_version'] = 'must_be_king_call_app_health_descriptor_v1';
    }
    if (trim((string) ($healthDescriptor['app_key'] ?? '')) !== $appKey) {
        $errors['health.app_key'] = 'must_match_package';
    }
    if (videochat_call_app_mcp_health_check_paths($healthDescriptor) === []) {
        $errors['health.checks'] = 'required';
    }

    $marketplaceListing = is_array($mcpDescriptor['marketplace_listing'] ?? null) ? $mcpDescriptor['marketplace_listing'] : [];
    if (trim((string) ($marketplaceListing['name'] ?? '')) !== trim((string) ($manifest['name'] ?? ''))) {
        $errors['marketplace_listing.name'] = 'must_match_manifest';
    }
    if (trim((string) ($marketplaceListing['category'] ?? '')) !== trim((string) ($manifest['category'] ?? ''))) {
        $errors['marketplace_listing.category'] = 'must_match_manifest';
    }
    if (trim((string) ($marketplaceListing['order_scope'] ?? '')) !== 'organization') {
        $errors['marketplace_listing.order_scope'] = 'must_be_organization';
    }
    if (trim((string) ($marketplaceListing['summary'] ?? '')) === '') {
        $errors['marketplace_listing.summary'] = 'required';
    }

    return ['ok' => $errors === [], 'errors' => $errors];
}

function videochat_call_app_mcp_find_package(string $appKey, ?string $packageRoot = null): ?array
{
    $normalized = strtolower(trim($appKey));
    if ($normalized === '') {
        return null;
    }

    foreach (videochat_call_app_scan_packages($packageRoot) as $package) {
        if (strtolower(trim((string) ($package['app_key'] ?? ''))) === $normalized) {
            return $package;
        }
    }

    return null;
}

/**
 * @return array<string, mixed>
 */
function videochat_call_app_mcp_describe(array $package): array
{
    $manifest = is_array($package['manifest'] ?? null) ? $package['manifest'] : [];
    $mcpDescriptor = is_array($package['mcp_descriptor'] ?? null) ? $package['mcp_descriptor'] : [];

    return [
        'schema_version' => 'king.call_app.describe.v1',
        'app_key' => (string) ($package['app_key'] ?? ''),
        'version' => (string) ($package['version'] ?? ''),
        'name' => (string) ($manifest['name'] ?? ''),
        'status' => (string) ($package['health_status'] ?? 'unknown'),
        'category' => (string) ($manifest['category'] ?? ''),
        'manufacturer' => (string) ($manifest['manufacturer'] ?? ''),
        'description' => (string) ($manifest['description'] ?? ''),
        'service_name' => (string) ($manifest['semantic_dns']['service_name'] ?? ''),
        'mcp_service_name' => (string) ($mcpDescriptor['service_name'] ?? ''),
        'mcp_methods' => videochat_call_app_mcp_method_names($mcpDescriptor),
        'metadata_hash' => (string) ($package['metadata_hash'] ?? ''),
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_call_app_mcp_capabilities(array $package): array
{
    $manifest = is_array($package['manifest'] ?? null) ? $package['manifest'] : [];
    $mcpDescriptor = is_array($package['mcp_descriptor'] ?? null) ? $package['mcp_descriptor'] : [];

    return [
        'schema_version' => 'king.call_app.capabilities.v1',
        'app_key' => (string) ($package['app_key'] ?? ''),
        'capabilities' => videochat_call_app_string_list($mcpDescriptor['capabilities'] ?? []),
        'permissions' => videochat_call_app_string_list($manifest['permissions'] ?? []),
        'default_participant_access' => (string) ($manifest['default_participant_access'] ?? ''),
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_call_app_mcp_launch_contract(array $package): array
{
    $manifest = is_array($package['manifest'] ?? null) ? $package['manifest'] : [];
    $mcpDescriptor = is_array($package['mcp_descriptor'] ?? null) ? $package['mcp_descriptor'] : [];
    $launchContract = is_array($mcpDescriptor['launch_contract'] ?? null) ? $mcpDescriptor['launch_contract'] : [];

    return [
        'schema_version' => 'king.call_app.launch_contract.v1',
        'app_key' => (string) ($package['app_key'] ?? ''),
        'version' => (string) ($package['version'] ?? ''),
        'iframe_entrypoint' => (string) ($launchContract['iframe_entrypoint'] ?? ''),
        'iframe_sandbox' => videochat_call_app_string_list($manifest['iframe']['sandbox'] ?? []),
        'bridge_protocol' => (string) ($launchContract['bridge_protocol'] ?? ''),
        'launch_token_required' => (bool) ($launchContract['launch_token_required'] ?? false),
        'launch_token_transport' => (string) ($manifest['iframe']['launch_token_transport'] ?? ''),
        'primary_session_token_allowed' => (bool) ($launchContract['primary_session_token_allowed'] ?? true),
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_call_app_mcp_health(array $package): array
{
    $healthDescriptor = is_array($package['health_descriptor'] ?? null) ? $package['health_descriptor'] : [];
    $checks = is_array($healthDescriptor['checks'] ?? null) ? $healthDescriptor['checks'] : [];

    return [
        'schema_version' => 'king.call_app.health.v1',
        'app_key' => (string) ($package['app_key'] ?? ''),
        'status' => (string) ($package['health_status'] ?? 'unknown'),
        'checks' => array_values(array_map(static function (array $check): array {
            return [
                'name' => (string) ($check['name'] ?? ''),
                'type' => (string) ($check['type'] ?? ''),
                'path' => (string) ($check['path'] ?? ''),
                'required' => (bool) ($check['required'] ?? false),
            ];
        }, array_filter($checks, 'is_array'))),
        'errors' => is_array($package['errors'] ?? null) ? $package['errors'] : [],
    ];
}

/**
 * @return array<int, array<string, string>>
 */
function videochat_call_app_mcp_export_formats(array $package): array
{
    $manifest = is_array($package['manifest'] ?? null) ? $package['manifest'] : [];
    $exports = is_array($manifest['exports'] ?? null) ? $manifest['exports'] : [];
    $formats = [];
    foreach ($exports as $export) {
        if (!is_array($export)) {
            continue;
        }
        $format = trim((string) ($export['format'] ?? ''));
        $mimeType = trim((string) ($export['mime_type'] ?? ''));
        if ($format !== '' && $mimeType !== '') {
            $formats[] = ['format' => $format, 'mime_type' => $mimeType];
        }
    }

    return $formats;
}

/**
 * @return array<string, mixed>
 */
function videochat_call_app_mcp_marketplace_listing(array $package): array
{
    $manifest = is_array($package['manifest'] ?? null) ? $package['manifest'] : [];
    $mcpDescriptor = is_array($package['mcp_descriptor'] ?? null) ? $package['mcp_descriptor'] : [];
    $listing = is_array($mcpDescriptor['marketplace_listing'] ?? null) ? $mcpDescriptor['marketplace_listing'] : [];

    return [
        'schema_version' => 'king.call_app.marketplace_listing.v1',
        'app_key' => (string) ($package['app_key'] ?? ''),
        'version' => (string) ($package['version'] ?? ''),
        'name' => (string) ($listing['name'] ?? ''),
        'category' => (string) ($listing['category'] ?? ''),
        'summary' => (string) ($listing['summary'] ?? ''),
        'order_scope' => (string) ($listing['order_scope'] ?? ''),
        'requires_installation' => (bool) ($manifest['marketplace']['requires_installation'] ?? true),
        'categories' => videochat_call_app_string_list($manifest['marketplace']['categories'] ?? []),
        'default_license' => (string) ($manifest['marketplace']['default_license'] ?? ''),
        'metadata_hash' => (string) ($package['metadata_hash'] ?? ''),
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_call_app_mcp_result_for_method(array $package, string $method): array
{
    if ($method === 'call_app.describe') {
        return videochat_call_app_mcp_describe($package);
    }
    if ($method === 'call_app.capabilities') {
        return videochat_call_app_mcp_capabilities($package);
    }
    if ($method === 'call_app.crdt_schema') {
        return is_array($package['crdt_schema'] ?? null) ? $package['crdt_schema'] : [];
    }
    if ($method === 'call_app.launch_contract') {
        return videochat_call_app_mcp_launch_contract($package);
    }
    if ($method === 'call_app.health') {
        return videochat_call_app_mcp_health($package);
    }
    if ($method === 'call_app.export_formats') {
        return [
            'schema_version' => 'king.call_app.export_formats.v1',
            'app_key' => (string) ($package['app_key'] ?? ''),
            'formats' => videochat_call_app_mcp_export_formats($package),
        ];
    }
    if ($method === 'call_app.marketplace_listing') {
        return videochat_call_app_mcp_marketplace_listing($package);
    }

    return [];
}

/**
 * @return array<string, mixed>
 */
function videochat_call_app_mcp_handle_request(array $request, ?string $packageRoot = null): array
{
    $method = trim((string) ($request['method'] ?? ''));
    $params = is_array($request['params'] ?? null) ? $request['params'] : [];
    $appKey = trim((string) ($params['app_key'] ?? $request['app_key'] ?? ''));

    if (!in_array($method, videochat_call_app_mcp_supported_methods(), true)) {
        return [
            'ok' => false,
            'reason' => 'unsupported_method',
            'method' => $method,
            'supported_methods' => videochat_call_app_mcp_supported_methods(),
        ];
    }
    if ($appKey === '') {
        return ['ok' => false, 'reason' => 'app_key_required', 'method' => $method];
    }

    $package = videochat_call_app_mcp_find_package($appKey, $packageRoot);
    if (!is_array($package)) {
        return ['ok' => false, 'reason' => 'app_not_found', 'method' => $method, 'app_key' => $appKey];
    }

    $validation = videochat_call_app_mcp_validate_package($package);
    if (!(bool) ($validation['ok'] ?? false)) {
        return [
            'ok' => false,
            'reason' => 'invalid_metadata',
            'method' => $method,
            'app_key' => $appKey,
            'errors' => $validation['errors'] ?? [],
        ];
    }

    return [
        'ok' => true,
        'method' => $method,
        'response_schema' => videochat_call_app_mcp_response_schemas()[$method] ?? '',
        'app_key' => (string) ($package['app_key'] ?? ''),
        'result' => videochat_call_app_mcp_result_for_method($package, $method),
        'metadata_hash' => (string) ($package['metadata_hash'] ?? ''),
    ];
}

function videochat_call_app_mcp_handle_json_request(string $payload, ?string $packageRoot = null): string
{
    try {
        $request = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return json_encode(['ok' => false, 'reason' => 'invalid_json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{"ok":false,"reason":"encode_failed"}';
    }
    if (!is_array($request)) {
        return json_encode(['ok' => false, 'reason' => 'request_must_be_object'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{"ok":false,"reason":"encode_failed"}';
    }

    return json_encode(videochat_call_app_mcp_handle_request($request, $packageRoot), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{"ok":false,"reason":"encode_failed"}';
}
