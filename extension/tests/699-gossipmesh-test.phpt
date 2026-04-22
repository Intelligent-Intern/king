--TEST--
GossipMesh basic functionality test
--FILE--
<?php
require_once __DIR__ . '/../src/gossip_mesh/gossip_mesh.php';

$received = [];
$connected = [];
$disconnected = [];

$mesh = new GossipMesh(
    function($publisherId,$sequence,$data) use (&$received) {
        $received[] = [$publisherId,$sequence,$data];
    },
    function($peerId) use (&$connected) { $connected[] = $peerId; },
    function($peerId) use (&$disconnected) { $disconnected[] = $peerId; }
);

$mesh->addNeighbor(1,'127.0.0.1',1234);
$mesh->addNeighbor(2,'127.0.0.2',1235);

$shouldForward = $mesh->receiveFrame(42,1,2,'payload');

$forwards = $mesh->computeForwards(42,1);
foreach ($forwards as $peerId) {
    $mesh->markForwarded(42,1,$peerId);
}

$stats = $mesh->getStats();

echo "ShouldForward: ".($shouldForward?'true':'false')."\n";
echo "ReceivedFrames: ".count($received)."\n";
echo "ForwardedCount: ".$stats['frames_forwarded']."\n";
echo "Neighbors: ".$stats['neighbor_count']."\n";
echo "Forwards: ".implode(',', $forwards)."\n";
--EXPECTF--
ShouldForward: true
ReceivedFrames: 1
ForwardedCount: %d
Neighbors: 2
Forwards: %s
