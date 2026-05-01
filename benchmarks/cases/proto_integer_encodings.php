<?php

declare(strict_types=1);

function king_benchmark_proto_varint_case(): array
{
    return [
        'description' => 'schema-defined uint64 varint encode/decode boundary mix',
        'default_iterations' => 500000,
        'operations_per_iteration' => 2,
        'bootstrap' => static function (): array {
            $schemaName = 'BenchVarint_' . getmypid() . '_' . bin2hex(random_bytes(4));

            if (!king_proto_define_schema($schemaName, [
                'value' => ['tag' => 1, 'type' => 'uint64', 'required' => true],
            ])) {
                throw new RuntimeException('king_proto_define_schema() failed during varint benchmark setup.');
            }

            $values = king_benchmark_integer_boundary_values();
            $valueCount = count($values);

            return [
                'run' => static function (int $iteration) use ($schemaName, $values, $valueCount): int {
                    $value = $values[$iteration % $valueCount];
                    $encoded = king_proto_encode($schemaName, ['value' => $value]);
                    $decoded = king_proto_decode($schemaName, $encoded);

                    if (!is_array($decoded) || ($decoded['value'] ?? null) !== $value) {
                        throw new RuntimeException('Proto varint benchmark roundtrip verification failed.');
                    }

                    return strlen($encoded) + $value;
                },
            ];
        },
    ];
}

function king_benchmark_proto_omega_case(): array
{
    return [
        'description' => 'PHP Elias omega encode/decode over the same integer boundary mix',
        'default_iterations' => 500000,
        'operations_per_iteration' => 2,
        'bootstrap' => static function (): array {
            $values = king_benchmark_integer_boundary_values();
            $valueCount = count($values);

            return [
                'run' => static function (int $iteration) use ($values, $valueCount): int {
                    $value = $values[$iteration % $valueCount] + 1;
                    $encoded = king_benchmark_elias_omega_encode($value);
                    $decoded = king_benchmark_elias_omega_decode($encoded);

                    if ($decoded !== $value) {
                        throw new RuntimeException('Elias omega benchmark roundtrip verification failed.');
                    }

                    return strlen($encoded) + $decoded;
                },
            ];
        },
    ];
}

function king_benchmark_integer_boundary_values(): array
{
    return [
        0,
        1,
        2,
        3,
        15,
        16,
        31,
        32,
        63,
        64,
        127,
        128,
        255,
        256,
        16383,
        16384,
        2097151,
        2097152,
        268435455,
        268435456,
        2147483647,
    ];
}

function king_benchmark_elias_omega_encode(int $value): string
{
    if ($value < 1) {
        throw new InvalidArgumentException('Elias omega encoding requires a positive integer.');
    }

    $bits = '0';
    while ($value > 1) {
        $binary = decbin($value);
        $bits = $binary . $bits;
        $value = strlen($binary) - 1;
    }

    return king_benchmark_pack_bits($bits);
}

function king_benchmark_elias_omega_decode(string $encoded): int
{
    $bits = king_benchmark_unpack_bits($encoded);
    $cursor = 0;
    $value = 1;

    while (($bits[$cursor] ?? '0') !== '0') {
        $width = $value + 1;
        $chunk = substr($bits, $cursor, $width);

        if (strlen($chunk) !== $width) {
            throw new RuntimeException('Truncated Elias omega payload.');
        }

        $value = bindec($chunk);
        $cursor += $width;
    }

    return $value;
}

function king_benchmark_pack_bits(string $bits): string
{
    $padding = (8 - (strlen($bits) % 8)) % 8;
    if ($padding > 0) {
        $bits .= str_repeat('0', $padding);
    }

    $packed = '';
    for ($offset = 0, $length = strlen($bits); $offset < $length; $offset += 8) {
        $packed .= chr(bindec(substr($bits, $offset, 8)));
    }

    return $packed;
}

function king_benchmark_unpack_bits(string $bytes): string
{
    $bits = '';

    for ($i = 0, $length = strlen($bytes); $i < $length; $i++) {
        $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
    }

    return $bits;
}
