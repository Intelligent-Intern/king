<?php

declare(strict_types=1);

require_once __DIR__ . '/../http/router.php';

function model_inference_ui_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[ui-chat-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $assetPath = __DIR__ . '/../public/chat.html';
    model_inference_ui_contract_assert(is_file($assetPath), 'public/chat.html must exist on disk');
    $rawAsset = (string) file_get_contents($assetPath);
    model_inference_ui_contract_assert($rawAsset !== '', 'public/chat.html must be non-empty');

    $jsonResponse = static function (int $status, array $payload): array {
        return ['status' => $status, 'headers' => ['content-type' => 'application/json'], 'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    };
    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        return $jsonResponse($status, ['status' => 'error', 'error' => ['code' => $code, 'message' => $message, 'details' => $details], 'time' => gmdate('c')]);
    };
    $methodFromRequest = static function (array $r): string { return strtoupper((string) ($r['method'] ?? 'GET')); };
    $pathFromRequest = static function (array $r): string { return (string) ($r['path'] ?? '/'); };
    $runtimeEnvelope = static function (): array { return ['node' => ['node_id' => 'node_ui_contract', 'role' => 'inference-serving']]; };
    $openDatabase = static function (): PDO {
        throw new RuntimeException('openDatabase must not be reached by /ui.');
    };
    $getSession = static function () {
        throw new RuntimeException('inference session must not be reached by /ui.');
    };
    $getMetrics = static function () {
        throw new RuntimeException('inference metrics must not be reached by /ui.');
    };

    $dispatch = static function (string $method, string $path) use (
        $jsonResponse, $errorResponse, $methodFromRequest, $pathFromRequest,
        $runtimeEnvelope, $openDatabase, $getSession, $getMetrics
    ): array {
        return model_inference_dispatch_request(
            ['method' => $method, 'path' => $path, 'uri' => $path, 'headers' => []],
            $jsonResponse, $errorResponse, $methodFromRequest, $pathFromRequest,
            $runtimeEnvelope, $openDatabase, $getSession, $getMetrics,
            '/ws', '127.0.0.1', 18090
        );
    };

    // 1. GET /ui → 200, text/html, security headers pinned.
    $response = $dispatch('GET', '/ui');
    model_inference_ui_contract_assert((int) $response['status'] === 200, 'GET /ui must 200');
    model_inference_ui_contract_assert(
        str_contains((string) ($response['headers']['content-type'] ?? ''), 'text/html'),
        'content-type must include text/html'
    );
    model_inference_ui_contract_assert(
        ($response['headers']['cache-control'] ?? null) === 'no-store',
        'cache-control must be no-store (the asset is not stable across sessions)'
    );
    model_inference_ui_contract_assert(
        ($response['headers']['x-content-type-options'] ?? null) === 'nosniff',
        'x-content-type-options must be nosniff'
    );

    // 2. Trailing-slash variant also resolves.
    $responseTrailing = $dispatch('GET', '/ui/');
    model_inference_ui_contract_assert((int) $responseTrailing['status'] === 200, 'GET /ui/ must also 200');

    // 3. HEAD returns headers without a body.
    $responseHead = $dispatch('HEAD', '/ui');
    model_inference_ui_contract_assert((int) $responseHead['status'] === 200, 'HEAD /ui must 200');
    model_inference_ui_contract_assert($responseHead['body'] === '', 'HEAD /ui must return an empty body');

    // 4. Non-GET/HEAD is 405 method_not_allowed.
    $badMethod = $dispatch('POST', '/ui');
    model_inference_ui_contract_assert((int) $badMethod['status'] === 405, 'POST /ui must 405');
    $badPayload = json_decode((string) $badMethod['body'], true);
    model_inference_ui_contract_assert(
        (($badPayload['error'] ?? [])['code'] ?? null) === 'method_not_allowed',
        'POST /ui must emit method_not_allowed'
    );

    // 5. Body is the same bytes we shipped on disk (no templating, no
    // hidden injection). Byte-identical guarantees the contract tests
    // can reason about the HTML deterministically.
    model_inference_ui_contract_assert(
        $response['body'] === $rawAsset,
        'GET /ui body must be byte-identical to public/chat.html'
    );

    // 6. Critical markers that clients depend on: page opens WS against
    // /ws, binary frame decoder expects "KITF" magic, and the form is
    // present so a human can type a prompt.
    $markers = [
        "/ws'",
        '0x4B495446',
        '<form id="form"',
        '<textarea id="prompt"',
        '<button id="send"',
        'event: \'infer.start\'',
        'FT_DELTA = 0',
        'FT_END = 1',
        'FT_ERROR = 2',
    ];
    foreach ($markers as $marker) {
        model_inference_ui_contract_assert(
            str_contains($rawAsset, $marker),
            "chat.html must contain marker: {$marker}"
        );
    }

    // 7. Catalog-fixture sanity: /ui is listed with the expected shape.
    $catalog = json_decode((string) file_get_contents(__DIR__ . '/../../contracts/v1/api-ws-contract.catalog.json'), true);
    model_inference_ui_contract_assert(is_array($catalog), 'catalog must decode');
    $entry = $catalog['api']['chat_ui'] ?? null;
    model_inference_ui_contract_assert(is_array($entry), 'catalog.api.chat_ui must be listed');
    model_inference_ui_contract_assert(
        (string) ($entry['content_type'] ?? '') === 'text/html; charset=utf-8',
        'catalog.api.chat_ui.content_type must match the route'
    );
    model_inference_ui_contract_assert(
        is_array($entry['paths'] ?? null) && in_array('/ui', $entry['paths'], true) && in_array('/ui/', $entry['paths'], true),
        'catalog.api.chat_ui.paths must list both /ui and /ui/'
    );

    fwrite(STDOUT, "[ui-chat-contract] PASS (html " . number_format(strlen($rawAsset)) . " bytes; 9 markers asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[ui-chat-contract] ERROR: ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine() . "\n");
    exit(1);
}
