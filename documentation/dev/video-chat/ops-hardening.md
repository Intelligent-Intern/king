# Video Chat Ops Hardening

Status: verbindlicher Ops-Baseline-Vertrag fuer Backup, Restore, Metrics, Logs, Alerts, Rollout und Rollback.

Dieses Runbook schliesst Issue #17. Es bleibt ehrlich zur aktuellen Runtime: `docker-compose.v1.yml` ist weiter Single-Node-Dev/Staging, aber Backup/Restore und die Observability-Pipeline sind reproduzierbar verdrahtet und statisch im Smoke abgesichert.

## Backup und Restore

Backup:

```bash
bash demo/video-chat/scripts/backup-sqlite.sh
```

Expliziter Pfad:

```bash
bash demo/video-chat/scripts/backup-sqlite.sh \
  demo/video-chat/backend-king-php/.local/video-chat.sqlite \
  demo/video-chat/.backups
```

Restore:

```bash
bash demo/video-chat/scripts/restore-sqlite.sh \
  demo/video-chat/.backups/video-chat-YYYYMMDDTHHMMSSZ.sqlite \
  demo/video-chat/backend-king-php/.local/video-chat.sqlite
```

Restore-Regeln:

- Restore verlangt `sqlite3`; blindes Kopieren ohne Integritaetspruefung ist kein gueltiger Restore-Drill.
- Falls `*.sha256` neben dem Backup liegt, wird der Checksum-Check vor dem Restore ausgefuehrt.
- `PRAGMA integrity_check` muss fuer Backup und Ziel `ok` liefern.
- Ein existierendes Ziel wird nur mit `VIDEOCHAT_RESTORE_OVERWRITE=1` ersetzt.
- Vor dem Ersetzen wird automatisch ein `*.pre-restore-<timestamp>` Sicherheitsbackup geschrieben.
- Vor Produktiv-Restore muessen Backend-/WS-/SFU-Worker gestoppt oder gedraint sein.

Restore-Drill:

```bash
bash demo/video-chat/scripts/check-ops-hardening.sh
```

Der Drill erstellt eine temporaere SQLite-Datenbank, erzeugt ein Backup, stellt es in eine neue Datei wieder her und prueft Row-Count plus Integritaet. Das ist der Mindestnachweis, dass Backup/Restore nicht nur dokumentiert ist.

## Metrics/Logs/Alerts Pipeline

Maschinenlesbarer Katalog:

- `demo/video-chat/ops/metrics-alerts.catalog.json`

Der Katalog enthaelt:

- alle `K-01` bis `K-15` KPIs aus Issue #9,
- alle `A-01` bis `A-15` Alerts aus Issue #9,
- Pflichtlabels `build`, `env`, `room_size_tier`, `client_device_class`, `codec_stage`,
- Logstreams fuer `backend-http`, `backend-ws`, `backend-sfu`, `frontend-runtime`, `turn-relay`,
- Artefakt-Outputs `load-summary.json`, `alerts-during-load.json`, `metrics.ndjson.gz`.

OTLP-Bindings:

- `VIDEOCHAT_OTEL_EXPORTER_ENDPOINT` aktiviert den zentralen Collector-Pfad.
- `VIDEOCHAT_OTEL_SERVICE_NAME` setzt den Service-Namen pro Backend-Rolle.
- `VIDEOCHAT_OTEL_EXPORTER_PROTOCOL` erlaubt `grpc` oder `http/protobuf`.
- `VIDEOCHAT_OTEL_METRICS_ENABLE=1` aktiviert Metrikexport.
- `VIDEOCHAT_OTEL_LOGS_ENABLE=1` aktiviert Logexport.
- `VIDEOCHAT_OTEL_QUEUE_STATE_PATH` kann pro Worker auf eine lokale Retry-Queue-Datei zeigen.

Compose verdrahtet diese Variablen fuer HTTP-, WS- und SFU-Backend. Ohne Collector-Endpunkt bleibt die lokale Demo still; mit Endpunkt exportieren die King-Telemetry-INI-Werte an den zentralen Collector.

Alerting-Regeln:

- Warning erzeugt Ticket plus On-call-Ping.
- Critical erzeugt Incident-Channel plus Eskalation.
- Alerts feuern nach zwei verletzten Fenstern und recovern nach zwei gesunden Fenstern.
- Release-Kandidaten duerfen nicht promoted werden, wenn `A-01`, `A-02`, `A-03`, `A-04`, `A-13`, `A-14` oder `A-15` im Pflichtlastprofil critical sind.

## Rollout/Rollback Runbook

### Local Compose Dev/Staging

Rollout:

1. `git status --short` pruefen und nicht-zielbezogene lokale Aenderungen sichern.
2. `bash demo/video-chat/scripts/smoke.sh` ausfuehren.
3. Optionales Backup erstellen: `bash demo/video-chat/scripts/backup-sqlite.sh`.
4. Stack aktualisieren: `docker compose -f demo/video-chat/docker-compose.v1.yml up --build -d`.
5. `/health`, `/api/runtime`, Login, Join-Modal und WS/SFU-Verbindung pruefen.
6. Katalog-Dashboard fuer `K-01..K-15` und Alerts `A-01..A-15` beobachten.

Rollback:

1. Neue Container stoppen: `docker compose -f demo/video-chat/docker-compose.v1.yml down`.
2. Letzten bekannten Commit/Build starten.
3. Falls Daten korrumpiert sind: `VIDEOCHAT_RESTORE_OVERWRITE=1 bash demo/video-chat/scripts/restore-sqlite.sh <backup> <db>`.
4. `/health`, Session-Login und Join-Flow pruefen.
5. Incident-Notiz mit Ursache, betroffenen Alerts und Restore-Artefakten ablegen.

### Hardened Single-Node Staging

Rollout:

1. `VIDEOCHAT_KING_ENV=staging` und Secret-Datei-Bindings setzen.
2. `VIDEOCHAT_REQUIRE_SECRET_SOURCES=1` aktivieren.
3. OTLP Collector ueber `VIDEOCHAT_OTEL_EXPORTER_ENDPOINT` setzen.
4. Vor Deploy ein SQLite-Backup plus sha256 erzeugen.
5. Backend HTTP zuerst starten, danach WS und SFU, danach Frontend.
6. Smoke, Login, Access-Link, Lobby-Admit, Chat und Mini-Video pruefen.

Rollback:

1. Frontend-Traffic stoppen oder zur vorherigen Version routen.
2. WS/SFU drainen, dann HTTP stoppen.
3. Vorheriges Image/Commit starten.
4. Falls Schema-/Datenproblem: Restore-Skript mit letztem validen Backup verwenden.
5. Alerts `A-01..A-15` bis Recovery beobachten.

### Future Multi-Node Production

Dieser Pfad ist erst zulaessig, wenn `documentation/dev/video-chat/multi-node-runtime-architecture.md` implementiert ist.

Rollout:

1. Shared SQL, presence TTL Store, inter-node bus und SFU-Topology-Store muessen live sein.
2. Dual-write/read-verify muss vor Read-Switch gruen sein.
3. Canary-Nodes bekommen OTLP-Service-Namen pro Rolle und Node.
4. WebSocket- und SFU-Nodes werden drain-first gerollt.
5. Promotion nur ohne Critical Alerts in Pflichtprofilen.

Rollback:

1. Canary-Traffic stoppen und Sessions reconnecten lassen.
2. Read-Switch zurueck auf letzte stabile Store-Generation.
3. Bus-Publish fuer neue Version deaktivieren.
4. SQLite-/Shared-SQL-Snapshot aus Restore-Artefakt pruefen.
5. Postmortem mit K-/A-Verlauf und betroffenen Nodes abschliessen.

## Smoke Gate

`demo/video-chat/scripts/smoke.sh` ruft `demo/video-chat/scripts/check-ops-hardening.sh` vor dem Compose-Smoke auf. Der Guard prueft:

- Restore-Skript existiert und ist syntaktisch gueltig.
- Restore-Drill ist reproduzierbar.
- Katalog enthaelt exakt `K-01..K-15` und `A-01..A-15`.
- README, Issue und Smoke referenzieren die Ops-Baseline.
