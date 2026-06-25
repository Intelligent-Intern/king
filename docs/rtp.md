# RTP

RTP is available procedurally through `king_rtp_*`. The native OO surface is
`King\RTP\Socket` and keeps the same native RTP resource internally.

## Internal Layout

The native RTP socket and object contracts live in `extension/include/rtp.h`.
Runtime code lives under `extension/src/media/`. The media module owns its PHP
arginfo, function-table entries, and RTP object binding, and is included
directly by the extension bootstrap.

## Function, Example 1: Bind Socket and Read ICE/DTLS Data

```php
<?php
$socket = king_rtp_bind('127.0.0.1', 5004);
if ($socket === false) {
    throw new RuntimeException(king_get_last_error());
}

$ice = king_rtp_ice_credentials($socket);
$fingerprint = king_rtp_dtls_fingerprint($socket);

var_dump($ice, $fingerprint);
king_rtp_close($socket);
```

## Function, Example 2: Accept DTLS and Send an RTP Packet

```php
<?php
$socket = king_rtp_bind('0.0.0.0', 5004);

$accepted = king_rtp_dtls_accept($socket, '192.0.2.10', 5004, 3000);
if ($accepted !== true) {
    throw new RuntimeException('DTLS accept failed');
}

king_rtp_send($socket, '192.0.2.10', 5004, random_bytes(160));
$packet = king_rtp_recv($socket, 1000);

var_dump($packet);
king_rtp_close($socket);
```

## OO, Example 1: Native King\RTP\Socket Class

```php
<?php
use King\RTP\Socket;

$rtp = new Socket('127.0.0.1', 5004);
var_dump($rtp->iceCredentials());
echo $rtp->dtlsFingerprint() . PHP_EOL;
$rtp->close();
```

## OO, Example 2: Media Peer Service with Native Socket Class

```php
<?php
use King\RTP\Socket;

final class MediaPeer
{
    public function __construct(private Socket $rtp) {}

    public function acceptPeer(string $ip, int $port): void
    {
        if (!$this->rtp->acceptDtls($ip, $port, 3000)) {
            throw new RuntimeException(king_get_last_error());
        }
    }

    public function sendAudioFrame(string $host, int $port, string $frame): void
    {
        if (!$this->rtp->send($host, $port, $frame)) {
            throw new RuntimeException('RTP send failed');
        }
    }

    public function receiveAudioFrame(): ?array
    {
        $packet = $this->rtp->receive(1000);
        return $packet === false ? null : $packet;
    }
}

$peer = new MediaPeer(new Socket('0.0.0.0', 5004));
$peer->sendAudioFrame('192.0.2.10', 5004, random_bytes(160));
```
