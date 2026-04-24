<?php
declare(strict_types=1);

namespace King\Voltron\Distributed;

require_once __DIR__ . '/../GgufTensorLoader.php';
require_once __DIR__ . '/../VoltronTokenizer.php';

class LayerWorker
{
    private int $layerStart;
    private int $layerEnd;
    private string $ggufPath;
    private $loader;
    private int $hiddenDim;
    private int $headCount;
    private int $headDim;
    private float $ropeFreqBase;
    private float $rmsEps;

    public function __construct(
        string $ggufPath,
        int $layerStart = 0,
        int $layerEnd = 11
    ) {
        $this->ggufPath = $ggufPath;
        $this->layerStart = $layerStart;
        $this->layerEnd = $layerEnd;
        
        $this->loader = \King\Voltron\GgufTensorLoader::fromParams(['gguf_path' => $ggufPath]);
        $this->hiddenDim = $this->loader->ne0('token_embd.weight');
        $this->headCount = (int) ($this->loader->metadata('llama.attention.head_count', 16));
        $this->headDim = (int) ($this->loader->metadata('llama.attention.head_dim', 128));
        $this->ropeFreqBase = (float) ($this->loader->metadata('llama.attention.rope_freq_base', 10000.0));
        $this->rmsEps = (float) ($this->loader->metadata('llama.attention.layer_norm_rms_epsilon', 1e-5));
    }

    public function forward(array $hidden, int $position): array
    {
        for ($layer = $this->layerStart; $layer <= $this->layerEnd; $layer++) {
            $hidden = $this->forwardLayer($hidden, $layer, $position);
        }
        
        return [
            'hidden' => $hidden,
            'layers_processed' => $this->layerEnd - $this->layerStart + 1,
        ];
    }

    private function forwardLayer(array $hidden, int $layer, int $position): array
    {
        $prefixes = ["blk.{$layer}.", "layers.{$layer}.", "model.layers.{$layer}."];
        
        $normTensor = $this->resolveTensor($prefixes, ['attn_norm.weight', 'attention_norm.weight']);
        $qTensor = $this->resolveTensor($prefixes, ['attn_q.weight', 'self_attn.q_proj.weight']);
        $kTensor = $this->resolveTensor($prefixes, ['attn_k.weight', 'self_attn.k_proj.weight']);
        $vTensor = $this->resolveTensor($prefixes, ['attn_v.weight', 'self_attn.v_proj.weight']);
        $oTensor = $this->resolveTensor($prefixes, ['attn_output.weight', 'self_attn.o_proj.weight']);
        
        $norm = $this->loader->readRow($normTensor, 0);
        $x = $this->rmsNorm($hidden, $norm);
        
        $q = $this->project($qTensor, $x);
        $k = $this->project($kTensor, $x);
        $v = $this->project($vTensor, $x);
        
        $qHeads = $this->splitHeads($q);
        $kHeads = $this->splitHeads($k);
        $vHeads = $this->splitHeads($v);
        
        foreach ($qHeads as &$qHead) { $this->applyRope($qHead, $position); }
        foreach ($kHeads as &$kHead) { $this->applyRope($kHead, $position); }
        
        $ctx = $this->attention($qHeads, $kHeads, $vHeads);
        
        $proj = $this->project($oTensor, $ctx);
        $hidden = $this->vecAdd($hidden, $proj);
        
        $gateTensor = $this->resolveTensor($prefixes, ['ffn_gate.weight', 'mlp.gate_proj.weight']);
        $upTensor = $this->resolveTensor($prefixes, ['ffn_up.weight', 'mlp.up_proj.weight']);
        $downTensor = $this->resolveTensor($prefixes, ['ffn_down.weight', 'mlp.down_proj.weight']);
        
        $ffnNormTensor = $this->resolveTensor($prefixes, ['ffn_norm.weight', 'post_attention_layernorm.weight']);
        $ffnNorm = $this->loader->readRow($ffnNormTensor, 0);
        $x = $this->rmsNorm($hidden, $ffnNorm);
        
        $gate = $this->project($gateTensor, $x);
        $up = $this->project($upTensor, $x);
        
        $act = [];
        for ($i = 0; $i < count($gate); $i++) {
            $g = $gate[$i];
            $act[] = $this->silu($g) * $up[$i];
        }
        
        $down = $this->project($downTensor, $act);
        $hidden = $this->vecAdd($hidden, $down);
        
        return $hidden;
    }

    private function rmsNorm(array $x, array $weights): array
    {
        $n = max(1, count($x));
        $mean = 0.0;
        foreach ($x as $v) { $mean += $v * $v; }
        $mean /= $n;
        $inv = 1.0 / sqrt($mean + $this->rmsEps);
        
        $out = [];
        foreach ($x as $i => $v) {
            $w = $weights[$i] ?? 1.0;
            $out[] = $v * $inv * $w;
        }
        return $out;
    }

    private function project(string $tensor, array $input): array
    {
        if (!function_exists('king_native_gguf_tensor_scan')) {
            return $this->projectPhp($tensor, $input);
        }
        
        $tensorMeta = $this->loader->nativeTensorMeta($tensor);
        $result = king_native_gguf_tensor_scan($this->ggufPath, $tensorMeta, $input, []);
        
        return is_array($result) ? $result : $this->projectPhp($tensor, $input);
    }

    private function projectPhp(string $tensor, array $input): array
    {
        $rows = $this->loader->rowCount($tensor);
        $out = [];
        for ($row = 0; $row < $rows; $row++) {
            $weights = $this->loader->readRow($tensor, $row);
            $sum = 0.0;
            for ($i = 0; $i < count($input) && $i < count($weights); $i++) {
                $sum += $weights[$i] * $input[$i];
            }
            $out[] = $sum;
        }
        return $out;
    }

    private function splitHeads(array $values): array
    {
        $heads = [];
        $headSize = $this->headDim;
        $count = count($values);
        
        for ($h = 0; $h < $this->headCount; $h++) {
            $head = [];
            for ($d = 0; $d < $headSize && ($h * $headSize + $d) < $count; $d++) {
                $head[] = $values[$h * $headSize + $d];
            }
            $heads[] = $head;
        }
        return $heads;
    }

    private function applyRope(array &$head, int $position): void
    {
        $dim = count($head);
        if ($dim < 2) return;
        
        $freq = $this->ropeFreqBase;
        $invDim = 1.0 / $dim;
        
        for ($i = 0; $i < $dim; $i += 2) {
            $theta = pow($freq, -($i * $invDim)) * $position;
            $cos = cos($theta);
            $sin = sin($theta);
            
            $x1 = $head[$i] ?? 0;
            $x2 = $head[$i + 1] ?? 0;
            
            $head[$i] = $x1 * $cos - $x2 * $sin;
            $head[$i + 1] = $x1 * $sin + $x2 * $cos;
        }
    }

    private function attention(array $qHeads, array $kHeads, array $vHeads): array
    {
        $scale = 1.0 / sqrt($this->headDim);
        $ctx = [];
        
        foreach ($qHeads as $headIdx => $qHead) {
            $scores = [];
            foreach ($kHeads as $kHead) {
                $scores[] = $this->dot($qHead, $kHead) * $scale;
            }
            
            $weights = $this->softmax($scores);
            
            $headCtx = array_fill(0, $this->headDim, 0.0);
            foreach ($weights as $idx => $weight) {
                $vHead = $vHeads[$idx] ?? [];
                for ($i = 0; $i < count($headCtx) && $i < count($vHead); $i++) {
                    $headCtx[$i] += $weight * $vHead[$i];
                }
            }
            
            foreach ($headCtx as $v) { $ctx[] = $v; }
        }
        
        return $ctx;
    }

    private function silu(float $x): float
    {
        return $x / (1.0 + exp(-$x));
    }

    private function vecAdd(array $a, array $b): array
    {
        $out = [];
        $n = max(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $out[] = ($a[$i] ?? 0.0) + ($b[$i] ?? 0.0);
        }
        return $out;
    }

    private function dot(array $a, array $b): float
    {
        $sum = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $sum += $a[$i] * $b[$i];
        }
        return $sum;
    }

    private function softmax(array $x): array
    {
        $max = max($x);
        $exp = [];
        $sum = 0.0;
        foreach ($x as $v) {
            $e = exp($v - $max);
            $exp[] = $e;
            $sum += $e;
        }
        
        foreach ($exp as &$v) { $v /= $sum; }
        return $exp;
    }

    private function resolveTensor(array $prefixes, array $candidates): string
    {
        foreach ($prefixes as $prefix) {
            foreach ($candidates as $candidate) {
                $name = $prefix . $candidate;
                if ($this->loader->tensor($name) !== null) {
                    return $name;
                }
            }
        }
        
        foreach ($candidates as $candidate) {
            if ($this->loader->tensor($candidate) !== null) {
                return $candidate;
            }
        }
        
        throw new \RuntimeException("Cannot resolve tensor from: " . implode(', ', $candidates));
    }

    public function embed(int $tokenId): array
    {
        return $this->loader->readRow('token_embd.weight', $tokenId);
    }
}