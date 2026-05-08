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
    $envRoot = $root . DIRECTORY_SEPARATOR . 'whiteboard' . DIRECTORY_SEPARATOR . '..';
    putenv('VIDEOCHAT_CALL_APP_PACKAGE_ROOT=' . $envRoot);
    videochat_call_app_semantic_dns_assert(realpath(videochat_call_app_package_root()) === realpath($root), 'package root must honor VIDEOCHAT_CALL_APP_PACKAGE_ROOT');
    putenv('VIDEOCHAT_CALL_APP_PACKAGE_ROOT');

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

    $futurePayload = videochat_call_app_semantic_dns_service_payload(
        array_merge($whiteboard, ['app_key' => 'kanban']),
        ['hostname' => 'whiteboard.kingrt.test', 'public_root_domain' => 'kingrt.test']
    );
    videochat_call_app_semantic_dns_assert((string) ($futurePayload['hostname'] ?? '') === 'kanban.kingrt.test', 'future app host must resolve from app_key under the root domain');

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

    $runtimeEnv = [
        'VIDEOCHAT_DEPLOY_DOMAIN' => 'kingrt.test',
        'VIDEOCHAT_DEPLOY_CALL_APP_DOMAIN' => 'whiteboard.kingrt.test',
        'VIDEOCHAT_DEPLOY_REGISTRY_DOMAIN' => 'registry.kingrt.test',
        'VIDEOCHAT_CALL_APP_MCP_ENDPOINT' => 'mcp://registry.kingrt.test/call_app.whiteboard.mcp',
        'VIDEOCHAT_CALL_APP_SEMANTIC_DNS_REGISTER' => '1',
        'VIDEOCHAT_CALL_APP_MOTHERNODE_ID' => 'registry-kingrt-test',
        'VIDEOCHAT_CALL_APP_MOTHERNODE_DNS_PORT' => '55354',
    ];
    $runtimeOptions = videochat_call_app_semantic_dns_runtime_options_from_env($runtimeEnv);
    videochat_call_app_semantic_dns_assert((string) ($runtimeOptions['hostname'] ?? '') === 'whiteboard.kingrt.test', 'runtime public host mismatch');
    videochat_call_app_semantic_dns_assert((string) ($runtimeOptions['public_root_domain'] ?? '') === 'kingrt.test', 'runtime public root domain mismatch');
    videochat_call_app_semantic_dns_assert((string) ($runtimeOptions['mcp_endpoint'] ?? '') === 'mcp://registry.kingrt.test/call_app.whiteboard.mcp', 'runtime MCP endpoint mismatch');
    videochat_call_app_semantic_dns_assert((bool) ($runtimeOptions['register'] ?? false), 'runtime registration must be enabled from env');
    videochat_call_app_semantic_dns_assert((string) (($runtimeOptions['mother_node'] ?? [])['hostname'] ?? '') === 'registry.kingrt.test', 'runtime registry host mismatch');
    videochat_call_app_semantic_dns_assert((int) (($runtimeOptions['semantic_dns_init'] ?? [])['dns_port'] ?? 0) === 55354, 'runtime DNS port mismatch');
    videochat_call_app_semantic_dns_assert(videochat_call_app_should_start_semantic_dns_runtime('http', 1, false, $runtimeEnv), 'HTTP worker 1 must start the call-app Mothernode');
    videochat_call_app_semantic_dns_assert(!videochat_call_app_should_start_semantic_dns_runtime('ws', 1, false, $runtimeEnv), 'WS workers must not start the call-app Mothernode');
    videochat_call_app_semantic_dns_assert(!videochat_call_app_should_start_semantic_dns_runtime('http', 2, false, $runtimeEnv), 'extra HTTP workers must not start the call-app Mothernode');

    $runtimeServices = [];
    $runtimeMotherNodes = [];
    $runtimeRegistration = videochat_call_app_register_runtime_semantic_dns_catalog($root, [
        'env' => $runtimeEnv,
        'register_service' => static function (array $servicePayload) use (&$runtimeServices): bool {
            $runtimeServices[] = $servicePayload;
            return true;
        },
        'register_mother_node' => static function (array $motherNodePayload) use (&$runtimeMotherNodes): bool {
            $runtimeMotherNodes[] = $motherNodePayload;
            return true;
        },
    ]);
    videochat_call_app_semantic_dns_assert((bool) ($runtimeRegistration['ok'] ?? false), 'runtime registration must succeed');
    videochat_call_app_semantic_dns_assert(count($runtimeServices) >= 1, 'runtime registration must register service payloads');
    videochat_call_app_semantic_dns_assert(count($runtimeMotherNodes) === 1, 'runtime registration must register exactly one Mothernode');
    videochat_call_app_semantic_dns_assert((string) ($runtimeServices[0]['hostname'] ?? '') === 'whiteboard.kingrt.test', 'runtime service hostname mismatch');
    videochat_call_app_semantic_dns_assert((string) (($runtimeServices[0]['attributes'] ?? [])['mcp_endpoint'] ?? '') === 'mcp://registry.kingrt.test/call_app.whiteboard.mcp', 'runtime service MCP endpoint mismatch');
    videochat_call_app_semantic_dns_assert((string) ($runtimeMotherNodes[0]['node_id'] ?? '') === 'registry-kingrt-test', 'runtime Mothernode id mismatch');
    videochat_call_app_semantic_dns_assert((string) ($runtimeMotherNodes[0]['hostname'] ?? '') === 'registry.kingrt.test', 'runtime registry host mismatch');
    videochat_call_app_semantic_dns_assert((int) ($runtimeMotherNodes[0]['managed_services_count'] ?? 0) >= 1, 'runtime Mothernode must report managed services');

    fwrite(STDOUT, "[call-app-semantic-dns-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[call-app-semantic-dns-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
