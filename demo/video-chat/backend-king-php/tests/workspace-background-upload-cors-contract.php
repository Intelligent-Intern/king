<?php

declare(strict_types=1);

require_once __DIR__ . '/../http/router.php';

function videochat_workspace_background_upload_cors_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[workspace-background-upload-cors-contract] FAIL: {$message}\n");
    exit(1);
}

$jsonResponse = static function (int $status, array $payload): array {
    return [
        'status' => $status,
        'headers' => ['content-type' => 'application/json'],
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

$activeWebsocketsBySession = [];
$presenceState = [];
$lobbyState = [];
$typingState = [];
$reactionState = [];

$response = videochat_dispatch_request(
    [
        'method' => 'OPTIONS',
        'path' => '/api/admin/workspace-administration/background-images',
        'uri' => '/api/admin/workspace-administration/background-images',
        'headers' => [
            'origin' => 'https://app.kingrt.com',
            'access-control-request-method' => 'POST',
            'access-control-request-headers' => 'authorization,content-type,x-upload-trace-id,x-upload-batch-index,x-upload-batch-count',
        ],
    ],
    $activeWebsocketsBySession,
    $presenceState,
    $lobbyState,
    $typingState,
    $reactionState,
    $jsonResponse,
    $errorResponse,
    static fn (array $request): string => strtoupper(trim((string) ($request['method'] ?? 'GET'))) ?: 'GET',
    static fn (array $request): array => [null, 'not_used'],
    static fn (): PDO => throw new RuntimeException('preflight must not open database'),
    static fn (): string => 'sess-cors-contract',
    static fn (array $request): string => (string) ($request['path'] ?? '/'),
    static fn (): array => ['service' => 'video-chat-backend-king-php', 'time' => gmdate('c')],
    '/ws',
    '/tmp',
    1024
);

videochat_workspace_background_upload_cors_contract_assert((int) ($response['status'] ?? 0) === 204, 'background upload preflight must return 204');
$headers = is_array($response['headers'] ?? null) ? (array) $response['headers'] : [];
$allowedHeaders = strtolower((string) ($headers['access-control-allow-headers'] ?? ''));
foreach ([
    'authorization',
    'content-type',
    'x-session-id',
    'x-upload-trace-id',
    'x-upload-batch-index',
    'x-upload-batch-count',
] as $requiredHeader) {
    videochat_workspace_background_upload_cors_contract_assert(
        str_contains($allowedHeaders, $requiredHeader),
        "preflight must allow {$requiredHeader}"
    );
}

fwrite(STDOUT, "[workspace-background-upload-cors-contract] PASS\n");
