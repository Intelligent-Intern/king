<?php
declare(strict_types=1);

namespace King\Voltron;

final class VoltronExecutionMode
{
    public const PARITY_FULL_LLAMA_CPP = 'parity_full_llama_cpp';

    public static function current(): string
    {
        return getenv('VOLTRON_EXECUTION_MODE') ?: self::PARITY_FULL_LLAMA_CPP;
    }

    public static function isParityMode(): bool
    {
        return self::current() === self::PARITY_FULL_LLAMA_CPP;
    }
}