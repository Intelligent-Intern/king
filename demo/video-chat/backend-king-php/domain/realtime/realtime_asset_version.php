<?php

declare(strict_types=1);

function videochat_realtime_current_asset_version(): string
{
    return trim((string) (getenv('VIDEOCHAT_ASSET_VERSION') ?: ''));
}

function videochat_realtime_runtime_descriptor(): array
{
    $assetVersion = videochat_realtime_current_asset_version();
    if ($assetVersion === '') {
        return [];
    }

    return [
        'asset_version' => $assetVersion,
    ];
}

function videochat_realtime_normalize_client_asset_version(mixed $value): string
{
    $candidate = trim((string) $value);
    if ($candidate === '') {
        return '';
    }

    if (!preg_match('/^[A-Za-z0-9._-]{1,80}$/', $candidate)) {
        return '';
    }

    return $candidate;
}

function videochat_realtime_client_asset_version_from_query(array $queryParams): string
{
    return videochat_realtime_normalize_client_asset_version($queryParams['asset_version'] ?? '');
}

function videochat_realtime_asset_version_mismatch(string $clientAssetVersion): bool
{
    $serverAssetVersion = videochat_realtime_current_asset_version();
    if ($serverAssetVersion === '' || $clientAssetVersion === '') {
        return false;
    }

    return $serverAssetVersion !== $clientAssetVersion;
}

function videochat_realtime_asset_invalidation_frame(
    string $clientAssetVersion,
    string $transport = 'ws'
): array {
    return [
        'type' => 'assets/invalidate',
        'reason' => 'asset_version_mismatch',
        'force_reload' => true,
        'transport' => trim($transport) === '' ? 'ws' : trim($transport),
        'asset_version' => videochat_realtime_current_asset_version(),
        'client' => [
            'asset_version' => $clientAssetVersion,
        ],
        'runtime' => videochat_realtime_runtime_descriptor(),
        'time' => gmdate('c'),
    ];
}
