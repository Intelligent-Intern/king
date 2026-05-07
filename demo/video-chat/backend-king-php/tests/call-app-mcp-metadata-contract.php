<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/call_apps/call_app_mcp_metadata.php';

function videochat_call_app_mcp_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-app-mcp-metadata-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_call_app_mcp_remove_dir(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $entries = scandir($path);
    if (!is_array($entries)) {
        return;
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child)) {
            videochat_call_app_mcp_remove_dir($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}

function videochat_call_app_mcp_copy_package(string $source, string $target): void
{
    mkdir($target . DIRECTORY_SEPARATOR . 'public', 0777, true);
    foreach (['call-app.manifest.json', 'mcp.descriptor.json', 'crdt.schema.json', 'health.descriptor.json'] as $file) {
        copy($source . DIRECTORY_SEPARATOR . $file, $target . DIRECTORY_SEPARATOR . $file);
    }
    copy($source . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.html', $target . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.html');
}

try {
    $root = videochat_call_app_package_root();
    $methods = videochat_call_app_mcp_supported_methods();
    foreach ([
        'call_app.describe',
        'call_app.capabilities',
        'call_app.crdt_schema',
        'call_app.launch_contract',
        'call_app.health',
        'call_app.export_formats',
        'call_app.marketplace_listing',
    ] as $method) {
        videochat_call_app_mcp_assert(in_array($method, $methods, true), "supported method missing {$method}");
    }

    $describe = videochat_call_app_mcp_handle_request([
        'method' => 'call_app.describe',
        'params' => ['app_key' => 'whiteboard'],
    ], $root);
    videochat_call_app_mcp_assert((bool) ($describe['ok'] ?? false), 'describe must succeed');
    videochat_call_app_mcp_assert((string) ($describe['response_schema'] ?? '') === 'king.call_app.describe.v1', 'describe schema mismatch');
    $describeResult = is_array($describe['result'] ?? null) ? $describe['result'] : [];
    videochat_call_app_mcp_assert((string) ($describeResult['app_key'] ?? '') === 'whiteboard', 'describe app_key mismatch');
    videochat_call_app_mcp_assert((string) ($describeResult['service_name'] ?? '') === 'call_app.whiteboard', 'describe service name mismatch');
    videochat_call_app_mcp_assert(strlen((string) ($describeResult['metadata_hash'] ?? '')) === 64, 'describe metadata hash must be sha256');

    $capabilities = videochat_call_app_mcp_handle_request([
        'method' => 'call_app.capabilities',
        'params' => ['app_key' => 'whiteboard'],
    ], $root);
    videochat_call_app_mcp_assert((bool) ($capabilities['ok'] ?? false), 'capabilities must succeed');
    $capabilityResult = is_array($capabilities['result'] ?? null) ? $capabilities['result'] : [];
    videochat_call_app_mcp_assert(in_array('call_apps.crdt.append', $capabilityResult['capabilities'] ?? [], true), 'capabilities must include CRDT append');
    videochat_call_app_mcp_assert(in_array('call_apps.marketplace.order', $capabilityResult['permissions'] ?? [], true), 'permissions must include marketplace order');
    videochat_call_app_mcp_assert((string) ($capabilityResult['default_participant_access'] ?? '') === 'blocked_by_default', 'default participant access mismatch');

    $crdt = videochat_call_app_mcp_handle_request([
        'method' => 'call_app.crdt_schema',
        'params' => ['app_key' => 'whiteboard'],
    ], $root);
    videochat_call_app_mcp_assert((bool) ($crdt['ok'] ?? false), 'CRDT schema must succeed');
    $crdtResult = is_array($crdt['result'] ?? null) ? $crdt['result'] : [];
    videochat_call_app_mcp_assert((string) ($crdtResult['protocol'] ?? '') === 'king.call_app.crdt.v1', 'CRDT protocol mismatch');
    videochat_call_app_mcp_assert(in_array('operation_id', $crdtResult['envelope']['required_fields'] ?? [], true), 'CRDT envelope must include operation_id');

    $launch = videochat_call_app_mcp_handle_request([
        'method' => 'call_app.launch_contract',
        'params' => ['app_key' => 'whiteboard'],
    ], $root);
    videochat_call_app_mcp_assert((bool) ($launch['ok'] ?? false), 'launch contract must succeed');
    $launchResult = is_array($launch['result'] ?? null) ? $launch['result'] : [];
    videochat_call_app_mcp_assert((string) ($launchResult['iframe_entrypoint'] ?? '') === 'public/index.html', 'iframe entrypoint mismatch');
    videochat_call_app_mcp_assert((bool) ($launchResult['launch_token_required'] ?? false), 'launch token must be required');
    videochat_call_app_mcp_assert((bool) ($launchResult['primary_session_token_allowed'] ?? true) === false, 'primary session token must be rejected');
    videochat_call_app_mcp_assert(!in_array('allow-same-origin', $launchResult['iframe_sandbox'] ?? [], true), 'sandbox must not include allow-same-origin');

    $health = videochat_call_app_mcp_handle_request([
        'method' => 'call_app.health',
        'params' => ['app_key' => 'whiteboard'],
    ], $root);
    videochat_call_app_mcp_assert((bool) ($health['ok'] ?? false), 'health must succeed');
    $healthResult = is_array($health['result'] ?? null) ? $health['result'] : [];
    videochat_call_app_mcp_assert((string) ($healthResult['status'] ?? '') === 'healthy', 'health status mismatch');
    videochat_call_app_mcp_assert(count($healthResult['checks'] ?? []) >= 4, 'health must include required checks');

    $exports = videochat_call_app_mcp_handle_request([
        'method' => 'call_app.export_formats',
        'params' => ['app_key' => 'whiteboard'],
    ], $root);
    videochat_call_app_mcp_assert((bool) ($exports['ok'] ?? false), 'export formats must succeed');
    $formatNames = array_map(static fn (array $entry): string => (string) ($entry['format'] ?? ''), $exports['result']['formats'] ?? []);
    videochat_call_app_mcp_assert(in_array('png', $formatNames, true), 'PNG export missing');
    videochat_call_app_mcp_assert(in_array('pdf', $formatNames, true), 'PDF export missing');

    $listing = videochat_call_app_mcp_handle_request([
        'method' => 'call_app.marketplace_listing',
        'params' => ['app_key' => 'whiteboard'],
    ], $root);
    videochat_call_app_mcp_assert((bool) ($listing['ok'] ?? false), 'marketplace listing must succeed');
    $listingResult = is_array($listing['result'] ?? null) ? $listing['result'] : [];
    videochat_call_app_mcp_assert((string) ($listingResult['order_scope'] ?? '') === 'organization', 'marketplace order scope mismatch');
    videochat_call_app_mcp_assert((bool) ($listingResult['requires_installation'] ?? false), 'listing must require installation');

    $jsonResponse = videochat_call_app_mcp_handle_json_request(json_encode([
        'method' => 'call_app.describe',
        'params' => ['app_key' => 'whiteboard'],
    ], JSON_UNESCAPED_SLASHES), $root);
    $decodedJsonResponse = json_decode($jsonResponse, true);
    videochat_call_app_mcp_assert(is_array($decodedJsonResponse) && (bool) ($decodedJsonResponse['ok'] ?? false), 'JSON MCP request must succeed');

    $unsupported = videochat_call_app_mcp_handle_request([
        'method' => 'call_app.unknown',
        'params' => ['app_key' => 'whiteboard'],
    ], $root);
    videochat_call_app_mcp_assert(!(bool) ($unsupported['ok'] ?? true), 'unsupported method must fail');
    videochat_call_app_mcp_assert((string) ($unsupported['reason'] ?? '') === 'unsupported_method', 'unsupported method reason mismatch');

    $tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'king-call-app-mcp-' . bin2hex(random_bytes(4));
    try {
        videochat_call_app_mcp_copy_package($root . DIRECTORY_SEPARATOR . 'whiteboard', $tmpRoot . DIRECTORY_SEPARATOR . 'whiteboard');
        $descriptorPath = $tmpRoot . DIRECTORY_SEPARATOR . 'whiteboard' . DIRECTORY_SEPARATOR . 'mcp.descriptor.json';
        $descriptor = json_decode((string) file_get_contents($descriptorPath), true);
        videochat_call_app_mcp_assert(is_array($descriptor), 'temporary descriptor must decode');
        $descriptor['methods'] = array_values(array_filter(
            is_array($descriptor['methods'] ?? null) ? $descriptor['methods'] : [],
            static fn (array $method): bool => (string) ($method['name'] ?? '') !== 'call_app.health'
        ));
        file_put_contents($descriptorPath, json_encode($descriptor, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $invalid = videochat_call_app_mcp_handle_request([
            'method' => 'call_app.describe',
            'params' => ['app_key' => 'whiteboard'],
        ], $tmpRoot);
        videochat_call_app_mcp_assert(!(bool) ($invalid['ok'] ?? true), 'incomplete MCP descriptor must fail');
        videochat_call_app_mcp_assert((string) ($invalid['reason'] ?? '') === 'invalid_metadata', 'incomplete descriptor reason mismatch');
        videochat_call_app_mcp_assert(isset(($invalid['errors'] ?? [])['mcp.methods.call_app.health']), 'incomplete descriptor must report missing health method');
    } finally {
        videochat_call_app_mcp_remove_dir($tmpRoot);
    }

    fwrite(STDOUT, "[call-app-mcp-metadata-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[call-app-mcp-metadata-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
