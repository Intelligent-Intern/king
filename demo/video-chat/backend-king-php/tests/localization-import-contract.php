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
INSERT INTO translation_resources(tenant_id, locale, namespace, resource_key, value)
VALUES(NULL, 'en', :namespace, :resource_key, :value)
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
    videochat_localization_import_assert((int) $pdo->query('SELECT COUNT(*) FROM translation_resources')->fetchColumn() === 1, 'preview must not mutate translation resources');

    $sameFilePreview = videochat_preview_translation_csv($pdo, $sameFilePlaceholderCsv);
    videochat_localization_import_assert($sameFilePreview['ok'] === false, 'same-file missing placeholder preview should fail');
    $sameFileErrorCodes = array_map(static fn (array $error): string => (string) ($error['code'] ?? ''), $sameFilePreview['errors'] ?? []);
    videochat_localization_import_assert(in_array('missing_required_placeholders', $sameFileErrorCodes, true), 'same-file preview must report missing placeholders');

    $invalidPreview = videochat_preview_translation_csv($pdo, $invalidCsv);
    videochat_localization_import_assert($invalidPreview['ok'] === false, 'invalid preview should fail');
    $errorCodes = array_map(static fn (array $error): string => (string) ($error['code'] ?? ''), $invalidPreview['errors'] ?? []);
    videochat_localization_import_assert(in_array('unsupported_locale', $errorCodes, true), 'invalid preview must report unsupported locale');
    videochat_localization_import_assert(in_array('duplicate_key', $errorCodes, true), 'invalid preview must report duplicate key');
    videochat_localization_import_assert(in_array('missing_required_placeholders', $errorCodes, true), 'invalid preview must report missing placeholders');

    $disabledPreview = videochat_handle_localization_routes(
        '/api/admin/localization/imports/preview',
        'POST',
        ['method' => 'POST', 'uri' => '/api/admin/localization/imports/preview', 'body' => json_encode(['csv' => $validCsv])],
        ['user' => ['id' => 2, 'role' => 'admin']],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_localization_import_assert(is_array($disabledPreview), 'disabled preview response should be returned');
    videochat_localization_import_assert((int) ($disabledPreview['status'] ?? 0) === 410, 'CSV preview route must be disabled');
    $disabledPreviewPayload = videochat_localization_import_decode($disabledPreview);
    videochat_localization_import_assert(
        (string) (($disabledPreviewPayload['error'] ?? [])['code'] ?? '') === 'localization_csv_import_disabled',
        'CSV preview route must report disabled import code'
    );

    $disabledCommit = videochat_handle_localization_routes(
        '/api/admin/localization/imports/commit',
        'POST',
        ['method' => 'POST', 'uri' => '/api/admin/localization/imports/commit', 'body' => json_encode(['csv' => $validCsv, 'file_name' => 'good.csv'])],
        ['user' => ['id' => 1, 'role' => 'admin']],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_localization_import_assert(is_array($disabledCommit), 'disabled commit response should be returned');
    videochat_localization_import_assert((int) ($disabledCommit['status'] ?? 0) === 410, 'CSV commit route must be disabled');
    videochat_localization_import_assert((int) $pdo->query('SELECT COUNT(*) FROM translation_resources')->fetchColumn() === 1, 'disabled CSV routes must not mutate translation resources');
    videochat_localization_import_assert((int) (videochat_list_translation_imports($pdo)['total'] ?? 0) === 0, 'disabled CSV routes must not create import history');

    @unlink($databasePath);
    fwrite(STDOUT, "[localization-import-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[localization-import-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
