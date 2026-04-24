<?php
// Quick test client for layer server
$host = $argv[1] ?? '127.0.0.1';
$port = (int) ($argv[2] ?? 9533);
$action = $argv[3] ?? 'health';
$tokenId = (int) ($argv[4] ?? 17);

$request = match($action) {
    'embed' => json_encode(['action' => 'embed', 'token_id' => $tokenId]),
    default => json_encode(['action' => 'health']),
};

$fp = fsockopen($host, $port, $errno, $errstr, 5);
if (!$fp) {
    die("Cannot connect: $errstr ($errno)\n");
}

$len = strlen($request);
fwrite($fp, pack('N', $len));
fwrite($fp, $request);

// Read response
$header = fread($fp, 4);
if ($header && strlen($header) === 4) {
    $respLen = unpack('N', $header)[1];
    if ($respLen > 0 && $respLen < 1000000) {
        $response = fread($fp, $respLen);
        $data = json_decode($response, true);
        echo json_encode($data, JSON_PRETTY_PRINT) . "\n";
    }
} else {
    echo "No response or invalid header\n";
}

fclose($fp);