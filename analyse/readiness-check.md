# Video Call And KingRT Readiness Check

Stand: 2026-05-10

## Verdict

Nicht release-ready. Die Plattform hat viele einzelne Bausteine, aber der Video Call hat noch keinen einfachen, autoritativen v1-Media-Vertrag. Der groesste technische Blocker ist nicht "ein weiterer Bugfix", sondern die fehlende zentrale Entscheidung: Welche Clients duerfen wie senden, wer entscheidet das, und welcher Zustand gilt fuer alle Teilnehmer?

## Readiness Matrix

| Bereich | Ist-Stand | Readiness | Naechste Entscheidung |
| --- | --- | --- | --- |
| King Edge | Routet Static, API, WS, Call-App-Assets und optional SFU. SFU ist am Edge per Env abschaltbar. | Teilbereit | Edge-Kontrakt fuer v1 festlegen: API + WS + Call-App Assets, kein versteckter Media-Bypass. |
| Realtime Backend | `module_realtime.php` bindet WebSocket, Presence, Lobby, GossipMesh und SFU-Module ein. | Teilbereit | Media-Signaling vom allgemeinen Realtime-State trennen und v1-State explizit machen. |
| Auth, Call Access, Lobby | IAM/Call-Access-Arbeit ist umfangreich vorhanden; Lobby/Guest-Join bleiben als v1-relevant. | Teilbereit | Guest-Admission weiter als Produktvertrag pruefen, nicht als Medien-Fallback vermischen. |
| Client Capability Detection | Frontend erkennt lokal WLVC/WASM und WebRTC-Basisfaehigkeit in `runtimeCapabilities.ts`. | Nicht ausreichend | Capability-Report muss nach Join an Backend/Orchestrator gehen und call-weit sichtbar werden. |
| 720p30 Capture | `strictStabilityPolicy.ts` definiert 1280x720@30 und `mediaOrchestration.ts` nutzt exakte Constraints. | Teilbereit | Fallback-Pfade aus dem aktiven v1-Vertrag entfernen: kein Auto-Downgrade, kein Qualitaets-Experiment. |
| Media Orchestrator | Es gibt Frontend-Orchestration fuer UI, Roster, Runtime-Switching und Security-Sync. Kein zentraler Media-Plan. | Nicht bereit | Backend-orchestrierter `media_session_plan.v1` mit Zustandsmaschine. |
| SFU | Code, Compose-Service, Backend-Route und Tests existieren; Edge kann SFU deaktivieren. | Nicht bereit | Fuer v1 entweder explizit als Transport planen oder komplett aus dem aktiven Vertrag parken. |
| Gossip | Topologie/Telemetry/Recovery-Code existiert, aber kein klares v1-Media-Protokoll. | Nicht bereit | Nur nach neuem v1-Protokoll wieder aktivieren, nicht als impliziter Notausgang. |
| MediaSecurity | Sender-Key/Participant-Set-Logik existiert und kann derzeit Medienfluss blockieren. | Nicht bereit | Fuer v1 klaeren: entweder produktreifer Sicherheitsvertrag oder aus aktivem Media-Pfad herausnehmen. |
| Background Segmentation | Background- und Avatar-Fallback-Pfade existieren. | Geparkt | Nicht in Stabilisierung mischen. Fuer v1 spaeter als Feature, nicht als Media-Basis. |
| Call Apps | Sessions, Launch-Token, CRDT, Entfernen, Grants und interne Diagnostics sind vorhanden. | Teilbereit | Call Apps koennen bleiben, aber sie duerfen den Media-Zustand nicht treiben. |
| Diagnostics | Client-Diagnostics, Call-Diagnostics-App, Telemetry-Snapshot und Admin-Infra existieren. | Teilbereit | Diagnostics als Beobachtung behalten, nicht als automatische Reparaturmaschine. |
| Operations | Admin Infrastructure und Video Operations liefern Snapshots; Deploy-Smoke ist nicht Release-Gate. | Teilbereit | Release-Readiness-Gate definieren, getrennt von Staging-Smoke. |
| Markdown/Planung | Repo-Root ist auf `README.md`, `SPRINT.md` und `BACKLOG.md` reduziert; Analyse liegt in `analyse/`, Historie im Archiv. | Teilbereit | `BACKLOG.md` weiter auf offene Punkte kuerzen und keine neuen Themen-MDs im Root anlegen. |

## V1 Contract Needed

Der Video Call braucht fuer v1 einen kleinen, harten Vertrag:

1. Client joint den Call ueber Auth/Lobby.
2. Client sendet `client.capabilities.v1` ueber den authentifizierten WebSocket.
3. Backend speichert die Capabilities pro Call-Participant.
4. Orchestrator berechnet `media_session_plan.v1` fuer den Call.
5. Jeder Client sendet nur, was im Plan erlaubt ist.
6. Kein automatisches Qualitaets-Hoch/Runter, keine Regression-Probes, keine Background-Tab-Policy-Reparaturen.
7. Wenn 720p30 nicht geht, bekommt der Teilnehmer einen klaren Zustand, statt dass der Client heimlich auf Experimente ausweicht.

## Minimal Capability Payload

```json
{
  "schema_version": "king.video.client_capabilities.v1",
  "client_id": "participant-session-id",
  "media": {
    "camera_720p30": true,
    "microphone": true,
    "screen_share": true
  },
  "runtime": {
    "websocket": true,
    "webrtc": true,
    "webassembly": true,
    "webcodecs": false,
    "gpu": "available_or_unknown"
  },
  "constraints": {
    "video_width": 1280,
    "video_height": 720,
    "video_fps": 30
  }
}
```

Keine Tokens, keine SDP, keine ICE-Rohdaten, keine Cookies, keine Frames.

## Release Gate Fuer Video Call v1

- [ ] Ein einziger aktiver v1-Media-Pfad ist definiert.
- [ ] Capability Exchange ist Backend-sichtbar und call-weit synchronisiert.
- [ ] Orchestrator setzt pro Teilnehmer einen eindeutigen Zustand.
- [ ] 720p30 wird strikt geprueft; nicht passende Clients werden klar markiert.
- [ ] Alte automatische Quality-Recovery-, Regression-, Background- und Repair-Pfade sind nicht im aktiven v1-Vertrag.
- [ ] Diagnostics zeigen den Zustand, loesen aber keine verdeckten Reparaturen aus.
- [ ] Tests pruefen den neuen Vertrag, nicht alte SFU/Gossip/Regression-Annahmen.

## Zusaetzliche Analyseanker

- `analyse/video-call-v1-codebase-map.md` kartiert die relevanten Codebereiche.
- `analyse/video-call-v1-contract-map.md` trennt proven behavior, offene
  v1-Entscheidungen, geparkte Pfade und neue Contract-Testbedarfe.
