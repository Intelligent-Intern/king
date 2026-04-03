<?php

/* Local S3-compatible mock server for object-store cloud and backup contracts. */

$capturePath = (string) ($argv[1] ?? '');
$stateDirectory = (string) ($argv[2] ?? '');
$port = (int) ($argv[3] ?? 0);
$bindHost = (string) ($argv[4] ?? '127.0.0.1');
$options = json_decode((string) ($argv[5] ?? '{}'), true);

if ($capturePath === '' || $stateDirectory === '' || $port <= 0) {
    fwrite(STDERR, "invalid object-store S3 mock arguments\n");
    exit(2);
}

if (!is_array($options)) {
    $options = [];
}

$objectsDirectory = $stateDirectory . '/objects';
$metadataDirectory = $stateDirectory . '/metadata';
$multipartDirectory = $stateDirectory . '/multipart';
$gcsSessionsDirectory = $stateDirectory . '/gcs_sessions';
$azureBlocksDirectory = $stateDirectory . '/azure_blocks';
if (!is_dir($objectsDirectory) && !mkdir($objectsDirectory, 0700, true) && !is_dir($objectsDirectory)) {
    fwrite(STDERR, "failed to create object directory\n");
    exit(2);
}
if (!is_dir($metadataDirectory) && !mkdir($metadataDirectory, 0700, true) && !is_dir($metadataDirectory)) {
    fwrite(STDERR, "failed to create object metadata directory\n");
    exit(2);
}
if (!is_dir($multipartDirectory) && !mkdir($multipartDirectory, 0700, true) && !is_dir($multipartDirectory)) {
    fwrite(STDERR, "failed to create multipart directory\n");
    exit(2);
}
if (!is_dir($gcsSessionsDirectory) && !mkdir($gcsSessionsDirectory, 0700, true) && !is_dir($gcsSessionsDirectory)) {
    fwrite(STDERR, "failed to create GCS session directory\n");
    exit(2);
}
if (!is_dir($azureBlocksDirectory) && !mkdir($azureBlocksDirectory, 0700, true) && !is_dir($azureBlocksDirectory)) {
    fwrite(STDERR, "failed to create Azure block directory\n");
    exit(2);
}

$capture = [
    'events' => [],
    'state_directory' => $stateDirectory,
];

register_shutdown_function(static function () use (&$capture, $capturePath): void {
    file_put_contents($capturePath, json_encode($capture, JSON_PRETTY_PRINT));
});
