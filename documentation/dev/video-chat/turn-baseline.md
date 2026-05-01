# Video-Chat TURN Baseline

Status: baselinefaehig, aber nicht default-aktiv.

Der aktive Demo-Compose bleibt ohne TURN-Profil STUN-only. TURN wird ueber das
optionale Compose-Profil `turn` bereitgestellt und muss explizit mit einem
secret-managed Shared Secret gestartet werden.

## Komponenten

- TURN service: `videochat-turn-v1` in `demo/video-chat/docker-compose.v1.yml`
- Credential generator: `demo/video-chat/scripts/generate-turn-ice-servers.php`
- Baseline gate: `demo/video-chat/scripts/check-turn-baseline.sh`
- NAT matrix result validator: `demo/video-chat/scripts/turn-nat-matrix-contract.sh`

## Secret-Binding

Der TURN-Service akzeptiert kein eingechecktes Demo-Secret. Das Shared Secret
muss aus einer Runtime-Quelle kommen:

- `VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET`
- oder `VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET_FILE`

`VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET_FILE` ist der bevorzugte Binding-Punkt fuer
Vault-/KMS-Agenten, Docker/Kubernetes Secrets oder CI-Secret-Mounts. Der Compose
Service bricht ab, wenn weder Env noch Secret-Datei gesetzt ist.

## Rotierende WebRTC-Credentials

Die Frontend-ICE-Konfiguration bleibt `VITE_VIDEOCHAT_ICE_SERVERS`. Der Wert
wird mit zeitlich begrenzten TURN-REST-Credentials erzeugt:

```bash
export VIDEOCHAT_TURN_STATIC_AUTH_SECRET_FILE=/run/secrets/videochat-turn-static-auth-secret
export VIDEOCHAT_TURN_URIS='turn:turn.example.com:3478?transport=udp,turn:turn.example.com:3478?transport=tcp'
export VITE_VIDEOCHAT_ICE_SERVERS="$(php demo/video-chat/scripts/generate-turn-ice-servers.php)"
```

Der Generator setzt standardmaessig eine TTL von 3600 Sekunden. Rotation heisst:
bei jeder neuen Ausgabe aendert sich der zeitgebundene Username und damit das
HMAC-SHA1-Credential.

## Compose-Start

```bash
cd demo/video-chat
VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET_FILE=/run/secrets/videochat-turn-static-auth-secret \
VIDEOCHAT_V1_TURN_REALM=videochat.example.com \
VITE_VIDEOCHAT_ICE_SERVERS="$(cd ../.. && php demo/video-chat/scripts/generate-turn-ice-servers.php)" \
docker compose --profile turn -f docker-compose.v1.yml up --build
```

Das `turn`-Profil oeffnet standardmaessig:

- `3478/tcp`
- `3478/udp`
- UDP relay range `49160-49200`

## NAT-Matrix

Die echte NAT-Matrix ist absichtlich opt-in, weil sie reale Netzumgebungen
braucht und nicht in einem lokalen Repo-Smoke simuliert werden kann.

Pflichtszenarien fuer ein Release:

- `mobile-lte`
- `restrictive-nat`
- `udp-blocked-turn-tcp`
- `corporate-firewall`

Die Ergebnisdatei wird mit
`demo/video-chat/scripts/turn-nat-matrix-contract.sh` validiert. Der Contract
erwartet pro Szenario einen `pass`-Status und Evidence-Felder fuer Browser,
Netz, TURN-Transport und Candidate-Pair.
