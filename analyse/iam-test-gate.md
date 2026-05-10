# IAM Test Gate

Stand: 2026-05-10

## Befund

`demo/video-chat/frontend-vue/package.json` hat bereits die sinnvollen lokalen
IAM/Call-Access-Einstiege:

- `npm run test:ci:iam-call-access:static` ist der schnelle host-sichere
  Hygiene-Gate fuer kleine IAM- und Call-Access-Aenderungen.
- `npm run test:ci:iam-call-access` und
  `npm run test:ci:iam-call-access:full` sind der kanonische volle
  Contract-Gate ueber `test:contract:iam-call-access`.
- `npm run test:ci:iam-call-access:sqlite` ist der fokussierte Backend-
  Runtime-Proof. Er nutzt Host-`pdo_sqlite`, wenn vorhanden, sonst den Docker-
  PHP-Fallback.
- `npm run test:ci:iam-call-access:docker` ist der explizite
  Docker-Proof-Discovery-Gate fuer `*docker-proof.sh`.
- `npm run test:e2e:call-access -- --reporter=list` ist der fokussierte
  Browser-Gate fuer die stabilen Call-Access-Specs.
- `npm run test:contract:iam-local-run-docs` prueft die lokale Doku und
  Script-Verdrahtung.

Der direkte Backend-Proof
`demo/video-chat/backend-king-php/tests/iam-core-org-session-journey-contract.sh`
ist lokal nur ein schmaler Einzelproof und skippt ohne Host-`pdo_sqlite`.
Fuer belastbare lokale Backend-Abdeckung ist deshalb
`npm run test:ci:iam-call-access:sqlite` der bessere Einstieg, weil dieser Gate
den Docker-Fallback einschliesst.

## Alias-Entscheidung

Kein weiterer `package.json`-Alias ist aktuell noetig.

Die vorhandenen Contracts `iam-call-access-ci-wire-contract.mjs` und
`iam-local-run-docs-contract.mjs` sichern bereits die erwarteten Package-Scripts
ab: canonical/full, static, sqlite, docker, local docs und focused E2E. Ein
zusaetzlicher Alias fuer `iam-core-org-session-journey-contract.sh` wuerde den
Backend-Proof eher fragmentieren, weil der Proof bereits ueber
`iam-call-access-sqlite-runtime-proof.sh` und damit ueber den bestehenden
SQLite- und Full-Gate erreichbar ist.

Ebenfalls nicht ergaenzen: ein weicher `--available`-Alias. Die lokale Doku
haelt bewusst fest, dass der kanonische Gate strict `--full` bleibt.

## Lokale Umgebung

In diesem Worktree ist Host-PHP ohne `pdo_sqlite` verfuegbar, Docker aber
erreichbar. `test:ci:iam-call-access:sqlite` und
`test:ci:iam-call-access:docker` sind damit lokal sinnvoll, aber nicht mehr
host-safe; sie laufen ueber Container-Fallbacks.

## Verifikation

- `npm run test:contract:iam-local-run-docs`: PASS.
- `npm run test:ci:iam-call-access:static`: FAIL nach mehreren gruenen
  Vorpruefungen bei
  `iam9-06-call-app-entitlement-revocation-contract.mjs`. Der Fehler ist ein
  bestehender Doku-Drift in `documentation/iam7-08-call-app-entitlement-revocation.md`
  und liegt ausserhalb dieser Aufgabe-F-Ownership.
- `../backend-king-php/tests/iam-core-org-session-journey-contract.sh`: SKIP,
  weil Host-PHP kein `pdo_sqlite` hat.

Nicht ausgefuehrt:

- `npm run test:ci:iam-call-access:sqlite`: sinnvoller Backend-Gate, aber hier
  nur ueber Docker-Fallback; fuer die Alias-Entscheidung nicht erforderlich.
- `npm run test:ci:iam-call-access:docker`: sinnvoller Container-Proof-Discovery-
  Gate, aber nicht host-safe und fuer die Alias-Entscheidung nicht erforderlich.
- `npm run test:ci:iam-call-access` / `:full`: wuerde den bereits blockierten
  Static-Drift plus die schweren Backend-Gates einschliessen.
- `npm run test:e2e:call-access -- --reporter=list`: Browser/Playwright-Gate,
  nur sinnvoll bei UI- oder Browser-Flow-Aenderungen; hier wurde nur die
  Analyse ergaenzt.
