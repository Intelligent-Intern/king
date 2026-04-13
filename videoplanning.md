# Video Planning (Guidance Only)

Zweck dieser Datei:
- beschreibt nur Leitplanken, Architekturziele und Abnahmekriterien
- enthält **keine** ausführbare Issue-Liste mehr
- ausführbare Backlogs liegen in `READYNESS_TRACKER.md` (lang) und `ISSUES.md` (aktiver Batch)

## 1) Produktziel

Der neue Video-Call-Stack wird als produktionsnaher Pfad umgesetzt:
- Backend: King-PHP Runtime (HTTP + WebSocket + IIBIN)
- Frontend: Vue 3
- Persistenz: SQLite (Demo-tauglich, deterministisch, migrationsfähig)

Der Mock ist die führende UX- und Funktionsreferenz.

## 2) Nicht verhandelbare Leitplanken

- Keine Funktionsreduktion gegenüber dem Mock.
- Kein Node-Provisorium im neuen Realtime-Pfad.
- Auth, Session, RBAC, CRUD und Signaling laufen serverautorisiert über King.
- Jeder Mock-Flow besitzt einen expliziten REST- oder WS/IIBIN-Contract.
- Clean-Code-Aufteilung ist Pflicht:
  - Backend in klar getrennte Module (auth/session/rbac/calls/realtime).
  - Frontend in klar getrennte Stores/Views/Components.
  - Keine monolithischen Handler-Dateien mit gemischter Verantwortung.

## 3) Mock-Parität (Abnahmebasis)

Release-ready ist der neue Stack erst, wenn diese Gruppen vollständig sind:
- Login/Logout + rollenbasierte Navigation.
- Admin- und User-Flows mit durchgesetztem RBAC.
- Calls CRUD inkl. Schedule/Edit/Cancel.
- Kalenderflow mit internen und externen Teilnehmern.
- Invite-Link/Code inkl. Copy und Join.
- Workspace mit Sidebars, Tabs, Listen, Pagination, Controls und Reactions.
- Settings/Branding (Avatar/Logo-Crop, Theme, Time-Format, Mail-Template-Slots).

## 4) Architektur-Schnitt

- Legacy-Pfad bleibt als Referenz bestehen, bekommt aber keine neue Produktlogik.
- Neuer Pfad:
  - `demo/video-chat/backend-king-php/`
  - `demo/video-chat/frontend-vue/`
- Umschaltung auf neuen Standardpfad erst nach nachgewiesener Mock-Parität.

## 5) Realtime-Grundsatz

Workspace-Realtime läuft über King-Websocket mit serverautorisiertem Zustand:
- Presence
- Chat + Typing
- Signaling (`offer`/`answer`/`ice`/`hangup`)
- Lobby-Queue + Moderator-Aktionen

## 6) Qualität und Tests

Mindestnachweis je Feature-Leaf:
- Contract-Test für DTO/Event-Shape (REST/WS).
- Positiv- und Negativfall (validation, forbidden, conflict, expired, not found).
- Reconnect-/Recovery-Verhalten für Session und Realtime.
- Smoke auf dem neuen Stack (kein Legacy-Bypass als “green shortcut”).

## 7) Bewusste Nicht-Ziele für diesen Strang

Folgende Punkte bleiben bewusst außerhalb des aktuellen Mock-Paritätsstrangs,
auch wenn sie in früheren Entwürfen enthalten waren:
- Renderer-Eskalation DOM/Canvas/WebGL für 500+ oder 1000+ Teilnehmer.
- Breakout-Room-Orchestrierung als eigener Produktbereich.
- Super-Admin-Lizenz-/Instanz-Management außerhalb der Video-Call-Produktoberfläche.
- Langfristige 12-Jahres-Roadmap als aktiver Ausführungsplan.

Diese Themen sind optional spätere Tracks, aber **nicht** Abnahmeblocker für die
Mock-Parität des aktuellen Replatform-Batches.
