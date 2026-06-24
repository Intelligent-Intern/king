# King Primitives

Diese Doku zeigt die oeffentlichen King-Primitives praktisch und ohne
Release-Sprache. Jede Primitive hat denselben Aufbau:

- Function, Beispiel 1: kleines Beispiel
- Function, Beispiel 2: ausfuehrliches Beispiel
- OO, Beispiel 1: kleines Beispiel
- OO, Beispiel 2: ausfuehrliches Beispiel

Wenn King fuer eine Primitive noch keine native OO-Klasse exportiert, steht das
in der jeweiligen Datei klar dabei. Die OO-Beispiele sind dann kleine
userland Adapter um die echten `king_*` Funktionen.

## Inhaltsverzeichnis

- [Async und Awaitables](async-awaitables.md)
- [Config, Session, TLS und QUIC](config-session-tls-quic.md)
- [HTTP/1](http1.md)
- [HTTP/2](http2.md)
- [HTTP/3](http3.md)
- [WebSocket](websocket.md)
- [Pipeline Orchestrator](pipeline-orchestrator.md)
- [XSLT 2.0/3.0 fuer E-Rechnungen](xslt.md)
- [MCP](mcp.md)
- [IIBIN](iibin.md)
- [Object Store](object-store.md)
- [CDN](cdn.md)
- [Semantic DNS](semantic-dns.md)
- [Autoscaling](autoscaling.md)
- [Telemetry](telemetry.md)
- [System Runtime](system-runtime.md)
- [RTP](rtp.md)
- [DB Ingest](db-ingest.md)

## Grundregeln

- Procedural APIs heissen `king_*`.
- Native OO-APIs liegen unter `King\...`, `King\Client\...` oder
  `King\WebSocket\...`.
- Async-Methoden geben `King\Awaitable` zurueck und werden mit
  `king_await()` oder `$awaitable->await()` aufgeloest.
- Fehler sollten ueber Exceptions behandelt werden. `king_get_last_error()`
  ist nur fuer alte Integrationspunkte gedacht.
- Konfigurationen laufen entweder ueber `King\Config` oder, bei aelteren
  Funktionen, ueber ein Array bzw. `king_new_config()`.
