# Analyse Index

Stand: 2026-05-10

Diese Analyse ist der neue Sammelpunkt fuer den Video-Call- und KingRT-Readiness-Stand. Sie ersetzt keine Implementierung und loescht keine Historie; sie macht sichtbar, was aktuell belastbar ist, was Altlast ist und welche Architektur fuer v1 gebraucht wird.

## Kurzfazit

Video Call ist noch nicht release-ready. KingRT hat tragfaehige Bausteine fuer Auth, Calls, Realtime, Call Apps, Diagnostics und Infrastruktur-Snapshots, aber der Media-Teil ist noch kein sauber orchestrierter v1-Vertrag. Es gibt eine strikte 720p30-Policy, gleichzeitig existieren noch SFU-, Gossip-, MediaSecurity-, Background- und Recovery-Pfade aus frueheren Experimenten.

Die naechste Architekturentscheidung sollte nicht "noch ein Fallback" sein. Fuer v1 braucht der Call einen expliziten Capability-Austausch, einen zentralen Orchestrator und ein verbindliches State-Management. Ein Client, der den v1-Vertrag nicht erfuellt, bekommt keinen stillen Qualitaets-Fallback, sondern einen klaren Zustand wie `video_unavailable`, `receive_only` oder `blocked_capability`.

## Dokumente

- [Demo Program Complete Audit](demo-program-complete-audit.md): Vollstaendiges `demo/`-Inventar, Dateiklassifizierung, tote/seltsame Kandidaten und technische Uebersicht.
- [Video Call Streaming v1 Gap Analysis](video-call-streaming-v1-gap-analysis.md): Aktuelles Streaming-Ziel, Ist-/Soll-Architektur, Mermaid-Diagramme, Gap-Tabelle und Stabilisierungsplan.
- [Video Call v1 Readiness Check](video-call-v1-readiness-check.md): Harte Readiness-Checkliste fuer den aktuellen Call-Zielvertrag.
- [CEO Briefing: Video Call v1](ceo-briefing-video-call-v1.md): Kurzfassung fuer Fuehrungsentscheidung.
- [Readiness Check](readiness-check.md): Release-Faehigkeit fuer Video Call und KingRT.
- [Architecture](architecture.md): Aktuelle Architektur und Zielbild als Mermaid-Diagramme.
- [Implementation Protocol](implementation-protocol.md): Warum die aktuellen Teile existieren und wo sie vom Zielbild abweichen.
- [Video Call v1 Codebase Map](video-call-v1-codebase-map.md): Codebereich-fuer-Codebereich Karte fuer Frontend, Backend, Ops und Tests.
- [Video Call v1 Contract Map](video-call-v1-contract-map.md): Konsolidierter Media-Vertrag mit Capability Exchange, Orchestrator, State Machine, aktiven/geparkten Pfaden und Testentscheidungen.
- [CEO Briefing](ceo-briefing.md): Management-Zusammenfassung ohne Code-Details.
- [MD Cleanup Plan](md-cleanup-plan.md): Kanonische Markdown-Struktur und Archivierungsplan.

## Arbeitsregeln fuer diese Analyse

- Der aktuelle Stand ist v1-Bauphase, kein oeffentlicher Release.
- Bestehende Smoke-/Deploy-Checks sind Umgebungschecks, keine Release-Freigabe.
- Keine neuen Abwaertskompatibilitaets-Fallbacks als Ersatz fuer fehlende Architektur.
- Background, Gossip, SFU und MediaSecurity werden hier nur bewertet, nicht weitergebaut.
- Root-Markdown bleibt kanonisch auf `README.md`, `SPRINT.md` und `BACKLOG.md`
  begrenzt; thematische Analyse liegt hier in `analyse/`.
