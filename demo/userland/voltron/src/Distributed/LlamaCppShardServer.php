<?php
declare(strict_types=1);

namespace King\Voltron\Distributed;

class LlamaCppShardServer
{
    private int $port;
    private int $shardIndex;
    private int $shardCount;
    private string $modelPath;
    private string $llamaServerPath;
    private ?int $processId = null;
    private bool $running = false;
    private $socket;

    private const BASE_PORT = 9700;

    public function __construct(
        int $shardIndex = 0,
        int $shardCount = 6,
        string $modelPath = '',
        string $llamaServerPath = '/tmp/llama.cpp/build/bin/llama-server'
    ) {
        $this->shardIndex = $shardIndex;
        $this->shardCount = $shardCount;
        $this->llamaServerPath = $llamaServerPath;
        
        if ($modelPath === '') {
            $modelPath = getenv('VOLTRON_GGUF_PATH') ?: 
                '/Users/sasha/qwen2.5-coder-3b-Q4_K.gguf';
        }
        
        $this->modelPath = $modelPath;
        $this->port = self::BASE_PORT + $shardIndex;
    }

    public function start(): void
    {
        $layersPerShard = (int) (36 / $this->shardCount);
        $layerStart = $this->shardIndex * $layersPerShard;
        $layerEnd = min(35, $layerStart + $layersPerShard - 1);
        
        $this->socket = stream_socket_server(
            "tcp://0.0.0.0:{$this->port}",
            $errno,
            $errstr
        );
        
        if (!$this->socket) {
            throw new \RuntimeException("Failed to create server: {$errstr}");
        }

        stream_set_blocking($this->socket, false);
        
        $this->running = true;
        echo "LlamaCppShardServer shard {$this->shardIndex} listening on port {$this->port}\n";
        echo "Model: {$this->modelPath}\n";
        echo "Layers: {$layerStart}-{$layerEnd}\n";
        
        while ($this->running) {
            $client = @stream_socket_accept($this->socket, 0, $errno);
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
        stream_set_timeout($client, 60);
        
        $header = fread($client, 4);
        if (!$header || strlen($header) < 4) {
            fclose($client);
            return;
        }
        
        $length = unpack('N', $header)[1];
        $body = '';
        while (strlen($body) < $length) {
            $chunk = fread($client, $length - strlen($body));
            if ($chunk === false || $chunk === '') break;
            $body .= $chunk;
        }
        
        if ($body === '') {
            fclose($client);
            return;
        }
        
        $request = json_decode($body, true);
        $response = $this->processRequest($request);
        
        $responseBody = json_encode($response);
        $responseHeader = pack('N', strlen($responseBody));
        
        fwrite($client, $responseHeader . $responseBody);
        fclose($client);
    }

    private function processRequest(array $request): array
    {
        $action = $request['action'] ?? '';
        
        switch ($action) {
            case 'health':
                return ['status' => 'ok', 'shard' => $this->shardIndex];
            case 'info':
                return $this->getShardInfo();
            case 'embed':
                return $this->handleEmbed($request);
            case 'forward':
                return $this->handleForward($request);
            case 'generate':
                return $this->handleGenerate($request);
            default:
                return ['error' => "Unknown action: $action"];
        }
    }

    private function getShardInfo(): array
    {
        $layersPerShard = (int) (36 / $this->shardCount);
        $layerStart = $this->shardIndex * $layersPerShard;
        $layerEnd = min(35, $layerStart + $layersPerShard - 1);
        
        return [
            'shard_index' => $this->shardIndex,
            'shard_count' => $this->shardCount,
            'layer_start' => $layerStart,
            'layer_end' => $layerEnd,
            'port' => $this->port,
        ];
    }

    private function handleEmbed(array $request): array
    {
        $tokenId = $request['token_id'] ?? 0;
        
        return [
            'token_id' => $tokenId,
            'shard_index' => $this->shardIndex,
            'status' => 'embed_not_implemented',
        ];
    }

    private function handleForward(array $request): array
    {
        $hidden = $request['hidden'] ?? [];
        $position = $request['position'] ?? 0;
        
        return [
            'hidden' => $hidden,
            'position' => $position,
            'shard_index' => $this->shardIndex,
            'status' => 'forward_not_implemented',
        ];
    }

    private function handleGenerate(array $request): array
    {
        $prompt = $request['prompt'] ?? '';
        $maxTokens = $request['max_tokens'] ?? 20;
        
        $cmd = sprintf(
            '%s -m %s -p %s -n %d --temp 0 2>/dev/null',
            escapeshellarg($this->llamaServerPath),
            escapeshellarg($this->modelPath),
            escapeshellarg($prompt),
            $maxTokens
        );
        
        $output = shell_exec($cmd);
        
        return [
            'output' => $output,
            'shard_index' => $this->shardIndex,
        ];
    }

    static public function createShardProcesses(
        int $shardCount,
        string $modelPath,
        string $llamaServerPath = '/tmp/llama.cpp/build/bin/llama-server'
    ): array {
        $pids = [];
        
        for ($i = 0; $i < $shardCount; $i++) {
            $cmd = sprintf(
                'php %s %d %d %s %s > /tmp/shard-%d.log 2>&1 &',
                escapeshellarg(__FILE__),
                $i,
                $shardCount,
                escapeshellarg($modelPath),
                escapeshellarg($llamaServerPath),
                $i
            );
            
            exec($cmd);
            $pids[] = $i;
            
            usleep(50000);
        }
        
        sleep(2);
        
        return $pids;
    }

    static public function killAllShards(): void
    {
        exec('pkill -f "LlamaCppShardServer.php" 2>/dev/null');
    }

    static public function healthCheck(int $shardCount): array
    {
        $results = [];
        
        for ($i = 0; $i < $shardCount; $i++) {
            $port = self::BASE_PORT + $i;
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
            
            if ($socket) {
                $request = json_encode(['action' => 'health']);
                $header = pack('N', strlen($request));
                fwrite($socket, $header . $request);
                
                $responseHeader = fread($socket, 4);
                if ($responseHeader && strlen($responseHeader) === 4) {
                    $length = unpack('N', $responseHeader)[1];
                    $response = fread($socket, $length);
                    $results[$i] = json_decode($response, true);
                } else {
                    $results[$i] = ['status' => 'no_response'];
                }
                fclose($socket);
            } else {
                $results[$i] = ['status' => 'down', 'error' => $errstr];
            }
        }
        
        return $results;
    }
}

if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $shardIndex = (int) ($argv[1] ?? 0);
    $shardCount = (int) ($argv[2] ?? 1);
    $modelPath = $argv[3] ?? '';
    $llamaServerPath = $argv[4] ?? '/tmp/llama.cpp/build/bin/llama-server';
    
    $server = new LlamaCppShardServer($shardIndex, $shardCount, $modelPath, $llamaServerPath);
    
    $server->start();
}