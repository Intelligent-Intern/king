# RTP

RTP ist procedural ueber `king_rtp_*` verfuegbar. Eine native OO-Klasse gibt es
aktuell nicht, die OO-Beispiele sind userland Adapter.

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

## OO, Beispiel 1: RtpSocket Adapter

```php
<?php
final class RtpSocket
{
    private mixed $socket;

    public function __construct(string $host, int $port)
    {
        $this->socket = king_rtp_bind($host, $port);
        if ($this->socket === false) {
            throw new RuntimeException(king_get_last_error());
        }
    }

    public function iceCredentials(): array
    {
        return king_rtp_ice_credentials($this->socket);
    }

    public function send(string $host, int $port, string $frame): void
    {
        if (king_rtp_send($this->socket, $host, $port, $frame) !== true) {
            throw new RuntimeException('RTP send failed');
        }
    }

    public function receive(int $timeoutMs): array|false
    {
        return king_rtp_recv($this->socket, $timeoutMs);
    }

    public function close(): void
    {
        king_rtp_close($this->socket);
    }
}

$rtp = new RtpSocket('127.0.0.1', 5004);
var_dump($rtp->iceCredentials());
$rtp->close();
```

## OO, Beispiel 2: Media Peer Adapter

```php
<?php
final class MediaPeer
{
    public function __construct(private RtpSocket $rtp) {}

    public function sendAudioFrame(string $host, int $port, string $frame): void
    {
        $this->rtp->send($host, $port, $frame);
    }
}
```
