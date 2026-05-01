<?php

declare(strict_types=1);

function videochat_config_env_string(string $name): string
{
    $value = getenv($name);
    return is_string($value) ? trim($value) : '';
}

function videochat_config_env_flag(string $name, bool $fallback = false): bool
{
    $value = strtolower(videochat_config_env_string($name));
    if ($value === '') {
        return $fallback;
    }

    if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }

    if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    return $fallback;
}

function videochat_config_env_is_false(string $name): bool
{
    $value = strtolower(videochat_config_env_string($name));
    return in_array($value, ['0', 'false', 'no', 'off'], true);
}

/**
 * @return array{source: string, value: string, error: string}
 */
function videochat_config_secret_source(string $name, string $defaultValue = ''): array
{
    // Active file bindings include VIDEOCHAT_DEMO_ADMIN_PASSWORD_FILE,
    // VIDEOCHAT_DEMO_USER_PASSWORD_FILE, and VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET_FILE.
    $envValue = videochat_config_env_string($name);
    if ($envValue !== '') {
        return ['source' => 'env', 'value' => $envValue, 'error' => ''];
    }

    $fileName = $name . '_FILE';
    $filePath = videochat_config_env_string($fileName);
    if ($filePath !== '') {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return ['source' => 'file', 'value' => '', 'error' => "{$fileName} is not readable"];
        }

        $fileValue = trim((string) file_get_contents($filePath));
        if ($fileValue === '') {
            return ['source' => 'file', 'value' => '', 'error' => "{$fileName} is empty"];
        }

        return ['source' => 'file', 'value' => $fileValue, 'error' => ''];
    }

    if ($defaultValue !== '') {
        return ['source' => 'default', 'value' => $defaultValue, 'error' => ''];
    }

    return ['source' => 'missing', 'value' => '', 'error' => "{$name} or {$fileName} is required"];
}

function videochat_config_secret_value(string $name, string $defaultValue = ''): string
{
    $secret = videochat_config_secret_source($name, $defaultValue);
    if ((string) $secret['error'] !== '') {
        throw new RuntimeException((string) $secret['error']);
    }

    return (string) $secret['value'];
}

function videochat_config_requires_secret_sources(): bool
{
    if (videochat_config_env_flag('VIDEOCHAT_REQUIRE_SECRET_SOURCES', false)) {
        return true;
    }

    $environment = strtolower(videochat_config_env_string('VIDEOCHAT_KING_ENV') ?: 'development');
    return in_array($environment, ['production', 'prod', 'staging', 'stage'], true);
}

function videochat_config_is_known_demo_secret(string $value): bool
{
    $normalized = strtolower(trim($value));
    return in_array($normalized, [
        'admin',
        'admin123',
        'password',
        'password123',
        'secret',
        'changeme',
        'change-me',
        'user',
        'user123',
        'demo',
        'demo123',
    ], true);
}

/**
 * @return array{ok: bool, required: bool, errors: array<int, string>, warnings: array<int, string>}
 */
function videochat_config_hardening_report(): array
{
    $required = videochat_config_requires_secret_sources();
    $errors = [];
    $warnings = [];

    $adminPassword = videochat_config_secret_source('VIDEOCHAT_DEMO_ADMIN_PASSWORD', 'admin123');
    $userPassword = videochat_config_secret_source('VIDEOCHAT_DEMO_USER_PASSWORD', 'user123');

    foreach ([
        'VIDEOCHAT_DEMO_ADMIN_PASSWORD' => $adminPassword,
        'VIDEOCHAT_DEMO_USER_PASSWORD' => $userPassword,
    ] as $name => $secret) {
        $source = (string) ($secret['source'] ?? '');
        $value = (string) ($secret['value'] ?? '');
        $error = (string) ($secret['error'] ?? '');

        if ($error !== '') {
            $errors[] = "{$name}: {$error}";
            continue;
        }

        if ($required && !in_array($source, ['env', 'file'], true)) {
            $errors[] = "{$name} must be supplied by env or {$name}_FILE in hardened deployments";
        }

        if ($required && videochat_config_is_known_demo_secret($value)) {
            $errors[] = "{$name} uses a known demo/default secret";
        }

        if ($required && strlen($value) < 12) {
            $errors[] = "{$name} must be at least 12 characters in hardened deployments";
        }

        if (!$required && $source === 'default') {
            $warnings[] = "{$name} is using the local demo default";
        }
    }

    if (
        trim((string) ($adminPassword['value'] ?? '')) !== ''
        && trim((string) ($adminPassword['value'] ?? '')) === trim((string) ($userPassword['value'] ?? ''))
    ) {
        $errors[] = 'VIDEOCHAT_DEMO_ADMIN_PASSWORD and VIDEOCHAT_DEMO_USER_PASSWORD must differ';
    }

    if ($required && !videochat_config_env_is_false('VIDEOCHAT_DEMO_SEED_CALLS')) {
        $errors[] = 'VIDEOCHAT_DEMO_SEED_CALLS must be 0/false/off/no in hardened deployments';
    }

    $turnSecret = videochat_config_secret_source('VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET');
    if ((string) ($turnSecret['source'] ?? '') !== 'missing') {
        $turnValue = (string) ($turnSecret['value'] ?? '');
        $turnError = (string) ($turnSecret['error'] ?? '');
        if ($turnError !== '') {
            $errors[] = 'VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET: ' . $turnError;
        } elseif (videochat_config_is_known_demo_secret($turnValue) || strlen($turnValue) < 16) {
            $errors[] = 'VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET must be a non-default secret with at least 16 characters';
        }
    }

    return [
        'ok' => $errors === [],
        'required' => $required,
        'errors' => $errors,
        'warnings' => $warnings,
    ];
}
