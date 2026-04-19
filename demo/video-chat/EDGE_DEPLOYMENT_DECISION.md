# Video-Chat Edge Deployment Decision

Status: verworfen fuer den aktiven Demo-Pfad.

Issue #13 hatte urspruenglich das Ziel, eine interne Edge-Baseline fuer TLS
und WebSocket-Routing (`/ws` und `/sfu`) direkt im Video-Chat-Demo-Verzeichnis
mitzuliefern. Diese Richtung bleibt bewusst verworfen: der aktive Demo-Scope
enthaelt kein internes Edge-Deploy-Pack.

## Aktiver Scope

Der aktive Scope bleibt:

- `demo/video-chat/frontend-vue`
- `demo/video-chat/backend-king-php`
- `demo/video-chat/docker-compose.v1.yml`

Die Compose-Datei startet die aktive Single-Node-Demo mit getrennten King-PHP
Backend-Services fuer HTTP, `/ws` und `/sfu`. Sie ist kein Produktions-Edge und
enthaelt keine TLS-Terminierung.

## Nicht Teil des aktiven Demo-Pfads

Folgende Dateien und Verzeichnisse duerfen nicht als Default-Deploy-Pfad
zurueckkehren:

- `demo/video-chat/deploy/`
- `demo/video-chat/docker-compose.edge.yml`
- `demo/video-chat/nginx*`
- `demo/video-chat/caddy*`
- `demo/video-chat/traefik*`

Eine spaetere produktionsfaehige Edge-Implementierung muss als eigenes Issue
eingefuehrt werden und mindestens diese Punkte abdecken:

- TLS-Zertifikatsquelle und Rotation,
- Reverse-Proxy-Upgrade-Regeln fuer `/ws` und `/sfu`,
- explizite Backend-Origin- und Port-Matrix,
- Healthchecks fuer HTTP, WS und SFU,
- Rollback-/Rollback-Test fuer den Edge-Pfad,
- Secret-Handling ohne Demo-Defaults.

Bis dahin bleibt die Server-Faehigkeit im README ehrlich als
Dev/Staging/Internal-Demo beschrieben, nicht als production-ready Edge-Deploy.

## Gate

Der statische Gate laeuft mit:

```bash
bash demo/video-chat/scripts/check-edge-deployment-decision.sh
```
