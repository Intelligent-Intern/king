<?php

declare(strict_types=1);

/**
 * Single source of truth for resolving a validated inference-request
 * envelope into the OpenAI-style messages[] array that the backend sends
 * to llama.cpp's /v1/chat/completions.
 *
 * Resolution rules (kept identical across HTTP and WS transports):
 *   1. If the envelope carries a non-empty messages[] (T-1 multi-turn
 *      memory): use it verbatim as the base. Then:
 *        a. If `system` is non-empty AND messages[] does NOT already lead
 *           with a system turn, prepend {role:system, content:system}.
 *        b. If `prompt` is non-empty, append {role:user, content:prompt}
 *           as the final user turn. This lets clients pass the full prior
 *           history in messages[] without having to mutate it client-side
 *           to include the new user turn.
 *   2. Otherwise (legacy single-turn mode): build [{role:system?, content}?,
 *      {role:user, content:prompt}] from the separate fields.
 *
 * @param array<string, mixed> $validatedEnvelope
 * @return array<int, array{role: string, content: string}>
 */
function model_inference_build_chat_messages(array $validatedEnvelope): array
{
    $supplied = $validatedEnvelope['messages'] ?? null;
    if (is_array($supplied) && count($supplied) > 0) {
        $messages = [];
        $system = $validatedEnvelope['system'] ?? null;
        $hasLeadingSystem = isset($supplied[0]['role']) && $supplied[0]['role'] === 'system';
        if (is_string($system) && $system !== '' && !$hasLeadingSystem) {
            $messages[] = ['role' => 'system', 'content' => $system];
        }
        foreach ($supplied as $m) {
            if (is_array($m) && isset($m['role'], $m['content'])) {
                $messages[] = ['role' => (string) $m['role'], 'content' => (string) $m['content']];
            }
        }
        $prompt = $validatedEnvelope['prompt'] ?? null;
        if (is_string($prompt) && $prompt !== '') {
            $messages[] = ['role' => 'user', 'content' => $prompt];
        }
        return $messages;
    }

    $messages = [];
    if (isset($validatedEnvelope['system']) && is_string($validatedEnvelope['system']) && $validatedEnvelope['system'] !== '') {
        $messages[] = ['role' => 'system', 'content' => $validatedEnvelope['system']];
    }
    $messages[] = ['role' => 'user', 'content' => (string) ($validatedEnvelope['prompt'] ?? '')];
    return $messages;
}
