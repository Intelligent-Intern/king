<?php
declare(strict_types=1);

namespace King\Voltron;

class OllamaKernels
{
    private static ?OllamaBackend $backend = null;
    private static int $position = 0;

    public static function initialState(string $prompt, array $params): array
    {
        $runtime = self::runtimeForParams($params);
        /** @var VoltronTokenizer $tokenizer */
        $tokenizer = $runtime['tokenizer'];

        $formattedPrompt = $tokenizer->formatPrompt($prompt);
        $tokenIds = $tokenizer->encode($formattedPrompt, false);
        if ($tokenIds === []) {
            $fallback = $tokenizer->bosId();
            $tokenIds[] = is_int($fallback) ? $fallback : 0;
        }

        $maxTokens = is_int($params['inference_max_tokens'] ?? null) && (int) $params['inference_max_tokens'] > 0
            ? (int) $params['inference_max_tokens']
            : 64;

        return [
            'prompt' => $prompt,
            'formatted_prompt' => $formattedPrompt,
            'token_ids' => $tokenIds,
            'generated_token_ids' => [],
            'generated_text' => '',
            'position' => 0,
            'stop' => false,
            'max_tokens' => $maxTokens,
            'latent_dim' => $runtime['hidden_dim'],
            'kv_cache' => [],
            'activations' => [],
            'model_runtime_key' => $runtime['key'],
            'last_token_id' => end($tokenIds),
            'finished_reason' => null,
        ];
    }

    public static function executeBlock(string $block, array $state, array $params): array
    {
        return match ($block) {
            'embed' => self::executeEmbed($state, $params),
            'attention' => self::executeAttention($state, $params),
            'ffn' => self::executeFfn($state, $params),
            'output_head' => self::executeOutputHead($state, $params),
            default => throw new \RuntimeException("Unknown Ollama block: {$block}"),
        };
    }

    private static function runtimeForParams(array $params): array
    {
        $modelName = $params['inference_model_name'] ?? 'qwen2.5-coder:3b';
        
        if (self::$backend === null) {
            $baseUrl = $params['ollama_url'] ?? 'http://127.0.0.1:11434';
            $timeout = $params['ollama_timeout'] ?? 120;
            self::$backend = new OllamaBackend($baseUrl, $modelName, $timeout);
        }

        $loader = GgufTensorLoader::fromParams($params);
        $hiddenDim = $loader->ne0('token_embd.weight');
        
        $key = sha1($modelName . '|ollama');

        return [
            'loader' => $loader,
            'hidden_dim' => $hiddenDim,
            'tokenizer' => new VoltronTokenizer(
                $loader->tokenizerTokens(),
                $loader->tokenizerTypes(),
                $loader->tokenizerScores(),
                $loader->tokenizerMerges(),
                $loader->tokenizerModel(),
                $loader->tokenizerPre(),
                (int) ($loader->metadata('tokenizer.ggml.bos_token_id', 0)),
                (int) ($loader->metadata('tokenizer.ggml.eos_token_id', 151643)),
                0
            ),
            'key' => $key,
        ];
    }

    private static function executeEmbed(array $state, array $params): array
    {
        $tokenIds = $state['token_ids'] ?? [];
        if ($tokenIds === []) {
            throw new \RuntimeException('Missing token_ids in embed block');
        }

        $lastToken = end($tokenIds);
        $state['position'] = count($tokenIds) - 1;
        $state['last_token_id'] = $lastToken;
        $state['activations']['embed_token_id'] = $lastToken;

        return $state;
    }

    private static function executeAttention(array $state, array $params): array
    {
        $state['activations']['attention'] = true;
        return $state;
    }

    private static function executeFfn(array $state, array $params): array
    {
        $state['activations']['ffn'] = true;
        return $state;
    }

    private static function executeOutputHead(array $state, array $params): array
    {
        $prompt = $state['formatted_prompt'] ?? $state['prompt'] ?? '';
        $generatedSoFar = $state['generated_text'] ?? '';
        
        $fullPrompt = $prompt . $generatedSoFar;
        
        $maxTokens = $state['max_tokens'] ?? 64;
        $remaining = $maxTokens - strlen($generatedSoFar);
        
        if ($remaining <= 0 || ($state['stop'] ?? false)) {
            $state['stop'] = true;
            $state['finished_reason'] = 'length';
            return $state;
        }

        $options = [
            'num_predict' => min($remaining, 1),
            'temperature' => (float) ($params['inference_temperature'] ?? 0.7),
            'top_p' => (float) ($params['inference_top_p'] ?? 0.9),
            'top_k' => (int) ($params['inference_top_k'] ?? 40),
            'repeat_penalty' => (float) ($params['inference_repeat_penalty'] ?? 1.1),
        ];

        $result = self::$backend->generate($fullPrompt, $options);
        
        $newText = $result['response'] ?? '';
        $state['generated_text'] = $generatedSoFar . $newText;
        
        $state['last_token_id'] = 0;
        
        $state['stop'] = ($result['done'] ?? false) || strlen($state['generated_text']) >= $maxTokens;
        $state['finished_reason'] = $result['done_reason'] ?? ($state['stop'] ? 'length' : null);

        return $state;
    }
}