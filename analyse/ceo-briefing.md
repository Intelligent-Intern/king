# CEO Briefing

Stand: 2026-05-10

## Executive Summary

KingRT ist noch in der v1-Bauphase. Es gibt keinen Release-Stand. Die aktuelle Umgebung kann fuer Smoke-Checks und interne Tests dienen, aber nicht als Produktfreigabe.

Der Video Call ist der groesste offene Blocker. Das Problem ist nicht nur ein einzelner Fehler, sondern ein zu breites Media-System: SFU, Gossip, WLVC, native WebRTC, MediaSecurity, Background, Quality-Recovery und Diagnostics existieren parallel. Dadurch ist schwer zu erkennen, welcher Zustand gerade gilt und warum Video sichtbar oder nicht sichtbar ist.

Die richtige v1-Entscheidung ist ein kleiner harter Vertrag: Clients melden ihre Faehigkeiten, KingRT entscheidet zentral, wer 720p30 senden darf, und alle Teilnehmer bekommen denselben Media-Plan. Was den Vertrag nicht erfuellt, wird nicht versteckt downgraded, sondern bekommt einen klaren Zustand.

## Status Ampel

| Bereich | Status | Bedeutung |
| --- | --- | --- |
| KingRT Core | Gelb | Auth, Calls, API, WS, Call Apps und Diagnostics sind weit, aber noch nicht release-final. |
| Video Call Media | Rot | Kein sauberer zentraler Media-Vertrag, zu viele parallele Pfade. |
| Call Apps | Gelb | Grundsystem und mehrere Apps existieren; weitere Stabilisierung noetig. |
| Diagnostics | Gelb | Gute Basis, aber derzeit eher Beobachtung als Release-Gate. |
| Operations | Gelb | Snapshots und Telemetry existieren, Release-Readiness-Gate fehlt. |
| Dokumentation/Planung | Gelb | Root ist bereinigt auf `README.md`, `SPRINT.md`, `BACKLOG.md`; Analyse liegt in `analyse/`, historische Root-Themen sind archiviert. |

## Wichtigste Risiken

1. Media-Komplexitaet blockiert Stabilitaet.
2. Alte Recovery-Pfade koennen neue Fehler erzeugen.
3. Tests koennen noch alte SFU/Gossip/Regression-Annahmen absichern, obwohl das nicht mehr der gewuenschte v1-Vertrag ist.
4. Deploy-/Smoke-Erwartungen koennen vom aktiv gestarteten Media-Pfad abweichen, besonders bei SFU.

## Empfohlene Entscheidung

Fuer v1 keine weiteren Fallback-Features bauen. Stattdessen:

- Capability Exchange als Pflicht.
- Orchestrator als zentrale Wahrheit.
- State Machine als einziger Media-Zustand.
- 720p30@30fps als harter Sendestandard.
- Nicht passende Clients sichtbar markieren, nicht heimlich degradieren.
- SFU/Gossip/Background/MediaSecurity nur wieder aufnehmen, wenn sie in diesen Vertrag passen.

## Naechste Fuehrungsentscheidung

Der naechste technische Sprint sollte `client.capabilities.v1`,
`media_session_plan.v1` und `call_media_state.v1` bauen. Erst danach werden
SFU/Gossip/Native/MediaSecurity als explizit geplante Pfade wieder als
Release-Gates bewertet.
