# RTP

RTP ist procedural ueber `king_rtp_*` verfuegbar. Die native OO-Oberflaeche
ist `King\RTP\Socket` und haelt intern dieselbe native RTP-Resource.

## Function, Beispiel 1: Socket binden und ICE/DTLS Daten lesen

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

## Function, Beispiel 2: DTLS akzeptieren und RTP Paket senden

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

## OO, Beispiel 1: native King\RTP\Socket Klasse

```php
<?php
use King\RTP\Socket;

$rtp = new Socket('127.0.0.1', 5004);
var_dump($rtp->iceCredentials());
echo $rtp->dtlsFingerprint() . PHP_EOL;
$rtp->close();
```

## OO, Beispiel 2: Media Peer Service mit nativer Socket-Klasse

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
