<?php

declare(strict_types=1);

function videochat_stt_config_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[stt-config-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_stt_config_contract_read(string $path): string
{
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException("could not read {$path}");
    }

    return $contents;
}

try {
    $repoRoot = dirname(__DIR__, 3);
    $videoChatRoot = $repoRoot . '/video-chat';
    $compose = videochat_stt_config_contract_read($videoChatRoot . '/docker-compose.v1.yml');
    $dockerfile = videochat_stt_config_contract_read($videoChatRoot . '/backend-king-php/Dockerfile');
    $installer = videochat_stt_config_contract_read($videoChatRoot . '/scripts/install-whisper-model.sh');
    $domain = videochat_stt_config_contract_read($videoChatRoot . '/backend-king-php/domain/calls/call_stt.php');
    $sidebar = videochat_stt_config_contract_read($videoChatRoot . '/frontend-vue/src/layouts/CallWorkspaceLeftSidebar.vue');

    foreach (['VIDEOCHAT_STT_ACTIVE', 'VIDEOCHAT_STT_COMMAND', 'VIDEOCHAT_STT_FFMPEG_COMMAND', 'VIDEOCHAT_STT_MODEL', 'VIDEOCHAT_STT_TEMP_DIR'] as $envName) {
        videochat_stt_config_contract_assert(str_contains($compose, $envName), "compose missing {$envName}");
        videochat_stt_config_contract_assert(str_contains($dockerfile, $envName), "Dockerfile missing {$envName}");
        videochat_stt_config_contract_assert(str_contains($domain, $envName), "domain config missing {$envName}");
    }

    videochat_stt_config_contract_assert(str_contains($compose, 'VIDEOCHAT_STT_ACTIVE: "${VIDEOCHAT_STT_ACTIVE:-false}"'), 'compose should default STT disabled');
    videochat_stt_config_contract_assert(str_contains($dockerfile, 'ENV VIDEOCHAT_STT_ACTIVE=false'), 'Dockerfile should default STT disabled');
    videochat_stt_config_contract_assert(str_contains($compose, '/data/models/whisper.cpp/ggml-tiny.en.bin'), 'compose should expose small CPU model path');
    videochat_stt_config_contract_assert(str_contains($dockerfile, 'ffmpeg'), 'Dockerfile should include ffmpeg for browser audio chunk conversion');
    videochat_stt_config_contract_assert(str_contains($installer, 'ggml-tiny.en.bin'), 'installer should default to tiny.en GGML model');
    videochat_stt_config_contract_assert(str_contains($installer, 'huggingface.co/ggerganov/whisper.cpp'), 'installer should use whisper.cpp-compatible model source');
    videochat_stt_config_contract_assert(str_contains($installer, '--dry-run'), 'installer should support dry-run contract');
    videochat_stt_config_contract_assert(str_contains($domain, 'call_stt_settings'), 'backend should persist per-call STT state');
    videochat_stt_config_contract_assert(str_contains($domain, 'videochat_chat_archive_append_message'), 'backend should persist transcripts through chat archive');
    videochat_stt_config_contract_assert(str_contains($domain, 'videochat_chat_broker_insert_event'), 'backend should publish transcripts through chat broker');
    videochat_stt_config_contract_assert(str_contains($domain, 'videochat_stt_prepare_transcription_input'), 'backend should prepare browser audio chunks for local STT');
    videochat_stt_config_contract_assert(str_contains($sidebar, 'Speech transcription'), 'left sidebar should expose STT activation surface');
    videochat_stt_config_contract_assert(!str_contains(strtolower($sidebar), 'consent prompt'), 'sidebar should not add an extra consent prompt');

    fwrite(STDOUT, "[stt-config-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[stt-config-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
