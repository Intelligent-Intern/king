<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/call_apps/call_app_mcp_metadata.php';

function videochat_text_document_mcp_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-app-text-document-mcp-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $root = videochat_call_app_package_root();
    $packages = videochat_call_app_scan_packages($root);
    $textDocument = null;
    foreach ($packages as $package) {
        if ((string) ($package['app_key'] ?? '') === 'text-document') {
            $textDocument = $package;
            break;
        }
    }

    videochat_text_document_mcp_assert(is_array($textDocument), 'text-document package must be discovered');
    videochat_text_document_mcp_assert((bool) ($textDocument['ok'] ?? false), 'text-document package must validate');
    videochat_text_document_mcp_assert((string) ($textDocument['health_status'] ?? '') === 'healthy', 'text-document package must be healthy');
    videochat_text_document_mcp_assert(strlen((string) ($textDocument['metadata_hash'] ?? '')) === 64, 'metadata hash must be sha256 hex');

    $describe = videochat_call_app_mcp_handle_request([
        'method' => 'call_app.describe',
        'params' => ['app_key' => 'text-document'],
    ], $root);
    videochat_text_document_mcp_assert((bool) ($describe['ok'] ?? false), 'describe must succeed');
    $describeResult = is_array($describe['result'] ?? null) ? $describe['result'] : [];
    videochat_text_document_mcp_assert((string) ($describeResult['app_key'] ?? '') === 'text-document', 'describe app key mismatch');
    videochat_text_document_mcp_assert((string) ($describeResult['service_name'] ?? '') === 'call_app.text-document', 'describe service name mismatch');

    $launch = videochat_call_app_mcp_handle_request([
        'method' => 'call_app.launch_contract',
        'params' => ['app_key' => 'text-document'],
    ], $root);
    videochat_text_document_mcp_assert((bool) ($launch['ok'] ?? false), 'launch contract must succeed');
    $launchResult = is_array($launch['result'] ?? null) ? $launch['result'] : [];
    videochat_text_document_mcp_assert((string) ($launchResult['iframe_entrypoint'] ?? '') === 'public/index.html', 'iframe entrypoint mismatch');
    videochat_text_document_mcp_assert((bool) ($launchResult['primary_session_token_allowed'] ?? true) === false, 'primary token must be rejected');
    videochat_text_document_mcp_assert(!in_array('allow-same-origin', $launchResult['iframe_sandbox'] ?? [], true), 'sandbox must keep opaque origin');

    $crdt = videochat_call_app_mcp_handle_request([
        'method' => 'call_app.crdt_schema',
        'params' => ['app_key' => 'text-document'],
    ], $root);
    videochat_text_document_mcp_assert((bool) ($crdt['ok'] ?? false), 'CRDT schema must succeed');
    $crdtResult = is_array($crdt['result'] ?? null) ? $crdt['result'] : [];
    videochat_text_document_mcp_assert((string) ($crdtResult['documents'][0]['kind'] ?? '') === 'text_document', 'CRDT document kind mismatch');
    foreach (['text_document.block.upsert', 'text_document.block.delete', 'text_document.format.update', 'text_document.note.upsert'] as $operationType) {
        videochat_text_document_mcp_assert(in_array($operationType, $crdtResult['documents'][0]['operation_types'] ?? [], true), "CRDT operation missing {$operationType}");
    }

    $exports = videochat_call_app_mcp_handle_request([
        'method' => 'call_app.export_formats',
        'params' => ['app_key' => 'text-document'],
    ], $root);
    videochat_text_document_mcp_assert((bool) ($exports['ok'] ?? false), 'export formats must succeed');
    $formats = array_map(static fn (array $entry): string => (string) ($entry['format'] ?? ''), $exports['result']['formats'] ?? []);
    videochat_text_document_mcp_assert($formats === ['odt', 'pdf'], 'text-document exports must be ODT and PDF');

    $health = videochat_call_app_mcp_handle_request([
        'method' => 'call_app.health',
        'params' => ['app_key' => 'text-document'],
    ], $root);
    videochat_text_document_mcp_assert((bool) ($health['ok'] ?? false), 'health must succeed');
    $healthPaths = array_map(static fn (array $entry): string => (string) ($entry['path'] ?? ''), $health['result']['checks'] ?? []);
    foreach (['public/index.html', 'public/text-document.css', 'public/text-document.js'] as $path) {
        videochat_text_document_mcp_assert(in_array($path, $healthPaths, true), "health missing {$path}");
    }

    fwrite(STDOUT, "[call-app-text-document-mcp-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[call-app-text-document-mcp-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
