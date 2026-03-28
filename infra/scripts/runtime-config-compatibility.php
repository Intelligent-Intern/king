<?php

declare(strict_types=1);

function fail_config_matrix(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function build_config_snapshot(array $overrides, bool $expectUserlandOverrides, int $expectOptionCount): array
{
    $config = king_new_config($overrides);
    $session = king_connect('127.0.0.1', 443, $config);
    $stats = king_get_stats($session);

    if (!is_resource($session)) {
        fail_config_matrix('Failed to create config-bound session.');
    }

    if (($stats['config_binding'] ?? null) !== 'resource') {
        fail_config_matrix('Unexpected config binding.');
    }
    if (($stats['config_is_frozen'] ?? null) !== true) {
        fail_config_matrix('Config snapshot was not frozen.');
    }
    if (($stats['config_userland_overrides_applied'] ?? null) !== $expectUserlandOverrides) {
        fail_config_matrix('Unexpected userland override flag.');
    }
    if (($stats['config_option_count'] ?? null) !== $expectOptionCount) {
        fail_config_matrix('Unexpected config option count.');
    }

    if (!king_close($session)) {
        fail_config_matrix('Failed to close config-bound session.');
    }

    return [
        'config_binding' => $stats['config_binding'] ?? null,
        'config_is_frozen' => $stats['config_is_frozen'] ?? null,
        'config_userland_overrides_applied' => $stats['config_userland_overrides_applied'] ?? null,
        'config_option_count' => $stats['config_option_count'] ?? null,
        'config_quic_cc_algorithm' => $stats['config_quic_cc_algorithm'] ?? null,
        'config_tls_verify_peer' => $stats['config_tls_verify_peer'] ?? null,
        'config_http2_max_concurrent_streams' => $stats['config_http2_max_concurrent_streams'] ?? null,
        'config_tcp_enable' => $stats['config_tcp_enable'] ?? null,
        'config_storage_enable' => $stats['config_storage_enable'] ?? null,
        'config_storage_default_redundancy_mode' => $stats['config_storage_default_redundancy_mode'] ?? null,
        'config_cdn_enable' => $stats['config_cdn_enable'] ?? null,
        'config_cdn_cache_mode' => $stats['config_cdn_cache_mode'] ?? null,
        'config_dns_server_enable' => $stats['config_dns_server_enable'] ?? null,
        'config_dns_mode' => $stats['config_dns_mode'] ?? null,
        'config_otel_enable' => $stats['config_otel_enable'] ?? null,
        'config_otel_service_name' => $stats['config_otel_service_name'] ?? null,
        'config_autoscale_provider' => $stats['config_autoscale_provider'] ?? null,
        'config_autoscale_max_nodes' => $stats['config_autoscale_max_nodes'] ?? null,
        'config_mcp_enable_request_caching' => $stats['config_mcp_enable_request_caching'] ?? null,
        'config_mcp_default_request_timeout_ms' => $stats['config_mcp_default_request_timeout_ms'] ?? null,
        'config_orchestrator_enable_distributed_tracing' => $stats['config_orchestrator_enable_distributed_tracing'] ?? null,
        'config_geometry_default_vector_dimensions' => $stats['config_geometry_default_vector_dimensions'] ?? null,
        'config_geometry_calculation_precision' => $stats['config_geometry_calculation_precision'] ?? null,
        'config_smartcontract_enable' => $stats['config_smartcontract_enable'] ?? null,
        'config_smartcontract_dlt_provider' => $stats['config_smartcontract_dlt_provider'] ?? null,
        'config_ssh_gateway_enable' => $stats['config_ssh_gateway_enable'] ?? null,
        'config_ssh_gateway_auth_mode' => $stats['config_ssh_gateway_auth_mode'] ?? null,
    ];
}

function emit_snapshot(array $snapshot): void
{
    $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        fail_config_matrix('Failed to encode config snapshot.');
    }

    echo $json, "\n";
}

$mode = $argv[1] ?? '';

if ($mode === 'legacy_alias_overrides') {
    $snapshot = build_config_snapshot([
        'cc_algorithm' => 'bbr',
        'tls_verify_peer' => false,
        'tcp_enable' => false,
        'storage_enable' => true,
        'storage_default_redundancy_mode' => 'replication',
        'cdn_enable' => true,
        'cdn_cache_mode' => 'memory',
        'dns_server_enable' => true,
        'dns_mode' => 'service_discovery',
        'otel_enable' => false,
        'otel_service_name' => 'king_config_matrix',
    ], true, 11);

    emit_snapshot($snapshot);
    exit(0);
}

if ($mode === 'namespaced_overrides') {
    $snapshot = build_config_snapshot([
        'quic.cc_algorithm' => 'bbr',
        'tls.verify_peer' => false,
        'tcp.enable' => false,
        'storage.enable' => true,
        'storage.default_redundancy_mode' => 'replication',
        'cdn.enable' => true,
        'cdn.cache_mode' => 'memory',
        'dns.server_enable' => true,
        'dns.mode' => 'service_discovery',
        'otel.enable' => false,
        'otel.service_name' => 'king_config_matrix',
    ], true, 11);

    emit_snapshot($snapshot);
    exit(0);
}

if ($mode === 'ini_snapshot') {
    $snapshot = build_config_snapshot([], false, 0);

    if (($snapshot['config_quic_cc_algorithm'] ?? null) !== 'bbr') {
        fail_config_matrix('Unexpected INI-backed quic.cc_algorithm.');
    }
    if (($snapshot['config_tls_verify_peer'] ?? null) !== false) {
        fail_config_matrix('Unexpected INI-backed tls.verify_peer.');
    }
    if (($snapshot['config_http2_max_concurrent_streams'] ?? null) !== 32) {
        fail_config_matrix('Unexpected INI-backed http2.max_concurrent_streams.');
    }
    if (($snapshot['config_tcp_enable'] ?? null) !== false) {
        fail_config_matrix('Unexpected INI-backed tcp.enable.');
    }
    if (($snapshot['config_storage_default_redundancy_mode'] ?? null) !== 'replication') {
        fail_config_matrix('Unexpected INI-backed storage.default_redundancy_mode.');
    }
    if (($snapshot['config_cdn_cache_mode'] ?? null) !== 'memory') {
        fail_config_matrix('Unexpected INI-backed cdn.cache_mode.');
    }
    if (($snapshot['config_dns_mode'] ?? null) !== 'service_discovery') {
        fail_config_matrix('Unexpected INI-backed dns.mode.');
    }
    if (($snapshot['config_otel_service_name'] ?? null) !== 'king_config_matrix_ini') {
        fail_config_matrix('Unexpected INI-backed otel.service_name.');
    }
    if (($snapshot['config_autoscale_provider'] ?? null) !== 'hetzner') {
        fail_config_matrix('Unexpected INI-backed autoscale.provider.');
    }
    if (($snapshot['config_autoscale_max_nodes'] ?? null) !== 5) {
        fail_config_matrix('Unexpected INI-backed autoscale.max_nodes.');
    }
    if (($snapshot['config_mcp_enable_request_caching'] ?? null) !== true) {
        fail_config_matrix('Unexpected INI-backed mcp.enable_request_caching.');
    }
    if (($snapshot['config_mcp_default_request_timeout_ms'] ?? null) !== 41000) {
        fail_config_matrix('Unexpected INI-backed mcp.default_request_timeout_ms.');
    }
    if (($snapshot['config_orchestrator_enable_distributed_tracing'] ?? null) !== false) {
        fail_config_matrix('Unexpected INI-backed orchestrator.enable_distributed_tracing.');
    }
    if (($snapshot['config_geometry_default_vector_dimensions'] ?? null) !== 1024) {
        fail_config_matrix('Unexpected INI-backed geometry.default_vector_dimensions.');
    }
    if (($snapshot['config_geometry_calculation_precision'] ?? null) !== 'float32') {
        fail_config_matrix('Unexpected INI-backed geometry.calculation_precision.');
    }
    if (($snapshot['config_smartcontract_enable'] ?? null) !== true) {
        fail_config_matrix('Unexpected INI-backed smartcontract.enable.');
    }
    if (($snapshot['config_smartcontract_dlt_provider'] ?? null) !== 'solana') {
        fail_config_matrix('Unexpected INI-backed smartcontract.dlt_provider.');
    }
    if (($snapshot['config_ssh_gateway_enable'] ?? null) !== true) {
        fail_config_matrix('Unexpected INI-backed ssh.gateway_enable.');
    }
    if (($snapshot['config_ssh_gateway_auth_mode'] ?? null) !== 'mcp_token') {
        fail_config_matrix('Unexpected INI-backed ssh.gateway_auth_mode.');
    }

    emit_snapshot($snapshot);
    exit(0);
}

fail_config_matrix('Expected mode legacy_alias_overrides, namespaced_overrides, or ini_snapshot.');
