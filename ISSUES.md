# King Issues

> Status: 2026-04-19
> Fokus: saubere Architektur- und Skalierungsplanung für Video-Calls, inkl. WASM-Fallback, SFU, Background-Blur, persistenter Chat-/Datei-Ablage, Speaker-Awareness und issue-basierter Umsetzung.

## Harte Anforderungen

- Frontend-Gesetz: die Produkt-UX-Referenz ist führend.
- Zielkapazität: tausende Nutzer pro Call-Session.
- Sichtbar im Call-Viewport: maximal ca. 5 Teilnehmer gleichzeitig.
- Restliche Teilnehmer: performant in User-/Participant-Listen.
- Background-Blur/Background-Swap muss sauber integriert werden.
- Dokumentation in `ISSUES.md` muss so vollständig sein, dass ein neuer Call ohne Vorwissen weiterarbeiten kann.

## Aktueller verifizierter Stand (Research Snapshot)

- [x] Historischer `demo/video-chat/frontend`-Stand enthielt primär Codec-/Media-Libraries; diese liegen jetzt konsolidiert in `demo/video-chat/frontend-vue/src/lib/**`.
- [x] Aktueller Code hat TS-Fallback: `createHybridEncoder`/`createHybridDecoder` fallen auf TS-Codec zurück (Ist-Zustand, für Zielarchitektur zu entfernen).
- [x] Das ist **kein** automatischer Fallback auf nativen WebRTC-Codec (VP8/H264); dieser Pfad muss explizit definiert/implementiert werden.
- [x] Alex-Integration im Frontend kam über Commits `78f4f5c` und `e5d65b7` (Author: `Alice-and-Bob`); `e5d65b7` brachte auch Artefakte mit, später bereinigt (`25e15e7`).
- [x] In `../intelligent-intern/services/app/compat/src/modules/videocall` existiert ein ausgereiftes Background-Filter-System (FaceDetector/MediaPipe/TFJS/Center-Mask-Fallback + Controller + Runtime-Metriken + Baseline-Gates).
- [x] In diesem Repo existiert `demo/media-gateway/` (Rust), inkl. SFU-Bausteinen und Signaling/AMQP/QUIC-Skizze.

## Fallback-Verständnis (verbindlich zu klären und dann umzusetzen)

Aktuell:
- WASM nicht verfügbar -> TS-WLVC (bereits vorhanden, aber nicht Zielarchitektur).

Noch offen (muss als Vertrag implementiert werden):
- WASM nicht verfügbar/instabil -> direkter nativer WebRTC-Codec (ohne TS-WLVC-Runtime-Fallback).
- WLVC-Pfad nicht nutzbar/zu teuer -> nativer WebRTC-Codec.
- Harte Degradation bei Last -> ggf. Audio-only / reduzierte Videoqualität.

## Issue-Backlog (neu, phasenbasiert)

### #1 Architektur-Inventur und Quellenkatalog (Research)

Ziel:
- Vollständige Ist-Aufnahme für `frontend-vue` (inkl. übernommener Alex-Libraries), Backend, Extension, `demo/media-gateway`, sowie Referenzen aus `intelligent-intern`.

Checklist:
- [x] Relevante Pfade im aktuellen Repo gesichtet.
- [x] Alex-Commits mit Lib-Relevanz identifiziert und auf `frontend-vue` konsolidiert.
- [x] Blur-Referenzmodule in `intelligent-intern` identifiziert.
- [x] Architektur-Karte als kompakte Tabelle (Komponente -> Verantwortung -> Status -> Owner) ergänzen.
- [x] Abgleich gegen produktive `frontend-vue` Nutzung (welche Library-Pfade sind tatsächlich verdrahtet?).

Definition of done:
- Eine eindeutige Komponenten-/Verantwortungsmatrix liegt in diesem Dokument vor.
- Keine offenen "wo liegt das?"-Fragen für neue Sessions.

Notizen:
- `demo/video-chat/frontend-vue/src/lib/wasm/wasm-codec.ts`: Hybrid-WASM/TS-Factory.
- `demo/video-chat/frontend-vue/src/lib/sfu/sfuClient.ts`: SFU-Signaling/Track-Bookkeeping.
- `demo/media-gateway/src/sfu/*`: Rust-SFU-Prototyp.

Architektur-Karte (Snapshot):

| Komponente | Verantwortung | Produktionsverdrahtung | Owner/Status |
|---|---|---|---|
| `demo/video-chat/frontend-vue/src/domain/**` | Produkt-UI (Admin, Calls, Workspace) | Aktiv | Core |
| `demo/video-chat/frontend-vue/src/support/backendFetch.js` + `backendOrigin.js` | Zentrale Backend-Origin-/Fetch-Logik | Aktiv (breit genutzt) | Core |
| `demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.vue` | Realtime-Call-Orchestrierung im Frontend | Aktiv | Core |
| `demo/video-chat/frontend-vue/src/lib/sfu/sfuClient.ts` | SFU-Track-Signaling-Client (`/sfu`) | Aktiv (Import in `CallWorkspaceView.vue`) | Core |
| `demo/video-chat/frontend-vue/src/lib/wasm/wasm-codec.ts` | WLVC WASM + Hybrid-Factory | Aktiv (Import in `CallWorkspaceView.vue`) | Core; TS-Fallback aus Zielpfad entfernen |
| `demo/video-chat/frontend-vue/src/lib/wavelet/**` | TS-WLVC/Transform-Pipeline | Teilaktiv über Hybrid/Libs; als Runtime-Fallback nicht Ziel | Core |
| `demo/video-chat/frontend` | Historischer, entfernter Pfad (nach `frontend-vue/src/lib/**` übernommen) | Nicht mehr vorhanden | archival |
| `demo/video-chat/backend-king-php/**` | API/Auth/Calls/Realtime im King-PHP-Backend | Aktiv | Core |
| `extension/**` | King Runtime/Extension (native APIs) | Aktiv (plattformweit) | Core |
| `demo/media-gateway/**` | Rust SFU/Gateway Prototyp | Noch nicht produktiv verdrahtet | PoC/Integrationsentscheidung offen |

---

### #2 Medien-Fallback-Vertrag definieren (Research/Planning)

Ziel:
- Einen eindeutigen, testbaren Stufenvertrag definieren: WASM optional, direkter nativer WebRTC-Fallback, Degradationsregeln.

Checklist:
- [x] Stufe A definieren: WASM-WLVC.
- [x] Stufe B definieren: nativer WebRTC-Codec (kein WLVC).
- [x] Stufe C definieren: Last-/Fehler-Degradation (z. B. Audio-only).
- [x] TS-WLVC-Fallback in Produktpfad deaktivieren/entfernen (nur optionales Dev-Experiment).
- [x] Trigger je Stufe definieren (Feature-Detection, Runtime-Metriken, Fehlerklassen).
- [x] Telemetrie je Übergang definieren (warum wurde gewechselt?).

Definition of done:
- Es gibt ein normiertes Fallback-Dokument mit Zustandsmaschine und Triggern.
- Jede Stufe hat klare Ein-/Ausstiegskriterien und Testfälle.

Notizen:
- Bereits vorhanden (Ist): WASM -> TS-Fallback.
- Ziel: direkter Übergang von WLVC auf nativen WebRTC-Codec, TS-Fallback raus aus Produktpfad.

Stufe A (definiert): WASM-WLVC
- Zweck: maximal effiziente WLVC-Verarbeitung auf Clients mit verfügbarer WASM-Runtime.
- Eintrittsbedingungen:
  - WASM-Bundle erfolgreich geladen und initialisiert.
  - Encoder + Decoder in `lib/wasm/wasm-codec` sind operational.
  - Session ist für WLVC-Pfad freigegeben (kein Forced-Fallback aktiv).
- Laufzeitvertrag:
  - Outbound Video nutzt WLVC über WASM-Encoder.
  - Inbound WLVC-Frames werden über WASM-Decoder verarbeitet.
  - Fehler im Einzel-Frame führen nicht direkt zu Session-Abbruch.
- Austrittsbedingung (High-Level):
  - Wenn WASM init/runtime nicht stabil ist, Wechsel auf Stufe B (nativer WebRTC-Codec).
  - Kein Übergang auf TS-WLVC als Produktpfad.

Stufe B (definiert): nativer WebRTC-Codec
- Zweck: stabiler Betriebsmodus ohne WLVC/WASM-Abhängigkeit.
- Eintrittsbedingungen:
  - Stufe A nicht verfügbar oder nicht stabil.
  - Browser/Client bietet nutzbare native WebRTC-Codecs (z. B. VP8/H264/Opus).
- Laufzeitvertrag:
  - Video/Audio laufen über Standard-WebRTC-Pipeline.
  - Keine WLVC-Encode/Decode-Pflicht im Client.
  - Teilnehmer kann trotz WLVC-Fehlern weiter im Call bleiben.
- Austrittsbedingung (High-Level):
  - Bei extremer Last/Fehlerlage Wechsel auf Stufe C (Degradation, z. B. Audio-only).
  - Optionaler Rückwechsel auf Stufe A nur nach stabiler Requalifizierung.

Stufe C (definiert): Last-/Fehler-Degradation
- Zweck: Call unter Stress funktionsfähig halten statt Abbruch.
- Eintrittsbedingungen:
  - Schwere Paketverluste/CPU-Overload/Decode-Fehler über definierte Schwellen.
  - SFU/Client meldet Instabilität, die Stufe B nicht mehr sauber trägt.
- Laufzeitvertrag:
  - Priorität auf Verständlichkeit/Stabilität: Audio-first.
  - Video wird reduziert (fps/auflösung) oder temporär deaktiviert (Audio-only).
  - Sichtfenster bleibt strikt begrenzt, nicht-kritische Effekte/Features werden ausgesetzt.
- Austrittsbedingung (High-Level):
  - Rückkehr zu Stufe B erst nach stabiler Messphase.
  - Rückkehr zu Stufe A nur nach zusätzlicher WASM-Requalifizierung.

TS-WLVC-Fallback-Abbau (definiert)
- Ziel: `createHybridEncoder`/`createHybridDecoder` dürfen im Produktbetrieb nicht mehr automatisch auf TS-WLVC gehen.
- Scope:
  - Produktpfad (`frontend-vue` Realtime Call Workspace) nutzt nur Stufe A/B/C.
  - TS-WLVC bleibt optionaler Dev-/Experimentierpfad hinter explizitem Feature-Flag.
- Migrationsschritte:
  - Hybrid-Factories im Produktpfad durch explizite Capability-Entscheidung ersetzen.
  - Bei fehlendem/instabilem WASM direkt auf nativen WebRTC-Betrieb gehen.
  - Guardrails in CI/Test ergänzen, damit TS-WLVC nicht unbemerkt zurück in den Produktpfad rutscht.

Trigger-Matrix (definiert)
- A -> B (WASM-WLVC auf nativen WebRTC):
  - WASM init schlägt fehl oder lädt nicht innerhalb Timeout.
  - Wiederholte WLVC encode/decode runtime faults über Schwellwert.
  - Client meldet fehlende stabile Ressourcen für WLVC.
- B -> C (nativer WebRTC auf Degradation):
  - Paketverlust/Jitter/Decode-Fehler/CPU-Last über Schwellwertfenster.
  - SFU oder Client signalisiert Instabilität trotz B-Modus.
- C -> B (Recovery aus Degradation):
  - Stabilitätsfenster erfüllt (Metriken unter Schwellwert über Mindestdauer).
  - Audio/Signaling stabil, keine harten Fehler im Beobachtungsfenster.
- B -> A (Requalifizierung zurück auf WASM-WLVC):
  - Expliziter Requalify-Check erfolgreich (WASM verfügbar + stabil).
  - Rückwechsel nur kontrolliert (cooldown + keine laufende Instabilität).

Telemetrie-Matrix (definiert)
- Pflicht-Event bei jedem Stufenwechsel: `media.fallback.transition.v1`
  - Felder:
    - `call_id`, `participant_id`, `from_stage`, `to_stage`
    - `reason_code` (z. B. `wasm_init_failed`, `codec_runtime_fault`, `network_overload`, `manual_requalify`)
    - `sample_window_ms`, `cooldown_state`, `timestamp`
    - Metrik-Snapshot: `packet_loss`, `jitter_ms`, `rtt_ms`, `decode_errors`, `cpu_load`, `fps_out`, `fps_in`
- Zusätzliche Diagnoseevents:
  - `media.fallback.guardrail_hit.v1` (Schwellwertverletzung erkannt, aber noch kein Wechsel)
  - `media.fallback.recovery_candidate.v1` (Stabilitätsfenster erreicht, Recovery möglich)
  - `media.fallback.recovery_commit.v1` (Recovery tatsächlich durchgeführt)
- Auswertung:
  - Aggregation nach `reason_code`, Browser, Gerätetyp, Region, Call-Größe.
  - Ziel: Top-Ursachen für Wechsel und Rückwechselrate transparent machen.

---

### #3 Skalierungsarchitektur für tausende Teilnehmer (Research/Planning)

Ziel:
- Architektur, die große Teilnehmerzahl unterstützt, bei gleichzeitig begrenzter Sichtbarkeit im Haupt-Call (max ~5).

Checklist:
- [x] Kapazitätsziele festlegen (z. B. 1k, 5k, 10k Teilnehmer-Szenarien).
- [x] "Visible participants window" spezifizieren (max 5, Active-Speaker/Pinning-Regeln).
- [x] Teilnehmerliste als server-getriebenes, paginiertes/virtuelles Modell definieren.
- [x] Presence-/Roster-Update-Strategie definieren (Delta-Events statt Full Snapshot).
- [x] Lastgrenzen und Backpressure-Regeln für Reactions/Chat/Participant-Events definieren.
- [x] SFU-Fanout- und Admission-Policy dokumentieren.

Definition of done:
- Ein belastbares Architekturkonzept inkl. Datenflüssen, Limits, und SLOs liegt vor.
- Klarer Split zwischen "was wird gerendert" und "was ist nur im Roster".

Notizen:
- Sichtbare Teilnehmerzahl muss klein gehalten werden (Zielwert ~5).
- Liste muss für große Mengen unabhängig vom Video-Rendering skalieren.

Kapazitätsziele (definiert):
- Tier 1 (Baseline): 1.000 gleichzeitige Teilnehmer in einer Session.
- Tier 2 (Target): 5.000 gleichzeitige Teilnehmer in einer Session.
- Tier 3 (Stretch): 10.000 gleichzeitige Teilnehmer in einer Session.
- Sichtbarkeit im Main-Viewport bleibt dabei hart begrenzt (max. ~5 aktive Streams).
- Alle weiteren Teilnehmer bleiben roster-/listenbasiert (kein Voll-Video-Fanout zum Client).

Visible Participants Window (definiert):
- Größe: maximal 5 gleichzeitige Video-Slots im Hauptbereich.
- Slot-Priorität (hoch nach niedrig):
  - gepinnte Teilnehmer
  - aktueller Active Speaker
  - Host/Owner (falls nicht bereits enthalten)
  - weitere Sprecher nach Recency/Score
- Wechselregeln:
  - keine harten Flips pro Audio-Spike (stabilisierte Sprecher-Erkennung mit Hysterese).
  - Mindesthaltezeit pro Slot, außer bei Pin/Host-Override.
  - bei Bandbreiten-/Leistungsdruck zuerst Non-Pinned-Nebenstreams reduzieren.
- Garantien:
  - eigener Self-View bleibt verfügbar (als eigener Kanal, nicht zwingend im Top-5-Mainset).
  - Teilnehmer außerhalb Top-5 bleiben vollständig im Roster sichtbar und moderierbar.

Roster-Modell (definiert):
- Server ist Source of Truth für Teilnehmerliste (keine vollständige Client-Schattenliste als Primärquelle).
- Abfrageform: `page`, `page_size`, `search`, `sort`, optionale `status`-Filter.
- Client rendert nur sichtbaren Bereich (virtuelles Rendering), nicht die komplette Liste.
- Paging bleibt stabil über Cursor/Sort-Key (keine springende Reihenfolge bei Updates).
- Aktionen (mute/remove/role-change) laufen gegen serverseitige IDs und aktualisieren nur betroffene Rows.
- UI-Regel: Roster kann 10k Einträge verwalten, ohne Main-Video-Rendering zu blockieren.

Presence-/Roster-Update-Strategie (definiert):
- Primärpfad: Delta-Events (`join`, `leave`, `status_change`, `role_change`, `media_state_change`) statt Full Snapshot.
- Reihenfolge/Sicherheit:
  - Jeder Delta-Event trägt monotone `seq`/`version`.
  - Client verwirft alte/out-of-order Events.
- Re-Sync-Regel:
  - Nur bei Gap-Erkennung (`missing_seq`) oder Reconnect wird ein gezielter Partial/Full-Re-Sync angefordert.
  - Re-Sync bleibt serverseitig paginiert; kein erzwungener 10k-Einmaldump.
- UI-Aktualisierung:
  - nur betroffene Teilnehmerzeilen und abgeleitete Counter werden aktualisiert.
  - kein globales Re-Rendern der gesamten Roster-Struktur pro Event.

Lastgrenzen + Backpressure (definiert):
- Reactions:
  - soft-limit pro Sender: bis 20/s normal durchlassen.
  - darüber batching auf Server-Seite (z. B. 25er Batch-Event), statt Flood einzelner Broadcasts.
  - keine harte UI-Warnleiste für normalen Flood-Fall; System drosselt intern.
- Chat:
  - rate-limit pro Sender + bounded queue; bei Überlauf drop nach Policy (latest-wins oder reject mit code).
  - Nachrichtenlänge bleibt begrenzt; direkte oversized `chat/send` Frames werden serverseitig abgelehnt.
  - Frontend-Produktpfad wandelt oversized Text/Paste-Inhalte in Datei-Anhänge um (siehe #18), statt GB-große WS-Nachrichten zu senden.
- Participant-Events:
  - Join/Leave/Role/Mute Events als delta-stream mit coalescing bei Burst.
  - serverseitige fanout-throttles für nicht-kritische Events (z. B. speaking indicators).
- Backpressure-Prinzip:
  - kritisch (moderation/security) vor kosmetisch (typing/speaking/reaction bursts).
  - unter Last zuerst Sampling/Batching, erst danach harte Drops.

SFU-Fanout + Admission-Policy (definiert):
- Admission:
  - Session-Join nur mit gültigem Auth-/Room-Kontext.
  - harte Obergrenzen pro Room (gemäß Tier-Ziel) + kontrollierte Warteschlange bei Überlauf.
  - Priorisierte Aufnahme für Host/Admin/Owner-Rollen.
- Fanout-Grundsatz:
  - Server entscheidet über Forwarding-Sets, nicht der Client.
  - pro Client wird nur das notwendige Stream-Set geliefert (Top-Window + relevante Audios).
  - nicht sichtbare Teilnehmer erhalten keine unnötigen Full-Video-Streams.
- Laststeuerung:
  - adaptives Simulcast/SVC-Layer-Forwarding (falls verfügbar).
  - unter Last erst Qualität reduzieren, dann sekundäre Streams ausdünnen.
- Moderation/Sicherheit:
  - mute/remove/role-change greifen serverautoritativ und sofort im Forwarding.
  - isolierte bzw. gebannte Teilnehmer verlieren Stream-Fanout umgehend.

---

### #4 Issue-Splitter: Folge-Issues systematisch anlegen

Ziel:
- Nach Abschluss von #1-#3 automatisch konsistente Folge-Issues (#5 bis #N) erzeugen.

Checklist:
- [x] Abhängigkeiten aus #1-#3 in Workstreams übersetzen.
- [x] Pro Workstream konfliktfreie Dateizuständigkeit definieren.
- [x] Issue-Templates mit DoD, Tests, Telemetrie, Migrationspfad erzeugen.
- [x] Reihenfolge/Parallelisierung dokumentieren.

Definition of done:
- Folge-Issues sind so geschnitten, dass parallele Umsetzung ohne gegenseitige Blocker möglich ist.

Notizen:
- Diese Phase ist Pflicht, bevor große Implementierungswellen gestartet werden.

Workstreams aus #1-#3 (definiert):
- WS-A Architektur + Runtime Contracts:
  - Inputs: #1 Architekturkarte, #2 Stufenvertrag A/B/C.
  - Output-Issues: Fallback-State-Machine, Capability-Gates, Runtime-Guardrails.
- WS-B Frontend Realtime UX + Roster Scale:
  - Inputs: #3 Visible-Window, Roster-Modell, Delta-Strategie.
  - Output-Issues: Top-5-Window, Virtualized Roster, Row-level Updates.
- WS-C Backend/SFU Core:
  - Inputs: #3 Fanout/Admission + Backpressure-Regeln.
  - Output-Issues: Admission Queue, selective Fanout, coalesced event streams.
- WS-D Observability + Load Verification:
  - Inputs: #2 Telemetrie-Matrix + #3 Kapazitätsziele.
  - Output-Issues: fallback transition telemetry, SLO dashboards, load profiles.
- WS-E Migration + Hygiene:
  - Inputs: #2 TS-WLVC-Abbau, #10 Repo-Hygiene.
  - Output-Issues: remove product TS fallback path, CI guard checks, artifact policy.

Dateizuständigkeit pro Workstream (konfliktfrei, definiert):
- WS-A Architektur + Runtime Contracts (primärer Write-Scope):
  - `demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.vue`
  - `demo/video-chat/frontend-vue/src/domain/realtime/callMediaPreferences.js`
  - `demo/video-chat/frontend-vue/src/support/runtime.js`
- WS-B Frontend Realtime UX + Roster Scale (primärer Write-Scope):
  - `demo/video-chat/frontend-vue/src/domain/calls/AdminCallsView.vue`
  - `demo/video-chat/frontend-vue/src/domain/calls/UserDashboardView.vue`
  - `demo/video-chat/frontend-vue/src/domain/users/AdminUsersView.vue`
- WS-C Backend/SFU Core (primärer Write-Scope):
  - `demo/video-chat/backend-king-php/http/module_realtime.php`
  - `demo/video-chat/backend-king-php/http/module_calls.php`
  - `demo/video-chat/backend-king-php/domain/realtime/realtime_reaction.php`
  - `demo/video-chat/backend-king-php/domain/calls/call_management.php`
- WS-D Observability + Load Verification (primärer Write-Scope):
  - `demo/video-chat/contracts/v1/api-ws-contract.catalog.json`
  - `demo/video-chat/scripts/smoke.sh`
  - `demo/video-chat/backend-king-php/tests/*.php`
- WS-E Migration + Hygiene (primärer Write-Scope):
  - `demo/video-chat/frontend-vue/src/lib/wasm/wasm-codec.ts`
  - `demo/video-chat/frontend-vue/src/lib/wavelet/*`
  - `.gitignore`, CI workflow files

Issue-Template (Standard, definiert):
- Titel:
  - `[WS-<A..E>] <kurzer Ergebnisname>`
- Pflichtfelder:
  - Ziel/Business-Nutzen
  - Scope (inkl. explizit Out-of-Scope)
  - Write-Scope (Dateipfade)
  - Abhängigkeiten (blockers/blocked-by)
- DoD (muss vollständig erfüllt sein):
  - Funktionale Akzeptanzkriterien erfüllt
  - Keine Abweichung vom UX-Vertrag im betroffenen UI-Bereich
  - Keine Regression in Auth/Realtime/Moderation
- Tests:
  - Unit/Component-Tests (wo sinnvoll)
  - Contract-/API-Tests (bei Backend/Realtime-Änderungen)
  - Smoke-Szenario (Happy Path + mind. 1 Failure Path)
- Telemetrie:
  - neue/angepasste Events benannt
  - reason-codes + minimale Metrikfelder dokumentiert
  - Dashboard/Query-Hinweis für Verifikation hinterlegt
- Migrationspfad:
  - Rollout-Strategie (feature-flag/canary/gradual)
  - Rollback-Regel (wann + wie)
  - Daten-/Konfig-Migration explizit benannt (falls nötig)

Reihenfolge + Parallelisierung (definiert):
- Phase 1 (seriell, blocker-first):
  - WS-A (Runtime-Contracts/Fallback-State-Machine) zuerst finalisieren.
  - Grund: WS-B/WS-C brauchen stabile Runtime-Entscheidungen.
- Phase 2 (parallel möglich, disjunkte Write-Scopes):
  - WS-B (Frontend Roster/Top-5 UX) parallel zu WS-C (Backend/SFU Core).
  - WS-D (Telemetry/Load) kann parallel anlaufen, sobald erste Events/Contracts stehen.
- Phase 3 (seriell vor Release):
  - WS-E (Migration/Hygiene) nach WS-A/B/C, damit finale Pfade bereinigt werden.
  - Abschluss mit integrierter Smoke/Load-Verifikation aus WS-D.
- Merge-/Integrationsregel:
  - nur fast-forward in Integrationsbranch, wenn jeweiliger WS-DoD + Smoke erfüllt ist.
  - bei Scope-Kollisionen hat der definierte Write-Scope Vorrang, sonst Re-Slice statt Hotfix-Chaos.

---

### #5 Background-Filter aus `intelligent-intern` übernehmen (Implementierung)

Ziel:
- Blur/Background-Handling in `frontend-vue` auf bewährte Bausteine heben.

Checklist:
- [x] `background_filter_controller.ts`-Pattern übernehmen.
- [x] Backend-Selection/Fallback (FaceDetector -> MediaPipe -> TFJS -> center_mask) integrieren.
- [x] Preview-Prefs-Parität für Blur/Backdrop/Quality herstellen.
- [x] Runtime-Metriken und Baseline-Gates übernehmen.
- [x] UI-Controls (Blur-Mode/Backdrop/Qualität) stabil an Stores binden.

Definition of done:
- Blur funktioniert robust auf unterschiedlichen Browsern/GPU-Profilen.
- Bei Backend-Ausfall bleibt ein funktionaler Fallback aktiv.

Quellen:
- `../intelligent-intern/services/app/compat/src/modules/videocall/background_filter_stream.ts`
- `../intelligent-intern/services/app/compat/src/modules/videocall/background_filter_backend_selector.ts`
- `../intelligent-intern/services/app/compat/src/modules/videocall/preview_prefs.ts`

---

### #6 Codec-/Transport-Fallback implementieren (Implementierung)

Ziel:
- Vertrag aus #2 produktiv implementieren.

Checklist:
- [x] Runtime-Capability-Erkennung (WASM/WLVC/WebRTC).
- [x] `createHybrid*`-Pfad für Produktbetrieb entschärfen: kein TS-WLVC-Runtime-Fallback.
- [x] Sauberer Wechsel WLVC <-> nativer WebRTC ohne Session-Abbruch.
- [x] Persistente Telemetrie-Events für Fallback-Transitions.
- [x] UI-neutrales Handling (kein störender Fehlerbanner bei erwarteten Fallbacks).

Definition of done:
- Clients können bei fehlendem WASM oder WLVC-Problemen stabil weiterarbeiten.

---

### #7 Large-Call Teilnehmermodell (Implementierung)

Ziel:
- Tausende Teilnehmer verwalten, aber nur kleines Sichtfenster rendern.

Checklist:
- [x] "Top-5 visible" Logik (Active Speaker + Pinning) implementieren.
- [x] Roster-Rendering virtualisieren/paginieren.
- [x] Serverseitige Suche/Filterung/Sortierung integrieren.
- [x] Rechte-/Moderationsaktionen auf große Listen skalieren.

Definition of done:
- UI bleibt responsiv bei hoher Teilnehmerzahl.
- Keine Full-List-Repaints bei jedem Presence-Event.

---

### #8 Media-Gateway/SFU Integrationspfad (Implementierung)

Ziel:
- Entscheidung und Integration zwischen King-Backend-SFU-Pfad und `demo/media-gateway`-Pfad.

Checklist:
- [x] Zielarchitektur festlegen: was ist Produktpfad, was ist Referenz/PoC.
- [x] Signaling-Vertrag zwischen Backend und Gateway festzurren.
- [x] Auth/JWT/Room-Bindung und Security-Hardening abgleichen.
- [x] Interop-Testmatrix erstellen (Join/Leave, Track publish/unpublish, reconnect).

Definition of done:
- SFU-Pfad ist eindeutig, reproduzierbar testbar, und ohne Architektur-Dopplung.

Architekturentscheidung (v1, festgelegt):
- Produktpfad v1: `demo/video-chat/backend-king-php` ist der aktive SFU-/Signaling-Pfad.
  - REST auf HTTP-Listener.
  - WebSocket auf WS-Listener (`/ws` + `/sfu`), server mode getrennt (`http` vs `ws`).
  - Frontend `frontend-vue` bindet an `/sfu` via `src/lib/sfu/sfuClient.ts`.
- `demo/media-gateway` bleibt in v1 Referenz/PoC (nicht produktiv verdrahtet).
  - Zweck: Vorarbeit für späteren externen Gateway-Pfad (AMQP/Proto/QUIC/WebRTC-RS).
  - Kein paralleler Produktbetrieb von zwei SFU-Laufwegen in v1.
- Konsequenz:
  - Fehler wie `websocket_endpoint_disabled` auf Port `18080` sind erwartbar, wenn `/sfu` auf HTTP-Listener aufgerufen wird.
  - Für v1 ist die korrekte Betriebsannahme: `/sfu` nur auf dem WS-Listener.

Signaling-Vertrag (v1, festgezurrt):
- Kanal-Split:
  - `/ws`: WebRTC-Signaling zwischen Peers (`call/offer`, `call/answer`, `call/ice`, `call/hangup`).
  - `/sfu`: Publisher/Track/Frame-Signaling (`sfu/*`) für den aktiven SFU-Pfad.
- Kanonische Identifier:
  - `room_id` ist kanonisch.
  - `call_id` bleibt Brücken-Alias am AMQP-Envelope (`topic=call.signaling`, `payload.call_id = room_id`).
  - `peer_id` (Gateway-Protobuf) mappt auf Benutzer/Publisher-Kontext (`user_id`/`publisher_id` je Nachrichtentyp).
- Namenskonvention am Wire:
  - Primär `snake_case` für JSON-Keys an Backend/Gateway-Grenzen.
  - Übergangs-Aliase (camelCase) sind nur Kompatibilität auf Adapter-Ebene; Ziel bleibt `snake_case` als Contract-Quelle.
- Mapping Backend <-> Gateway:
  - `call/offer` <-> Protobuf `SessionDescription{ type=\"offer\" }`
  - `call/answer` <-> Protobuf `SessionDescription{ type=\"answer\" }`
  - `call/ice` <-> Protobuf `IceCandidate` (Bridge-JSON nutzt `type=\"candidate\"` + `candidate{sdpMid,sdpMLineIndex}`)
  - `call/hangup` <-> Protobuf `LeaveRequest` (optional `RoomEnd` für Host/Room-Ende)
- `/sfu` Message-Vertrag:
  - Server -> Client: `sfu/welcome`, `sfu/joined`, `sfu/published`, `sfu/tracks`, `sfu/unpublished`, `sfu/frame`, `sfu/publisher_left`.
  - Client -> Server: `sfu/publish`, `sfu/subscribe`, `sfu/unpublish`, `sfu/frame`, `sfu/leave`.
  - `sfu/join` wird im Produktpfad nicht als bindender Command benötigt (Room kommt aus WS-Query `room`, Rolle aus Query `role`); bestehende Clients dürfen ihn tolerant senden.
- Nachrichtenkonsistenz:
  - Pflichtfelder pro SFU-Frame: `track_id`, `timestamp`, `data`, `frame_type`.
  - Legacy-Key `frameType` bleibt bis Adapter-Bereinigung kompatibel, ist aber nicht kanonisch.
  - Pflichtfelder pro Call-Signaling: `type`, `target_user_id`, `payload`.
  - Ziel: kein Typ-/Key-Mix in Kernpfaden; Brücken normalisieren beim Eintritt.

Auth/JWT/Room-Bindung + Security-Hardening (v1, abgeglichen):
- Produktpfad-Auth (Backend King-PHP):
  - `/ws` und `/sfu` authentifizieren mit Session-Token (`Authorization: Bearer`, `X-Session-Id`, WS-Query `session|token|session_id`).
  - RBAC auf WS-Pfaden ist aktiv (`admin|user`), transport-spezifisch fail-closed.
  - Session-Rotation/Revoke ist vorhanden; `/ws` revalidiert Session-Liveness im Loop und trennt invalidierte Sessions.
- Access-Link/JWT-Kontext:
  - Öffentliche Join-Entry ist `GET /api/call-access/{uuid}/join` + `POST /api/call-access/{uuid}/session`.
  - Session-Issuing für Access-Link liefert `session_id`-Token, an Call/Participant-Kontext gebunden (personal/open link).
  - Open-Links erzeugen Guest-User serverseitig; Joinbarkeit wird gegen Call-Status (`scheduled|active`) geprüft.
- Room-Bindung (v1-Vertrag):
  - Kanonisch ist `room` im WS-Query beim `/sfu`-Connect.
  - `sfu/join.room_id` ist nur Kompatibilitätsfeld; falls vorhanden, muss es exakt zu Query-`room` matchen.
  - Kein stilles Defaulting auf `lobby` im produktiven `/sfu`-Pfad für Call-Workspaces.
  - `/sfu` muss vor Track-Publish prüfen, dass Session-User für den Room/Call autorisiert ist.
- Erkannte Lücke (Ist-Zustand, zu schließen):
  - Frontend sendet `roomId` aktuell primär im `sfu/join`, Backend liest Room aktuell aus Query.
  - Daraus entsteht Room-Mismatch-Risiko (inkl. implizitem `lobby`-Fallback).
  - Verbindliche Folge: Adapter vereinheitlichen (Query `room_id` + snake_case, Match-Check serverseitig erzwingen).
- Gateway-Pfad (PoC, aber Security-Vertrag fest):
  - JWT-HS256-Validation vorhanden (`sub|effective_id` + `room|call_id` + `exp`).
  - `JWT_SECRET` darf in produktiven Deployments nie auf `dev-secret-unsafe` fallen (strict vault mode).
  - Join-Rate-Limit + Token-Längenlimit bleibt Pflicht im Gateway-Pfad.

Interop-Testmatrix (v1):

| ID | Flow | Transport/Pfad | Erwartung | Automatisierung (Ist) | Status |
|---|---|---|---|---|---|
| I-01 | WS Handshake strict | WS `/ws` | ungültiger Handshake fail-closed (`400/405/426`), Auth-Callback nicht vor Handshake-Validierung | `tests/realtime-websocket-gateway-contract.sh`, `tests/videochat-integration-matrix-realtime-contract.sh` | abgedeckt |
| I-02 | Session-Liveness/Revoke | WS `/ws` | revoked/expired Session wird erkannt; strukturierter Close-Descriptor | `tests/realtime-session-revocation-contract.sh`, `tests/videochat-integration-matrix-realtime-contract.sh` | abgedeckt |
| I-03 | Presence Join/Leave/Reconnect | WS `/ws` (`room/*`) | Snapshot + Join/Leave-Deltas korrekt; Reconnect-Resync vorhanden; Room-Move konsistent | `tests/realtime-presence-contract.sh` | abgedeckt |
| I-04 | Directed Signaling | WS `/ws` (`call/offer|answer|ice|hangup`) | nur target-user im selben Room; self-target/invalid target fail-closed | `tests/realtime-signaling-contract.sh` | abgedeckt |
| I-05 | Reactions Backpressure | WS `/ws` (`reaction/*`) | Flood -> Batch-Fanout, room-scoped, ohne cross-room leak | `tests/realtime-reaction-contract.sh` | abgedeckt |
| I-06 | SFU Auth-Handshake | WS `/sfu` | Auth + RBAC + WS-Handshake für `/sfu` (wie `/ws`) | kein dedizierter Test vorhanden | offen |
| I-07 | SFU Publish/Subscribe | WS `/sfu` (`sfu/publish|subscribe|unpublish`) | `sfu/tracks`, `sfu/unpublished`, `sfu/publisher_left` korrekt pro Room | kein dedizierter Test vorhanden | offen |
| I-08 | SFU Frame Relay | WS `/sfu` (`sfu/frame`) | Frames nur an Subscriber im selben Room, kein self-echo | kein dedizierter Test vorhanden | offen |
| I-09 | SFU Reconnect | WS `/sfu` | Reconnect -> Join/Publisher-Liste/Resubscribe stabil | kein dedizierter Test vorhanden | offen |
| I-10 | Room-Binding Enforcement | WS `/sfu` | Query-`room` und optionales Join-Payload müssen matchen; kein stilles Fallback auf `lobby` | kein dedizierter Test vorhanden | offen |
| I-11 | Gateway JWT-Bindung | Gateway Join (`SignalMessage.Join`) | JWT `sub/effective_id` == `peer_id`, `room/call_id` == `room_id` | Rust Unit in `demo/media-gateway/src/sfu/mod.rs` (rate-limit + token path) | teilweise |
| I-12 | Gateway <-> Backend Mapping | AMQP `call.signaling` + Backend Signaling | `room_id <-> call_id` mapping konsistent, `offer/answer/ice/hangup` interoperabel | kein E2E-Interop-Test vorhanden | offen |
| I-13 | Access-Link Join-Session | REST `/api/call-access/{id}/join|session` -> WS | Access-gebundene Session führt nur in erlaubten Call/Room-Kontext | kein dedizierter Endpoint-Contract-Test vorhanden | offen |

v1-Ausführungsreihenfolge (empfohlen):
- Phase A (muss grün sein): I-01 bis I-05.
- Phase B (vor produktivem SFU-Rollout verpflichtend): I-06 bis I-10.
- Phase C (Gateway-Aktivierung): I-11 + I-12.
- Phase D (Public Join Hardening): I-13.

Konkrete neue Test-Artefakte für #8-Folgearbeit:
- `backend-king-php/tests/realtime-sfu-contract.sh|php` für I-06 bis I-10.
- `backend-king-php/tests/call-access-session-contract.sh|php` für I-13.
- `demo/media-gateway/tests/gateway-backend-interop-contract.(rs|sh)` für I-12.

---

### #9 Last-/SLO-/Observability-Paket (Implementierung)

Ziel:
- Messbar machen, ob "tausende Nutzer" erreichbar ist.

Checklist:
- [x] KPIs festlegen: Join-Zeit, Crash-Rate, FPS, encode/decode ms, packet loss, SFU CPU/RAM.
- [x] Lastprofile definieren (Burst joins, steady-state, emoji/chat flood, reconnect storms).
- [x] Alerting-Schwellen definieren.
- [x] Smoke + Load + Chaos-Tests in CI/ops dokumentieren.

Definition of done:
- Es gibt reproduzierbare Lastnachweise statt nur Funktions-Demos.

KPI-Katalog (v1, verbindlich):

| KPI-ID | KPI | Definition (formal) | Messquelle | Zielwert v1 |
|---|---|---|---|---|
| K-01 | Join API Latency | `t(join_session_200) - t(join_click)` für `/api/call-access/{id}/session` bzw. Enter-Flow | Frontend Event + Backend Request-Timing | p95 <= 800 ms |
| K-02 | First Media Time | `t(first_remote_video_frame)` oder `t(first_remote_audio_packet)` minus `t(join_click)` | Frontend Realtime/Media Hooks | p95 <= 3.5 s |
| K-03 | Join Success Rate | `successful_joins / join_attempts` (Window 5 min) | Backend + Frontend Join Events | >= 99.0% |
| K-04 | Reconnect Recovery Rate | `recovered_sessions / reconnect_attempts` | WS/SFU reconnect events | >= 95.0% |
| K-05 | Client Crash/Error Rate | `fatal_call_workspace_errors / active_call_minutes` | Frontend global/runtime errors | <= 0.20 pro 1000 call-min |
| K-06 | Outbound FPS | gemittelter Video-Out FPS pro Publisher | Client Media Stats | p50 >= 20 fps, p95 >= 15 fps |
| K-07 | Inbound FPS (visible set) | gemittelter Video-In FPS für Top-5 sichtbare Teilnehmer | Client Render/Track Stats | p50 >= 20 fps, p95 >= 12 fps |
| K-08 | Encode Time | pro Frame `encode_end - encode_start` (WLVC/WebRTC-Pfad) | Client Codec Stats | p95 <= 20 ms |
| K-09 | Decode Time | pro Frame `decode_end - decode_start` (WLVC/WebRTC-Pfad) | Client Codec Stats | p95 <= 16 ms |
| K-10 | Packet Loss | inbound/outbound packet loss ratio pro Peer | WebRTC getStats / SFU counters | p95 <= 3.0% |
| K-11 | Jitter | WebRTC jitter in ms | WebRTC getStats | p95 <= 30 ms |
| K-12 | RTT | round-trip time in ms | WebRTC getStats | p95 <= 180 ms |
| K-13 | SFU CPU | CPU je SFU-Instanz | Host/Container metrics | <= 70% steady, <= 85% peak |
| K-14 | SFU RAM | RSS/Working Set je SFU-Instanz | Host/Container metrics | <= 75% steady, <= 90% peak |
| K-15 | Signaling Error Rate | `failed_signaling_events / signaling_events` (`/ws` + `/sfu`) | Backend error envelopes + SFU logs | <= 0.5% |

Messregeln (v1):
- Alle KPIs mit Labels `build`, `env`, `room_size_tier`, `client_device_class`, `codec_stage` erfassen.
- Percentile-Basis: p50/p95 über rolling 5-min und 1-h Fenster.
- Sichtfenster-KPIs (`K-07`) beziehen sich nur auf das aktive Top-5-Window aus #3.
- Bei Fallback-Übergängen (`WLVC -> native`, `native -> degraded`) müssen KPI-Samples `transition_reason` mitführen.

Lastprofile (v1, verbindlich):

| Profil-ID | Szenario | Lastmodell | Dauer | Pass/Fail Kernkriterien |
|---|---|---|---|---|
| L-01 | Burst Join (Tier 1) | 1.000 Teilnehmer treten innerhalb 60s bei (linear + 2x Spike in den ersten 10s) | 15 min | `K-03 >= 99%`, `K-01 p95 <= 800ms`, `K-02 p95 <= 3.5s` |
| L-02 | Burst Join (Tier 2) | 5.000 Teilnehmer innerhalb 180s, 20% mobile Clients | 20 min | `K-03 >= 98.5%`, `K-02 p95 <= 4.5s`, `K-13 peak <= 85%` |
| L-03 | Steady-State Tier 1 | 1.000 gleichzeitig aktiv, 5 sichtbare Streams, rest roster-only | 60 min | `K-06/K-07` Zielwerte halten, `K-15 <= 0.5%`, keine Memory-Drift |
| L-04 | Steady-State Tier 2 | 5.000 gleichzeitig aktiv, Active-Speaker-Wechsel alle 5-10s | 60 min | `K-07 p95 >= 12fps`, `K-10 p95 <= 3%`, `K-14 steady <= 75%` |
| L-05 | Steady-State Tier 3 (Stretch) | 10.000 gleichzeitig aktiv, hartes Top-5-Viewport-Limit | 45 min | Keine instabilen Crash-Loops, `K-04 >= 90%`, kontrollierte Degradation erlaubt |
| L-06 | Emoji/Reaction Flood | 10% der User senden 25-60 Reactions/s über 5 min | 15 min | Batch-Mechanik greift, UI bleibt bedienbar, `K-15 <= 1.0%` |
| L-07 | Chat Flood | 10% der User senden 5-10 Chat-Events/s | 15 min | Priorisierung kritisch vor kosmetisch, kein globaler UI-Lock, bounded queue stabil |
| L-08 | Reconnect Storm | 30% der Clients disconnecten in 10s und reconnecten innerhalb 60s | 20 min | `K-04 >= 95%` (Tier 1/2), keine room-cross leaks, Snapshot-Resync korrekt |
| L-09 | Mixed-Adverse Network | 25% Clients mit hoher RTT/Jitter/packet-loss (simuliert) | 30 min | Fallback/Degradation kontrolliert, Audio bleibt stabil, keine Massenabbrüche |
| L-10 | Public Access Burst | 2.000 Access-Link Session-Issues in 2 min (`/api/call-access/*/session`) | 15 min | Session-Issuing stabil, keine ungebundenen Room-Joins, `K-01` unter Ziel |
| L-11 | Activity Flood | 10% der User senden 4-8 `participant/activity` Updates/s plus 2% Gesture-Spikes | 15 min | Server-Coalescing greift, `layout/*` bleibt bedienbar, `K-15 <= 1.0%`, keine Snapshot-Aufblähung |

Profilparameter (global):
- Client-Mix: 50% desktop, 30% laptop/tablet, 20% phone.
- Codec-Stufen: primär WLVC-WASM; bei Faults nativer WebRTC-Fallback gemäß #2.
- Sichtbarkeit: maximal 5 aktive Video-Slots pro Client, rest roster/presence-only.
- Moderation/Role-Events: in jedem Profil mindestens 1 Event pro Minute je 100 Teilnehmer.

Mess-/Ausführungsregeln:
- Jedes Profil separat mit Warmup (5 min), Messphase, Cooldown (3 min) fahren.
- Ergebnis pro Profil als `PASS`, `PASS_WITH_DEGRADATION`, `FAIL` klassifizieren.
- `PASS_WITH_DEGRADATION` ist nur für L-05 und L-09 zulässig, wenn Audio+Signaling stabil bleiben.

Alerting-Schwellen (v1, verbindlich):

| Alert-ID | KPI/Signal | Warning | Critical | Bewertungsfenster | Aktion |
|---|---|---|---|---|---|
| A-01 | `K-03 Join Success Rate` | < 99.0% | < 98.0% | rolling 5 min | Warning: on-call informieren; Critical: Incident + Join-Rate-Limiter prüfen |
| A-02 | `K-01 Join API Latency p95` | > 900 ms | > 1200 ms | rolling 5 min | Backend/API-Triage, DB-/Session-Pfad prüfen |
| A-03 | `K-02 First Media Time p95` | > 4.0 s | > 6.0 s | rolling 5 min | SFU/Signaling/Client-Media Startup prüfen |
| A-04 | `K-04 Reconnect Recovery Rate` | < 95% | < 90% | rolling 10 min | WS/SFU stability incident, reconnect storm mitigation |
| A-05 | `K-06/K-07 FPS` | p95 < 12 fps | p95 < 8 fps | rolling 10 min | Degradation policy prüfen, active-window pressure reduzieren |
| A-06 | `K-08 Encode p95` | > 22 ms | > 30 ms | rolling 5 min | Codec-stage audit, fallback transitions prüfen |
| A-07 | `K-09 Decode p95` | > 18 ms | > 25 ms | rolling 5 min | Decoder pressure, rendering hot path analysieren |
| A-08 | `K-10 Packet Loss p95` | > 4.0% | > 7.0% | rolling 5 min | Network/SFU routing incident, quality downshift erlauben |
| A-09 | `K-11 Jitter p95` | > 40 ms | > 70 ms | rolling 5 min | Path quality incident, retransmit/fec strategy prüfen |
| A-10 | `K-12 RTT p95` | > 220 ms | > 350 ms | rolling 5 min | Regional routing/backhaul check |
| A-11 | `K-13 SFU CPU` | > 80% sustained | > 90% sustained | 5 min sustained | Autoscale/overflow routing, hot room rebalance |
| A-12 | `K-14 SFU RAM` | > 85% sustained | > 92% sustained | 5 min sustained | Memory pressure incident, pod recycle policy |
| A-13 | `K-15 Signaling Error Rate` | > 0.8% | > 1.5% | rolling 5 min | signaling contract regression triage |
| A-14 | Session endpoint failures (`/api/auth/session`, `/api/call-access/*/session`) | > 1.0% 5xx | > 3.0% 5xx | rolling 5 min | auth/session incident, deploy rollback check |
| A-15 | WS endpoint errors (`websocket_*`, `system/error` spikes) | > 2x baseline | > 4x baseline | rolling 10 min vs 24h baseline | realtime gateway incident |

Alerting-Regeln (Betrieb):
- Severity-Mapping: `Warning` => Ticket + On-call Ping, `Critical` => Incident-Channel + Eskalation.
- Mute/Recover-Hysterese:
  - Alert feuert erst nach 2 aufeinanderfolgenden verletzten Fenstern.
  - Recovery erst nach 3 aufeinanderfolgenden gesunden Fenstern.
- Noise-Guard:
  - Während geplanter Lasttests (`env=loadtest`) nur sammeln, kein Pager.
- Bei bekannten externen Provider-Störungen nur einmalige Sammelmeldung pro 30 min.
- Pflichtfelder pro Alert-Event:
  - `alert_id`, `severity`, `kpi_id`, `window`, `build`, `env`, `room_size_tier`, `codec_stage`, `region`.

Smoke + Load + Chaos in CI/Ops (v1, dokumentiert):

CI-Stufenmodell:
- Stage 0: Fast Smoke (pro Push/PR)
  - Quelle: `.github/workflows/ci.yml` + `demo/video-chat/scripts/smoke.sh`.
  - Enthält: Syntax-Gates, Compose-Boot, `/health`, `/api/runtime`, Auth-Login/Session-Sanity, Kern-Contract-Tests.
  - Ziel: schneller Fail bei Build-/Runtime-/Contract-Bruch.
- Stage 1: Nightly Load (geplant, `main` + optional release branches)
  - Ausführung der Lastprofile L-01 bis L-04 als Pflicht, L-05 optional als Stretch.
  - Ergebnis: KPI-Report (p50/p95, Success-Rates) + PASS/PASS_WITH_DEGRADATION/FAIL.
- Stage 2: Chaos Campaign (geplant, nightly/weekly)
  - Fault-Injection auf SFU/WS/API/Netzpfad, inkl. Reconnect-Storm-Szenarien.
  - Ziel: Recovery und Failover-Verhalten gegen A-Alerts absichern.

Smoke-Gates (Ist, bereits vorhanden):
- Canonical CI-Job ruft Compose-Smoke mit festen Ports auf (`VIDEOCHAT_SMOKE_COMPOSE_ONLY=1`).
- `smoke.sh` validiert:
  - backend/frontend launcher syntax,
  - compose up/down + migrations/auth sanity,
  - contract checks (`session-auth`, `refresh`, `logout`, `rbac`, `realtime-*`, `wlvc-wire`, invite/call signaling),
  - frontend WLVC contract + dev boot check.

Load-Test-Dokumentation (v1 Soll):
- Trigger:
  - nightly workflow_dispatch + schedule (UTC nachts),
  - manuell vor Release-Cut verpflichtend.
- Inputs:
  - Profil-ID (`L-01..L-10`), Zielumgebung, Dauer, Ramp-Pattern.
- Outputs (Artefakte):
  - `load-summary.json` (profilweise KPI p50/p95, pass/fail),
  - `alerts-during-load.json`,
  - komprimierte Rohmetriken (`metrics.ndjson.gz`).
- Gate-Regel:
  - Release-Kandidat nur, wenn Pflichtprofile (L-01..L-04, L-08) nicht `FAIL` sind.

Chaos-Test-Dokumentation (v1 Soll):
- Fault-Typen:
  - SFU Instanz-Restart/kill,
  - künstliche RTT/Jitter/packet-loss-Erhöhung,
  - kurzzeitige API-5xx-Spikes (`/api/auth/session`, `/api/call-access/*/session`),
  - WS connection churn (disconnect/reconnect bursts).
- Erfolgskriterien:
  - Reconnect-Recovery gemäß `K-04`,
  - keine room-cross leaks,
  - keine dauerhaften Critical-Alerts > 15 min.
- Pflichtnachweise:
  - Incident-Timeline, betroffene KPIs, Recovery-Zeit, Postmortem-Notiz.

Ops-Ausführung/Runbook:
- Vor Teststart:
  - `env` label auf `loadtest`/`chaos` setzen (Pager-Muting nach Alerting-Regeln).
  - Baseline-Snapshot (24h Vergleich) erfassen.
- Während Test:
  - Live-Dashboard für K-01..K-15 + A-01..A-15 überwachen.
  - Abbruchkriterien:
    - Datenverlust-/Security-Anomalie,
    - Critical-Alert > 30 min ohne Recovery.
- Nach Test:
  - Ergebnis klassifizieren (PASS/PASS_WITH_DEGRADATION/FAIL),
  - offene Maßnahmen als Folge-Issues aufnehmen.

---

### #10 Repo-Hygiene und Artefakt-Grenzen (Implementierung)

Ziel:
- Keine Build-/Tool-Artefakte mehr im Produktpfad.

Checklist:
- [x] `.gitignore` für generierte WASM/Build-Outputs prüfen und härten.
- [x] CI-Check einbauen: keine `.vite`, keine CMake-Buildtrees, keine transienten Binary-Artefakte.
- [x] Dokumentieren, welche WASM-Artefakte versioniert sein dürfen.

WASM-Versionierungsregel (verbindlich):
- Erlaubt zu versionieren (produktrelevant):
  - Quellcode/Headers unter:
    - `demo/video-chat/frontend-vue/src/lib/wasm/**/*.cpp`
    - `demo/video-chat/frontend-vue/src/lib/wasm/**/*.h`
  - Laufzeitartefakte (vom Frontend direkt geladen):
    - `demo/video-chat/frontend-vue/src/lib/wasm/wlvc.wasm`
    - `demo/video-chat/frontend-vue/src/lib/wasm/wlvc.js`
    - `demo/video-chat/frontend-vue/src/lib/wasm/wlvc.d.ts`
- Nicht versionieren (immer generiert/transient):
  - `demo/video-chat/**/src/lib/wasm/build/**`
  - `demo/video-chat/**/dist/**` (inkl. `dist/assets/wlvc-*.wasm`)
  - `.vite`-Caches, CMake-Buildtrees, Objekt-/Library-Binaries (`*.o`, `*.a`, `*.so`, ...)
- Release-Regel:
  - Änderungen an `wlvc.wasm` müssen immer zusammen mit passendem `wlvc.js` und den zugehörigen C++-Quellen committed werden (kein Einzel-Blob-Update).

Notizen:
- 2026-04-15: Root-`.gitignore` gehärtet für `frontend-vue` Build-Outputs (`.vite`, `dist`) sowie generische CMake-Buildtree-Artefakte (`CMakeFiles`, `CMakeCache.txt`, `cmake-build-*`, `cmake_install.cmake`, `compile_commands.json`).
- 2026-04-15: CI-Guard `infra/scripts/check-repo-artifact-hygiene.sh` ergänzt und in `.github/workflows/ci.yml` (Shard 1) verdrahtet.

Definition of done:
- Branch bleibt sauber, Merges bringen keine Build-Leichen zurück.

---

### #11 CI Smoke Stabilisierung (Compose + Runtime Readiness) (Implementierung)

Ziel:
- `VIDEOCHAT_SMOKE_COMPOSE_ONLY=1` darf nicht mehr mit frühem `curl`-Fehler (`22/500`) abbrechen, solange der Stack noch hochfährt.

Checklist:
- [x] Root-Cause dokumentiert: bisheriger Fail war bei frühen HTTP-Probes ohne Retry auf `/api/runtime`/Auth-Endpoints.
- [x] `smoke.sh` gehärtet: Retry-Windows für `/health`, Frontend `/`, `/api/runtime`, Login und Session-Probe.
- [x] Einheitlicher Compose-Debug-Dump bei Fehlern ergänzt (`compose ps` + Backend-Logs), statt nacktem `curl`-Exitcode.
- [x] PHP-Image-Autowahl robust gemacht: nicht mehr `strings | head -1`, sondern API-Kandidaten + Host-PHP-API Matching.
- [x] Log-Ausgabe erweitert (`king_extension_api_candidates`, `host_php_api`) zur schnelleren CI-Diagnose.

Definition of done:
- Compose-Smoke läuft stabiler unter langsamem Start.
- Failures liefern deterministische Diagnose statt unklarer `curl`-Codes.

Notizen:
- Reproduktionskommando: `VIDEOCHAT_SMOKE_COMPOSE_ONLY=1 bash demo/video-chat/scripts/smoke.sh`.
- Bei erzwungenem API-Mismatch (`VIDEOCHAT_SMOKE_COMPOSE_BACKEND_PHP_IMAGE=php:8.5-cli-trixie` mit 8.4-`king.so`) wird jetzt sauber mit Container-Diagnose abgebrochen.

---

### #12 Security Findings Triage (2026-04-15)

Ziel:
- Neue Findings aus Security-Scan dokumentiert bewerten und in klare Folgeschritte überführen.

Checklist:
- [x] **Tar option injection** (`verify-release-supply-chain.sh`) verifiziert als bereits mitigiert.
- [x] **Demo backend userId spoofing** als Demo-scope Risiko einsortiert (nicht Core-Runtime).
- [x] **McpHost STOP ohne Auth** als Demo/userland-scope Risiko einsortiert.
- [x] Für Demo-scope Findings explizite Hardening-Policy ergänzen (akzeptiert vs. absichern).

Status je Finding:
- `Tar option injection in supply-chain verification script`:
  - Status: **geschlossen/mitigiert**.
  - Beleg: `infra/scripts/verify-release-supply-chain.sh` nutzt Pfadvalidierung (`archive_entry_path_is_safe`) und extrahiert mit `tar -xOf "${archive}" -- "${manifest_entry}"`.
- `Demo backend accepts client-supplied userId enabling spoofing`:
  - Status: **informational / demo-scope**.
  - Scope: `demo/video-chat/dev-backend.mjs` (legacy demo backend, nicht aktiver `backend-king-php` Produktpfad).
  - Folge: Hardening-Entscheidung dokumentieren (z. B. explizit nur localhost/dev, oder verbindliches Token/Server-assigned identity).
- `McpHost accepts unauthenticated STOP/shutdown commands`:
  - Status: **informational / demo-scope**.
  - Scope: `demo/userland/flow-php/src/McpHost.php` (repo-lokale Helper).
  - Folge: STOP-Gate optional absichern (token/allowlist) oder Demo-only im Vertrag explizit markieren.

Hardening-Policy:
- `demo/video-chat/SECURITY_HARDENING.md` definiert die Entscheidungsklassen `geschlossen/mitigiert`, `absichern`, `akzeptiert/demo-only` und `entfernen`.
- `demo/video-chat/scripts/check-security-hardening-policy.sh` prueft statisch, dass die Policy und die vorhandenen Kontrollen fuer Tar-Extraction, entfernten Legacy-`dev-backend.mjs` und loopback-only `McpHost STOP` nicht auseinanderlaufen.

---

### #13 Server Edge Deployment Baseline (TLS + WS Routing) (verworfen)

Ziel:
- War: die TLS/Reverse-Proxy-Lücke für `/ws` und `/sfu` als lauffähiges Edge-Baseline schließen.
- Aktuell: `demo/video-chat/deploy` wurde verworfen/entfernt; kein Edge-Deploy-Pack im Repo.

Checklist:
- [x] Entscheidung dokumentiert: Edge-Deploy-Pack aus dem Repo entfernt.
- [ ] Falls erneut benötigt: neue Deploy-Implementierung separat und bewusst einführen.

Definition of done:
- Der aktive Demo-Scope bleibt auf `frontend-vue` + `backend-king-php` ohne internes Edge-Deploy.

---

### #14 TURN Integration Baseline (zurückgestellt)

Ziel:
- STUN-only-Status beenden und TURN-Relay als baselinefähig bereitstellen.

Checklist:
- [ ] TURN-Service/Deployment aktuell nicht im Repo verdrahtet (deploy-Pfad entfernt).
- [x] Frontend-ICE-Server-Konfiguration über `VITE_VIDEOCHAT_ICE_SERVERS` (JSON) verdrahtet.
- [ ] TURN Credential Rotation + Secret Manager Binding (Vault/KMS) erzwingen.
- [ ] End-to-end NAT-Matrix-Tests (mobile/restrictive NAT) ergänzen.

Definition of done:
- TURN ist nicht nur dokumentiert, sondern als deploybare Basis konfigurierbar.

---

### #15 Secret-Management + Config Hardening (Plan/Implementierung)

Ziel:
- Keine Demo-Secrets in realen Deployments und klare Secret-Übergabepfade.

Checklist:
- [ ] Keine Edge-Deploy-Vorlage mehr im Repo; Secret-Management bei künftigem Deploy-Pfad neu definieren.
- [ ] Mandatory Secret Sources (Vault/KMS/CI-Secret) in Deploy-Skripten erzwingen.
- [ ] Fail-closed Start, falls Demo-/Default-Secrets erkannt werden.
- [ ] Rotations-Runbook für JWT/Session/TURN-Credentials dokumentieren.

Definition of done:
- Deployment startet nicht mehr mit unsicheren Defaults.

---

### #16 Multi-Node Runtime Architektur (Planung)

Ziel:
- Übergang von Single-Node/SQLite auf skalierbare Multi-Node-Architektur.

Checklist:
- [ ] Zustandsmodell splitten: Session/Auth, Call State, Roster/Presence, Realtime Fanout.
- [ ] Persistenzstrategie definieren (SQLite-Ersatz + Migrationspfad).
- [ ] Inter-Node Signaling/Coordination (Bus/Queue) für `/ws` + `/sfu` festlegen.
- [ ] Rolloutplan mit Zero-Downtime-Migration dokumentieren.

Definition of done:
- Es gibt einen verbindlichen Architektur- und Migrationsvertrag für Multi-Node.

---

### #17 Ops Hardening: Metrics/Alerts/Backup/Rollback (Implementierung)

Ziel:
- Operative Mindesthärtung für Betrieb und Incident-Recovery liefern.

Checklist:
- [x] SQLite-Backup-Skript ergänzt (`demo/video-chat/scripts/backup-sqlite.sh`).
- [ ] Restore-Skript + Restore-Drill dokumentieren.
- [ ] Zentrale Metrics/Logs/Alerts Pipeline verdrahten (mind. K-01..K-15, A-01..A-15).
- [ ] Rollout/Rollback-Runbook pro Deployment-Variante ergänzen.

Definition of done:
- Backup/Restore ist reproduzierbar und Kern-Operational-Metriken sind zentral sichtbar.

---

### #18 Chat-Limits, Auto-Datei-Konvertierung und sichere Attachments (Implementierung)

Ziel:
- Chat darf keine riesigen WebSocket-Payloads akzeptieren, muss aber lange Inhalte und Dateien nutzbar machen.
- Große Text-/Paste-Inhalte werden automatisch als Datei-Anhang gespeichert und als Download im Chat angeboten.
- Uploads laufen nicht als Base64-Blob über `/ws`, sondern bounded über HTTP/King Object Store plus realtime Metadaten-Fanout.

Vertragsentscheidung:
- Inline-Chat bleibt kurz: maximal `2.000` Unicode-Zeichen und maximal `8 KiB` UTF-8 Payload.
- Größere Texte werden nicht verworfen, sondern clientseitig in einen Text-Anhang umgewandelt.
- Auto-Dateiformat:
  - Default: `.txt`
  - CSV-Erkennung: mehrzeilige tabellarische Struktur mit konsistenten Trennzeichen -> `.csv`
  - Markdown-Erkennung: Überschriften/Listen/Code-Fences/Links -> `.md`
  - User kann vor dem Senden den vorgeschlagenen Dateinamen/Typ ändern.
- Backend bleibt fail-closed: direkte oversized `chat/send` Frames werden weiterhin abgelehnt, wenn sie nicht als Attachment referenziert sind.

Checklist:
- [x] Frontend-Composer limitiert Inline-Text auf `2.000` Zeichen und `8 KiB` Bytes.
- [x] Paste-Handler erkennt oversized Text und wandelt ihn automatisch in einen Attachment-Draft um.
- [x] Auto-Datei-Draft zeigt Dateiname, Typ (`txt`, `csv`, `md`), Größe und Preview-Ausschnitt.
- [x] Chat-Send unterstützt Nachrichten mit Text plus Attachment-Refs.
- [x] Backend-Contract für `chat/send` um `attachments[]` erweitern, ohne alte reine Textnachrichten zu brechen.
- [x] HTTP Upload-Endpunkt für Chat-Dateien ergänzt: `POST /api/calls/{call_id}/chat/attachments`.
- [x] Uploads schreiben in den King Object Store; SQLite speichert Metadaten/Refs/Status und die Teilnehmer-ACL wird serverseitig aus dem Call geprüft.
- [x] Object-Keys sind call- und room-scoped als flache King-Object-IDs ohne Slash, weil der Store Pfadseparatoren ablehnt.
- [x] Download-Endpunkt mit Auth/Call-Teilnehmerprüfung ergänzt: `GET /api/calls/{call_id}/chat/attachments/{attachment_id}`.
- [x] Dateien werden erst nach erfolgreichem Object-Store-Commit als Chat-Message referenziert.
- [x] Fehlgeschlagene/stornierte Upload-Drafts werden bereinigt und nicht im Chat sichtbar; unveröffentlichte Drafts können per `DELETE` entfernt werden.
- [x] Realtime-Event `chat/message` trägt Attachment-Metadaten (`id`, `name`, `content_type`, `size_bytes`, `kind`, `extension`, `download_url`).
- [x] Attachments sind downloadbar, aber niemals inline ausführbar.

Erlaubte Dateitypen:
- [x] Bilder: `jpg`, `jpeg`, `png`, `webp`, `gif` bis maximal 10 Bilder pro Nachricht.
- [x] Text/Tabellen/Markdown: `txt`, `csv`, `md`.
- [x] PDF: `pdf`.
- [x] Office/OpenDocument: `doc`, `docx`, `xls`, `xlsx`, `ppt`, `pptx`, `odt`, `ods`, `odp`.
- [x] Serverseitige MIME-/Magic-Byte-Prüfung; Extension allein reicht nicht.
- [x] OOXML/ODF Container prüfen, weil sie ZIP-basiert sind; beliebige ZIPs bleiben verboten.
- [x] Explizite Blocklist: `exe`, `dll`, `com`, `bat`, `cmd`, `ps1`, `sh`, `js`, `msi`, `jar`, `app`, `deb`, `rpm`, rohe Archive (`zip`, `rar`, `7z`, `tar`, `gz`) und sonstige unbekannte Binaries.

Limits und Quotas:
- [x] Maximal 10 Bilder pro Nachricht.
- [x] Maximal 10 Attachments pro Nachricht insgesamt.
- [x] Vorschlag v1: Bilder maximal `8 MiB` je Datei, Office/PDF maximal `25 MiB` je Datei.
- [x] Vorschlag v1: `100 MiB` Gesamtlimit pro Chat-Nachricht.
- [x] Vorschlag v1: call-weite Soft-Quota und harte Quota konfigurieren, damit ein Call nicht den Object Store flutet.
- [x] Backend liefert strukturierte Fehlercodes: `chat_inline_too_large`, `attachment_type_not_allowed`, `attachment_too_large`, `attachment_count_exceeded`, `chat_storage_quota_exceeded`.

Definition of done:
- Große Pastedaten führen nicht zu Browser-/WS-Freeze und nicht zu oversized WS Frames.
- Dateien sind sicher typvalidiert, persistent gespeichert, downloadbar und room-/call-scoped autorisiert.
- Keine ausführbaren oder unbekannten Binärdateien können über den Chat verteilt werden.

Tests:
- [x] Backend Contract: Inline-Limit, oversized direct send reject, Attachment-Metadaten, Download-ACL.
- [x] Backend Contract: Allowlist/Blocklist/Magic-Byte-Erkennung inkl. OOXML/ODF.
- [x] Frontend/Playwright: Paste > Limit erzeugt Datei-Draft statt Chat-Text.
- [x] Playwright + Backend-Fanout-Contract: großer Paste wird zu `.txt`/`.md`/`.csv` Attachment und Attachment-Metadaten erreichen andere Room-Teilnehmer.
- [x] Playwright: bis zu 10 Bilder werden clientseitig akzeptiert, das 11. Attachment wird sichtbar abgelehnt.
- [x] Playwright: PDF/Office Upload-Drafts funktionieren, `.exe`/unbekannte Binary wird sichtbar abgelehnt.
- [x] Backend Contract: Download-Link funktioniert nur für eingeloggte berechtigte Call-Teilnehmer.

---

### #19 Persistenter Chat-Verlauf und Read-only Archivmodal (Implementierung)

Ziel:
- Chat und Dateien bleiben nach dem Call für registrierte Teilnehmer verfügbar.
- Die Call-/Chatübersicht erhält ein zusätzliches Icon, über das registrierte User den Chat read-only öffnen.
- Das Archivmodal zeigt links den Chatverlauf und rechts die Dateien/Attachments.

Checklist:
- [x] Chat-Events append-only persistieren: Message-Metadaten in DB, Payload/Transcript-Snapshots im King Object Store.
- [x] Attachment-Refs aus #18 in denselben Archivindex aufnehmen.
- [x] Persistenzmodell für `call_chat_messages`, `call_chat_attachments`, `call_chat_acl` definieren.
- [x] Read-only API ergänzen, z. B. `GET /api/calls/{call_id}/chat-archive`.
- [x] Archivantwort paginieren/cursorbasiert laden; keine vollständigen Monster-Verläufe auf einmal.
- [x] Dateien rechts im Modal gruppieren: Bilder, PDFs, Office, Text/CSV/MD.
- [x] Linke Modalspalte: chronologischer Chat, Sender, Zeit, Text, Attachment-Chips.
- [x] Rechte Modalspalte: Datei-Liste mit Name, Typ, Größe, Sender, Timestamp und Download-Aktion.
- [x] Suche/Filter im Archiv definieren: Textsuche, Senderfilter, Dateitypfilter.
- [x] Nur registrierte User mit Call-Teilnahme/Call-Berechtigung dürfen das Archiv sehen.
- [x] Gäste ohne Account erhalten keinen dauerhaften Archivzugriff.
- [x] Admin/Owner/Moderator Berechtigungen für Archivzugriff und ggf. Retention/Export definieren.
- [x] Retention-Policy definieren: Standard-Aufbewahrung, Löschpfad bei Call-Löschung/DSGVO-Anfrage.
- [x] Exportoption optional vorbereiten: `.json`/`.md` Transcript plus Attachment-Liste.

Definition of done:
- Nach Call-Ende kann ein registrierter berechtigter User aus der Call-/Chatübersicht das Archiv öffnen.
- Modal ist read-only, stabil paginiert und zeigt Chat links sowie Dateien rechts.
- Downloads nutzen dieselben ACLs wie Live-Chat-Attachments.

Tests:
- [x] Backend Contract: Archiv-API nur für berechtigte registrierte User.
- [x] Backend Contract: Gast/Unbeteiligter/Admin-RBAC-Grenzen.
- [x] Backend Contract: Pagination, Attachment-Gruppierung, Download-ACL.
- [x] Playwright: User öffnet Chatübersicht, klickt Chat-Archiv-Icon, Modal zeigt Chat links und Dateien rechts.
- [x] Playwright: Read-only Verhalten; keine Eingabe-/Sendeaktion im Archiv möglich.
- [x] Playwright: Datei-Download aus Archiv funktioniert für berechtigten User und scheitert für unberechtigten User.

---

### #20 Speaker-/Movement-Awareness und Admin-Layoutstrategien (Implementierung)

Ziel:
- Das Video-Layout reagiert sinnvoll auf Sprecher und sichtbare Aktivität.
- Admin/Owner kann im Call Layout-Modus und Aktivitätsstrategie wählen.
- Große Calls bleiben kontrolliert: maximal definierte Video-Slots, Activity-Score statt chaotischer Client-Entscheidungen.

Activity-Index Vertrag:
- [x] Pro Teilnehmer wird ein `activity_score` geführt.
- [x] Audio-Quelle: WebRTC Audio-Level/VAD/Speaking-State mit kurzer Glättung.
- [x] Bewegung-Quelle: lokale Frame-Differenz/Motion-Metrik für sichtbare Gesten/Bewegung.
- [x] Optionaler Gesture-Hinweis: Winken/Handbewegung erhöht Score stärker als normale Hintergrundbewegung.
- [x] Privacy-Regel: keine Rohframes für Activity-Erkennung an Backend senden; nur normalisierte Scores/Events.
- [x] Score hat Zeitzerfall, z. B. Fenster `2s`, `5s`, `15s`.
- [x] Server/SFU aggregiert Score serverautoritativ und verteilt nur kompakte `participant/activity` Updates.
- [x] Scores werden rate-limited/coalesced, damit Activity nicht den Realtime-Kanal flutet.

Admin-Layoutmodi:
- [x] Linke Sidebar erhält Layout-Icons für:
  - Grid: bis zu 8 gleich große Videos.
  - Main + Mini: ein Hauptvideo plus vertikale/Story-Mini-Videos.
  - Main only: nur Hauptvideo.
- [x] Layoutmodus wird als Call-State persistiert und an Teilnehmer synchronisiert.
- [x] Pinned User überschreiben automatische Activity-Auswahl.
- [x] Host/Admin kann Layoutstrategie je Call setzen.

Aktivitätsstrategien:
- [x] `manual_pinned`: Admin/Pinning entscheidet; Activity beeinflusst nur Hinweise/Badges.
- [x] `most_active_window`: aktivste Teilnehmer im aktuellen Fenster besetzen Main + Mini-Videos.
- [x] `active_speaker_main`: aktivster Teilnehmer der letzten ca. `2s` wechselt ins Main-Video, vorheriger Main rückt in den Mini-Stack.
- [x] `round_robin_active`: bei ähnlich aktiven Teilnehmern wird fair rotiert, damit nicht eine Person dauerhaft alles blockiert.
- [x] Hysterese/Cooldown definieren, damit Main-Video nicht bei jedem Audio-Spike flackert.
- [x] Admin kann automatische Wechsel pausieren.
- [x] Teilnehmerliste zeigt optional Activity-Indikator, aber ohne störendes Dauerblinken.

Backend/SFU Aufgaben:
- [x] WS/SFU Eventvertrag für `participant/activity`, `layout/mode`, `layout/strategy`, `layout/selection`.
- [x] Serverseitige Validierung: nur Admin/Owner/Moderator darf Layoutmodus/Strategie ändern.
- [x] Activity-Score darf nicht von beliebigen Clients gefälscht werden; SFU/WebRTC-Metriken bevorzugen.
- [x] Reconnect/Join bekommt aktuellen Layout-State im Snapshot.
- [x] Lastprofil aus #9 um Activity-Flood ergänzen.

Frontend Aufgaben:
- [x] Sidebar-Icons für Layoutmodi ergänzen.
- [x] Admin-Strategie-Auswahl sichtbar und verständlich machen.
- [x] Video-Renderer kann Grid bis 8, Main+Mini und Main-only sauber darstellen.
- [x] Mini-Stack aktualisiert sich ohne Track-Leaks.
- [x] Main-Video-Wechsel animieren/debouncen, ohne Schwarzbild-Flicker.

Definition of done:
- Admin kann Layout und Strategie im Call ändern; alle Teilnehmer sehen konsistent dieselbe Auswahl.
- Activity-basierte Umschaltung ist stabil, nachvollziehbar und flackert nicht.
- Bewegung/Sprechen erhöht den Activity-Index, aber Privatsphäre bleibt gewahrt.

Tests:
- [x] Backend Contract: Layout-/Strategy-Commands RBAC-geschützt.
- [x] Backend Contract: Activity-Events werden normalisiert, rate-limited und snapshot-fähig.
- [x] Frontend Unit: Layout-Auswahl priorisiert Pinning vor Activity.
- [x] Playwright: Admin schaltet Grid/Main+Mini/Main-only, User sieht Layoutwechsel.
- [x] Playwright: simulierte Speaking-/Activity-Events verschieben Main/Mini nach Strategie.
- [x] Playwright: Pinning verhindert automatische Verdrängung aus Main.
- [x] Playwright: Activity-Pause stoppt automatische Main-Wechsel.

---

### #21 Playwright E2E Matrix für Chat, Dateien und Activity (Implementierung)

Ziel:
- Alle neuen Chat-/Attachment-/Archiv-/Activity-Funktionen erhalten browsernahe Regressionstests.
- Tests laufen deterministisch mit zwei Accounts und kontrollierten Fake-Medien/Fake-Dateien.

Checklist:
- [x] Test-fixtures für erlaubte Dateien: `txt`, `csv`, `md`, `pdf`, `docx`, `xlsx`, `odt`, `png`, `jpg`, `webp`.
- [x] Test-fixtures für verbotene Dateien: `exe`, `sh`, unbekannter Binary-Blob, umbenannte Binary mit erlaubter Extension.
- [x] Fake-Media Setup für Audio-Level/Speaking und Motion-Events definieren.
- [x] E2E: Paste > Limit -> Auto-Datei -> Senden -> zweiter Teilnehmer sieht Datei.
- [x] E2E: Drag-and-drop 10 Bilder -> Senden -> zweiter Teilnehmer sieht 10 Bildattachments.
- [x] E2E: 11 Bilder -> sichtbare Ablehnung.
- [x] E2E: PDF/Office Upload -> Download funktioniert.
- [x] E2E: verbotener Dateityp -> Upload blockiert, keine Chatmessage.
- [x] E2E: Chatarchiv nach Call-Ende öffnen, read-only prüfen, Dateien rechts prüfen.
- [x] E2E: unberechtigter User kann Archiv/Downloads nicht öffnen.
- [x] E2E: Admin Layout-Icons + Strategiewechsel.
- [x] E2E: Activity-Score beeinflusst Main/Mini nach Strategie.
- [x] E2E: Pinning/Manual-Modus überschreibt Activity.
- [x] CI-Gate ergänzen, damit neue Chat-/Activity-Flows nicht nur manuell getestet sind.

Definition of done:
- Relevante User-Journeys sind als Playwright-Spezifikationen abgedeckt.
- Tests laufen gegen `docker-compose.v1.yml` stabil und ohne externe Services.
- Fehlerfälle sind sichtbar geprüft, nicht nur Happy Path.

## Persistente Research-Notizen (für Folgesessions)

- Alex-Relevanz (historisch, inzwischen nach `frontend-vue/src/lib/**` konsolidiert):
  - `78f4f5c` fügt `src/lib/sfuClient.ts` hinzu.
  - `e5d65b7` brachte SFU/WASM-Inhalte plus Artefakte; später bereinigt.
- WASM-Fallback heute:
  - vorhanden auf TS-WLVC-Ebene (Ist, nicht Ziel).
  - Ziel: nativer WebRTC-Fallback ohne TS-WLVC-Runtime-Fallback.
- Blur-Referenz (`intelligent-intern`) ist weit fortgeschritten:
  - Controller + Backend-Selector + Stream-Processing + Gates + Prefs + Tests.
- `demo/media-gateway` enthält Rust-SFU-Skizze mit AMQP/Signaling/QUIC und JWT-Checks; Integrationsentscheidung offen.
