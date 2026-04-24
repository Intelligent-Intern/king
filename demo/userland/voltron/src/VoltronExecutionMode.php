<?php
declare(strict_types=1);

namespace King\Voltron;

final class VoltronExecutionMode
{
    public const PARITY_FULL_LLAMA_CPP = 'parity_full_llama_cpp';
    public const EXPERIMENTAL_LAYER_SHARDED = 'experimental_layer_sharded';

    public static function current(): string
    {
        return (string) (
            getenv('VOLTRON_EXECUTION_MODE')
            ?: self::PARITY_FULL_LLAMA_CPP
        );
    }

    public static function requiresSingleFullWorker(): bool
    {
        return self::current() === self::PARITY_FULL_LLAMA_CPP;
    }

    public static function isLayerSharded(): bool
    {
        return self::current() === self::EXPERIMENTAL_LAYER_SHARDED;
    }
}