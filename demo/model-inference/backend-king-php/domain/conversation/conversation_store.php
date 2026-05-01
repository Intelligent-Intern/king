<?php

declare(strict_types=1);

/**
 * #C-batch conversation persistence (V.8 tracker bullet).
 *
 * Persists each completed inference turn so the chat UI can rehydrate
 * state.history on page reload, and so future callers can audit / replay
 * a conversation. Storage is SQLite-only at this leaf — no object-store
 * layer yet. Volume budget: demo scale (<10k messages per session, <100k
 * total).
 *
 * Threat model: session_id is client-supplied and NOT authenticated; any
 * caller with a session_id can read its messages. This matches the
 * existing inference-request envelope contract (see
 * contracts/v1/inference-request.contract.json honesty rules) and is an
 * accepted fence for the demo.
 */

function model_inference_conversation_schema_migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS conversations (
        session_id TEXT PRIMARY KEY,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        turn_count INTEGER NOT NULL DEFAULT 0,
        last_request_id TEXT,
        last_model_name TEXT,
        last_model_quantization TEXT
    )');
    // #A-4 idempotent ALTER: add user_ref column if it doesn't yet
    // exist. NULL user_ref means anonymous (pre-A-batch behavior).
    $existingCols = array_column($pdo->query('PRAGMA table_info(conversations)')->fetchAll(), 'name');
    if (!in_array('user_ref', $existingCols, true)) {
        $pdo->exec('ALTER TABLE conversations ADD COLUMN user_ref INTEGER');
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_conversations_user_ref ON conversations(user_ref)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS conversation_messages (
        message_id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id TEXT NOT NULL,
        seq INTEGER NOT NULL,
        role TEXT NOT NULL,
        content TEXT NOT NULL,
        request_id TEXT,
        model_name TEXT,
        model_quantization TEXT,
        created_at TEXT NOT NULL,
        FOREIGN KEY (session_id) REFERENCES conversations(session_id)
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_conversation_messages_session ON conversation_messages(session_id, seq ASC)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_conversation_messages_session_seq ON conversation_messages(session_id, seq)');
}

/**
 * Append one full turn (user input + assistant reply) to the conversation.
 *
 * @param array<string, mixed> $validatedEnvelope normalized inference-request envelope
 * @param string               $assistantContent  the streamed/completed assistant text
 * @param array<string, mixed> $modelEnvelope     registry row envelope
 * @return array{session_id: string, seq_before: int, seq_after: int, appended: int}
 */
function model_inference_conversation_append_turn(
    PDO $pdo,
    array $validatedEnvelope,
    string $assistantContent,
    string $requestId,
    array $modelEnvelope,
    ?int $userRef = null
): array {
    $sessionId = (string) ($validatedEnvelope['session_id'] ?? '');
    if ($sessionId === '') {
        throw new InvalidArgumentException('conversation_append_turn requires session_id');
    }
    $now = gmdate('c');
    $modelName = (string) ($modelEnvelope['model_name'] ?? '');
    $modelQuant = (string) ($modelEnvelope['quantization'] ?? '');

    $pdo->beginTransaction();
    try {
        $meta = model_inference_conversation_get_meta($pdo, $sessionId);
        if ($meta === null) {
            $insert = $pdo->prepare('INSERT INTO conversations (
                session_id, created_at, updated_at, turn_count,
                last_request_id, last_model_name, last_model_quantization, user_ref
            ) VALUES (:sid, :created, :updated, 0, :req, :mn, :mq, :uref)');
            $insert->execute([
                ':sid' => $sessionId, ':created' => $now, ':updated' => $now,
                ':req' => $requestId, ':mn' => $modelName, ':mq' => $modelQuant,
                ':uref' => $userRef,
            ]);
            $seq = 0;
        } else {
            $seqStmt = $pdo->prepare('SELECT COALESCE(MAX(seq), -1) AS max_seq FROM conversation_messages WHERE session_id = :sid');
            $seqStmt->execute([':sid' => $sessionId]);
            $row = $seqStmt->fetch();
            $seq = ((int) ($row['max_seq'] ?? -1)) + 1;
        }

        $seqBefore = $seq;
        $appended = 0;

        $existingMessages = model_inference_conversation_list_messages($pdo, $sessionId, 10000);
        $existingCount = count($existingMessages);

        $suppliedHistory = $validatedEnvelope['messages'] ?? null;
        if (is_array($suppliedHistory) && count($suppliedHistory) > 0) {
            // Client passed messages[]; persist any that haven't been
            // persisted yet (skip the first $existingCount that the store
            // already has). This keeps the persisted record in sync with
            // the client's rendered transcript.
            $newOnes = array_slice($suppliedHistory, $existingCount);
            foreach ($newOnes as $m) {
                if (!is_array($m) || !isset($m['role'], $m['content'])) {
                    continue;
                }
                model_inference_conversation_persist_message(
                    $pdo, $sessionId, $seq, (string) $m['role'], (string) $m['content'],
                    null, $modelName, $modelQuant, $now
                );
                $seq++;
                $appended++;
            }
        } else {
            // Legacy prompt-only path: persist the prompt as a user turn
            // if nothing is there yet for this session.
            $userPrompt = (string) ($validatedEnvelope['prompt'] ?? '');
            if ($existingCount === 0 && $userPrompt !== '') {
                $system = $validatedEnvelope['system'] ?? null;
                if (is_string($system) && $system !== '') {
                    model_inference_conversation_persist_message(
                        $pdo, $sessionId, $seq, 'system', $system,
                        null, $modelName, $modelQuant, $now
                    );
                    $seq++;
                    $appended++;
                }
                model_inference_conversation_persist_message(
                    $pdo, $sessionId, $seq, 'user', $userPrompt,
                    null, $modelName, $modelQuant, $now
                );
                $seq++;
                $appended++;
            } elseif ($existingCount === 0) {
                // no prompt, no messages — nothing to persist for the user side
            }
        }

        // Assistant reply for this turn.
        if ($assistantContent !== '') {
            model_inference_conversation_persist_message(
                $pdo, $sessionId, $seq, 'assistant', $assistantContent,
                $requestId, $modelName, $modelQuant, $now
            );
            $seq++;
            $appended++;
        }

        // A-4: bind a user on first authenticated turn. Once bound, keep
        // the owner — an authenticated request from a DIFFERENT user on
        // an owned session must not silently reassign ownership. The
        // endpoint's ownership gate catches cross-user reads; here we
        // simply refuse to overwrite an existing user_ref with a
        // mismatched one.
        if ($userRef !== null && $meta !== null) {
            $existingRef = $meta['user_ref'] ?? null;
            if ($existingRef !== null && (int) $existingRef !== (int) $userRef) {
                $userRef = (int) $existingRef;
            }
        }

        if ($userRef !== null) {
            $update = $pdo->prepare('UPDATE conversations SET
                updated_at = :updated,
                turn_count = turn_count + 1,
                last_request_id = :req,
                last_model_name = :mn,
                last_model_quantization = :mq,
                user_ref = COALESCE(user_ref, :uref)
                WHERE session_id = :sid');
            $update->execute([
                ':updated' => $now, ':req' => $requestId,
                ':mn' => $modelName, ':mq' => $modelQuant,
                ':uref' => $userRef, ':sid' => $sessionId,
            ]);
        } else {
            $update = $pdo->prepare('UPDATE conversations SET
                updated_at = :updated,
                turn_count = turn_count + 1,
                last_request_id = :req,
                last_model_name = :mn,
                last_model_quantization = :mq
                WHERE session_id = :sid');
            $update->execute([
                ':updated' => $now, ':req' => $requestId,
                ':mn' => $modelName, ':mq' => $modelQuant, ':sid' => $sessionId,
            ]);
        }

        $pdo->commit();
        return [
            'session_id' => $sessionId,
            'seq_before' => $seqBefore,
            'seq_after' => $seq,
            'appended' => $appended,
        ];
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function model_inference_conversation_persist_message(
    PDO $pdo,
    string $sessionId,
    int $seq,
    string $role,
    string $content,
    ?string $requestId,
    string $modelName,
    string $modelQuant,
    string $now
): void {
    $stmt = $pdo->prepare('INSERT INTO conversation_messages (
        session_id, seq, role, content, request_id, model_name, model_quantization, created_at
    ) VALUES (:sid, :seq, :role, :content, :req, :mn, :mq, :created)');
    $stmt->execute([
        ':sid' => $sessionId, ':seq' => $seq, ':role' => $role, ':content' => $content,
        ':req' => $requestId, ':mn' => $modelName, ':mq' => $modelQuant, ':created' => $now,
    ]);
}

/** @return array<string, mixed>|null */
function model_inference_conversation_get_meta(PDO $pdo, string $sessionId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM conversations WHERE session_id = :sid');
    $stmt->execute([':sid' => $sessionId]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    return [
        'session_id' => (string) $row['session_id'],
        'created_at' => (string) $row['created_at'],
        'updated_at' => (string) $row['updated_at'],
        'turn_count' => (int) $row['turn_count'],
        'last_request_id' => $row['last_request_id'] !== null ? (string) $row['last_request_id'] : null,
        'last_model_name' => $row['last_model_name'] !== null ? (string) $row['last_model_name'] : null,
        'last_model_quantization' => $row['last_model_quantization'] !== null ? (string) $row['last_model_quantization'] : null,
        'user_ref' => isset($row['user_ref']) && $row['user_ref'] !== null ? (int) $row['user_ref'] : null,
    ];
}

/**
 * List conversations owned by a specific user. Returns meta rows only
 * (no messages). Used by #A-7 to power "my conversations" cross-device
 * continuation.
 *
 * @return array<int, array<string, mixed>>
 */
function model_inference_conversation_list_by_user(PDO $pdo, int $userRef, int $limit = 50): array
{
    if ($userRef < 1 || $limit < 1) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT * FROM conversations
        WHERE user_ref = :uref
        ORDER BY updated_at DESC
        LIMIT :limit');
    $stmt->bindValue(':uref', $userRef, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[] = [
            'session_id' => (string) $row['session_id'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'turn_count' => (int) $row['turn_count'],
            'last_request_id' => $row['last_request_id'] !== null ? (string) $row['last_request_id'] : null,
            'last_model_name' => $row['last_model_name'] !== null ? (string) $row['last_model_name'] : null,
            'last_model_quantization' => $row['last_model_quantization'] !== null ? (string) $row['last_model_quantization'] : null,
            'user_ref' => (int) $row['user_ref'],
        ];
    }
    return $result;
}

/** @return array<int, array<string, mixed>> */
function model_inference_conversation_list_messages(PDO $pdo, string $sessionId, int $limit = 100): array
{
    if ($limit < 1) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT * FROM conversation_messages
        WHERE session_id = :sid ORDER BY seq ASC LIMIT :limit');
    $stmt->bindValue(':sid', $sessionId, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[] = [
            'message_id' => (int) $row['message_id'],
            'seq' => (int) $row['seq'],
            'role' => (string) $row['role'],
            'content' => (string) $row['content'],
            'request_id' => $row['request_id'] !== null ? (string) $row['request_id'] : null,
            'model_name' => $row['model_name'] !== null ? (string) $row['model_name'] : null,
            'model_quantization' => $row['model_quantization'] !== null ? (string) $row['model_quantization'] : null,
            'created_at' => (string) $row['created_at'],
        ];
    }
    return $result;
}

/**
 * Delete all rows for a session. Exposed for test isolation and future
 * DELETE /api/conversations/{id} endpoint (not yet landed).
 */
function model_inference_conversation_delete(PDO $pdo, string $sessionId): int
{
    $pdo->beginTransaction();
    try {
        $msgs = $pdo->prepare('DELETE FROM conversation_messages WHERE session_id = :sid');
        $msgs->execute([':sid' => $sessionId]);
        $deletedMessages = $msgs->rowCount();
        $meta = $pdo->prepare('DELETE FROM conversations WHERE session_id = :sid');
        $meta->execute([':sid' => $sessionId]);
        $pdo->commit();
        return $deletedMessages;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
