# Deploy Gate Readiness

Stand: 2026-05-10

Scope: lokaler Audit-Plan fuer den Video-Chat-Deploy. In dieser Aufgabe wurde
kein Deploy, kein DNS-Update und kein Certbot-Lauf ausgefuehrt.

## Kurzbefund

Der Deploy-Pfad ist nur mit getrennten Gates sauber: `deploy.sh deploy` startet
Production Edge/TURN und deaktiviert SFU (`VIDEOCHAT_SFU_ENABLED=0`,
`VIDEOCHAT_EDGE_SFU_ENABLED=0`), waehrend `deploy-smoke.sh` weiterhin SFU
WebSocket-Routing als Smoke erwartet. Deshalb darf SFU aktuell nicht Teil des
harten Domain/Admin/Ops-Deploy-Gates sein. SFU/Media bleibt ein separates
Capability-/Browser-Gate.

Wenn keine neuen Domains hinzukommen, gehoeren DNS-Mutation und Certbot nicht in
den Deploy-Gate-Lauf. Keine `wizard`-, `prepare`-, `certonly`-, DNS-refresh- oder
Default-Remote-Certbot-Smoke-Schritte laufen lassen.

## Pre-Deploy Gate

Vor einem spaeteren Operator-Deploy lokal laufen lassen:

```bash
git branch --show-current
git status --short
bash demo/video-chat/scripts/check-deploy-idempotency.sh
VIDEOCHAT_SMOKE_COMPOSE_ONLY=1 \
VIDEOCHAT_SMOKE_REQUIRE_COMPOSE=1 \
VIDEOCHAT_SMOKE_ARTIFACTS_DIR=compat-artifacts/video-chat-smoke \
bash demo/video-chat/scripts/smoke.sh
```

Video/Diagnostics-spezifisch:

```bash
cd demo/video-chat/frontend-vue
npm run test:vcap:readiness:local
npm run test:predeploy:background
node tests/contract/prod-debug-observability-contract.mjs
```

Nur wenn IAM/Call-Access betroffen ist oder das Release als vollstaendiger
Video-Chat-Release-Kandidat gilt:

```bash
cd demo/video-chat/frontend-vue
npm run test:ci:iam-call-access
```

## No-DNS/No-Certbot Deploy Constraint

Wenn ein Operator spaeter ohne neue Domains deployed, muss der Deploy-Lauf
DNS- und Certbot-Mutationen explizit ausschalten:

```bash
VIDEOCHAT_DEPLOY_HCLOUD_DNS=0 \
VIDEOCHAT_DEPLOY_REFRESH_DNS_ON_PREPARE=0 \
VIDEOCHAT_DEPLOY_SKIP_CERTBOT=1 \
demo/video-chat/scripts/deploy.sh deploy
```

Das ist kein in diesem Audit auszufuehrender Schritt. Vorher muss bestaetigt
sein, dass die bestehenden Zertifikate fuer alle verwendeten Hosts bereits
gueltig sind.

## Post-Deploy Diagnostics Gate

Direkt nach einem spaeteren Deploy zuerst oeffentliche, read-only Diagnostics:

```bash
VIDEOCHAT_DEPLOY_DOMAIN=<root-domain> \
VIDEOCHAT_PROD_DEBUG_SKIP_REMOTE=1 \
demo/video-chat/scripts/prod-debug.sh
```

Wenn SSH-read-only erlaubt ist, danach Remote-Compose-Status und redigierte Logs:

```bash
VIDEOCHAT_DEPLOY_DOMAIN=<root-domain> \
demo/video-chat/scripts/prod-debug.sh
```

Dieser Schritt ist laut Script read-only: kein Deploy, kein Restart, kein DB
Write, kein DNS, keine Admin-Aktion.

`deploy-smoke.sh` nur als Domain/Admin/Ops-Smoke verwenden, wenn die SFU-Erwartung
vorher getrennt oder bewusst gewaived ist. Ohne Certbot-/SAN-Remote-Pruefung:

```bash
VIDEOCHAT_DEPLOY_DOMAIN=<root-domain> \
VIDEOCHAT_DEPLOY_SMOKE_SKIP_REMOTE=1 \
demo/video-chat/scripts/deploy-smoke.sh
```

Fuer den echten Browser-/Media-Beweis, wenn Testdaten im Produkt erlaubt sind:

```bash
VIDEOCHAT_DEPLOY_DOMAIN=<root-domain> \
demo/video-chat/scripts/bgf-production-browser-smoke.sh
```

Dieser Browser-Smoke ist infra-read-only, erzeugt aber normale App-Level
Testdaten ueber die deployed API. Fuer reine Read-only-Diagnose vorher nur den
Dry-Run verwenden:

```bash
VIDEOCHAT_DEPLOY_DOMAIN=<root-domain> \
VIDEOCHAT_PRODUCTION_BROWSER_SMOKE_DRY_RUN=1 \
demo/video-chat/scripts/bgf-production-browser-smoke.sh
```
