<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/error_envelope.php';

function videochat_contract_schema_fail(string $message): never
{
    fwrite(STDERR, "[contract-schema-versioning-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_contract_schema_assert(bool $condition, string $message): void
{
    if (!$condition) {
        videochat_contract_schema_fail($message);
    }
}

function videochat_contract_schema_decode_json(string $raw, string $label): array
{
    try {
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        videochat_contract_schema_fail("{$label} JSON decode failed: " . $error->getMessage());
    }

    if (!is_array($decoded)) {
        videochat_contract_schema_fail("{$label} must decode to an object.");
    }

    return $decoded;
}

function videochat_contract_schema_validate_definition(mixed $schema, string $path, array &$errors): void
{
    if (!is_array($schema)) {
        $errors[] = "{$path}: schema node must be an object.";
        return;
    }

    $allowedKeys = [
        'type' => true,
        'one_of' => true,
        'required' => true,
        'optional' => true,
        'allow_additional' => true,
        'items' => true,
        'min_length' => true,
        'enum' => true,
        'pattern' => true,
        'min' => true,
        'max' => true,
    ];
    foreach (array_keys($schema) as $key) {
        if (!is_string($key) || !isset($allowedKeys[$key])) {
            $errors[] = "{$path}: unsupported schema key '{$key}'.";
        }
    }

    if (array_key_exists('one_of', $schema)) {
        $variants = $schema['one_of'];
        if (!is_array($variants) || $variants === []) {
            $errors[] = "{$path}.one_of: must be a non-empty array.";
            return;
        }
        foreach ($variants as $index => $variant) {
            videochat_contract_schema_validate_definition($variant, "{$path}.one_of[{$index}]", $errors);
        }
        return;
    }

    $type = $schema['type'] ?? null;
    $allowedTypes = ['any', 'null', 'string', 'int', 'bool', 'array', 'object'];
    if (!is_string($type) || !in_array($type, $allowedTypes, true)) {
        $errors[] = "{$path}.type: must be one of " . implode(', ', $allowedTypes) . '.';
        return;
    }

    if (array_key_exists('min_length', $schema) && (!is_int($schema['min_length']) || $schema['min_length'] < 0)) {
        $errors[] = "{$path}.min_length: must be a non-negative int.";
    }
    if (array_key_exists('min', $schema) && !is_int($schema['min'])) {
        $errors[] = "{$path}.min: must be an int.";
    }
    if (array_key_exists('max', $schema) && !is_int($schema['max'])) {
        $errors[] = "{$path}.max: must be an int.";
    }
    if (array_key_exists('enum', $schema)) {
        $enum = $schema['enum'];
        if (!is_array($enum) || $enum === []) {
            $errors[] = "{$path}.enum: must be a non-empty array.";
        } else {
            foreach ($enum as $index => $value) {
                if (!is_string($value)) {
                    $errors[] = "{$path}.enum[{$index}]: must be a string.";
                }
            }
        }
    }
    if (array_key_exists('pattern', $schema)) {
        $pattern = $schema['pattern'];
        if (!is_string($pattern) || $pattern === '' || @preg_match($pattern, '') === false) {
            $errors[] = "{$path}.pattern: must be a valid non-empty preg pattern.";
        }
    }
    if (array_key_exists('allow_additional', $schema) && !is_bool($schema['allow_additional'])) {
        $errors[] = "{$path}.allow_additional: must be bool.";
    }

    if ($type === 'array' && array_key_exists('items', $schema)) {
        videochat_contract_schema_validate_definition($schema['items'], "{$path}.items", $errors);
    }

    if ($type === 'object') {
        foreach (['required', 'optional'] as $bucket) {
            if (!array_key_exists($bucket, $schema)) {
                continue;
            }
            if (!is_array($schema[$bucket])) {
                $errors[] = "{$path}.{$bucket}: must be an object keyed by field name.";
                continue;
            }
            foreach ($schema[$bucket] as $fieldName => $fieldSchema) {
                if (!is_string($fieldName) || $fieldName === '' || preg_match('/^[A-Za-z0-9_]+$/', $fieldName) !== 1) {
                    $errors[] = "{$path}.{$bucket}: field names must be non-empty snake/json identifiers.";
                    continue;
                }
                videochat_contract_schema_validate_definition($fieldSchema, "{$path}.{$bucket}.{$fieldName}", $errors);
            }
        }
    }
}

function videochat_contract_schema_validate_value(mixed $value, mixed $schema, string $path, array &$errors): void
{
    if (!is_array($schema)) {
        $errors[] = "{$path}: schema node must be object.";
        return;
    }
    if (isset($schema['one_of'])) {
        foreach ((array) $schema['one_of'] as $variant) {
            $variantErrors = [];
            videochat_contract_schema_validate_value($value, $variant, $path, $variantErrors);
            if ($variantErrors === []) {
                return;
            }
        }
        $errors[] = "{$path}: value did not match any one_of variant.";
        return;
    }

    $type = (string) ($schema['type'] ?? '');
    if ($type === 'any') {
        return;
    }
    if ($type === 'null') {
        if ($value !== null) {
            $errors[] = "{$path}: expected null.";
        }
        return;
    }
    if ($type === 'string') {
        if (!is_string($value)) {
            $errors[] = "{$path}: expected string.";
            return;
        }
        if (is_int($schema['min_length'] ?? null) && strlen($value) < (int) $schema['min_length']) {
            $errors[] = "{$path}: shorter than min_length.";
        }
        if (is_array($schema['enum'] ?? null) && !in_array($value, (array) $schema['enum'], true)) {
            $errors[] = "{$path}: not in enum.";
        }
        if (is_string($schema['pattern'] ?? null) && preg_match((string) $schema['pattern'], $value) !== 1) {
            $errors[] = "{$path}: pattern mismatch.";
        }
        return;
    }
    if ($type === 'int') {
        if (!is_int($value)) {
            $errors[] = "{$path}: expected int.";
            return;
        }
        if (is_int($schema['min'] ?? null) && $value < (int) $schema['min']) {
            $errors[] = "{$path}: below min.";
        }
        if (is_int($schema['max'] ?? null) && $value > (int) $schema['max']) {
            $errors[] = "{$path}: above max.";
        }
        return;
    }
    if ($type === 'bool') {
        if (!is_bool($value)) {
            $errors[] = "{$path}: expected bool.";
        }
        return;
    }
    if ($type === 'array') {
        if (!is_array($value)) {
            $errors[] = "{$path}: expected array.";
            return;
        }
        if (isset($schema['items'])) {
            foreach ($value as $index => $item) {
                videochat_contract_schema_validate_value($item, $schema['items'], "{$path}[{$index}]", $errors);
            }
        }
        return;
    }
    if ($type === 'object') {
        if (!is_array($value)) {
            $errors[] = "{$path}: expected object.";
            return;
        }
        foreach (($schema['required'] ?? []) as $key => $childSchema) {
            if (!array_key_exists($key, $value)) {
                $errors[] = "{$path}.{$key}: missing required key.";
                continue;
            }
            videochat_contract_schema_validate_value($value[$key], $childSchema, "{$path}.{$key}", $errors);
        }
        foreach (($schema['optional'] ?? []) as $key => $childSchema) {
            if (array_key_exists($key, $value)) {
                videochat_contract_schema_validate_value($value[$key], $childSchema, "{$path}.{$key}", $errors);
            }
        }
        return;
    }

    $errors[] = "{$path}: unsupported type '{$type}'.";
}

function videochat_contract_schema_assert_payload(array $catalog, string $section, string $name, array $payload): void
{
    $schema = $catalog[$section][$name] ?? null;
    videochat_contract_schema_assert(is_array($schema), "missing schema {$section}.{$name}");
    $errors = [];
    videochat_contract_schema_validate_value($payload, $schema, "{$section}.{$name}", $errors);
    if ($errors !== []) {
        videochat_contract_schema_fail("payload mismatch for {$section}.{$name}:\n- " . implode("\n- ", $errors));
    }
}

try {
    $catalogPath = realpath(__DIR__ . '/../../contracts/v1/api-ws-contract.catalog.json');
    videochat_contract_schema_assert(is_string($catalogPath) && $catalogPath !== '', 'versioned catalog path must resolve');
    videochat_contract_schema_assert(str_ends_with(str_replace('\\', '/', $catalogPath), '/contracts/v1/api-ws-contract.catalog.json'), 'catalog must stay under contracts/v1');

    $catalogRaw = file_get_contents($catalogPath);
    videochat_contract_schema_assert(is_string($catalogRaw) && trim($catalogRaw) !== '', 'catalog must be readable');
    $catalog = videochat_contract_schema_decode_json($catalogRaw, 'catalog');

    videochat_contract_schema_assert((string) ($catalog['catalog_name'] ?? '') === 'king-video-chat-api-ws', 'catalog_name mismatch');
    $catalogVersion = (string) ($catalog['catalog_version'] ?? '');
    videochat_contract_schema_assert(preg_match('/^v1\.\d+\.\d+(?:-[A-Za-z0-9._-]+)?$/', $catalogVersion) === 1, 'catalog_version must be pinned to v1 semver form');
    videochat_contract_schema_assert(is_array($catalog['api'] ?? null) && $catalog['api'] !== [], 'api schema section must be non-empty');
    videochat_contract_schema_assert(is_array($catalog['ws'] ?? null) && $catalog['ws'] !== [], 'ws schema section must be non-empty');

    $requiredApiDtos = ['runtime_health', 'bootstrap', 'error_response'];
    $requiredWsDtos = ['system_error', 'room_snapshot', 'chat_message', 'chat_ack', 'typing_start', 'typing_stop', 'reaction_event', 'reaction_batch', 'lobby_snapshot', 'signaling_event'];
    foreach ($requiredApiDtos as $dtoName) {
        videochat_contract_schema_assert(isset($catalog['api'][$dtoName]), "missing api DTO {$dtoName}");
    }
    foreach ($requiredWsDtos as $dtoName) {
        videochat_contract_schema_assert(isset($catalog['ws'][$dtoName]), "missing ws DTO {$dtoName}");
    }

    $definitionErrors = [];
    foreach (['api', 'ws'] as $section) {
        foreach ((array) $catalog[$section] as $name => $schema) {
            videochat_contract_schema_assert(is_string($name) && preg_match('/^[a-z][a-z0-9_]*$/', $name) === 1, "{$section} DTO names must be snake_case");
            videochat_contract_schema_validate_definition($schema, "{$section}.{$name}", $definitionErrors);
        }
    }
    if ($definitionErrors !== []) {
        videochat_contract_schema_fail("schema definition errors:\n- " . implode("\n- ", $definitionErrors));
    }

    $restError = videochat_error_envelope('auth_failed', 'A valid session token is required.', ['reason' => 'missing_token'], '2026-04-19T21:30:00+00:00');
    videochat_contract_schema_assert_payload($catalog, 'api', 'error_response', $restError);

    $wsError = videochat_realtime_error_frame('websocket_auth_failed', 'A valid session token is required.', ['reason' => 'missing_token'], '2026-04-19T21:30:01+00:00');
    videochat_contract_schema_assert_payload($catalog, 'ws', 'system_error', $wsError);

    fwrite(STDOUT, "[contract-schema-versioning-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[contract-schema-versioning-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
