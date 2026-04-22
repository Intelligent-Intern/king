# Video-Chat Edge Deployment Decision

Status: aktiv fuer den Production-Deploy-Pfad.

Der Live-Deploy-Pfad nutzt einen King/PHP Edge-Container im
Video-Chat-Demo-Verzeichnis. Er terminiert TLS mit Certbot-Material, leitet
HTTP auf HTTPS um, liefert das gebaute Vue-Frontend statisch aus und routet API,
Lobby-WebSocket (`/ws`) und SFU-WebSocket (`/sfu`) auf interne King-PHP
Backend-Services.

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
- Secret-Handling ohne Demo-Defaults.

Der aktuelle Deploy-Helper setzt diesen Pfad fuer einen einzelnen Hetzner-Host
um. Multi-Node, TURN-NAT-Haertung und horizontale SFU-Skalierung bleiben
separate Production-Ausbaupunkte.

## Gate

Der statische Gate laeuft mit:

```bash
bash demo/video-chat/scripts/check-edge-deployment-decision.sh
```
