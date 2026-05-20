# Implementation Protocol

Stand: 2026-05-10

## Was aktuell umgesetzt ist

1. Edge Routing
   - `demo/video-chat/edge/edge.php` routet Call-App-Assets, `/ws`, `/api`, Static und optional `/sfu`.
   - SFU ist am Edge per `VIDEOCHAT_EDGE_SFU_ENABLED` / `VIDEOCHAT_SFU_ENABLED` abschaltbar.

2. Realtime Backend
   - `demo/video-chat/backend-king-php/http/module_realtime.php` bindet Realtime-WebSocket, Presence, Lobby, GossipMesh und SFU-Module.
   - Das ist historisch gewachsen: Signaling, Lobby, Presence und Media-Experimente liegen noch nah beieinander.

3. Call Apps
   - `module_call_apps.php` stellt Availability, Sessions, Launch-Token, CRDT Bootstrap/Ops/Snapshots, Grants und Session-Remove bereit.
   - `useCallAppIframeBridge.js` uebergibt Launch-Kontext, Capabilities und Permissions sicher an App-Iframes.
   - Package-Quellen, Manifest, MCP/Health-Descriptor, CRDT-Schema und App-Assets gehoeren in `demo/call-apps/<app-key>/`.
   - `demo/video-chat/frontend-vue/src/domain/realtime/callApps` ist Host/Bridge/Shell, nicht App-Quelle.
   - `demo/video-chat/backend-king-php/domain/call_apps` ist Runtime/Session/CRDT/Marketplace, nicht App-Quelle.
   - `demo/video-chat/frontend-vue/dist/call-app` ist Build-Artefakt und keine Quelle.
   - Call Apps sind als Produktbereich weiter sinnvoll, aber sie duerfen nicht die Media-Orchestrierung ersetzen.

4. Diagnostics
   - `clientDiagnostics.ts` sammelt Client-Diagnostics, redacted Payloads und batched Uploads.
   - `callAppDiagnosticTailBridge.js` bridged Tail-Events, Stage-Updates und Telemetry-Snapshots in die Call-Diagnostics-App.
   - `call_app_diagnostics.php` liefert admin-only Telemetry mit CPU-Load, Memory, Containerstatus, aktiven Calls, WebSocket-Zaehlern und Recent Errors.

5. Operations / Infrastructure
   - `infrastructure_inventory.php` kennt Deployment, Nodes, Services, OpenTelemetry-Konfiguration und Scaling-Snapshot.
   - `video_operations.php` berechnet Live-Calls und Concurrent Participants aus frischer Presence, nicht aus Einladungen.

6. Media Client
   - `runtimeCapabilities.ts` erkennt lokal WebRTC-Basisfaehigkeit und WLVC/WASM Encoder/Decoder.
   - `strictStabilityPolicy.ts` definiert eine harte 720p30-Policy mit deaktivierten Quality-Recovery-, Background- und Repair-Flags.
   - `mediaOrchestration.ts` kann exakte 1280x720@30 Constraints anfordern.

## Warum das so entstanden ist

Der aktuelle Code zeigt mehrere historische Phasen:

- Erst wurde ein breites Realtime-Produkt gebaut: Calls, Lobby, Chat, Presence, Call Apps.
- Danach wurden Media-Pfade erweitert: native WebRTC, WLVC/WASM, SFU, GossipMesh, MediaSecurity.
- Danach kamen Diagnostics und Telemetry, um die vielen Fehlerbilder sichtbar zu machen.
- Zuletzt wurde eine strikte 720p30-Policy eingefuehrt, um automatische Qualitaets-Experimente und Reparaturpfade zu bremsen.

Das erklaert die Komplexitaet, rechtfertigt sie aber fuer v1 nicht mehr. Fuer v1 muss die Architektur wieder kleiner und autoritativer werden.

## Wo der aktuelle Stand vom v1-Ziel abweicht

| Thema | Abweichung |
| --- | --- |
| Capability Exchange | Es gibt lokale Probes, aber keinen call-weiten, backend-autoritativen Capability-Austausch. |
| Orchestrator | `orchestration.ts` orchestriert UI/Roster/Socket-Nebenwirkungen, nicht den verbindlichen Media-Plan. |
| State Management | Participant-, Media-, Security-, Runtime- und Recovery-State sind ueber mehrere Module verteilt. |
| Media Transport | SFU, Gossip, native WebRTC, WLVC und MediaSecurity sind noch gleichzeitig als Denkmodell sichtbar. |
| Fallbacks | Trotz strikter Policy existieren weiterhin Codepfade fuer Audio-only, receive-only, loose constraints und alte Recovery-Mechanismen. |
| Tests | Einige Tests pruefen alte SFU/Gossip/Regression-Annahmen und passen nicht mehr zu einem kleinen v1-Vertrag. |
| Docs | Root-Markdown ist bereinigt; `README.md`, `SPRINT.md` und `BACKLOG.md` bleiben Root-kanonisch. Analyse liegt in `analyse/`, Historie im Archiv. |

## Entscheidung fuer den naechsten technischen Sprint

Nicht weiter an einzelnen Recovery-Symptomen ziehen. Der naechste technische Sprint sollte den v1-Media-Vertrag bauen:

- `client.capabilities.v1`
- `media_session_plan.v1`
- `call_media_state.v1`
- klare Zustandsuebergaenge
- Diagnostics nur beobachtend
- keine automatische Qualitaetsverbesserung
- keine Regression-Probes
- keine Background-Tab-Reparatur als aktiver Media-Vertrag
- keine implizite SFU/Gossip-Reparatur

## Source Anchors

- `demo/video-chat/edge/edge.php`
- `demo/video-chat/backend-king-php/http/module_realtime.php`
- `demo/video-chat/backend-king-php/http/module_call_apps.php`
- `demo/video-chat/backend-king-php/domain/call_apps/call_app_diagnostics.php`
- `demo/video-chat/backend-king-php/domain/infrastructure/infrastructure_inventory.php`
- `demo/video-chat/backend-king-php/domain/operations/video_operations.php`
- `demo/video-chat/frontend-vue/src/domain/realtime/media/runtimeCapabilities.ts`
- `demo/video-chat/frontend-vue/src/domain/realtime/local/mediaOrchestration.ts`
- `demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/strictStabilityPolicy.ts`
- `demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/runtimeSwitching.ts`
- `analyse/video-call-v1-codebase-map.md`
- `analyse/video-call-v1-contract-map.md`
