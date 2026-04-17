<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'service' => 'video-chat-backend-king-php',
    'status' => 'bootstrapped',
    'message' => 'King PHP backend scaffold is active. API and WebSocket contracts land in V1 follow-up leaves.',
    'time' => gmdate('c'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
