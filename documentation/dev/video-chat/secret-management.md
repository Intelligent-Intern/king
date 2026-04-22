# Video-Chat Secret Management

Status: aktiv fuer Secret-/Config-Hardening.

Diese Demo darf lokal mit Demo-Credentials laufen. Sobald der Backend-Start als
hardened Deployment markiert wird, muss er fail-closed starten: keine
Demo-Passwoerter, keine Demo-Seed-Calls und keine schwachen TURN-Secrets.

## Hardened Start

Hardened Mode ist aktiv, wenn eine der Bedingungen zutrifft:

- `VIDEOCHAT_KING_ENV=production`
- `VIDEOCHAT_KING_ENV=staging`
- `VIDEOCHAT_REQUIRE_SECRET_SOURCES=1`

Dann prueft `backend-king-php/support/config_hardening.php`:

- `VIDEOCHAT_DEMO_ADMIN_PASSWORD` oder `VIDEOCHAT_DEMO_ADMIN_PASSWORD_FILE` ist gesetzt,
- `VIDEOCHAT_DEMO_USER_PASSWORD` oder `VIDEOCHAT_DEMO_USER_PASSWORD_FILE` ist gesetzt,
- beide Passwoerter sind mindestens 12 Zeichen lang,
- beide Passwoerter sind verschieden,
- bekannte Demo-Werte wie `admin123` und `user123` sind verboten,
- `VIDEOCHAT_DEMO_SEED_CALLS` ist `0`, `false`, `off` oder `no`,
- falls `VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET` genutzt wird, ist es kein
  Demo-Wert und mindestens 16 Zeichen lang.

Der aktive Server bricht vor SQLite-Bootstrap ab, wenn diese Pruefung fehlschlaegt.

## Secret Sources

Zulaessige Quellen fuer Deployments:

- Vault-/KMS-Agent schreibt eine Datei und setzt `*_FILE`,
- Docker/Kubernetes Secret wird als Datei gemountet und per `*_FILE` referenziert,
- CI-Secret wird als Runtime-Environment gesetzt.

Aktive Secret-Bindings:

- `VIDEOCHAT_DEMO_ADMIN_PASSWORD` oder `VIDEOCHAT_DEMO_ADMIN_PASSWORD_FILE`
- `VIDEOCHAT_DEMO_USER_PASSWORD` oder `VIDEOCHAT_DEMO_USER_PASSWORD_FILE`
- `VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET` oder `VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET_FILE`
- `VIDEOCHAT_TURN_STATIC_AUTH_SECRET` oder `VIDEOCHAT_TURN_STATIC_AUTH_SECRET_FILE`
  fuer `scripts/generate-turn-ice-servers.php`

`*_FILE` ist fuer Server-/Container-Deployments bevorzugt, weil Secrets dann
nicht in Prozesslisten, Shell-History oder Compose-Interpolationslogs landen.

## Session/JWT Status

Der aktive Video-Chat-Backendpfad nutzt keine JWT-Signing-Secrets. Sessions sind
serverseitig persistierte, zufaellige Session-IDs (`sess_...`) in SQLite. Wenn
spaeter JWTs eingefuehrt werden, muessen Signing Keys ueber Vault/KMS/CI-Secret
gebunden werden und Dual-Key-Rotation dokumentiert sein, bevor der Deploy-Pfad
aktiviert wird.

Der Gateway-JWT-Vertrag ist trotzdem als inaktiver Backend-Contract fixiert:
`backend-king-php/tests/gateway-jwt-binding-contract.sh` prueft HS256,
`sub/effective_id`-Bindung an `peer_id`, `room/call_id`-Bindung an `room_id`,
Secret-Policy, Token-Laengenlimit und Join-Rate-Limit. Der Contract macht keinen
JWT-Pfad aktiv, sondern verhindert schwache Semantik bei einer spaeteren
Gateway-Integration.

## Rotation Runbook

Admin/User Demo Credentials:

1. Neues Secret in Vault/KMS/CI anlegen.
2. `VIDEOCHAT_DEMO_ADMIN_PASSWORD_FILE` und/oder `VIDEOCHAT_DEMO_USER_PASSWORD_FILE`
   auf die neue Secret-Datei zeigen lassen.
3. Backend neu starten. Der SQLite-Bootstrap aktualisiert vorhandene Demo-User
   auf die neuen Hashes.
4. Aktive Sessions revoken:
   `UPDATE sessions SET revoked_at = datetime('now') WHERE revoked_at IS NULL OR revoked_at = '';`

Session Tokens:

1. Reguläre Rotation laeuft ueber `POST /api/auth/refresh`; alte Session wird
   atomar revoked und WebSocket-Verbindungen der alten Session werden geschlossen.
2. Emergency-Revoke: obiges SQL gegen `sessions.revoked_at` ausfuehren.
3. Danach Backend/WebSocket-Clients reconnecten lassen; revoked Sessions werden
   fail-closed abgewiesen.

TURN Credentials:

1. Neues `VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET_FILE` bereitstellen.
2. Coturn mit dem neuen Secret rollen.
3. `VITE_VIDEOCHAT_ICE_SERVERS` mit
   `php demo/video-chat/scripts/generate-turn-ice-servers.php` neu erzeugen.
4. Frontend neu deployen/starten.
5. Alte TURN-Credentials erst nach Ablauf von `VIDEOCHAT_TURN_TTL_SECONDS`
   entfernen.

Future Edge Deploy:

1. Kein `demo/video-chat/deploy/` reaktivieren.
2. Neues Issue fuer den Deploy-Pfad anlegen.
3. Deploy-Skript muss vor Start `demo/video-chat/scripts/check-secret-management.sh`
   ausfuehren und Secret-Quellen explizit validieren.

## Gate

```bash
bash demo/video-chat/scripts/check-secret-management.sh
```
