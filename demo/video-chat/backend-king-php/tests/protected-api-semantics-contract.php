<?php

declare(strict_types=1);

function videochat_protected_api_semantics_fail(string $message): never
{
    fwrite(STDERR, "[protected-api-semantics-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_protected_api_semantics_assert(bool $condition, string $message): void
{
    if (!$condition) {
        videochat_protected_api_semantics_fail($message);
    }
}

/** @return array<string, mixed> */
function videochat_protected_api_semantics_decode_json(string $path): array
{
    $raw = file_get_contents($path);
    videochat_protected_api_semantics_assert(is_string($raw) && trim($raw) !== '', 'matrix must be readable');

    try {
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        videochat_protected_api_semantics_fail('matrix JSON decode failed: ' . $error->getMessage());
    }

    videochat_protected_api_semantics_assert(is_array($decoded), 'matrix must decode to an object');
    return $decoded;
}

function videochat_protected_api_semantics_is_list(mixed $value): bool
{
    return is_array($value) && array_keys($value) === range(0, count($value) - 1);
}

function videochat_protected_api_semantics_path(string $root, mixed $path, string $label): string
{
    videochat_protected_api_semantics_assert(is_string($path) && trim($path) !== '', "{$label} must be a non-empty path");
    $normalized = str_replace('\\', '/', trim($path));
    videochat_protected_api_semantics_assert($normalized[0] !== '/', "{$label} must be relative: {$normalized}");
    videochat_protected_api_semantics_assert(!str_contains($normalized, '../'), "{$label} must not traverse: {$normalized}");
    videochat_protected_api_semantics_assert(is_file($root . '/' . $normalized), "{$label} does not exist: {$normalized}");
    return $normalized;
}

/** @param array<int, string> $paths */
function videochat_protected_api_semantics_source_text(string $root, array $paths): string
{
    $text = '';
    foreach ($paths as $path) {
        $contents = file_get_contents($root . '/' . $path);
        videochat_protected_api_semantics_assert(is_string($contents), "could not read source {$path}");
        $text .= "\n/* {$path} */\n" . $contents;
    }
    return $text;
}

/** @param array<string, mixed> $semantic */
function videochat_protected_api_semantics_validate_semantic(
    string $endpointId,
    string $semanticName,
    array $semantic,
    string $sourceText,
    array $policy
): void {
    $expectedStatuses = [];
    if (isset($semantic['status'])) {
        videochat_protected_api_semantics_assert(is_int($semantic['status']), "{$endpointId}.{$semanticName}.status must be int");
        $expectedStatuses[] = (int) $semantic['status'];
    }
    if (isset($semantic['statuses'])) {
        videochat_protected_api_semantics_assert(videochat_protected_api_semantics_is_list($semantic['statuses']), "{$endpointId}.{$semanticName}.statuses must be list");
        foreach ($semantic['statuses'] as $status) {
            videochat_protected_api_semantics_assert(is_int($status), "{$endpointId}.{$semanticName}.statuses entries must be int");
            $expectedStatuses[] = (int) $status;
        }
    }
    videochat_protected_api_semantics_assert($expectedStatuses !== [], "{$endpointId}.{$semanticName} needs at least one status");

    if ($semanticName === 'auth') {
        videochat_protected_api_semantics_assert(in_array((int) ($policy['auth_status'] ?? 0), $expectedStatuses, true), "{$endpointId}.auth must include policy auth status");
    } elseif ($semanticName === 'forbidden') {
        videochat_protected_api_semantics_assert(in_array((int) ($policy['forbidden_status'] ?? 0), $expectedStatuses, true), "{$endpointId}.forbidden must include policy forbidden status");
    } elseif ($semanticName === 'conflict') {
        videochat_protected_api_semantics_assert(in_array((int) ($policy['conflict_status'] ?? 0), $expectedStatuses, true), "{$endpointId}.conflict must include policy conflict status");
    } elseif ($semanticName === 'validation') {
        $validationStatuses = is_array($policy['validation_statuses'] ?? null) ? array_map('intval', $policy['validation_statuses']) : [];
        videochat_protected_api_semantics_assert(array_intersect($validationStatuses, $expectedStatuses) !== [], "{$endpointId}.validation must include a policy validation status");
    }

    videochat_protected_api_semantics_assert(videochat_protected_api_semantics_is_list($semantic['error_codes'] ?? null), "{$endpointId}.{$semanticName}.error_codes must be list");
    foreach ($semantic['error_codes'] as $index => $code) {
        videochat_protected_api_semantics_assert(is_string($code) && trim($code) !== '', "{$endpointId}.{$semanticName}.error_codes[{$index}] must be non-empty");
        videochat_protected_api_semantics_assert(str_contains($sourceText, $code), "{$endpointId}.{$semanticName} source evidence missing token {$code}");
    }
}

try {
    $root = realpath(__DIR__ . '/../..');
    videochat_protected_api_semantics_assert(is_string($root) && $root !== '', 'video-chat root must resolve');
    $matrixPath = $root . '/contracts/v1/protected-api-semantics.matrix.json';
    videochat_protected_api_semantics_assert(is_file($matrixPath), 'protected API matrix must exist under contracts/v1');

    $matrix = videochat_protected_api_semantics_decode_json($matrixPath);
    videochat_protected_api_semantics_assert(($matrix['matrix_name'] ?? null) === 'king-video-chat-protected-api-semantics', 'matrix_name mismatch');
    $version = (string) ($matrix['matrix_version'] ?? '');
    videochat_protected_api_semantics_assert(preg_match('/^v1\.\d+\.\d+(?:-[A-Za-z0-9._-]+)?$/', $version) === 1, 'matrix_version must stay pinned to v1 semver form');

    $policy = $matrix['policy'] ?? null;
    videochat_protected_api_semantics_assert(is_array($policy), 'policy must be object');
    videochat_protected_api_semantics_assert((int) ($policy['auth_status'] ?? 0) === 401, 'auth status policy mismatch');
    videochat_protected_api_semantics_assert((int) ($policy['forbidden_status'] ?? 0) === 403, 'forbidden status policy mismatch');
    videochat_protected_api_semantics_assert((int) ($policy['conflict_status'] ?? 0) === 409, 'conflict status policy mismatch');
    videochat_protected_api_semantics_assert(in_array(422, array_map('intval', (array) ($policy['validation_statuses'] ?? [])), true), 'validation status policy must include 422');

    $endpoints = $matrix['endpoints'] ?? null;
    videochat_protected_api_semantics_assert(videochat_protected_api_semantics_is_list($endpoints) && $endpoints !== [], 'endpoints must be non-empty list');

    $ids = [];
    $withForbidden = 0;
    $withConflict = 0;
    $withValidation = 0;
    foreach ($endpoints as $endpointIndex => $endpoint) {
        videochat_protected_api_semantics_assert(is_array($endpoint), "endpoint {$endpointIndex} must be object");
        $endpointId = (string) ($endpoint['id'] ?? '');
        videochat_protected_api_semantics_assert(preg_match('/^[a-z][a-z0-9_]*$/', $endpointId) === 1, "endpoint {$endpointIndex} id must be snake_case");
        videochat_protected_api_semantics_assert(!isset($ids[$endpointId]), "duplicate endpoint id {$endpointId}");
        $ids[$endpointId] = true;

        videochat_protected_api_semantics_assert(is_string($endpoint['surface'] ?? null) && trim((string) $endpoint['surface']) !== '', "{$endpointId}.surface required");
        videochat_protected_api_semantics_assert(is_string($endpoint['protected_by'] ?? null) && trim((string) $endpoint['protected_by']) !== '', "{$endpointId}.protected_by required");
        videochat_protected_api_semantics_assert(videochat_protected_api_semantics_is_list($endpoint['route_patterns'] ?? null) && $endpoint['route_patterns'] !== [], "{$endpointId}.route_patterns required");
        foreach ($endpoint['route_patterns'] as $patternIndex => $pattern) {
            videochat_protected_api_semantics_assert(is_string($pattern) && trim($pattern) !== '', "{$endpointId}.route_patterns[{$patternIndex}] must be non-empty");
        }

        videochat_protected_api_semantics_assert(videochat_protected_api_semantics_is_list($endpoint['source_paths'] ?? null) && $endpoint['source_paths'] !== [], "{$endpointId}.source_paths required");
        $sourcePaths = [];
        foreach ($endpoint['source_paths'] as $pathIndex => $path) {
            $sourcePaths[] = videochat_protected_api_semantics_path($root, $path, "{$endpointId}.source_paths[{$pathIndex}]");
        }
        $sourceText = videochat_protected_api_semantics_source_text($root, $sourcePaths);

        videochat_protected_api_semantics_assert(videochat_protected_api_semantics_is_list($endpoint['contract_paths'] ?? null) && $endpoint['contract_paths'] !== [], "{$endpointId}.contract_paths required");
        foreach ($endpoint['contract_paths'] as $pathIndex => $path) {
            videochat_protected_api_semantics_path($root, $path, "{$endpointId}.contract_paths[{$pathIndex}]");
        }

        $semantics = $endpoint['semantics'] ?? null;
        videochat_protected_api_semantics_assert(is_array($semantics) && $semantics !== [], "{$endpointId}.semantics required");
        foreach ($semantics as $semanticName => $semantic) {
            videochat_protected_api_semantics_assert(is_string($semanticName) && in_array($semanticName, ['auth', 'forbidden', 'validation', 'conflict'], true), "{$endpointId}.semantics has unsupported key {$semanticName}");
            videochat_protected_api_semantics_assert(is_array($semantic), "{$endpointId}.{$semanticName} must be object");
            videochat_protected_api_semantics_validate_semantic($endpointId, $semanticName, $semantic, $sourceText, $policy);
            if ($semanticName === 'forbidden') {
                $withForbidden++;
            } elseif ($semanticName === 'conflict') {
                $withConflict++;
            } elseif ($semanticName === 'validation') {
                $withValidation++;
            }
        }

        $conflictApplicability = (string) ($endpoint['conflict_applicability'] ?? '');
        videochat_protected_api_semantics_assert($conflictApplicability !== '', "{$endpointId}.conflict_applicability required");
        if (!isset($semantics['conflict'])) {
            videochat_protected_api_semantics_assert(str_starts_with($conflictApplicability, 'not_applicable_'), "{$endpointId} without conflict semantics must explain not_applicable_*");
        }
    }

    foreach (['router_rbac_guard', 'admin_user_create', 'admin_user_update_delete', 'calls_cancel', 'call_access_links', 'invite_codes_create_redeem', 'chat_archive_attachments'] as $requiredEndpoint) {
        videochat_protected_api_semantics_assert(isset($ids[$requiredEndpoint]), "required endpoint missing: {$requiredEndpoint}");
    }
    videochat_protected_api_semantics_assert($withForbidden >= 6, 'matrix must cover forbidden semantics across protected APIs');
    videochat_protected_api_semantics_assert($withConflict >= 6, 'matrix must cover conflict semantics across protected APIs');
    videochat_protected_api_semantics_assert($withValidation >= 6, 'matrix must cover validation semantics across protected APIs');

    fwrite(STDOUT, "[protected-api-semantics-contract] PASS endpoints=" . count($endpoints) . " forbidden={$withForbidden} conflict={$withConflict} validation={$withValidation}\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[protected-api-semantics-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
