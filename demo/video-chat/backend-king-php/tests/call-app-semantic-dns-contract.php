<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/call_apps/call_app_semantic_dns.php';

function videochat_call_app_semantic_dns_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-app-semantic-dns-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $root = videochat_call_app_package_root();
    videochat_call_app_semantic_dns_assert(is_dir($root), 'package root must exist');
    videochat_call_app_semantic_dns_assert(str_ends_with($root, 'demo/call-app'), 'package root must be demo/call-app');

    $packages = videochat_call_app_scan_packages($root);
    videochat_call_app_semantic_dns_assert(count($packages) >= 1, 'at least one call app package must be discovered');
    $whiteboard = null;
    foreach ($packages as $package) {
        if ((string) ($package['app_key'] ?? '') === 'whiteboard') {
            $whiteboard = $package;
            break;
        }
    }
    videochat_call_app_semantic_dns_assert(is_array($whiteboard), 'whiteboard package must be discovered');
    videochat_call_app_semantic_dns_assert((bool) ($whiteboard['ok'] ?? false), 'whiteboard package must validate');
    videochat_call_app_semantic_dns_assert((string) ($whiteboard['version'] ?? '') === '0.1.0', 'whiteboard version mismatch');
    videochat_call_app_semantic_dns_assert((string) ($whiteboard['health_status'] ?? '') === 'healthy', 'whiteboard package must be healthy');
    videochat_call_app_semantic_dns_assert(strlen((string) ($whiteboard['metadata_hash'] ?? '')) === 64, 'metadata hash must be sha256 hex');

    $payload = videochat_call_app_semantic_dns_service_payload($whiteboard, [
        'hostname' => 'callapps.local',
        'port' => 9443,
        'mcp_endpoint' => 'mcp://mother-node.local/call_app.whiteboard.mcp',
    ]);
    $validation = videochat_call_app_validate_semantic_dns_payload($payload);
    videochat_call_app_semantic_dns_assert((bool) ($validation['ok'] ?? false), 'Semantic-DNS payload must validate');
    videochat_call_app_semantic_dns_assert((string) ($payload['service_type'] ?? '') === 'call_app', 'service type must be call_app');
    videochat_call_app_semantic_dns_assert((string) ($payload['service_name'] ?? '') === 'call_app.whiteboard', 'service name mismatch');
    videochat_call_app_semantic_dns_assert((string) ($payload['hostname'] ?? '') === 'callapps.local', 'hostname mismatch');
    videochat_call_app_semantic_dns_assert((int) ($payload['port'] ?? 0) === 9443, 'port mismatch');
    $attributes = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
    videochat_call_app_semantic_dns_assert((string) ($attributes['app_key'] ?? '') === 'whiteboard', 'attribute app_key mismatch');
    videochat_call_app_semantic_dns_assert((string) ($attributes['app_version'] ?? '') === '0.1.0', 'attribute app_version mismatch');
    videochat_call_app_semantic_dns_assert((string) ($attributes['category'] ?? '') === 'whiteboard', 'attribute category mismatch');
    videochat_call_app_semantic_dns_assert((string) ($attributes['crdt_protocol'] ?? '') === 'king.call_app.crdt.v1', 'attribute CRDT protocol mismatch');
    videochat_call_app_semantic_dns_assert((string) ($attributes['marketplace_order_scope'] ?? '') === 'organization', 'attribute order scope mismatch');
    videochat_call_app_semantic_dns_assert((bool) ($attributes['mother_node_registration_required'] ?? false), 'mother-node registration must be required');
    videochat_call_app_semantic_dns_assert(str_contains((string) ($attributes['capabilities_csv'] ?? ''), 'call_apps.crdt.append'), 'capabilities must include CRDT append');
    videochat_call_app_semantic_dns_assert(str_contains((string) ($attributes['mcp_methods_csv'] ?? ''), 'call_app.marketplace_listing'), 'MCP methods must include marketplace listing');
    videochat_call_app_semantic_dns_assert(str_contains((string) ($attributes['export_formats_csv'] ?? ''), 'png'), 'exports must include png');
    videochat_call_app_semantic_dns_assert(str_contains((string) ($attributes['export_formats_csv'] ?? ''), 'pdf'), 'exports must include pdf');

    $registeredPayloads = [];
    $registerResult = videochat_call_app_register_semantic_dns_services(
        [$payload],
        static function (array $servicePayload) use (&$registeredPayloads): bool {
            $registeredPayloads[] = $servicePayload;
            return true;
        }
    );
    videochat_call_app_semantic_dns_assert((bool) ($registerResult['ok'] ?? false), 'fake registration must succeed');
    videochat_call_app_semantic_dns_assert(count($registeredPayloads) === 1, 'fake registration must receive one payload');
    videochat_call_app_semantic_dns_assert((string) (($registeredPayloads[0]['attributes'] ?? [])['mcp_endpoint'] ?? '') === 'mcp://mother-node.local/call_app.whiteboard.mcp', 'registered payload MCP endpoint mismatch');

    $refreshPayloads = [];
    $refresh = videochat_call_app_refresh_semantic_dns_catalog($root, [
        'hostname' => 'callapps.local',
        'port' => 9443,
        'register' => true,
        'register_service' => static function (array $servicePayload) use (&$refreshPayloads): bool {
            $refreshPayloads[] = $servicePayload;
            return true;
        },
    ]);
    videochat_call_app_semantic_dns_assert((bool) ($refresh['ok'] ?? false), 'catalog refresh must succeed');
    videochat_call_app_semantic_dns_assert(count($refresh['service_payloads'] ?? []) >= 1, 'refresh must expose service payloads');
    videochat_call_app_semantic_dns_assert(count($refreshPayloads) >= 1, 'refresh must register service payloads through provided callable');
    videochat_call_app_semantic_dns_assert((bool) (($refresh['registration'] ?? [])['registration_available'] ?? false), 'registration must be available through provided callable');

    fwrite(STDOUT, "[call-app-semantic-dns-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[call-app-semantic-dns-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
