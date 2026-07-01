<?php
declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_string($path)) {
        $file = __DIR__ . '/../public' . $path;
        if ($path !== '/' && is_file($file)) {
            return false;
        }
    }
}

function chat_env_string(string $key, string $fallback): string
{
    $value = getenv($key);
    return is_string($value) && trim($value) !== '' ? trim($value) : $fallback;
}

function chat_env_int(string $key, int $fallback): int
{
    $value = getenv($key);
    return is_string($value) && preg_match('/^\d+$/', $value) === 1 ? max(1, (int) $value) : $fallback;
}

function chat_json_response(int $status, array $payload): void
{
    http_response_code($status);
    header('content-type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function chat_error(int $status, string $code, string $message): void
{
    chat_json_response($status, [
        'ok' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ]);
}

function chat_http_status_from_headers(array $headers): ?int
{
    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $header, $match) === 1) {
            return (int) $match[1];
        }
    }
    return null;
}

function chat_runtime_models(): array
{
    $modelsUrl = chat_env_string('KING_CHAT_MODELS_URL', 'http://inference:8080/v1/models');
    $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 2, 'ignore_errors' => true]]);
    $raw = @file_get_contents($modelsUrl, false, $context);
    $status = chat_http_status_from_headers($http_response_header ?? []);
    if ($status !== 200 || !is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !is_array($decoded['data'] ?? null)) {
        return [];
    }

    $models = [];
    foreach ($decoded['data'] as $model) {
        if (!is_array($model) || !is_string($model['id'] ?? null) || trim($model['id']) === '') {
            continue;
        }
        $models[] = trim($model['id']);
    }

    return array_values(array_unique($models));
}

function chat_runtime_model(): string
{
    $configured = chat_env_string('KING_CHAT_MODEL', 'gemma4:12b');
    $models = chat_runtime_models();
    if (in_array($configured, $models, true)) {
        return $configured;
    }
    return $models[0] ?? $configured;
}

function chat_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        chat_error(400, 'empty_body', 'Request body must be a JSON object.');
        exit;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        chat_error(400, 'invalid_json', 'Request body must be valid JSON.');
        exit;
    }

    return $decoded;
}

function chat_normalize_messages(mixed $messages, int $maxMessages, int $maxChars): array
{
    if (!is_array($messages) || $messages === []) {
        chat_error(400, 'messages_required', 'At least one user message is required.');
        exit;
    }
    if (count($messages) > $maxMessages) {
        chat_error(413, 'too_many_messages', 'The chat history is too long for this test route.');
        exit;
    }

    $normalized = [];
    foreach ($messages as $message) {
        if (!is_array($message)) {
            chat_error(400, 'invalid_message', 'Each message must be an object.');
            exit;
        }

        $role = $message['role'] ?? null;
        $content = $message['content'] ?? null;
        if (!in_array($role, ['system', 'user', 'assistant'], true) || !is_string($content)) {
            chat_error(400, 'invalid_message_shape', 'Messages require role system/user/assistant and string content.');
            exit;
        }

        $content = trim($content);
        if ($content === '') {
            continue;
        }
        if (mb_strlen($content, 'UTF-8') > $maxChars) {
            chat_error(413, 'message_too_large', 'A message exceeds the configured character limit.');
            exit;
        }

        $normalized[] = [
            'role' => $role,
            'content' => $content,
        ];
    }

    if ($normalized === [] || end($normalized)['role'] !== 'user') {
        chat_error(400, 'last_message_must_be_user', 'The last non-empty message must be a user message.');
        exit;
    }

    return array_values($normalized);
}

function chat_system_instruction(): ?array
{
    $content = chat_env_string('KING_CHAT_SYSTEM_PROMPT', '');
    if ($content === '') {
        return null;
    }

    return [
        'role' => 'system',
        'content' => $content,
    ];
}

function chat_latest_user_text(array $messages): string
{
    for ($index = count($messages) - 1; $index >= 0; $index--) {
        $message = $messages[$index];
        if (($message['role'] ?? null) === 'user' && is_string($message['content'] ?? null)) {
            return $message['content'];
        }
    }
    return '';
}

function chat_utf8_chars(string $value): array
{
    if ($value === '') {
        return [];
    }
    return preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

function chat_count_occurrences(string $text, string $needle, bool $caseSensitive): int
{
    if (!$caseSensitive) {
        $text = mb_strtolower($text, 'UTF-8');
        $needle = mb_strtolower($needle, 'UTF-8');
    }

    $count = 0;
    foreach (chat_utf8_chars($text) as $char) {
        if ($char === $needle) {
            $count++;
        }
    }
    return $count;
}

function chat_extract_count_occurrences_task(string $text): ?array
{
    $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if ($normalized === '') {
        return null;
    }

    $patterns = [
        '/\bhow many (?:letters?|characters?) ["\']?(\p{L}|\p{N})["\']? (?:are )?in (?:the )?(?:word|string )?["\']?([\p{L}\p{N}_ -]{1,80})["\']?\??$/iu',
        '/\bhow often (?:does|is) ["\']?(\p{L}|\p{N})["\']? (?:appear|occurs?|contained) in (?:the )?(?:word|string )?["\']?([\p{L}\p{N}_ -]{1,80})["\']?\??$/iu',
        '/\bwie ?viele (?:buchstaben|zeichen) ["\']?(\p{L}|\p{N})["\']? (?:hat|sind in|kommen in) (?:dem |der |das |wort |string )?["\']?([\p{L}\p{N}_ -]{1,80})["\']?\??$/iu',
        '/\bwie oft (?:kommt|ist) ["\']?(\p{L}|\p{N})["\']? in (?:dem |der |das |wort |string )?["\']?([\p{L}\p{N}_ -]{1,80})["\']? (?:vor|enthalten)\??$/iu',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $normalized, $match) !== 1) {
            continue;
        }

        $needle = trim($match[1]);
        $subject = trim($match[2], " \t\n\r\0\x0B\"'");
        if ($needle === '' || $subject === '') {
            continue;
        }

        return [
            'operation' => 'count_occurrences',
            'subject' => $subject,
            'needle' => $needle,
            'case_sensitive' => true,
            'result' => chat_count_occurrences($subject, $needle, true),
        ];
    }

    return null;
}

function chat_miniops_verifier_message(array $messages): ?array
{
    if (chat_env_string('KING_CHAT_MINIOPS_ENABLE', '0') !== '1') {
        return null;
    }

    $task = chat_extract_count_occurrences_task(chat_latest_user_text($messages));
    if ($task === null) {
        return null;
    }

    $program = [
        'version' => 1,
        'operation' => $task['operation'],
        'input' => [
            'text' => $task['subject'],
            'needle' => $task['needle'],
            'case_sensitive' => $task['case_sensitive'],
            'unit' => 'unicode_codepoint',
        ],
        'result' => $task['result'],
        'safety' => [
            'filesystem' => false,
            'network' => false,
            'cli' => false,
            'eval' => false,
        ],
    ];

    return [
        'role' => 'system',
        'content' => "King deterministic mini-op verifier:\n"
            . "Use this verified result. Do not estimate this task from model memory.\n"
            . "~~~king-miniops\n"
            . json_encode($program, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n~~~",
    ];
}

function chat_prepare_model_messages(array $messages): array
{
    $prepared = [];
    $instruction = chat_system_instruction();
    if ($instruction !== null) {
        $prepared[] = $instruction;
    }

    $verifier = chat_miniops_verifier_message($messages);
    if ($verifier !== null) {
        $prepared[] = $verifier;
    }
    foreach ($messages as $message) {
        if (($message['role'] ?? null) === 'system') {
            continue;
        }
        $prepared[] = $message;
    }
    return $prepared;
}

function chat_now(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function chat_db_path(): string
{
    return chat_env_string('KING_CHAT_DB_PATH', dirname(__DIR__) . '/var/chat.sqlite');
}

function chat_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = chat_db_path();
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create chat database directory.');
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 3000');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS chat_threads (
            id TEXT PRIMARY KEY,
            title TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id TEXT NOT NULL,
            role TEXT NOT NULL CHECK (role IN (\'user\', \'assistant\')),
            content TEXT NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (thread_id) REFERENCES chat_threads(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS chat_messages_thread_created_idx ON chat_messages(thread_id, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS chat_threads_updated_idx ON chat_threads(updated_at DESC)');

    return $pdo;
}

function chat_thread_id(): string
{
    return 'thr_' . bin2hex(random_bytes(12));
}

function chat_normalize_thread_id(mixed $value): string
{
    if (!is_string($value) || preg_match('/^thr_[a-f0-9]{24}$/', $value) !== 1) {
        chat_error(400, 'invalid_thread_id', 'Thread id is invalid.');
        exit;
    }
    return $value;
}

function chat_title_from_text(string $text): string
{
    $line = trim((string) preg_replace('/\s+/u', ' ', $text));
    if ($line === '') {
        return 'New chat';
    }
    if (mb_strlen($line, 'UTF-8') > 72) {
        $line = mb_substr($line, 0, 69, 'UTF-8') . '...';
    }
    return $line;
}

function chat_thread_row(string $threadId): ?array
{
    $stmt = chat_db()->prepare(
        'SELECT t.id, t.title, t.created_at, t.updated_at,
            (SELECT COUNT(*) FROM chat_messages m WHERE m.thread_id = t.id) AS message_count
         FROM chat_threads t
         WHERE t.id = :id'
    );
    $stmt->execute([':id' => $threadId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function chat_require_thread(string $threadId): array
{
    $thread = chat_thread_row($threadId);
    if ($thread === null) {
        chat_error(404, 'thread_not_found', 'Thread was not found.');
        exit;
    }
    return $thread;
}

function chat_create_thread(string $title = 'New chat'): array
{
    $threadId = chat_thread_id();
    $now = chat_now();
    $stmt = chat_db()->prepare(
        'INSERT INTO chat_threads (id, title, created_at, updated_at)
         VALUES (:id, :title, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':id' => $threadId,
        ':title' => chat_title_from_text($title),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    return chat_require_thread($threadId);
}

function chat_update_thread_timestamp(string $threadId): void
{
    $stmt = chat_db()->prepare('UPDATE chat_threads SET updated_at = :updated_at WHERE id = :id');
    $stmt->execute([':updated_at' => chat_now(), ':id' => $threadId]);
}

function chat_maybe_title_thread_from_user(string $threadId, string $content): void
{
    $thread = chat_require_thread($threadId);
    if (($thread['message_count'] ?? 0) > 0 || ($thread['title'] ?? '') !== 'New chat') {
        return;
    }
    $stmt = chat_db()->prepare('UPDATE chat_threads SET title = :title, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        ':title' => chat_title_from_text($content),
        ':updated_at' => chat_now(),
        ':id' => $threadId,
    ]);
}

function chat_insert_message(string $threadId, string $role, string $content): void
{
    if (!in_array($role, ['user', 'assistant'], true)) {
        throw new InvalidArgumentException('Invalid chat message role.');
    }
    $stmt = chat_db()->prepare(
        'INSERT INTO chat_messages (thread_id, role, content, created_at)
         VALUES (:thread_id, :role, :content, :created_at)'
    );
    $stmt->execute([
        ':thread_id' => $threadId,
        ':role' => $role,
        ':content' => $content,
        ':created_at' => chat_now(),
    ]);
    chat_update_thread_timestamp($threadId);
}

function chat_thread_messages(string $threadId): array
{
    chat_require_thread($threadId);
    $stmt = chat_db()->prepare(
        'SELECT role, content, created_at
         FROM chat_messages
         WHERE thread_id = :thread_id
         ORDER BY id ASC'
    );
    $stmt->execute([':thread_id' => $threadId]);

    $messages = [];
    foreach ($stmt->fetchAll() as $row) {
        $messages[] = [
            'role' => (string) $row['role'],
            'content' => (string) $row['content'],
            'created_at' => (string) $row['created_at'],
        ];
    }
    return $messages;
}

function chat_api_list_threads(): void
{
    $stmt = chat_db()->query(
        'SELECT t.id, t.title, t.created_at, t.updated_at,
            (SELECT COUNT(*) FROM chat_messages m WHERE m.thread_id = t.id) AS message_count
         FROM chat_threads t
         ORDER BY t.updated_at DESC
         LIMIT 100'
    );
    chat_json_response(200, [
        'ok' => true,
        'threads' => $stmt->fetchAll(),
    ]);
}

function chat_api_create_thread(): void
{
    $body = [];
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            chat_error(400, 'invalid_json', 'Request body must be valid JSON.');
            return;
        }
        $body = $decoded;
    }

    $thread = chat_create_thread(is_string($body['title'] ?? null) ? $body['title'] : 'New chat');
    chat_json_response(201, ['ok' => true, 'thread' => $thread, 'messages' => []]);
}

function chat_api_get_thread(string $threadId): void
{
    chat_json_response(200, [
        'ok' => true,
        'thread' => chat_require_thread($threadId),
        'messages' => chat_thread_messages($threadId),
    ]);
}

function chat_api_update_thread(string $threadId): void
{
    chat_require_thread($threadId);
    $body = chat_read_json_body();
    $title = is_string($body['title'] ?? null) ? chat_title_from_text($body['title']) : '';
    if ($title === '') {
        chat_error(400, 'title_required', 'Thread title is required.');
        return;
    }

    $stmt = chat_db()->prepare('UPDATE chat_threads SET title = :title, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([':title' => $title, ':updated_at' => chat_now(), ':id' => $threadId]);
    chat_json_response(200, ['ok' => true, 'thread' => chat_require_thread($threadId)]);
}

function chat_api_delete_thread(string $threadId): void
{
    chat_require_thread($threadId);
    $stmt = chat_db()->prepare('DELETE FROM chat_threads WHERE id = :id');
    $stmt->execute([':id' => $threadId]);
    chat_json_response(200, ['ok' => true]);
}

function chat_user_message_from_body(array $body): string
{
    if (is_string($body['message'] ?? null)) {
        $message = trim($body['message']);
        if ($message !== '') {
            return $message;
        }
    }

    $messages = $body['messages'] ?? null;
    if (is_array($messages)) {
        $normalized = chat_normalize_messages(
            $messages,
            chat_env_int('KING_CHAT_MAX_MESSAGES', 40),
            chat_env_int('KING_CHAT_MAX_MESSAGE_CHARS', 12000)
        );
        return chat_latest_user_text($normalized);
    }

    chat_error(400, 'message_required', 'A user message is required.');
    exit;
}

function chat_send_sse(string $event, array $payload): void
{
    echo 'event: ', $event, "\n";
    echo 'data: ', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n\n";
    @ob_flush();
    flush();
}

function chat_health(): void
{
    $modelsUrl = chat_env_string('KING_CHAT_MODELS_URL', 'http://inference:8080/v1/models');
    $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 2, 'ignore_errors' => true]]);
    $raw = @file_get_contents($modelsUrl, false, $context);
    $status = chat_http_status_from_headers($http_response_header ?? []);

    chat_json_response($status === 200 ? 200 : 503, [
        'ok' => $status === 200,
        'models_status' => $status,
        'model' => [
            'configured' => chat_env_string('KING_CHAT_MODEL', 'gemma4:12b'),
            'effective' => chat_runtime_model(),
            'available' => chat_runtime_models(),
        ],
        'storage' => [
            'driver' => 'sqlite',
            'ready' => is_file(chat_db_path()) || is_dir(dirname(chat_db_path())),
        ],
    ]);
}

function chat_stream_completion(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        chat_error(405, 'method_not_allowed', 'Use POST.');
        return;
    }

    $body = chat_read_json_body();
    $threadId = isset($body['thread_id']) ? chat_normalize_thread_id($body['thread_id']) : chat_create_thread()['id'];
    chat_require_thread($threadId);
    $userMessage = chat_user_message_from_body($body);
    if (mb_strlen($userMessage, 'UTF-8') > chat_env_int('KING_CHAT_MAX_MESSAGE_CHARS', 12000)) {
        chat_error(413, 'message_too_large', 'The message exceeds the configured character limit.');
        return;
    }
    chat_maybe_title_thread_from_user($threadId, $userMessage);
    chat_insert_message($threadId, 'user', $userMessage);

    $storedMessages = chat_thread_messages($threadId);
    $modelMessages = [];
    foreach ($storedMessages as $message) {
        $modelMessages[] = [
            'role' => $message['role'],
            'content' => $message['content'],
        ];
    }

    $maxTokens = min(
        chat_env_int('KING_CHAT_MAX_TOKENS', 1024),
        chat_env_int('KING_CHAT_MAX_COMPLETION_TOKENS', 2048)
    );

    $payload = [
        'model' => chat_runtime_model(),
        'stream' => true,
        'messages' => chat_prepare_model_messages($modelMessages),
        'max_tokens' => $maxTokens,
        'temperature' => 0,
        'stream_options' => ['include_usage' => true],
    ];

    header('content-type: text/event-stream; charset=utf-8');
    header('cache-control: no-cache');
    header('x-accel-buffering: no');

    chat_send_sse('status', ['state' => 'connecting']);
    chat_send_sse('thread', ['thread' => chat_require_thread($threadId)]);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "content-type: application/json\r\naccept: text/event-stream\r\nconnection: close\r\n",
            'content' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'timeout' => chat_env_int('KING_CHAT_REQUEST_TIMEOUT_SECONDS', 120),
            'ignore_errors' => true,
        ],
    ]);

    $stream = @fopen(chat_env_string('KING_CHAT_INFERENCE_URL', 'http://inference:8080/v1/chat/completions'), 'rb', false, $context);
    if (!is_resource($stream)) {
        chat_send_sse('error', ['message' => 'Could not connect to King inference route.']);
        return;
    }

    $status = null;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match) === 1) {
            $status = (int) $match[1];
            break;
        }
    }
    if ($status === null || $status < 200 || $status >= 300) {
        $errorBody = stream_get_contents($stream);
        fclose($stream);
        chat_send_sse('error', [
            'message' => 'King inference route returned an error.',
            'status' => $status,
            'body' => is_string($errorBody) ? mb_substr($errorBody, 0, 2000, 'UTF-8') : '',
        ]);
        return;
    }

    chat_send_sse('status', ['state' => 'streaming']);
    $buffer = '';
    $streamEvent = 'message';
    $streamError = null;
    $finishReason = null;
    while (!feof($stream)) {
        $line = fgets($stream);
        if (!is_string($line)) {
            break;
        }
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (str_starts_with($line, 'event:')) {
            $streamEvent = trim(substr($line, 6));
            continue;
        }
        if (!str_starts_with($line, 'data:')) {
            continue;
        }

        $data = trim(substr($line, 5));
        if ($data === '[DONE]') {
            break;
        }

        $event = json_decode($data, true);
        if (!is_array($event)) {
            continue;
        }

        if ($streamEvent === 'error') {
            $message = $event['message'] ?? 'King inference stream returned an error.';
            $streamError = is_string($message) ? $message : 'King inference stream returned an error.';
            chat_send_sse('error', ['message' => $streamError]);
            break;
        }

        $choice = $event['choices'][0] ?? null;
        if (!is_array($choice)) {
            continue;
        }

        $delta = $choice['delta'] ?? null;
        if (is_array($delta) && is_string($delta['content'] ?? null) && $delta['content'] !== '') {
            $buffer .= $delta['content'];
            chat_send_sse('delta', ['content' => $delta['content']]);
        }

        if (is_string($choice['finish_reason'] ?? null)) {
            $finishReason = $choice['finish_reason'];
            chat_send_sse('done', [
                'finish_reason' => $choice['finish_reason'],
                'chars' => mb_strlen($buffer, 'UTF-8'),
            ]);
        }
    }

    fclose($stream);
    if ($streamError !== null) {
        return;
    }
    if ($buffer === '') {
        chat_send_sse('error', [
            'message' => 'King inference returned HTTP 200 but no assistant content.',
            'finish_reason' => $finishReason,
        ]);
        return;
    }

    chat_insert_message($threadId, 'assistant', $buffer);
    chat_send_sse('thread', ['thread' => chat_require_thread($threadId)]);
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/api/health') {
    chat_health();
    return;
}
if ($path === '/api/threads' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    chat_api_list_threads();
    return;
}
if ($path === '/api/threads' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    chat_api_create_thread();
    return;
}
if (is_string($path) && preg_match('#^/api/threads/(thr_[a-f0-9]{24})$#', $path, $match) === 1) {
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if ($method === 'GET') {
        chat_api_get_thread($match[1]);
        return;
    }
    if ($method === 'PATCH') {
        chat_api_update_thread($match[1]);
        return;
    }
    if ($method === 'DELETE') {
        chat_api_delete_thread($match[1]);
        return;
    }
    chat_error(405, 'method_not_allowed', 'Unsupported thread method.');
    return;
}
if ($path === '/api/chat') {
    chat_stream_completion();
    return;
}

return false;
