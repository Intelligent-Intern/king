# Markdown Cleanup Plan

Stand: 2026-05-10

## Ist-Stand

- Vor dieser Analyse gefunden: 133 Markdown-Dateien bis `maxdepth 3`.
- Nach Anlage von `analyse/`: 139 Markdown-Dateien bis `maxdepth 3`.
- Im Repo-Root vor Cleanup gefunden: 14 Markdown-Dateien.
- Im Repo-Root nach dem ersten Cleanup-Move: 3 Markdown-Dateien
  (`README.md`, `BACKLOG.md`, `SPRINT.md`).
- Restproblem: `documentation/` enthaelt weiterhin historische Evidence,
  Release-Notizen und alte technische Planungen. Der Repo-Root ist bereinigt.

## Zielstruktur

Root soll nur noch diese kanonischen Steuerungsdateien enthalten:

- `README.md`
- `SPRINT.md`
- `BACKLOG.md`

Nicht mehr Root-kanonisch und nach
`documentation/archive/root-md-2026-05-10/` verschoben:

- `EPIC.md`
- `READYNESS_TRACKER.md`

Analyse- und Readiness-Dokumente liegen in:

- `analyse/README.md`
- `analyse/readiness-check.md`
- `analyse/architecture.md`
- `analyse/implementation-protocol.md`
- `analyse/ceo-briefing.md`
- `analyse/md-cleanup-plan.md`

Historische technische Dokumentation bleibt in:

- `documentation/`

## Root-Dateien, die nicht kanonisch bleiben sollten

Diese Dateien wurden aus dem Root entfernt und archiviert, nicht geloescht:

- `ADMIN_UX_ROUTE_AUDIT.md`
- `EPIC.md`
- `GOSSIP_CHECK.md`
- `GOSSIP_CURRENT_BUILD.md`
- `GOSSIP_PLANNING.md`
- `READYNESS_TRACKER.md`
- `SFU_CURRENT_BUILD.md`
- `SFU_PLANNING.md`
- `SPRINT_VIDEOCHAT_CONNECTION_UPGRADE.md`
- `TEST_HARNESS_INSTRUCTIONS.md`
- `WHITEBOARD_CHECK.md`

Weitere Behandlung:

- Aktuelle offene Punkte nach `BACKLOG.md` konsolidieren.
- Bleibende Architektur-/Evidence-Inhalte im Archiv oder in `analyse/` halten.
- Doppelte oder ersetzte Sprint-Details nicht im Root behalten.

## Backlog-Zielbild

`BACKLOG.md` sollte nur offene oder bewusst geparkte Punkte enthalten:

- Video Call v1 Media Contract
- Capability Exchange
- Orchestrator / State Machine
- Guest Join / Lobby Admission
- Call App Follow-ups
- IAM Follow-ups
- Manual/Parked Media Work: Background, Gossip, SFU, MediaSecurity

Nicht in `BACKLOG.md` gehoert:

- abgeschlossene Sprint-Protokolle
- alte Worker-Branch-Archaeologie
- Test-Ausgaben ohne offene Entscheidung
- parallele Sprint-Volltexte

## Sprint-Zielbild

`SPRINT.md` enthaelt genau einen aktiven Sprint mit Checkboxen. Der Root
Markdown Cleanup ist abgeschlossen; aktuell ist die Video Call v1 Media
Contract Analysis aktiv.

- `BACKLOG.md` haelt den geparkten Kontext.
- `SPRINT.md` bleibt die aktive Spitze.

## Naechster Cleanup-Schritt

1. Nicht aktive Sprint-Volltexte im Archiv belassen.
2. `BACKLOG.md` weiter auf offene, aktuelle Tickets kuerzen.
3. Video Call v1 Codebereiche nur in `analyse/` ausarbeiten, nicht im Root.
4. Sobald die Analyse als Sprint abgeschlossen ist, den naechsten aktiven
   Implementierungssprint aus den Backlog-Tickets ziehen.
