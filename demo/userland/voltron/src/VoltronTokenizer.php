<?php
declare(strict_types=1);

namespace King\Voltron;

use RuntimeException;

final class VoltronTokenizer
{
    /** @var array<int,string> */
    private array $rawTokens;
    /** @var array<int,string> */
    private array $decodedTokens = [];
    /** @var array<string,int> */
    private array $rawTokenToId = [];
    /** @var array<string,int> */
    private array $byteTokenIds = [];
    /** @var array<int,int> */
    private array $tokenTypes;
    /** @var array<int,float> */
    private array $tokenScores;
    /** @var array<string,int> */
    private array $mergeRanks = [];
    /** @var array<string,array<int,string>> */
    private array $bpeCache = [];
    /** @var array<string,bool> */
    private array $specialTokens = [];
    /** @var array<string,int>|null */
    private ?array $byteEncoder = null;
    /** @var array<string,string>|null */
    private ?array $byteDecoder = null;
    /** @var array<int,int>|null */
    private ?array $rankedCandidateIds = null;

    private ?int $bosId;
    private ?int $eosId;
    private ?int $unkId;
    private string $model;
    private string $pre;

    /**
     * @param array<int,string> $tokens
     * @param array<int,int> $tokenTypes
     * @param array<int,float> $tokenScores
     * @param array<int,string> $merges
     */
    public function __construct(
        array $tokens,
        array $tokenTypes = [],
        array $tokenScores = [],
        array $merges = [],
        string $model = '',
        string $pre = '',
        ?int $bosId = null,
        ?int $eosId = null,
        ?int $unkId = null
    ) {
        if ($tokens === []) {
            throw new RuntimeException('Tokenizer token list is empty.');
        }

        $this->rawTokens = $tokens;
        $this->tokenTypes = $tokenTypes;
        $this->tokenScores = [];
        $this->bosId = $bosId;
        $this->eosId = $eosId;
        $this->unkId = $unkId;
        $this->model = strtolower(trim($model));
        $this->pre = strtolower(trim($pre)) !== '' ? strtolower(trim($pre)) : 'qwen2';

        foreach ($tokens as $id => $token) {
            $token = is_string($token) ? $token : '';
            $this->rawTokens[$id] = $token;
            $this->rawTokenToId[$token] = $id;
            $this->decodedTokens[$id] = $this->decodeRawToken($token);

            if (preg_match('/^<0x([0-9A-Fa-f]{2})>$/', $token, $m) === 1) {
                $this->byteTokenIds[chr(hexdec($m[1]))] = $id;
            }
            if (preg_match('/^<\|.*\|>$/', $token) === 1) {
                $this->specialTokens[$token] = true;
            }

            $score = $tokenScores[$id] ?? 0.0;
            $this->tokenScores[$id] = is_float($score) || is_int($score) ? (float) $score : 0.0;
        }

        foreach ($merges as $rank => $merge) {
            if (!is_string($merge) || $merge === '') {
                continue;
            }
            $this->mergeRanks[$merge] = $rank;
        }
    }

    public function bosId(): ?int
    {
        return $this->bosId;
    }

    public function eosId(): ?int
    {
        return $this->eosId;
    }

    public function unkId(): ?int
    {
        return $this->unkId;
    }

    public function formatPrompt(string $prompt): string
    {
        if ($this->pre !== 'qwen2') {
            return $prompt;
        }

        return '<|im_start|>system' . "\n"
            . 'You are Qwen, created by Alibaba Cloud. You are a helpful assistant.'
            . '<|im_end|>' . "\n"
            . '<|im_start|>user' . "\n"
            . $prompt
            . '<|im_end|>' . "\n"
            . '<|im_start|>assistant' . "\n";
    }

    /**
     * @return array<int,int>
     */
    public function encode(string $text, bool $prependBos = false): array
    {
        $ids = [];
        if ($prependBos && is_int($this->bosId) && $this->bosId >= 0) {
            $ids[] = $this->bosId;
        }

        foreach ($this->splitSpecialTokens($text) as $segment) {
            if ($segment === '') {
                continue;
            }
            if (isset($this->specialTokens[$segment])) {
                $ids[] = $this->rawTokenToId[$segment];
                continue;
            }

            foreach ($this->pretokenize($segment) as $piece) {
                foreach ($this->encodePiece($piece) as $id) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /** @param array<int,int> $ids */
    public function decode(array $ids): string
    {
        $out = '';
        foreach ($ids as $id) {
            $out .= $this->decodeId((int) $id);
        }

        return $out;
    }

    public function decodeId(int $id): string
    {
        if (!isset($this->rawTokens[$id])) {
            return '';
        }

        $raw = $this->rawTokens[$id];
        if (preg_match('/^<\|.*\|>$/', $raw) === 1) {
            return '';
        }

        return $this->decodedTokens[$id] ?? '';
    }

    /**
     * @return array<int,int>
     */
    public function candidateIds(int $limit = 1024): array
    {
        $limit = max(16, $limit);

        if ($this->rankedCandidateIds === null) {
            $scored = [];
            foreach ($this->decodedTokens as $id => $decoded) {
                if ($decoded === '') {
                    continue;
                }
                $raw = $this->rawTokens[$id] ?? '';
                if (preg_match('/^<\|.*\|>$/', $raw) === 1) {
                    continue;
                }

                $score = (float) ($this->tokenScores[$id] ?? 0.0);
                $scored[$id] = $score;
            }
            arsort($scored, SORT_NUMERIC);
            $this->rankedCandidateIds = array_map(static fn($id): int => (int) $id, array_keys($scored));
        }

        return array_slice($this->rankedCandidateIds, 0, $limit);
    }

    /**
     * @return array<int,string>
     */
    private function splitSpecialTokens(string $text): array
    {
        if ($this->specialTokens === []) {
            return [$text];
        }

        $pattern = '/(' . implode('|', array_map(
            static fn(string $token): string => preg_quote($token, '/'),
            array_keys($this->specialTokens)
        )) . ')/u';

        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts) || $parts === []) {
            return [$text];
        }

        return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
    }

    /**
     * @return array<int,string>
     */
    private function pretokenize(string $text): array
    {
        $pattern = "/'s|'t|'re|'ve|'m|'ll|'d| ?\\pL+| ?\\pN+| ?[^\\s\\pL\\pN]+|\\s+(?!\\S)|\\s+/u";
        if (preg_match_all($pattern, $text, $matches) !== false && isset($matches[0]) && is_array($matches[0])) {
            return array_values(array_filter(
                array_map(static fn($v): string => is_string($v) ? $v : '', $matches[0]),
                static fn(string $v): bool => $v !== ''
            ));
        }

        return [$text];
    }

    /**
     * @return array<int,int>
     */
    private function encodePiece(string $piece): array
    {
        if ($piece === '') {
            return [];
        }

        $encoded = $this->encodeBytesToUnicode($piece);
        $tokens = $this->applyBpe($encoded);
        $ids = [];

        foreach ($tokens as $token) {
            if (isset($this->rawTokenToId[$token])) {
                $ids[] = $this->rawTokenToId[$token];
                continue;
            }

            foreach ($this->unicodeChars($token) as $char) {
                if (isset($this->rawTokenToId[$char])) {
                    $ids[] = $this->rawTokenToId[$char];
                    continue;
                }
                if (isset($this->byteTokenIds[$char])) {
                    $ids[] = $this->byteTokenIds[$char];
                    continue;
                }
                if (is_int($this->unkId) && $this->unkId >= 0) {
                    $ids[] = $this->unkId;
                }
            }
        }

        return $ids;
    }

    /**
     * @return array<int,string>
     */
    private function applyBpe(string $token): array
    {
        if ($token === '') {
            return [];
        }
        if ($this->mergeRanks === [] || ($this->model !== '' && $this->model !== 'gpt2')) {
            return [$token];
        }
        if (isset($this->bpeCache[$token])) {
            return $this->bpeCache[$token];
        }

        $word = $this->unicodeChars($token);
        if (count($word) <= 1) {
            $this->bpeCache[$token] = $word;
            return $word;
        }

        while (true) {
            $pairs = $this->wordPairs($word);
            if ($pairs === []) {
                break;
            }

            $bestPair = null;
            $bestRank = PHP_INT_MAX;
            foreach ($pairs as $pair) {
                $rank = $this->mergeRanks[$pair] ?? PHP_INT_MAX;
                if ($rank < $bestRank) {
                    $bestRank = $rank;
                    $bestPair = $pair;
                }
            }

            if ($bestPair === null || !isset($this->mergeRanks[$bestPair])) {
                break;
            }

            [$left, $right] = explode(' ', $bestPair, 2);
            $merged = [];
            $count = count($word);
            for ($i = 0; $i < $count; $i++) {
                if ($i < ($count - 1) && $word[$i] === $left && $word[$i + 1] === $right) {
                    $merged[] = $left . $right;
                    $i++;
                    continue;
                }
                $merged[] = $word[$i];
            }
            $word = $merged;
            if (count($word) <= 1) {
                break;
            }
        }

        $this->bpeCache[$token] = $word;
        return $word;
    }

    /**
     * @param array<int,string> $word
     * @return array<int,string>
     */
    private function wordPairs(array $word): array
    {
        $pairs = [];
        $count = count($word);
        for ($i = 0; $i < ($count - 1); $i++) {
            $pairs[] = $word[$i] . ' ' . $word[$i + 1];
        }

        return array_values(array_unique($pairs));
    }

    private function encodeBytesToUnicode(string $text): string
    {
        $map = $this->byteEncoder();
        $out = '';
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $byte = ord($text[$i]);
            $out .= $this->codepointToUtf8($map[$byte]);
        }

        return $out;
    }

    private function decodeRawToken(string $token): string
    {
        if (preg_match('/^<\|.*\|>$/', $token) === 1) {
            return '';
        }

        return $token;
    }

    /**
     * @return array<int,int>
     */
    private function byteEncoder(): array
    {
        if (is_array($this->byteEncoder)) {
            return $this->byteEncoder;
        }

        $bs = [];
        for ($i = ord('!'); $i <= ord('~'); $i++) {
            $bs[] = $i;
        }
        for ($i = ord("\xA1"); $i <= ord("\xAC"); $i++) {
            $bs[] = $i;
        }
        for ($i = ord("\xAE"); $i <= ord("\xFF"); $i++) {
            $bs[] = $i;
        }

        $cs = $bs;
        $n = 0;
        for ($b = 0; $b < 256; $b++) {
            if (in_array($b, $bs, true)) {
                continue;
            }
            $bs[] = $b;
            $cs[] = 256 + $n;
            $n++;
        }

        $map = [];
        foreach ($bs as $idx => $b) {
            $map[$b] = $cs[$idx];
        }

        $this->byteEncoder = $map;
        return $this->byteEncoder;
    }

    /**
     * @return array<string,string>
     */
    private function byteDecoder(): array
    {
        if (is_array($this->byteDecoder)) {
            return $this->byteDecoder;
        }

        $decoder = [];
        foreach ($this->byteEncoder() as $byte => $codepoint) {
            $decoder[$this->codepointToUtf8($codepoint)] = chr($byte);
        }

        $this->byteDecoder = $decoder;
        return $this->byteDecoder;
    }

    /**
     * @return array<int,string>
     */
    private function unicodeChars(string $value): array
    {
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($chars)) {
            return [$value];
        }

        return array_values(array_map(static fn($c): string => is_string($c) ? $c : '', $chars));
    }

    private function codepointToUtf8(int $codepoint): string
    {
        if ($codepoint <= 0x7F) {
            return chr($codepoint);
        }
        if ($codepoint <= 0x7FF) {
            return chr(0xC0 | ($codepoint >> 6))
                . chr(0x80 | ($codepoint & 0x3F));
        }
        if ($codepoint <= 0xFFFF) {
            return chr(0xE0 | ($codepoint >> 12))
                . chr(0x80 | (($codepoint >> 6) & 0x3F))
                . chr(0x80 | ($codepoint & 0x3F));
        }

        return chr(0xF0 | ($codepoint >> 18))
            . chr(0x80 | (($codepoint >> 12) & 0x3F))
            . chr(0x80 | (($codepoint >> 6) & 0x3F))
            . chr(0x80 | ($codepoint & 0x3F));
    }
}
