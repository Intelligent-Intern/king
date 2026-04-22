# Video-Chat Edge Deployment Decision

Status: aktiv fuer den Production-Deploy-Pfad.

Der Live-Deploy-Pfad nutzt einen King/PHP Edge-Container im
Video-Chat-Demo-Verzeichnis. Er terminiert TLS mit Certbot-Material, leitet
HTTP auf HTTPS um, liefert das gebaute Vue-Frontend statisch aus und routet API,
Lobby-WebSocket (`/ws`) und SFU-WebSocket (`/sfu`) auf interne King-PHP
Backend-Services. Statische CDN-Assets werden ueber denselben King/PHP Edge auf
`VIDEOCHAT_DEPLOY_CDN_DOMAIN` ausgeliefert; Default ist `cnd.<domain>`.

## Aktiver Scope

Der aktive Scope ist:

- `demo/video-chat/frontend-vue`
- `demo/video-chat/backend-king-php`
- `demo/video-chat/edge`
- `demo/video-chat/docker-compose.v1.yml`
- `demo/video-chat/scripts/deploy.sh`

Die Compose-Datei startet die Single-Node-Demo mit getrennten King-PHP
Backend-Services fuer HTTP, `/ws` und `/sfu`. Im `edge`-Profil startet sie
zusaetzlich `videochat-edge-v1` als einzigen oeffentlichen Entry Point auf
`:80` und `:443`. Die Backend-Ports werden im Production-Deploy nur auf
`127.0.0.1` gebunden.

Der Edge-Proxy laeuft bewusst mit Stall-Guards fuer Reads und Writes. Wenn ein
non-blocking Socket von `stream_select()` als bereit gemeldet wird, danach aber
wiederholt keine Bytes liefert und noch nicht als EOF markiert ist, greift
`VIDEOCHAT_EDGE_READ_STALL_TIMEOUT_SECONDS` mit kurzem Backoff. Das verhindert
runaway `php /app/edge.php` Worker bei halboffenen Browser-, WS- oder SFU-
Verbindungen.

## Nicht Teil des aktiven Demo-Pfads

Folgende externen Edge-Stacks bleiben verboten:

- `demo/video-chat/deploy/`
- `demo/video-chat/docker-compose.edge.yml`
- `demo/video-chat/nginx*`
- `demo/video-chat/caddy*`
- `demo/video-chat/traefik*`
- `demo/video-chat/haproxy*`

Der Production-Deploy-Pfad muss diese Punkte abdecken:

- TLS-Zertifikatsquelle und Rotation,
- WebSocket-Upgrade-Routing fuer `/ws` und `/sfu`,
- explizite Backend-Origin- und Port-Matrix,
- Healthchecks fuer HTTP, WS und SFU,
- Rollback-/Rollback-Test fuer den Edge-Pfad,
- statische CDN-Asset-Auslieferung inklusive korrektem WASM-MIME und CORS,
- Secret-Handling ohne Demo-Defaults.

Der aktuelle Deploy-Helper setzt diesen Pfad fuer einen einzelnen Hetzner-Host
um. Multi-Node, TURN-NAT-Haertung und horizontale SFU-Skalierung bleiben
separate Production-Ausbaupunkte.

## Gate

Der statische Gate laeuft mit:

```bash
bash demo/video-chat/scripts/check-edge-deployment-decision.sh
```
