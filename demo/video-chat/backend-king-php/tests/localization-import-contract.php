<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/localization/translation_imports.php';
require_once __DIR__ . '/../http/module_localization.php';

function videochat_localization_import_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[localization-import-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_localization_import_decode(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($payload) ? $payload : [];
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-localization-import-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $adminId = (int) $pdo->query("SELECT users.id FROM users INNER JOIN roles ON roles.id = users.role_id WHERE roles.slug = 'admin' ORDER BY users.id ASC LIMIT 1")->fetchColumn();
    videochat_localization_import_assert($adminId === 1, 'primary superadmin user id must be 1 in seeded test database');
    $seedCanonical = $pdo->prepare(
        <<<'SQL'
INSERT INTO translation_resources(tenant_id, locale, namespace, resource_key, value, source)
VALUES(NULL, 'en', :namespace, :resource_key, :value, 'contract')
SQL
    );
    $seedCanonical->execute([
        ':namespace' => 'common',
        ':resource_key' => 'invite',
        ':value' => 'Hello {name}, open {link}.',
    ]);

    $jsonResponse = static function (int $status, array $payload): array {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json; charset=utf-8'],
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    };
    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        return $jsonResponse($status, [
            'status' => 'error',
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
            'time' => gmdate('c'),
        ]);
    };
    $decodeJsonBody = static function (array $request): array {
        $decoded = json_decode((string) ($request['body'] ?? ''), true);
        return is_array($decoded) ? [$decoded, null] : [null, 'invalid_json'];
    };
    $openDatabase = static fn (): PDO => videochat_open_sqlite_pdo($databasePath);

    $validCsv = "locale,namespace,key,value\n"
        . "de,common,save,Speichern\n"
        . "sgd,common,save,Surigaonon Save\n";
    $invalidCsv = "locale,namespace,key,value\n"
        . "xx,common,save,Bad Locale\n"
        . "de,common,save,One\n"
        . "de,common,save,Two\n"
        . "de,common,invite,Hallo ohne Link\n";
    $sameFilePlaceholderCsv = "locale,namespace,key,value\n"
        . "en,common,welcome,Welcome {name}\n"
        . "de,common,welcome,Willkommen\n";

    $preview = videochat_preview_translation_csv($pdo, $validCsv);
    videochat_localization_import_assert($preview['ok'] === true, 'valid preview should pass');
    videochat_localization_import_assert((int) $preview['valid_rows'] === 2, 'valid preview row count mismatch');
    videochat_localization_import_assert((int) $pdo->query("SELECT COUNT(*) FROM translation_resources WHERE source <> 'contract'")->fetchColumn() === 0, 'preview must not mutate translation resources');

    $sameFilePreview = videochat_preview_translation_csv($pdo, $sameFilePlaceholderCsv);
    videochat_localization_import_assert($sameFilePreview['ok'] === false, 'same-file missing placeholder preview should fail');
    $sameFileErrorCodes = array_map(static fn (array $error): string => (string) ($error['code'] ?? ''), $sameFilePreview['errors'] ?? []);
    videochat_localization_import_assert(in_array('missing_required_placeholders', $sameFileErrorCodes, true), 'same-file preview must report missing placeholders');

    $forbiddenResponse = videochat_handle_localization_routes(
        '/api/admin/localization/imports/preview',
        'POST',
        ['method' => 'POST', 'uri' => '/api/admin/localization/imports/preview', 'body' => json_encode(['csv' => $validCsv])],
        ['user' => ['id' => 2, 'role' => 'admin']],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_localization_import_assert(is_array($forbiddenResponse), 'forbidden response should be returned');
    videochat_localization_import_assert((int) ($forbiddenResponse['status'] ?? 0) === 403, 'non-primary admin import should be forbidden');

    $invalidCommit = videochat_handle_localization_routes(
        '/api/admin/localization/imports/commit',
        'POST',
        ['method' => 'POST', 'uri' => '/api/admin/localization/imports/commit', 'body' => json_encode(['csv' => $invalidCsv, 'file_name' => 'bad.csv'])],
        ['user' => ['id' => 1, 'role' => 'admin']],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_localization_import_assert(is_array($invalidCommit), 'invalid commit response should be returned');
    videochat_localization_import_assert((int) ($invalidCommit['status'] ?? 0) === 422, 'invalid commit should fail validation');
    $invalidPayload = videochat_localization_import_decode($invalidCommit);
    $previewErrors = (((($invalidPayload['error'] ?? [])['details'] ?? [])['preview'] ?? [])['errors'] ?? []);
    $errorCodes = array_map(static fn (array $error): string => (string) ($error['code'] ?? ''), is_array($previewErrors) ? $previewErrors : []);
    videochat_localization_import_assert(in_array('unsupported_locale', $errorCodes, true), 'invalid commit must report unsupported locale');
    videochat_localization_import_assert(in_array('duplicate_key', $errorCodes, true), 'invalid commit must report duplicate key');
    videochat_localization_import_assert(in_array('missing_required_placeholders', $errorCodes, true), 'invalid commit must report missing placeholders');
    videochat_localization_import_assert((int) $pdo->query("SELECT COUNT(*) FROM translation_resources WHERE source <> 'contract'")->fetchColumn() === 0, 'failed commit must not mutate translation resources');

    $validCommit = videochat_handle_localization_routes(
        '/api/admin/localization/imports/commit',
        'POST',
        ['method' => 'POST', 'uri' => '/api/admin/localization/imports/commit', 'body' => json_encode(['csv' => $validCsv, 'file_name' => 'good.csv'])],
        ['user' => ['id' => 1, 'role' => 'admin']],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_localization_import_assert(is_array($validCommit), 'valid commit response should be returned');
    videochat_localization_import_assert((int) ($validCommit['status'] ?? 0) === 200, 'valid commit status mismatch');
    $validPayload = videochat_localization_import_decode($validCommit);
    $importId = (string) (((($validPayload['result'] ?? [])['import'] ?? [])['id'] ?? ''));
    videochat_localization_import_assert($importId !== '', 'valid commit should return import id');

    $resources = videochat_fetch_translation_resources($pdo, 'de', null, ['common']);
    videochat_localization_import_assert(($resources['common.save'] ?? '') === 'Speichern', 'committed translation should be visible in resource lookup');

    $imports = videochat_list_translation_imports($pdo);
    videochat_localization_import_assert((int) ($imports['total'] ?? 0) === 1, 'import history total mismatch');
    $import = videochat_fetch_translation_import($pdo, $importId);
    videochat_localization_import_assert(is_array($import), 'import detail should be fetchable');
    videochat_localization_import_assert((int) ($import['row_count'] ?? 0) === 2, 'import detail row count mismatch');

    $bundles = videochat_list_translation_bundles($pdo);
    videochat_localization_import_assert(count($bundles) >= 2, 'translation bundle list should include committed locale namespaces');
    $bundle = videochat_fetch_translation_bundle($pdo, 'de', 'common');
    videochat_localization_import_assert(is_array($bundle), 'translation bundle detail should be fetchable');
    videochat_localization_import_assert(count($bundle['resources'] ?? []) === 1, 'translation bundle resource count mismatch');

    @unlink($databasePath);
    fwrite(STDOUT, "[localization-import-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[localization-import-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
