<?php
declare(strict_types=1);

namespace King\Voltron\Distributed;

require_once __DIR__ . '/LayerWorker.php';

class OllamaLayerServer
{
    private int $port;
    private int $layerStart;
    private int $layerEnd;
    private string $modelPath;
    private $socket;
    private bool $running = false;
    private ?LayerWorker $worker = null;

    public function __construct(
        int $port = 9533,
        int $layerStart = 0,
        int $layerEnd = 11,
        string $modelPath = ''
    ) {
        $this->port = $port;
        $this->layerStart = $layerStart;
        $this->layerEnd = $layerEnd;
        
        if ($modelPath === '') {
            $modelPath = getenv('VOLTRON_GGUF_PATH') ?: 
                '/Users/sasha/king/demo/userland/voltron/models/qwen2.5-coder:3b-q4_k.gguf';
        }
        
        $this->modelPath = $modelPath;
    }

    public function start(): void
    {
        echo "Initializing LayerWorker for layers {$this->layerStart}-{$this->layerEnd}...\n";
        $this->worker = new LayerWorker($this->modelPath, $this->layerStart, $this->layerEnd);
        
        $this->socket = stream_socket_server(
            "tcp://0.0.0.0:{$this->port}",
            $errno,
            $errstr
        );
        
        if (!$this->socket) {
            throw new \RuntimeException("Failed to create server: {$errstr}");
        }

        stream_set_blocking($this->socket, true);
        
        $this->running = true;
        echo "OllamaLayerServer listening on port {$this->port}\n";
        echo "Serving layers {$this->layerStart}-{$this->layerEnd}\n";
        echo "Model: {$this->modelPath}\n";

        while ($this->running) {
            $client = @stream_socket_accept($this->socket, 1);
            if ($client) {
                $this->handleClient($client);
            }
            usleep(1000);
        }
    }

    public function stop(): void
    {
        $this->running = false;
        if ($this->socket) {
            fclose($this->socket);
        }
    }

    private function handleClient($client): void
    {
        stream_set_timeout($client, 30);
        
        $header = fread($client, 4);
        if (!$header || strlen($header) < 4) {
            fclose($client);
            return;
        }

        $len = unpack('N', $header)[1];
        if ($len > 1024 * 1024 || $len < 1) {
            fclose($client);
            return;
        }

        $data = fread($client, $len);
        if ($data === false || strlen($data) < $len) {
            fclose($client);
            return;
        }

        $request = json_decode($data, true);
        if (!is_array($request)) {
            fclose($client);
            return;
        }

        $response = $this->processRequest($request);
        $responseData = json_encode($response);
        
        $header = pack('N', strlen($responseData));
        fwrite($client, $header);
        fwrite($client, $responseData);
        fclose($client);
    }

    private function processRequest(array $request): array
    {
        $action = $request['action'] ?? '';

        return match ($action) {
            'forward' => $this->forward($request),
            'embed' => $this->embed($request),
            'health' => ['status' => 'ok', 'layers' => [$this->layerStart, $this->layerEnd]],
            default => ['error' => 'unknown action: ' . $action],
        };
    }

    private function forward(array $request): array
    {
        $hidden = $request['hidden'] ?? [];
        $position = $request['position'] ?? 0;

        if (!$this->worker) {
            return ['error' => 'worker not initialized'];
        }

        try {
            $start = hrtime(true);
            $result = $this->worker->forward($hidden, $position);
            $duration = (hrtime(true) - $start) / 1e6;

            return [
                'success' => true,
                'action' => 'forward',
                'hidden' => $result['hidden'],
                'layers_processed' => $result['layers_processed'],
                'hidden_size' => count($hidden),
                'position' => $position,
                'duration_ms' => round($duration, 2),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function embed(array $request): array
    {
        $tokenId = $request['token_id'] ?? 0;

        error_log("embed() called with token_id=$tokenId, worker=" . ($this->worker ? "ok" : "null"));

        if (!$this->worker) {
            return ['error' => 'worker not initialized'];
        }

        try {
            $hidden = $this->worker->embed((int) $tokenId);
            error_log("embed() returned " . count($hidden) . " values");
            return [
                'success' => true,
                'action' => 'embed',
                'hidden' => array_slice($hidden, 0, 10),  // Limit for transport
                'hidden_full_size' => count($hidden),
                'token_id' => $tokenId,
                'layer_range' => [$this->layerStart, $this->layerEnd],
            ];
        } catch (\Throwable $e) {
            error_log("embed() error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($argv[0] ?? '')) {
    $port = (int) ($argv[1] ?? 9533);
    $layerStart = (int) ($argv[2] ?? 0);
    $layerEnd = (int) ($argv[3] ?? 11);
    $modelPath = ($argv[4] ?? '') !== '' ? $argv[4] : (getenv('VOLTRON_GGUF_PATH') ?: '');

    $server = new OllamaLayerServer($port, $layerStart, $layerEnd, $modelPath);
    
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn() => $server->stop());
        pcntl_signal(SIGINT, fn() => $server->stop());
    }

    $server->start();
}