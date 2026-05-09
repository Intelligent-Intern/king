<?php

declare(strict_types=1);

function videochat_operator_feedback_contract_failures(array $failures): void
{
    if ($failures === []) {
        fwrite(STDOUT, "[operator-feedback-contract] PASS\n");
        return;
    }

    foreach ($failures as $failure) {
        fwrite(STDERR, "[operator-feedback-contract] FAIL: {$failure}\n");
    }
    exit(1);
}

function videochat_operator_feedback_contract_assert(array &$failures, bool $condition, string $message): void
{
    if (!$condition) {
        $failures[] = $message;
    }
}

function videochat_operator_feedback_contract_read(string $repoRoot, string $relativePath): string
{
    $path = $repoRoot . '/' . $relativePath;
    return is_file($path) ? (string) file_get_contents($path) : '';
}

function videochat_operator_feedback_contract_glob(string $repoRoot, string $pattern): string
{
    $source = '';
    foreach (glob($repoRoot . '/' . $pattern) ?: [] as $path) {
        if (is_file($path)) {
            $source .= "\n/* " . basename($path) . " */\n" . (string) file_get_contents($path);
        }
    }
    return $source;
}

function videochat_operator_feedback_contract_table_columns(PDO $pdo, string $table): array
{
    $columns = [];
    foreach ($pdo->query('PRAGMA table_info(' . $table . ')') ?: [] as $row) {
        $name = (string) ($row['name'] ?? '');
        if ($name !== '') {
            $columns[$name] = true;
        }
    }
    return $columns;
}

function videochat_operator_feedback_contract_insert_row(PDO $pdo, string $table, array $values): void
{
    $columns = videochat_operator_feedback_contract_table_columns($pdo, $table);
    $filtered = [];
    foreach ($values as $column => $value) {
        if (isset($columns[$column])) {
            $filtered[$column] = $value;
        }
    }
    if ($filtered === []) {
        return;
    }

    $columnSql = implode(', ', array_keys($filtered));
    $placeholderSql = implode(', ', array_map(static fn (string $column): string => ':' . $column, array_keys($filtered)));
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO {$table}({$columnSql}) VALUES({$placeholderSql})");
    foreach ($filtered as $column => $value) {
        $stmt->bindValue(':' . $column, $value);
    }
    $stmt->execute();
}

$repoRoot = dirname(__DIR__, 4);
$failures = [];

$router = videochat_operator_feedback_contract_read($repoRoot, 'demo/video-chat/backend-king-php/http/router.php');
$httpSources = videochat_operator_feedback_contract_glob($repoRoot, 'demo/video-chat/backend-king-php/http/*.php');
$domainSources = videochat_operator_feedback_contract_glob($repoRoot, 'demo/video-chat/backend-king-php/domain/**/*.php');
$supportSources = videochat_operator_feedback_contract_glob($repoRoot, 'demo/video-chat/backend-king-php/support/*.php');
$chatSource = videochat_operator_feedback_contract_read($repoRoot, 'demo/video-chat/backend-king-php/domain/realtime/realtime_chat.php');
$websocketCommandSource = videochat_operator_feedback_contract_read($repoRoot, 'demo/video-chat/backend-king-php/http/module_realtime_websocket_commands.php');
$operatorSource = $httpSources . "\n" . $domainSources . "\n" . $supportSources;
$operatorTableBlock = '';
if (preg_match('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+operator_feedback[\s\S]{0,5000}?(?:\)\s*SQL|"\s*,|;\s*)/i', $operatorSource, $matches) === 1) {
    $operatorTableBlock = (string) ($matches[0] ?? '');
}

videochat_operator_feedback_contract_assert(
    $failures,
    str_contains($operatorSource, 'operator_feedback'),
    'backend must define an operator_feedback contract term across intake, persistence, queue, and delivery code'
);
videochat_operator_feedback_contract_assert(
    $failures,
    preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+operator_feedback|CREATE\s+TABLE\s+operator_feedback/i', $operatorSource) === 1,
    'backend migrations/bootstrap must create an operator_feedback table'
);
foreach ([
    'call_id',
    'room_id',
    'sender_user_id',
    'session_id',
    'message_text',
    'status',
    'deployed_feature',
] as $column) {
    videochat_operator_feedback_contract_assert(
        $failures,
        preg_match('/\b' . preg_quote($column, '/') . '\b/', $operatorTableBlock) === 1,
        "operator_feedback storage must expose {$column}"
    );
}
videochat_operator_feedback_contract_assert(
    $failures,
    preg_match('/\b(?:tenant_id|organization_id)\b/', $operatorTableBlock) === 1,
    'operator_feedback storage must keep tenant_id or organization_id context'
);
videochat_operator_feedback_contract_assert(
    $failures,
    str_contains($chatSource, 'operator_feedback'),
    'chat decoder must preserve the client operator_feedback flag on chat/send commands'
);
videochat_operator_feedback_contract_assert(
    $failures,
    str_contains($websocketCommandSource, 'videochat_operator_feedback_capture_chat_message'),
    'websocket chat handler must capture flagged chat messages through videochat_operator_feedback_capture_chat_message'
);
videochat_operator_feedback_contract_assert(
    $failures,
    !str_contains($websocketCommandSource, 'videochat_operator_feedback_capture_chat_message') || preg_match('/operator_feedback[\s\S]{0,1200}videochat_operator_feedback_capture_chat_message|videochat_operator_feedback_capture_chat_message[\s\S]{0,1200}operator_feedback/', $websocketCommandSource) === 1,
    'websocket chat handler must pass the decoded operator flag into feedback capture'
);
videochat_operator_feedback_contract_assert(
    $failures,
    preg_match('#/api/operator-feedback/queue#', $httpSources) === 1,
    'backend must expose GET /api/operator-feedback/queue for manager triage'
);
videochat_operator_feedback_contract_assert(
    $failures,
    !preg_match("#['\"]/api/operator-feedback/queue['\"]#", $router),
    'operator feedback queue must not be listed as a public router endpoint'
);
videochat_operator_feedback_contract_assert(
    $failures,
    preg_match('/function\s+videochat_handle_operator_feedback_routes\s*\([\s\S]*array\s+\$apiAuthContext/', $httpSources) === 1,
    'operator feedback route handler must receive authenticated apiAuthContext'
);
videochat_operator_feedback_contract_assert(
    $failures,
    preg_match('/\/api\/operator-feedback\/queue[\s\S]{0,2000}videochat_operator_feedback_queue|videochat_operator_feedback_queue[\s\S]{0,2000}\/api\/operator-feedback\/queue/', $httpSources) === 1,
    'queue endpoint must read from videochat_operator_feedback_queue'
);
videochat_operator_feedback_contract_assert(
    $failures,
    str_contains($operatorSource, 'videochat_operator_feedback_deployed_notification_payload')
        && str_contains($operatorSource, "feature '")
        && str_contains($operatorSource, "' deployed"),
    "backend must provide deployed notification payload text feature '<requested feature>' deployed"
);

$domainFile = $repoRoot . '/demo/video-chat/backend-king-php/domain/realtime/operator_feedback.php';
if (is_file($repoRoot . '/demo/video-chat/backend-king-php/support/database.php')) {
    require_once $repoRoot . '/demo/video-chat/backend-king-php/support/database.php';
}
if (is_file($domainFile)) {
    require_once $domainFile;
}

$runtimeFunctions = [
    'videochat_operator_feedback_bootstrap',
    'videochat_operator_feedback_capture_chat_message',
    'videochat_operator_feedback_queue',
    'videochat_operator_feedback_mark_deployed',
    'videochat_operator_feedback_deployed_notification_payload',
];
foreach ($runtimeFunctions as $functionName) {
    videochat_operator_feedback_contract_assert(
        $failures,
        function_exists($functionName),
        "backend must implement {$functionName}()"
    );
}

if (function_exists('videochat_bootstrap_sqlite')
    && function_exists('videochat_open_sqlite_pdo')
    && in_array('sqlite', PDO::getAvailableDrivers(), true)
    && array_reduce($runtimeFunctions, static fn (bool $carry, string $name): bool => $carry && function_exists($name), true)
) {
    $databasePath = sys_get_temp_dir() . '/videochat-operator-feedback-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);
    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    videochat_operator_feedback_bootstrap($pdo);

    $tenantId = (int) ($pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn() ?: 1);
    $senderUserId = (int) ($pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn() ?: 2);
    $now = gmdate('c');
    $callId = 'call_operator_feedback_contract';
    $roomId = 'room_operator_feedback_contract';
    $sessionId = 'sess_operator_feedback_contract';
    videochat_operator_feedback_contract_insert_row($pdo, 'rooms', [
        'id' => $roomId,
        'tenant_id' => $tenantId,
        'name' => 'Operator Feedback Contract Room',
        'visibility' => 'private',
        'status' => 'active',
        'created_by_user_id' => $senderUserId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    videochat_operator_feedback_contract_insert_row($pdo, 'calls', [
        'id' => $callId,
        'tenant_id' => $tenantId,
        'room_id' => $roomId,
        'title' => 'Operator Feedback Contract Call',
        'access_mode' => 'invite_only',
        'owner_user_id' => $senderUserId,
        'status' => 'active',
        'starts_at' => $now,
        'ends_at' => gmdate('c', time() + 3600),
        'schedule_timezone' => 'UTC',
        'schedule_date' => gmdate('Y-m-d'),
        'schedule_duration_minutes' => 60,
        'schedule_all_day' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $connection = [
        'user_id' => $senderUserId,
        'session_id' => $sessionId,
        'active_call_id' => $callId,
        'room_id' => $roomId,
        'tenant_id' => $tenantId,
    ];
    $message = [
        'id' => 'chat_operator_feedback_contract_001',
        'client_message_id' => 'client_operator_feedback_contract_001',
        'text' => 'Please add status filters to the participant list',
        'sender' => [
            'user_id' => $senderUserId,
            'display_name' => 'Contract Sender',
            'role' => 'user',
        ],
        'server_time' => '2026-05-09T10:20:00Z',
    ];

    $flaggedCapture = videochat_operator_feedback_capture_chat_message($pdo, $callId, $roomId, $connection, $message, [
        'operator_feedback' => true,
    ]);
    videochat_operator_feedback_contract_assert($failures, (bool) ($flaggedCapture['ok'] ?? false), 'flagged chat message must create operator feedback');
    $feedbackId = (string) (($flaggedCapture['feedback'] ?? [])['id'] ?? ($flaggedCapture['id'] ?? ''));
    videochat_operator_feedback_contract_assert($failures, $feedbackId !== '', 'created operator feedback must return a stable feedback id');

    $queue = videochat_operator_feedback_queue($pdo, ['status' => 'open']);
    $queueRows = is_array($queue['feedback'] ?? null) ? $queue['feedback'] : (is_array($queue['items'] ?? null) ? $queue['items'] : []);
    videochat_operator_feedback_contract_assert($failures, count($queueRows) === 1, 'open feedback queue must contain the flagged chat feedback only');
    $row = is_array($queueRows[0] ?? null) ? $queueRows[0] : [];
    videochat_operator_feedback_contract_assert($failures, (string) ($row['call_id'] ?? '') === $callId, 'feedback queue row must keep call_id');
    videochat_operator_feedback_contract_assert($failures, (string) ($row['room_id'] ?? '') === $roomId, 'feedback queue row must keep room_id');
    videochat_operator_feedback_contract_assert($failures, (int) ($row['sender_user_id'] ?? 0) === $senderUserId, 'feedback queue row must keep sender_user_id');
    videochat_operator_feedback_contract_assert($failures, (string) ($row['session_id'] ?? '') === $sessionId, 'feedback queue row must keep session_id');
    videochat_operator_feedback_contract_assert($failures, (string) ($row['message_text'] ?? '') === $message['text'], 'feedback queue row must keep message_text');
    videochat_operator_feedback_contract_assert($failures, (string) ($row['status'] ?? '') === 'open', 'new feedback status must be open');
    $rowTenantId = (int) ($row['tenant_id'] ?? ($row['organization_id'] ?? 0));
    videochat_operator_feedback_contract_assert($failures, $rowTenantId === $tenantId, 'feedback queue row must keep tenant_id or organization_id');

    $normalCapture = videochat_operator_feedback_capture_chat_message($pdo, $callId, $roomId, $connection, [
        ...$message,
        'id' => 'chat_operator_feedback_contract_002',
        'client_message_id' => 'client_operator_feedback_contract_002',
        'text' => 'This is a normal chat message',
    ], [
        'operator_feedback' => false,
    ]);
    videochat_operator_feedback_contract_assert($failures, (string) ($normalCapture['state'] ?? 'skipped') === 'skipped', 'normal chat messages without operator flag must be skipped');
    $queueAfterNormal = videochat_operator_feedback_queue($pdo, ['status' => 'open']);
    $queueRowsAfterNormal = is_array($queueAfterNormal['feedback'] ?? null) ? $queueAfterNormal['feedback'] : (is_array($queueAfterNormal['items'] ?? null) ? $queueAfterNormal['items'] : []);
    videochat_operator_feedback_contract_assert($failures, count($queueRowsAfterNormal) === 1, 'normal chat messages must not add feedback rows');

    $deployed = videochat_operator_feedback_mark_deployed($pdo, $feedbackId, 'participant list status filters');
    videochat_operator_feedback_contract_assert($failures, (bool) ($deployed['ok'] ?? false), 'mark deployed must update feedback');
    $payload = videochat_operator_feedback_deployed_notification_payload(is_array($deployed['feedback'] ?? null) ? $deployed['feedback'] : [
        'id' => $feedbackId,
        'requested_feature' => 'participant list status filters',
        'deployed_feature' => 'participant list status filters',
    ]);
    videochat_operator_feedback_contract_assert($failures, (string) ($payload['type'] ?? '') === 'operator-feedback/deployed', 'deployed notification type mismatch');
    videochat_operator_feedback_contract_assert($failures, (string) (($payload['toast'] ?? [])['message'] ?? '') === "feature 'participant list status filters' deployed", 'deployed notification toast text mismatch');
}

videochat_operator_feedback_contract_failures($failures);
