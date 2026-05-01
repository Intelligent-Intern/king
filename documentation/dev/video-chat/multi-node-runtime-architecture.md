# Multi-Node Runtime Architecture Contract

Status: verbindlicher Architektur- und Migrationsvertrag fuer die Video-Chat-Runtime.

Kurzfassung: Multi-Node verlangt geteilte Persistenz, geteilte Presence, inter-node bus, SFU-Topology und einen zero-downtime Rollout mit sauberem rollback.

Dieses Dokument schliesst Issue #16 als Architekturvertrag. Es aktiviert noch keinen Multi-Node-Cluster, legt aber verbindlich fest, wie die aktuelle Single-Node-/SQLite-Runtime in eine skalierbare King-Runtime ueberfuehrt wird, ohne die bestehenden Realtime-, Lobby-, Chat-, SFU- und Auth-Vertraege zu schwaechen.

## Nicht verhandelbare Laufzeitregeln

- Die Runtime bleibt server-authoritative. Clients duerfen Call-, Lobby-, Presence-, Chat- und SFU-Zustand nicht lokal erfinden.
- Es gibt no manual Refresh fuer Realtime-Zustand. Korrekturpfade laufen ueber WebSocket-Events, Snapshots, Reconnect und Backfill.
- Ein Call ist global durch seine Call-ID/Room-ID identifiziert. Ein Join auf Node B darf nie einen zweiten isolierten Call neben dem Owner-Call auf Node A erzeugen.
- Jede Mutation wird vor Persistenz und vor Fanout autorisiert. Bus-Events sind kein Trust-Boundary-Ersatz.
- SQLite bleibt nur Single-Node-/Dev-Storage. Produktions- oder Multi-Node-Betrieb muss eine geteilte Persistenz und einen Inter-Node-Bus verwenden.

## Zustandsmodell

### Session/Auth

Dauerhafter Session/Auth-Zustand liegt in einem shared SQL store oder einem gleichwertigen King-Durable-Adapter:

- `users`, Rollen, Aktivierungsstatus, Login-Identitaeten und Passwort-/Credential-Metadaten.
- `sessions` mit opaque `sess_...` Tokens, Ablauf, Revocation-Status und letztem Seen-Zeitpunkt.
- Auth-Events wie Login, Logout, Session-Refresh, Revocation und Role-Change werden ueber `videochat.session.{session_id}.control` publiziert.
- Jeder `/ws`- und `/sfu`-Worker revalidiert Session-Liveness bei Connect, Reconnect und sicherheitsrelevanten Kommandos.
- Eine spaetere JWT-Variante ist nur zulaessig, wenn Key-Management, Rotation und Revocation mindestens denselben Vertrag wie die aktuelle opaque Session erfuellen.

### Call State

Call State ist durable, transaktional und nicht pro Prozess gespeichert:

- Calls, Zeitfenster, Owner, Rollen, Access-Mode, Invite-Codes, Access-Links und Teilnehmerzuordnungen.
- Call-Participant-State mit mindestens `invited`, `pending`, `admitted`, `cancelled`, `removed` und Moderator-/Owner-Rechten.
- Lobby-Mutationen sind atomar und idempotent: `invited -> pending`, `pending -> admitted`, `pending -> invited` bei Cancel/Disconnect, `pending -> removed` bei Deny.
- Join-Entscheidungen lesen ausschliesslich den durable Call State plus aktuelle Presence. Ein bereits eingeladener User darf dadurch noch nicht ohne Gate in den Call.
- Chat- und Attachment-Metadaten werden durable gespeichert; groessere Payloads liegen im King Object Store.

### Roster/Presence

Roster/Presence ist ephemeral, aber node-uebergreifend konsistent:

- Presence-Eintraege sind keyed nach `call_id`, `room_id`, `user_id`, `session_id`, `connection_id` und `node_id`.
- Jeder Eintrag hat eine presence TTL und wird per Heartbeat verlaengert. Fehlt der Heartbeat, wird der Eintrag automatisch entfernt.
- Pending-Lobby-User sind nur `pending`, solange das Join-Modal bzw. die Admission-Verbindung aktiv ist. Disconnect oder Modal-Cancel setzt den Participant-State zurueck auf `invited`.
- Roster-Snapshots werden aus geteilter Presence plus durable Call State abgeleitet, nicht aus lokalen Arrays einzelner Worker.
- Reconnect liefert einen Snapshot mit monotonic Version, damit Clients verpasste Deltas sauber ersetzen koennen.

### Realtime Fanout

Realtime Fanout laeuft ueber einen inter-node bus mit klaren Event-Grenzen:

- Chat, Typing, Reactions, Lobby, Roster, Speaker-Activity, Layout-Activity und Signaling haben eigene Eventtypen.
- Jedes Event traegt `event_id`, `room_id`, optional `call_id`, `sender_user_id`, `sequence` oder `created_at`, optional `target_user_id` und einen Dedupe-Key.
- Per-Room-Reihenfolge ist fuer Chat, Lobby und Roster erforderlich. Ephemeral Events wie Typing, Reaction-Bursts oder Activity duerfen gedroppt werden, aber nicht in falsche Raeume leaken.
- Fanout-Worker liefern nur an autorisierte Verbindungen, deren Session und Room-Membership zum Zeitpunkt der Zustellung gueltig sind.
- Backpressure priorisiert Auth, Lobby, Roster, Chat-Ack und Signaling vor Typing/Reactions/Activity.

### Media/SFU

Die SFU-Runtime darf nicht pro Node einen eigenen Call bilden:

- `/sfu`-Worker registrieren Publisher, Subscriber, Tracks, Codec-Metadaten und Node-Zuordnung in einer geteilten SFU-Topology.
- Teilnehmer in demselben `call_id`/`room_id` sehen dieselbe Publisher-Liste, egal an welchem Node sie haengen.
- Targeted Signaling und SFU-Control laufen ueber autorisierte Bus-Events, nicht ueber Prozesslokalitaet.
- Node-Ausfall entfernt Publisher per TTL/Heartbeat und erzeugt Roster/SFU-Deltas fuer alle betroffenen Teilnehmer.
- Codec-/WASM-/WLVC-Erweiterungen duerfen nur als Payload-/Track-Faehigkeit an diesen Vertrag andocken; sie duerfen Raumidentitaet und Admission nicht umgehen.

## Persistenzstrategie

Zielbild:

- Durable relational state: shared SQL store, bevorzugt Postgres oder ein King-Durable-SQL-Adapter mit vergleichbarer Transaktionssemantik.
- Durable Dateien und groessere Chat-/Attachment-Payloads: King Object Store mit Metadaten/ACL im relationalen Store.
- Ephemeral Presence und Locks: Redis-aehnliches TTL-KV oder King Distributed KV mit Heartbeat und Expiry.
- Realtime Coordination: inter-node bus mit mindestens per-Room-Ordering fuer kritische Streams, z.B. NATS, Redis Streams oder King Queue mit aequivalentem Vertrag.
- SQLite bleibt Dev-/Single-Node-Fallback fuer lokale Demos und Tests. Multi-Node darf SQLite nicht als geteilte Wahrheit verwenden.

Migrationspfad von SQLite:

1. Schema-Freeze fuer alle Call/Auth/Chat/Participant-Tabellen und Export eines reproduzierbaren SQLite-Snapshots.
2. Storage-Abstraktion hinter den bestehenden PHP-Modulen einfuehren, ohne API- oder WS-Vertraege zu veraendern.
3. Shadow-Import in den shared SQL store und Read-Verify fuer Counts, IDs, Foreign Keys, Participant-State und Chat/Attachment-Metadaten.
4. dual-write fuer neue Mutationen: Primaer in shared SQL, SQLite als Shadow nur waehrend der Verifikationsphase.
5. read switch per Feature-Flag auf shared SQL, mit Vergleichsmetriken und Fail-Closed bei Invariantenbruch.
6. SQLite-Write nach erfolgreicher Canary-Phase abschalten, SQLite-Export weiter als Rollback-Artefakt aufbewahren.
7. SQLite-Code nur entfernen, wenn Restore-/Rollback-Tests und Multi-Node-Contract-Tests stabil sind.

## Inter-Node Signaling und Coordination

Bus-Topics:

- `videochat.room.{room_id}.fanout` fuer Chat, Typing, Reactions, Roster und Activity.
- `videochat.call.{call_id}.lobby` fuer Invite/Pending/Admit/Deny/Cancel.
- `videochat.call.{call_id}.signaling` fuer WebRTC-Offer/Answer/ICE und targeted Signaling.
- `videochat.call.{call_id}.sfu` fuer Publisher/Subscriber/Track/Codec-Control.
- `videochat.session.{session_id}.control` fuer Logout, Revocation, Role-Change und Session-Expiry.
- `videochat.node.heartbeat` fuer Node-Registry und Worker-Liveness.

Regeln:

- Publish ist nur nach serverseitiger Autorisierung erlaubt.
- Deliver ist nur an aktuell autorisierte Sessions erlaubt.
- Kritische Kommandos sind idempotent und deduplizieren per `event_id`/`command_id`.
- Targeted Signaling prueft Sender-Membership, Target-Membership und Self-Target-Block vor Publish und erneut vor Deliver.
- Ein Node-Neustart erfordert Snapshot-Backfill fuer Roster, Lobby, Chat-Cursor und SFU-Topology.

## Zero-Downtime Rollout

Phase 0, Ist-Zustand:

- Single Node, SQLite, lokale Runtime-Strukturen, bestehender Compose-Devpfad.

Phase 1, Abstraktion und Mirror:

- Storage-Adapter einfuehren.
- Shared SQL Store und Bus read-only bzw. shadow befuellen.
- Contract-Tests bleiben gegen bestehende APIs und WS-Protokolle unveraendert.

Phase 2, dual-write:

- Mutationen in shared SQL + SQLite schreiben.
- Metriken fuer Divergenz, fehlende Dedupe-Keys, kaputte Foreign Keys und State-Machine-Verletzungen erfassen.
- Rollback: Feature-Flag auf SQLite-Reads zurueck, Bus-Publish deaktivieren, Shadow-Store verwerfen oder neu importieren.

Phase 3, Canary-Reads:

- Einzelne interne Calls lesen aus shared SQL und nutzen inter-node bus.
- `/ws` und `/sfu` laufen auf mindestens zwei Nodes mit sticky-freiem Reconnect-Test.
- Rollback: Canary-Nodes drainen, Sessions ueber `videochat.session.{session_id}.control` neu verbinden lassen.

Phase 4, Rolling Switch:

- Alle Nodes nutzen shared SQL, Presence TTL Store und Bus.
- WebSocket-Verbindungen werden nodeweise gedraint; Clients erhalten Reconnect und Snapshot-Backfill.
- SFU-Publisher werden per Topology-Snapshot wiederhergestellt oder sauber als disconnected markiert.

Phase 5, SQLite-Abbau:

- SQLite bleibt nur lokales Dev/Test-Profil.
- Produktionsstart scheitert fail-closed, wenn Multi-Node ohne shared SQL, presence TTL Store oder inter-node bus konfiguriert wird.

## Acceptance Gates

Ein Multi-Node-Implementierungs-Issue darf erst geschlossen werden, wenn diese Gates automatisiert sind:

- Session-Revocation auf Node A trennt `/ws` und `/sfu` auf Node B.
- Lobby-Pending von Node B erzeugt Badge/Listeneintrag beim Owner auf Node A.
- Admit auf Node A laesst den wartenden User auf Node B in denselben Call, nicht in einen neuen Call.
- Chat-Fanout erreicht alle autorisierten Teilnehmer ueber mehrere Nodes mit Dedupe und Reihenfolge.
- Targeted Signaling und ICE leaken nicht in falsche Calls, falsche Raeume oder an nicht zugelassene User.
- SFU-Publisher eines Users auf Node B erscheint als Mini-Video/Userlisten-Eintrag beim Owner auf Node A.
- Presence-Disconnect setzt `pending` zurueck auf `invited`, wenn der User noch nicht admitted wurde.
- Restore-Test importiert SQLite-Snapshot in shared SQL und validiert Teilnehmer-/Chat-/Attachment-Invarianten.
- Load-Test deckt mindestens zwei API-Nodes, zwei WS-Nodes und einen SFU-Node-Pool ab.

## Implementierungs-Schnitt

Dieses Issue liefert den Vertrag. Folge-Issues muessen einzelne Implementierungsblaetter daraus schneiden:

- Storage-Adapter + shared SQL Schema.
- Presence TTL Store + Heartbeat.
- Inter-Node-Bus + Fanout-Dedupe.
- Lobby-State-Machine ueber durable Participant-State.
- SFU-Topology-Registry + Cross-Node-Publisher-Snapshot.
- Zero-Downtime-Migrations- und Rollback-Automation.
