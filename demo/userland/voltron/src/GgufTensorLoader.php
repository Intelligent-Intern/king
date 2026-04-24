<?php
declare(strict_types=1);

namespace King\Voltron;

use RuntimeException;

final class GgufTensorLoader
{
    public const TYPE_F32 = 0;
    public const TYPE_F16 = 1;
    public const TYPE_Q8_0 = 8;
    public const TYPE_Q4_K = 12;
    public const TYPE_Q6_K = 14;

    /** @var array<string,self> */
    private static array $cache = [];

    private string $cacheKey;
    private string $path;
    /** @var resource */
    private $fh;
    private int $alignment = 32;
    private int $version = 0;
    private int $tensorCount = 0;
    private int $metadataCount = 0;
    private int $tensorDataOffset = 0;

    /** @var array<string,mixed> */
    private array $metadata = [];

    /** @var array<string,array{name:string,dims:array<int,int>,type:int,offset:int,ne0:int,row_count:int,row_size:int,n_elem:int,n_bytes:int}> */
    private array $tensors = [];

    /** @var array<string,array<int,array<int,float>>> */
    private array $rowCache = [];

    private int $rowCacheLimit;

    /** @param array<string,mixed> $params */
    public static function fromParams(array $params): self
    {
        $path = self::resolveGgufPath($params);
        $key = sha1($path);

        if (!isset(self::$cache[$key])) {
            self::$cache[$key] = new self($path, $key);
        }

        return self::$cache[$key];
    }

    /**
     * @param array<string,mixed> $params
     */
    private static function resolveGgufPath(array $params): string
    {
        $isValidObjectId = static function (string $candidate): bool {
            return $candidate !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $candidate) === 1;
        };

        $directPath = trim((string) ($params['gguf_path'] ?? getenv('VOLTRON_GGUF_PATH') ?? ''));
        if ($directPath !== '') {
            $pathCandidates = self::resolvePathCandidates($directPath);
            foreach ($pathCandidates as $candidatePath) {
                if (is_file($candidatePath)) {
                    return $candidatePath;
                }
            }
            $baseName = basename($directPath);
            foreach (self::ggufSearchRoots() as $root) {
                $hit = self::findFileByBasename($root, $baseName);
                if ($hit !== null) {
                    return $hit;
                }
            }
            throw new RuntimeException(
                "GGUF path does not exist: {$directPath}. "
                . 'Expected a real model file. If you already have it via Ollama, link or copy it into demo/models/.'
            );
        }

        $objectId = trim((string) ($params['gguf_object_id'] ?? getenv('VOLTRON_GGUF_OBJECT_ID') ?? ''));
        if ($objectId === '') {
            $modelSource = trim((string) ($params['model_source'] ?? ''));
            if (str_starts_with($modelSource, 'object://')) {
                $derived = substr($modelSource, strlen('object://'));
                if (is_string($derived) && $isValidObjectId($derived)) {
                    $objectId = $derived;
                }
            }
        }

        if ($objectId === '') {
            $autoPath = self::resolveAutoDiscoveredPath($params);
            if ($autoPath !== null) {
                return $autoPath;
            }

            throw new RuntimeException(
                'No GGUF source configured. Set --gguf=... or VOLTRON_GGUF_PATH, provide gguf_object_id/model_source object://..., '
                . 'or install/pull a local Ollama model (for example: ollama pull qwen2.5-coder:3b).'
            );
        }
        if (!$isValidObjectId($objectId)) {
            throw new RuntimeException(
                "Invalid GGUF object id '{$objectId}'. Use a King object-store object id matching [A-Za-z0-9._-], "
                . 'or pass --gguf=/path/to/model.gguf.'
            );
        }

        $cacheRoot = getenv('VOLTRON_GGUF_CACHE_ROOT');
        if (!is_string($cacheRoot) || trim($cacheRoot) === '') {
            $cacheRoot = sys_get_temp_dir() . '/voltron-gguf-cache';
        }
        if (!is_dir($cacheRoot) && !@mkdir($cacheRoot, 0777, true) && !is_dir($cacheRoot)) {
            throw new RuntimeException('Failed to create GGUF cache root.');
        }

        $cachePath = rtrim($cacheRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . sha1($objectId) . '.gguf';
        if (!is_file($cachePath)) {
            $blob = voltron_model_blob_get($objectId);
            if (@file_put_contents($cachePath, $blob) === false) {
                throw new RuntimeException('Failed to materialize GGUF object into local cache.');
            }
        }

        return $cachePath;
    }

    /**
     * @return array<int,string>
     */
    private static function resolvePathCandidates(string $path): array
    {
        $path = self::expandUserPath($path);
        $candidates = [];

        $push = static function (string $candidate) use (&$candidates): void {
            $normalized = str_replace('\\', '/', $candidate);
            if ($normalized === '') {
                return;
            }
            if (!in_array($normalized, $candidates, true)) {
                $candidates[] = $normalized;
            }
        };

        $push($path);

        $isAbsolute = str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;

        if (!$isAbsolute) {
            $cwd = getcwd();
            if (is_string($cwd) && $cwd !== '') {
                $push(rtrim($cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path);
            }

            $repoRoot = dirname(__DIR__, 4);
            $push($repoRoot . DIRECTORY_SEPARATOR . $path);

            $voltronRoot = dirname(__DIR__, 1);
            $push($voltronRoot . DIRECTORY_SEPARATOR . $path);
        }

        return $candidates;
    }

    /**
     * @return array<int,string>
     */
    private static function ggufSearchRoots(): array
    {
        $repoRoot = dirname(__DIR__, 4);
        return [
            $repoRoot . '/demo/models',
            $repoRoot . '/models',
            $repoRoot . '/demo/model-inference/backend-king-php/.local/fixtures',
        ];
    }

    /**
     * @param array<string,mixed> $params
     */
    private static function resolveAutoDiscoveredPath(array $params): ?string
    {
        $ollama = self::resolveFromOllama($params);
        if ($ollama !== null) {
            return $ollama;
        }

        $hf = self::resolveFromHfCache($params);
        if ($hf !== null) {
            return $hf;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $params
     */
    private static function resolveFromOllama(array $params): ?string
    {
        $modelsRoot = self::ollamaModelsRoot($params);
        if ($modelsRoot === null) {
            return null;
        }

        foreach (self::modelRefCandidates($params) as $modelRef) {
            $manifestRel = self::ollamaManifestRelPath($modelRef);
            if ($manifestRel === null) {
                continue;
            }

            $resolved = self::resolveFromOllamaManifest($modelsRoot, $manifestRel);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $params
     */
    private static function ollamaModelsRoot(array $params): ?string
    {
        $candidate = trim((string) ($params['ollama_models_root'] ?? getenv('VOLTRON_OLLAMA_MODELS_DIR') ?? ''));
        if ($candidate === '') {
            $home = getenv('HOME');
            if (is_string($home) && $home !== '') {
                $candidate = rtrim($home, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.ollama' . DIRECTORY_SEPARATOR . 'models';
            }
        }

        if ($candidate === '') {
            return null;
        }
        $candidate = self::expandUserPath($candidate);
        if (!is_dir($candidate)) {
            return null;
        }

        return $candidate;
    }

    private static function resolveFromOllamaManifest(string $modelsRoot, string $manifestRel): ?string
    {
        $manifestPath = rtrim($modelsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'manifests' . DIRECTORY_SEPARATOR . $manifestRel;
        if (!is_file($manifestPath)) {
            return null;
        }

        $raw = @file_get_contents($manifestPath);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $layers = $decoded['layers'] ?? null;
        if (!is_array($layers)) {
            return null;
        }

        $fallbackCandidates = [];
        foreach ($layers as $layer) {
            if (!is_array($layer)) {
                continue;
            }
            $digest = trim((string) ($layer['digest'] ?? ''));
            if ($digest === '' || !preg_match('/^sha256:[0-9a-f]{64}$/', $digest)) {
                continue;
            }
            $blobPath = rtrim($modelsRoot, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . 'blobs'
                . DIRECTORY_SEPARATOR
                . str_replace(':', '-', $digest);
            if (!is_file($blobPath) || !self::isGgufFile($blobPath)) {
                continue;
            }

            $mediaType = strtolower(trim((string) ($layer['mediaType'] ?? $layer['media_type'] ?? '')));
            if ($mediaType === '' || str_contains($mediaType, '.model') || str_contains($mediaType, 'model')) {
                return str_replace('\\', '/', $blobPath);
            }
            $fallbackCandidates[] = str_replace('\\', '/', $blobPath);
        }

        return $fallbackCandidates[0] ?? null;
    }

    private static function ollamaManifestRelPath(string $modelRef): ?string
    {
        $ref = trim($modelRef);
        if ($ref === '') {
            return null;
        }

        if (str_starts_with($ref, 'ollama://')) {
            $ref = substr($ref, strlen('ollama://'));
            if (!is_string($ref)) {
                return null;
            }
        }

        if (str_contains($ref, ' ')) {
            $parts = preg_split('/\s+/', $ref);
            if (is_array($parts) && isset($parts[0]) && is_string($parts[0])) {
                $ref = $parts[0];
            }
        }
        if ($ref === '') {
            return null;
        }

        $namePart = $ref;
        $tag = 'latest';
        $tagPos = strrpos($ref, ':');
        if ($tagPos !== false && $tagPos > 0 && $tagPos < (strlen($ref) - 1)) {
            $namePart = substr($ref, 0, $tagPos);
            $tag = substr($ref, $tagPos + 1);
        }
        if (!is_string($namePart) || !is_string($tag) || $namePart === '' || $tag === '') {
            return null;
        }

        $registry = 'registry.ollama.ai';
        $pathPart = trim($namePart, '/');
        if (preg_match('#^([A-Za-z0-9.-]+\.[A-Za-z0-9.-]+)/(.+)$#', $pathPart, $m) === 1) {
            $registry = $m[1];
            $pathPart = $m[2];
        }

        if (!str_contains($pathPart, '/')) {
            $pathPart = 'library/' . $pathPart;
        }
        $pathPart = trim($pathPart, '/');

        return $registry . '/' . $pathPart . '/' . $tag;
    }

    /**
     * @param array<string,mixed> $params
     */
    private static function resolveFromHfCache(array $params): ?string
    {
        $explicit = trim((string) ($params['hf_gguf_path'] ?? getenv('VOLTRON_HF_GGUF_PATH') ?? ''));
        if ($explicit !== '') {
            foreach (self::resolvePathCandidates($explicit) as $candidatePath) {
                if (is_file($candidatePath) && self::isGgufFile($candidatePath)) {
                    return $candidatePath;
                }
            }
        }

        $root = trim((string) ($params['hf_cache_root'] ?? getenv('VOLTRON_HF_CACHE_ROOT') ?? ''));
        if ($root === '') {
            $hfHome = getenv('HF_HOME');
            if (is_string($hfHome) && trim($hfHome) !== '') {
                $root = rtrim(trim($hfHome), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'hub';
            } else {
                $home = getenv('HOME');
                if (is_string($home) && trim($home) !== '') {
                    $root = rtrim(trim($home), DIRECTORY_SEPARATOR)
                        . DIRECTORY_SEPARATOR . '.cache'
                        . DIRECTORY_SEPARATOR . 'huggingface'
                        . DIRECTORY_SEPARATOR . 'hub';
                }
            }
        }
        if ($root === '') {
            return null;
        }
        $root = self::expandUserPath($root);
        if (!is_dir($root)) {
            return null;
        }

        $repo = trim((string) ($params['hf_repo'] ?? getenv('VOLTRON_HF_REPO') ?? ''));
        $filename = trim((string) ($params['hf_file'] ?? getenv('VOLTRON_HF_FILENAME') ?? ''));
        if ($repo !== '' && $filename !== '') {
            $repoDir = rtrim($root, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . 'models--'
                . str_replace('/', '--', $repo)
                . DIRECTORY_SEPARATOR
                . 'snapshots';
            if (is_dir($repoDir)) {
                $entries = @scandir($repoDir);
                if (is_array($entries)) {
                    foreach ($entries as $entry) {
                        if ($entry === '.' || $entry === '..') {
                            continue;
                        }
                        $candidate = $repoDir . DIRECTORY_SEPARATOR . $entry . DIRECTORY_SEPARATOR . $filename;
                        if (is_file($candidate) && self::isGgufFile($candidate)) {
                            return str_replace('\\', '/', $candidate);
                        }
                    }
                }
            }
        }

        $slugs = [];
        foreach (self::modelRefCandidates($params) as $ref) {
            $base = strtolower($ref);
            if (str_contains($base, ' ')) {
                $parts = preg_split('/\s+/', $base);
                if (is_array($parts) && isset($parts[0]) && is_string($parts[0])) {
                    $base = $parts[0];
                }
            }
            $slug = preg_replace('/[^a-z0-9]+/', '-', $base);
            if (is_string($slug)) {
                $slug = trim($slug, '-');
            }
            if (is_string($slug) && $slug !== '' && !in_array($slug, $slugs, true)) {
                $slugs[] = $slug;
            }
        }

        return self::findFirstGgufMatch($root, $slugs);
    }

    /**
     * @param array<int,string> $nameHints
     */
    private static function findFirstGgufMatch(string $root, array $nameHints): ?string
    {
        if (!is_dir($root)) {
            return null;
        }

        try {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $entry) {
                if (!$entry instanceof \SplFileInfo || !$entry->isFile()) {
                    continue;
                }
                $path = str_replace('\\', '/', $entry->getPathname());
                if (!str_ends_with(strtolower($path), '.gguf')) {
                    continue;
                }
                $name = strtolower($entry->getFilename());
                if ($nameHints !== []) {
                    $matched = false;
                    foreach ($nameHints as $hint) {
                        if ($hint !== '' && str_contains($name, $hint)) {
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched) {
                        continue;
                    }
                }
                if (self::isGgufFile($path)) {
                    return $path;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private static function isGgufFile(string $path): bool
    {
        $fh = @fopen($path, 'rb');
        if (!is_resource($fh)) {
            return false;
        }

        try {
            $magic = fread($fh, 4);
            return is_string($magic) && $magic === 'GGUF';
        } finally {
            @fclose($fh);
        }
    }

    /**
     * @param array<string,mixed> $params
     * @return array<int,string>
     */
    private static function modelRefCandidates(array $params): array
    {
        $raw = [];
        foreach ([
            $params['inference_model_name'] ?? null,
            $params['model_name'] ?? null,
            $params['model'] ?? null,
            getenv('VOLTRON_INFERENCE_MODEL_NAME'),
            'qwen2.5-coder:3b',
        ] as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $value = trim($candidate);
            if ($value !== '' && !in_array($value, $raw, true)) {
                $raw[] = $value;
            }
        }

        return $raw;
    }

    private static function expandUserPath(string $path): string
    {
        if ($path === '') {
            return $path;
        }
        if ($path[0] !== '~') {
            return $path;
        }

        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            return $path;
        }

        if ($path === '~') {
            return $home;
        }
        if (str_starts_with($path, '~/')) {
            return rtrim($home, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . substr($path, 2);
        }

        return $path;
    }

    private static function findFileByBasename(string $root, string $baseName): ?string
    {
        if ($baseName === '' || !is_dir($root)) {
            return null;
        }

        try {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $entry) {
                if (!$entry instanceof \SplFileInfo || !$entry->isFile()) {
                    continue;
                }
                if ($entry->getFilename() === $baseName) {
                    return str_replace('\\', '/', $entry->getPathname());
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function __construct(string $path, string $cacheKey)
    {
        $fh = @fopen($path, 'rb');
        if (!is_resource($fh)) {
            throw new RuntimeException("Failed to open GGUF file: {$path}");
        }

        $this->fh = $fh;
        $this->path = $path;
        $this->cacheKey = $cacheKey;

        $rowCacheLimit = getenv('VOLTRON_GGUF_ROW_CACHE_LIMIT');
        $this->rowCacheLimit = (is_string($rowCacheLimit) && ctype_digit($rowCacheLimit) && (int) $rowCacheLimit >= 0)
            ? (int) $rowCacheLimit
            : 0;

        $this->parse();
    }

    public function __destruct()
    {
        if (is_resource($this->fh)) {
            @fclose($this->fh);
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @return array<string,mixed> */
    public function metadataAll(): array
    {
        return $this->metadata;
    }

    public function metadata(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->metadata) ? $this->metadata[$key] : $default;
    }

    /** @return array<int,string> */
    public function tokenizerTokens(): array
    {
        $tokens = $this->metadata('tokenizer.ggml.tokens', []);
        if (!is_array($tokens)) {
            return [];
        }

        $normalized = [];
        foreach ($tokens as $tok) {
            $normalized[] = is_string($tok) ? $tok : '';
        }

        return $normalized;
    }

    /** @return array<int,string> */
    public function tokenizerMerges(): array
    {
        $merges = $this->metadata('tokenizer.ggml.merges', []);
        if (!is_array($merges)) {
            return [];
        }

        $normalized = [];
        foreach ($merges as $merge) {
            $normalized[] = is_string($merge) ? $merge : '';
        }

        return $normalized;
    }

    public function tokenizerModel(): string
    {
        $model = $this->metadata('tokenizer.ggml.model', '');
        return is_string($model) ? $model : '';
    }

    public function tokenizerPre(): string
    {
        $pre = $this->metadata('tokenizer.ggml.pre', '');
        return is_string($pre) ? $pre : '';
    }

    /** @return array<int,float> */
    public function tokenizerScores(): array
    {
        $scores = $this->metadata('tokenizer.ggml.scores', []);
        if (!is_array($scores)) {
            return [];
        }

        $normalized = [];
        foreach ($scores as $score) {
            $normalized[] = is_float($score) || is_int($score) ? (float) $score : 0.0;
        }

        return $normalized;
    }

    /** @return array<int,int> */
    public function tokenizerTypes(): array
    {
        $types = $this->metadata('tokenizer.ggml.token_type', []);
        if (!is_array($types)) {
            return [];
        }

        $normalized = [];
        foreach ($types as $type) {
            $normalized[] = is_int($type) ? $type : (int) $type;
        }

        return $normalized;
    }

    /** @return array<int,string> */
    public function tensorNames(): array
    {
        return array_keys($this->tensors);
    }

    /** @return array{name:string,dims:array<int,int>,type:int,offset:int,ne0:int,row_count:int,row_size:int,n_elem:int,n_bytes:int} */
    public function tensor(string $name): array
    {
        if (!isset($this->tensors[$name])) {
            throw new RuntimeException("Tensor not found in GGUF: {$name}");
        }

        return $this->tensors[$name];
    }

    /** @param array<int,string> $candidates */
    public function findTensor(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            if (isset($this->tensors[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    public function ne0(string $tensorName): int
    {
        return $this->tensor($tensorName)['ne0'];
    }

    public function rowCount(string $tensorName): int
    {
        return $this->tensor($tensorName)['row_count'];
    }

    /** @return array<string,int|string|array<int,int>> */
    public function nativeTensorMeta(string $tensorName): array
    {
        $meta = $this->tensor($tensorName);

        return [
            'name' => $meta['name'],
            'dims' => $meta['dims'],
            'type' => (int) $meta['type'],
            'absolute_offset' => (int) ($this->tensorDataOffset + $meta['offset']),
            'offset' => (int) $meta['offset'],
            'ne0' => (int) $meta['ne0'],
            'row_count' => (int) $meta['row_count'],
            'row_size' => (int) $meta['row_size'],
            'n_elem' => (int) $meta['n_elem'],
            'n_bytes' => (int) $meta['n_bytes'],
        ];
    }

    /**
     * @return array<int,float>
     */
    public function readRow(string $tensorName, int $rowIndex): array
    {
        $meta = $this->tensor($tensorName);
        if ($rowIndex < 0 || $rowIndex >= $meta['row_count']) {
            throw new RuntimeException("Tensor row out of bounds: {$tensorName}[{$rowIndex}] / {$meta['row_count']}");
        }

        if ($this->rowCacheLimit > 0 && isset($this->rowCache[$tensorName][$rowIndex])) {
            return $this->rowCache[$tensorName][$rowIndex];
        }

        $offset = $this->tensorDataOffset + $meta['offset'] + ($rowIndex * $meta['row_size']);
        $raw = $this->readAt($offset, $meta['row_size']);

        $decoded = match ($meta['type']) {
            self::TYPE_F32 => $this->decodeRowF32($raw, $meta['ne0']),
            self::TYPE_F16 => $this->decodeRowF16($raw, $meta['ne0']),
            self::TYPE_Q8_0 => $this->decodeRowQ8_0($raw, $meta['ne0']),
            self::TYPE_Q4_K => $this->decodeRowQ4K($raw, $meta['ne0']),
            self::TYPE_Q6_K => $this->decodeRowQ6K($raw, $meta['ne0']),
            default => throw new RuntimeException("Unsupported GGUF tensor type {$meta['type']} for {$tensorName}"),
        };

        if ($this->rowCacheLimit > 0) {
            if (!isset($this->rowCache[$tensorName])) {
                $this->rowCache[$tensorName] = [];
            }
            if (count($this->rowCache[$tensorName]) >= $this->rowCacheLimit) {
                array_shift($this->rowCache[$tensorName]);
            }
            $this->rowCache[$tensorName][$rowIndex] = $decoded;
        }

        return $decoded;
    }

    private function parse(): void
    {
        $magic = $this->readBytes(4);
        if ($magic !== 'GGUF') {
            throw new RuntimeException('Invalid GGUF magic header.');
        }

        $this->version = $this->readU32();
        if ($this->version < 2 || $this->version > 3) {
            throw new RuntimeException('Unsupported GGUF version: ' . $this->version);
        }

        $this->tensorCount = $this->readU64();
        $this->metadataCount = $this->readU64();

        for ($i = 0; $i < $this->metadataCount; $i++) {
            $key = $this->readGgufString();
            $type = $this->readU32();
            $value = $this->readMetadataValue($type);
            $this->metadata[$key] = $value;
        }

        $alignment = $this->metadata('general.alignment', 32);
        if (is_int($alignment) && $alignment > 0) {
            $this->alignment = $alignment;
        } elseif (is_float($alignment) && $alignment > 0.0) {
            $this->alignment = (int) $alignment;
        }

        for ($i = 0; $i < $this->tensorCount; $i++) {
            $name = $this->readGgufString();
            $nDims = $this->readU32();
            $dims = [];
            for ($d = 0; $d < $nDims; $d++) {
                $dims[] = $this->readU64();
            }

            $type = $this->readU32();
            $offset = $this->readU64();

            if ($dims === []) {
                throw new RuntimeException("Tensor {$name} has empty dimensions.");
            }

            $ne0 = $dims[0];
            if ($ne0 <= 0) {
                throw new RuntimeException("Tensor {$name} has invalid ne0.");
            }

            $nElem = 1;
            foreach ($dims as $dim) {
                $nElem *= max(1, $dim);
            }
            $rowCount = intdiv($nElem, $ne0);
            $rowSize = $this->rowSizeForType($type, $ne0);
            $nBytes = $rowSize * $rowCount;

            $this->tensors[$name] = [
                'name' => $name,
                'dims' => $dims,
                'type' => $type,
                'offset' => $offset,
                'ne0' => $ne0,
                'row_count' => $rowCount,
                'row_size' => $rowSize,
                'n_elem' => $nElem,
                'n_bytes' => $nBytes,
            ];
        }

        $cursor = ftell($this->fh);
        if (!is_int($cursor) || $cursor < 0) {
            throw new RuntimeException('Failed to determine GGUF tensor data start cursor.');
        }

        $this->tensorDataOffset = $this->alignTo($cursor, $this->alignment);
    }

    private function rowSizeForType(int $type, int $ne0): int
    {
        return match ($type) {
            self::TYPE_F32 => $ne0 * 4,
            self::TYPE_F16 => $ne0 * 2,
            self::TYPE_Q8_0 => $this->rowSizeQ8_0($ne0),
            self::TYPE_Q4_K => $this->rowSizeQ4K($ne0),
            self::TYPE_Q6_K => $this->rowSizeQ6K($ne0),
            default => throw new RuntimeException("Unsupported GGUF tensor type: {$type}"),
        };
    }

    private function rowSizeQ8_0(int $ne0): int
    {
        if ($ne0 % 32 !== 0) {
            throw new RuntimeException("Q8_0 row requires ne0 divisible by 32; got {$ne0}");
        }

        $blocks = intdiv($ne0, 32);
        return $blocks * (2 + 32);
    }

    private function rowSizeQ4K(int $ne0): int
    {
        if ($ne0 % 256 !== 0) {
            throw new RuntimeException("Q4_K row requires ne0 divisible by 256; got {$ne0}");
        }

        $blocks = intdiv($ne0, 256);
        return $blocks * 144;
    }

    private function rowSizeQ6K(int $ne0): int
    {
        if ($ne0 % 256 !== 0) {
            throw new RuntimeException("Q6_K row requires ne0 divisible by 256; got {$ne0}");
        }

        $blocks = intdiv($ne0, 256);
        return $blocks * 210;
    }

    /**
     * @return array<int,float>
     */
    private function decodeRowF32(string $raw, int $ne0): array
    {
        $out = [];
        for ($i = 0; $i < $ne0; $i++) {
            $bytes = substr($raw, $i * 4, 4);
            $unpacked = unpack('g', $bytes);
            $out[] = is_array($unpacked) ? (float) ($unpacked[1] ?? 0.0) : 0.0;
        }

        return $out;
    }

    /**
     * @return array<int,float>
     */
    private function decodeRowF16(string $raw, int $ne0): array
    {
        $out = [];
        for ($i = 0; $i < $ne0; $i++) {
            $h = $this->u16le(substr($raw, $i * 2, 2));
            $out[] = $this->halfToFloat($h);
        }

        return $out;
    }

    /**
     * @return array<int,float>
     */
    private function decodeRowQ8_0(string $raw, int $ne0): array
    {
        $out = [];
        $cursor = 0;
        $blocks = intdiv($ne0, 32);

        for ($b = 0; $b < $blocks; $b++) {
            $d = $this->halfToFloat($this->u16le(substr($raw, $cursor, 2)));
            $cursor += 2;

            for ($j = 0; $j < 32; $j++) {
                $q = ord($raw[$cursor + $j]);
                if ($q >= 128) {
                    $q -= 256;
                }
                $out[] = $d * $q;
            }
            $cursor += 32;
        }

        return $out;
    }

    /**
     * @return array<int,float>
     */
    private function decodeRowQ4K(string $raw, int $ne0): array
    {
        $out = [];
        $cursor = 0;
        $blocks = intdiv($ne0, 256);

        for ($b = 0; $b < $blocks; $b++) {
            $d = $this->halfToFloat($this->u16le(substr($raw, $cursor, 2)));
            $dmin = $this->halfToFloat($this->u16le(substr($raw, $cursor + 2, 2)));
            $cursor += 4;

            $scalesRaw = substr($raw, $cursor, 12);
            $scales = [];
            for ($i = 0; $i < 12; $i++) {
                $scales[] = ord($scalesRaw[$i]);
            }
            $cursor += 12;

            $q = substr($raw, $cursor, 128);
            $cursor += 128;

            $is = 0;
            $qOffset = 0;
            for ($j = 0; $j < 256; $j += 64) {
                [$sc1, $m1] = $this->getScaleMinK4($is + 0, $scales);
                [$sc2, $m2] = $this->getScaleMinK4($is + 1, $scales);

                $d1 = $d * $sc1;
                $min1 = $dmin * $m1;
                $d2 = $d * $sc2;
                $min2 = $dmin * $m2;

                for ($l = 0; $l < 32; $l++) {
                    $byte = ord($q[$qOffset + $l]);
                    $out[] = $d1 * ($byte & 0x0F) - $min1;
                }
                for ($l = 0; $l < 32; $l++) {
                    $byte = ord($q[$qOffset + $l]);
                    $out[] = $d2 * (($byte >> 4) & 0x0F) - $min2;
                }

                $qOffset += 32;
                $is += 2;
            }
        }

        return $out;
    }

    /**
     * @return array<int,float>
     */
    private function decodeRowQ6K(string $raw, int $ne0): array
    {
        $out = [];
        $cursor = 0;
        $blocks = intdiv($ne0, 256);

        for ($b = 0; $b < $blocks; $b++) {
            $ql = substr($raw, $cursor, 128);
            $cursor += 128;
            $qh = substr($raw, $cursor, 64);
            $cursor += 64;

            $scales = [];
            for ($i = 0; $i < 16; $i++) {
                $v = ord($raw[$cursor + $i]);
                if ($v >= 128) {
                    $v -= 256;
                }
                $scales[] = $v;
            }
            $cursor += 16;

            $d = $this->halfToFloat($this->u16le(substr($raw, $cursor, 2)));
            $cursor += 2;

            $qlOffset = 0;
            $qhOffset = 0;
            $scaleOffset = 0;

            for ($chunk = 0; $chunk < 2; $chunk++) {
                $values = array_fill(0, 128, 0.0);
                for ($l = 0; $l < 32; $l++) {
                    $is = intdiv($l, 16);
                    $qhByte = ord($qh[$qhOffset + $l]);
                    $qlA = ord($ql[$qlOffset + $l]);
                    $qlB = ord($ql[$qlOffset + 32 + $l]);

                    $q1 = (($qlA & 0x0F) | ((($qhByte >> 0) & 0x03) << 4)) - 32;
                    $q2 = (($qlB & 0x0F) | ((($qhByte >> 2) & 0x03) << 4)) - 32;
                    $q3 = ((($qlA >> 4) & 0x0F) | ((($qhByte >> 4) & 0x03) << 4)) - 32;
                    $q4 = ((($qlB >> 4) & 0x0F) | ((($qhByte >> 6) & 0x03) << 4)) - 32;

                    $values[$l] = $d * $scales[$scaleOffset + $is + 0] * $q1;
                    $values[$l + 32] = $d * $scales[$scaleOffset + $is + 2] * $q2;
                    $values[$l + 64] = $d * $scales[$scaleOffset + $is + 4] * $q3;
                    $values[$l + 96] = $d * $scales[$scaleOffset + $is + 6] * $q4;
                }

                foreach ($values as $v) {
                    $out[] = $v;
                }

                $qlOffset += 64;
                $qhOffset += 32;
                $scaleOffset += 8;
            }
        }

        return $out;
    }

    /**
     * @param array<int,int> $scales
     * @return array{0:int,1:int}
     */
    private function getScaleMinK4(int $j, array $scales): array
    {
        if ($j < 4) {
            $sc = $scales[$j] & 0x3F;
            $m = $scales[$j + 4] & 0x3F;
            return [$sc, $m];
        }

        $sc = (($scales[$j + 4] & 0x0F) << 2) | (($scales[$j - 4] >> 6) & 0x03);
        $m = ((($scales[$j + 4] >> 4) & 0x0F) << 2) | (($scales[$j] >> 6) & 0x03);

        return [$sc, $m];
    }

    private function readMetadataValue(int $type): mixed
    {
        return match ($type) {
            0 => ord($this->readBytes(1)),
            1 => $this->readI8(),
            2 => $this->u16le($this->readBytes(2)),
            3 => $this->readI16(),
            4 => $this->readU32(),
            5 => $this->readI32(),
            6 => $this->readFloat32(),
            7 => ord($this->readBytes(1)) !== 0,
            8 => $this->readGgufString(),
            9 => $this->readMetadataArray(),
            10 => $this->readU64(),
            11 => $this->readI64(),
            12 => $this->readFloat64(),
            default => throw new RuntimeException("Unsupported GGUF metadata type: {$type}"),
        };
    }

    /** @return array<int,mixed> */
    private function readMetadataArray(): array
    {
        $itemType = $this->readU32();
        $length = $this->readU64();
        $out = [];
        for ($i = 0; $i < $length; $i++) {
            $out[] = $this->readMetadataValue($itemType);
        }

        return $out;
    }

    private function readGgufString(): string
    {
        $len = $this->readU64();
        if ($len === 0) {
            return '';
        }
        return $this->readBytes($len);
    }

    private function readBytes(int $size): string
    {
        if ($size < 0) {
            throw new RuntimeException('Attempted to read negative byte count.');
        }
        if ($size === 0) {
            return '';
        }

        $data = fread($this->fh, $size);
        if (!is_string($data) || strlen($data) !== $size) {
            throw new RuntimeException("Unexpected EOF while reading GGUF payload (wanted {$size} bytes).");
        }

        return $data;
    }

    private function readAt(int $offset, int $size): string
    {
        if (fseek($this->fh, $offset, SEEK_SET) !== 0) {
            throw new RuntimeException("Failed to seek GGUF file to offset {$offset}.");
        }

        return $this->readBytes($size);
    }

    private function readU32(): int
    {
        $v = unpack('V', $this->readBytes(4));
        return (int) ($v[1] ?? 0);
    }

    private function readI32(): int
    {
        $u = $this->readU32();
        if ($u >= 0x80000000) {
            $u -= 0x100000000;
        }
        return $u;
    }

    private function readU64(): int
    {
        $bytes = $this->readBytes(8);
        $u = @unpack('P', $bytes);
        if (is_array($u) && isset($u[1])) {
            return (int) $u[1];
        }

        $parts = unpack('V2', $bytes);
        $lo = (int) ($parts[1] ?? 0);
        $hi = (int) ($parts[2] ?? 0);

        return (int) ($lo + ($hi * 4294967296));
    }

    private function readI64(): int
    {
        $u = $this->readU64();
        if ($u > PHP_INT_MAX) {
            return (int) ($u - 18446744073709551616.0);
        }
        return $u;
    }

    private function readI8(): int
    {
        $v = ord($this->readBytes(1));
        if ($v >= 128) {
            $v -= 256;
        }
        return $v;
    }

    private function readI16(): int
    {
        $u = $this->u16le($this->readBytes(2));
        if ($u >= 0x8000) {
            $u -= 0x10000;
        }
        return $u;
    }

    private function readFloat32(): float
    {
        $v = unpack('g', $this->readBytes(4));
        return (float) ($v[1] ?? 0.0);
    }

    private function readFloat64(): float
    {
        $v = unpack('e', $this->readBytes(8));
        return (float) ($v[1] ?? 0.0);
    }

    private function u16le(string $bytes): int
    {
        $v = unpack('v', $bytes);
        return (int) ($v[1] ?? 0);
    }

    private function alignTo(int $value, int $alignment): int
    {
        if ($alignment <= 1) {
            return $value;
        }
        $r = $value % $alignment;
        if ($r === 0) {
            return $value;
        }
        return $value + ($alignment - $r);
    }

    private function halfToFloat(int $h): float
    {
        $sign = ($h >> 15) & 0x0001;
        $exp = ($h >> 10) & 0x001F;
        $frac = $h & 0x03FF;

        if ($exp === 0) {
            if ($frac === 0) {
                return $sign ? -0.0 : 0.0;
            }
            $mant = $frac / 1024.0;
            $val = $mant * (2 ** -14);
            return $sign ? -$val : $val;
        }

        if ($exp === 31) {
            if ($frac === 0) {
                return $sign ? -INF : INF;
            }
            return NAN;
        }

        $mant = 1.0 + ($frac / 1024.0);
        $val = $mant * (2 ** ($exp - 15));
        return $sign ? -$val : $val;
    }
}
