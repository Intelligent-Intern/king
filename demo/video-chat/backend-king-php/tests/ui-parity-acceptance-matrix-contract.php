<?php

declare(strict_types=1);

function videochat_ui_parity_matrix_fail(string $message): never
{
    fwrite(STDERR, "[ui-parity-acceptance-matrix-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_ui_parity_matrix_assert(bool $condition, string $message): void
{
    if (!$condition) {
        videochat_ui_parity_matrix_fail($message);
    }
}

/**
 * @return array<string, mixed>
 */
function videochat_ui_parity_matrix_decode_json(string $path, string $label): array
{
    $raw = file_get_contents($path);
    videochat_ui_parity_matrix_assert(is_string($raw) && trim($raw) !== '', "{$label} must be readable");

    try {
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        videochat_ui_parity_matrix_fail("{$label} JSON decode failed: " . $error->getMessage());
    }

    videochat_ui_parity_matrix_assert(is_array($decoded), "{$label} must decode to an object");

    return $decoded;
}

function videochat_ui_parity_matrix_is_list_array(mixed $value): bool
{
    return is_array($value) && array_keys($value) === range(0, count($value) - 1);
}

function videochat_ui_parity_matrix_relative_path(string $root, mixed $path, string $label, bool $mustExist): string
{
    videochat_ui_parity_matrix_assert(is_string($path) && trim($path) !== '', "{$label} path must be a non-empty string");
    $normalized = str_replace('\\', '/', trim($path));
    videochat_ui_parity_matrix_assert($normalized[0] !== '/', "{$label} path must be relative: {$normalized}");
    videochat_ui_parity_matrix_assert(!str_contains($normalized, '../'), "{$label} path must not traverse: {$normalized}");

    if ($mustExist) {
        $absolute = $root . '/' . $normalized;
        videochat_ui_parity_matrix_assert(is_file($absolute), "{$label} path missing: {$normalized}");
    }

    return $normalized;
}

try {
    $root = realpath(__DIR__ . '/../..');
    videochat_ui_parity_matrix_assert(is_string($root) && $root !== '', 'video-chat root must resolve');

    $matrixPath = $root . '/contracts/v1/ui-parity-acceptance.matrix.json';
    $packagePath = $root . '/frontend-vue/package.json';
    videochat_ui_parity_matrix_assert(is_file($matrixPath), 'ui parity matrix must exist under contracts/v1');
    videochat_ui_parity_matrix_assert(is_file($packagePath), 'frontend package.json must exist');

    $matrix = videochat_ui_parity_matrix_decode_json($matrixPath, 'ui parity matrix');
    $package = videochat_ui_parity_matrix_decode_json($packagePath, 'frontend package.json');

    videochat_ui_parity_matrix_assert(($matrix['matrix_name'] ?? null) === 'king-video-chat-ui-parity', 'matrix_name mismatch');
    $version = (string) ($matrix['matrix_version'] ?? '');
    videochat_ui_parity_matrix_assert(preg_match('/^v1\.\d+\.\d+(?:-[A-Za-z0-9._-]+)?$/', $version) === 1, 'matrix_version must stay pinned to v1 semver form');

    $policy = $matrix['release_policy'] ?? null;
    videochat_ui_parity_matrix_assert(is_array($policy), 'release_policy must be an object');
    videochat_ui_parity_matrix_assert(($policy['mode'] ?? '') === 'tracked_gaps_block_release', 'release_policy.mode must keep gaps release-blocking');
    videochat_ui_parity_matrix_assert(($policy['strict_env'] ?? '') === 'VIDEOCHAT_UI_PARITY_REQUIRE_COVERED', 'strict release env mismatch');

    $packageScripts = $package['scripts'] ?? [];
    videochat_ui_parity_matrix_assert(is_array($packageScripts), 'package scripts must be available');
    videochat_ui_parity_matrix_assert(isset($packageScripts['test:e2e:ui-parity']), 'package script test:e2e:ui-parity is required');

    $commands = $matrix['commands'] ?? null;
    videochat_ui_parity_matrix_assert(is_array($commands) && $commands !== [], 'commands must be a non-empty object');

    foreach ($commands as $commandId => $command) {
        videochat_ui_parity_matrix_assert(is_string($commandId) && preg_match('/^[a-z][a-z0-9:_-]*$/', $commandId) === 1, "invalid command id {$commandId}");
        videochat_ui_parity_matrix_assert(is_array($command), "command {$commandId} must be an object");
        $kind = (string) ($command['kind'] ?? '');
        videochat_ui_parity_matrix_assert(in_array($kind, ['npm_script', 'shell'], true), "command {$commandId} kind invalid");
        videochat_ui_parity_matrix_assert(is_string($command['command'] ?? null) && trim((string) $command['command']) !== '', "command {$commandId} command string required");

        $workingDirectory = (string) ($command['working_directory'] ?? '');
        videochat_ui_parity_matrix_assert($workingDirectory !== '', "command {$commandId} working_directory required");
        videochat_ui_parity_matrix_assert(is_dir($root . '/' . $workingDirectory), "command {$commandId} working_directory missing: {$workingDirectory}");

        if ($kind === 'npm_script') {
            $script = (string) ($command['script'] ?? '');
            videochat_ui_parity_matrix_assert($script !== '', "command {$commandId} npm script required");
            videochat_ui_parity_matrix_assert(isset($packageScripts[$script]), "command {$commandId} references missing package script {$script}");
        }

        videochat_ui_parity_matrix_assert(videochat_ui_parity_matrix_is_list_array($command['paths'] ?? null), "command {$commandId} paths must be a list");
        foreach ($command['paths'] as $index => $path) {
            videochat_ui_parity_matrix_relative_path($root, $path, "command {$commandId} paths[{$index}]", true);
        }
    }

    $slices = $matrix['slices'] ?? null;
    videochat_ui_parity_matrix_assert(videochat_ui_parity_matrix_is_list_array($slices) && $slices !== [], 'slices must be a non-empty list');

    $sliceIds = [];
    $covered = 0;
    $gaps = 0;
    foreach ($slices as $sliceIndex => $slice) {
        videochat_ui_parity_matrix_assert(is_array($slice), "slice {$sliceIndex} must be an object");
        $sliceId = (string) ($slice['id'] ?? '');
        videochat_ui_parity_matrix_assert(preg_match('/^[a-z][a-z0-9_]*$/', $sliceId) === 1, "slice {$sliceIndex} id must be snake_case");
        videochat_ui_parity_matrix_assert(!isset($sliceIds[$sliceId]), "duplicate slice id {$sliceId}");
        $sliceIds[$sliceId] = true;

        $status = (string) ($slice['status'] ?? '');
        videochat_ui_parity_matrix_assert(in_array($status, ['covered', 'gap'], true), "slice {$sliceId} status invalid");
        videochat_ui_parity_matrix_assert(videochat_ui_parity_matrix_is_list_array($slice['readiness_refs'] ?? null) && $slice['readiness_refs'] !== [], "slice {$sliceId} readiness_refs required");
        videochat_ui_parity_matrix_assert(videochat_ui_parity_matrix_is_list_array($slice['acceptance'] ?? null) && $slice['acceptance'] !== [], "slice {$sliceId} acceptance required");

        foreach ($slice['readiness_refs'] as $refIndex => $ref) {
            videochat_ui_parity_matrix_assert(is_string($ref) && trim($ref) !== '', "slice {$sliceId} readiness_refs[{$refIndex}] must be non-empty");
        }
        foreach ($slice['acceptance'] as $acceptanceIndex => $text) {
            videochat_ui_parity_matrix_assert(is_string($text) && trim($text) !== '', "slice {$sliceId} acceptance[{$acceptanceIndex}] must be non-empty");
        }

        if ($status === 'covered') {
            $covered++;
            videochat_ui_parity_matrix_assert(empty($slice['release_blocking']), "covered slice {$sliceId} must not be marked release_blocking");
            videochat_ui_parity_matrix_assert(videochat_ui_parity_matrix_is_list_array($slice['checks'] ?? null) && $slice['checks'] !== [], "covered slice {$sliceId} checks required");

            foreach ($slice['checks'] as $checkIndex => $check) {
                videochat_ui_parity_matrix_assert(is_array($check), "slice {$sliceId} checks[{$checkIndex}] must be object");
                $commandId = (string) ($check['command_id'] ?? '');
                videochat_ui_parity_matrix_assert(isset($commands[$commandId]), "slice {$sliceId} references unknown command {$commandId}");
                videochat_ui_parity_matrix_assert(videochat_ui_parity_matrix_is_list_array($check['paths'] ?? null) && $check['paths'] !== [], "slice {$sliceId} checks[{$checkIndex}] paths required");
                foreach ($check['paths'] as $pathIndex => $path) {
                    videochat_ui_parity_matrix_relative_path($root, $path, "slice {$sliceId} check {$checkIndex} paths[{$pathIndex}]", true);
                }
            }
        } else {
            $gaps++;
            videochat_ui_parity_matrix_assert(($slice['release_blocking'] ?? null) === true, "gap slice {$sliceId} must be release_blocking");
            videochat_ui_parity_matrix_assert(!array_key_exists('checks', $slice), "gap slice {$sliceId} must not pretend to have executable checks");
            videochat_ui_parity_matrix_assert(videochat_ui_parity_matrix_is_list_array($slice['expected_checks'] ?? null) && $slice['expected_checks'] !== [], "gap slice {$sliceId} expected_checks required");
            foreach ($slice['expected_checks'] as $expectedIndex => $expected) {
                videochat_ui_parity_matrix_assert(is_array($expected), "slice {$sliceId} expected_checks[{$expectedIndex}] must be object");
                videochat_ui_parity_matrix_assert(is_string($expected['kind'] ?? null) && trim((string) $expected['kind']) !== '', "slice {$sliceId} expected_checks[{$expectedIndex}] kind required");
                videochat_ui_parity_matrix_relative_path($root, $expected['path'] ?? null, "slice {$sliceId} expected_checks[{$expectedIndex}]", false);
            }
        }
    }

    videochat_ui_parity_matrix_assert($covered > 0, 'matrix must contain covered executable slices');
    videochat_ui_parity_matrix_assert($gaps > 0, 'matrix must honestly retain open release-blocking gaps');

    $strict = trim((string) getenv('VIDEOCHAT_UI_PARITY_REQUIRE_COVERED')) === '1';
    if ($strict && $gaps > 0) {
        videochat_ui_parity_matrix_fail("strict release validation blocked by {$gaps} UI parity gap(s)");
    }

    fwrite(STDOUT, "[ui-parity-acceptance-matrix-contract] PASS covered={$covered} gaps={$gaps}\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[ui-parity-acceptance-matrix-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
