<?php

declare(strict_types=1);

function king_benchmark_proto_batch_case(): array
{
    return [
        'description' => 'schema-defined batch encode/decode over a fixed record set',
        'default_iterations' => 100000,
        'operations_per_iteration' => 64,
        'bootstrap' => static function (): array {
            $schemaName = 'BenchBatchUser_' . getmypid() . '_' . bin2hex(random_bytes(4));

            if (!king_proto_define_schema($schemaName, [
                'id' => ['tag' => 1, 'type' => 'uint32', 'required' => true],
                'name' => ['tag' => 2, 'type' => 'string', 'required' => true],
                'enabled' => ['tag' => 3, 'type' => 'bool'],
            ])) {
                throw new RuntimeException('king_proto_define_schema() failed during batch benchmark setup.');
            }

            $records = king_benchmark_proto_batch_records(32);
            $recordCount = count($records);

            return [
                'run' => static function (int $iteration) use ($schemaName, $records, $recordCount): int {
                    $encodedRecords = king_proto_encode_batch($schemaName, $records);
                    $decodedRecords = king_proto_decode_batch($schemaName, $encodedRecords);

                    if (count($encodedRecords) !== $recordCount || count($decodedRecords) !== $recordCount) {
                        throw new RuntimeException('Proto batch benchmark returned an unexpected record count.');
                    }

                    $selectedIndex = $iteration % $recordCount;
                    $decoded = $decodedRecords[$selectedIndex] ?? null;
                    if (!is_array($decoded) || ($decoded['id'] ?? null) !== ($selectedIndex + 1)) {
                        throw new RuntimeException('Proto batch benchmark roundtrip verification failed.');
                    }

                    return strlen($encodedRecords[$selectedIndex])
                        + (int) ($decoded['id'] ?? 0)
                        + (int) (!empty($decoded['enabled']));
                },
            ];
        },
    ];
}

function king_benchmark_proto_batch_records(int $count): array
{
    $records = [];

    for ($i = 0; $i < $count; $i++) {
        $records[] = [
            'id' => $i + 1,
            'name' => 'batch-user-' . ($i + 1),
            'enabled' => ($i % 2) === 0,
        ];
    }

    return $records;
}
