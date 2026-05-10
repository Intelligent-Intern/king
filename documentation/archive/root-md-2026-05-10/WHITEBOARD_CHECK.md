# Whiteboard Call App Abnahmeformular

Dieses Formular ist eine Vorlage fuer die manuelle Abnahme. Es ist bewusst nicht
als bestanden ausgefuellt.

## Metadaten

- Datum: Auszufuellen
- Release/Commit: Auszufuellen
- Umgebung: Auszufuellen
- Call-ID: Auszufuellen
- Tester: Auszufuellen
- Browser/Geraete: Auszufuellen

## Voraussetzungen

- [ ] Whiteboard Call App ist fuer die Organisation installiert und aktiviert.
- [ ] Test-Call enthaelt Owner, Moderator, Participant und Guest.
- [ ] Owner kann Teilnehmerrechte fuer die Call App aendern.
- [ ] Browser-Konsole und Backend-Logs werden fuer Diagnosen beobachtet.

## Diagnose-Nachweise

- Launch-token failures:
  - Beobachtung: Auszufuellen
  - Erwartetes Event: `call_app_launch_token_failed`
- Grant changes:
  - Beobachtung: Auszufuellen
  - Erwartetes Event: `call_app_grants_changed`
- CRDT append latency:
  - Beobachtung: Auszufuellen
  - Erwartetes Event: `call_app_crdt_append_latency`
- CRDT replay latency:
  - Beobachtung: Auszufuellen
  - Erwartetes Event: `call_app_crdt_replay_latency`
- Duplicate suppression:
  - Beobachtung: Auszufuellen
  - Erwartetes Event: `call_app_crdt_duplicate_suppressed`
- Snapshot compaction:
  - Beobachtung: Auszufuellen
  - Erwartetes Event: `call_app_crdt_snapshot_compacted`
- Iframe bridge errors:
  - Beobachtung: Auszufuellen
  - Erwartetes Event: `call_app_iframe_bridge_error`

## Manuelle Call Checks

### Owner

- [ ] Whiteboard aus der Call Apps Sidebar auswaehlen und anhaengen.
- [ ] Default-Zugriff fuer Teilnehmer setzen.
- [ ] Zeichnen, Text, Sticky Note, Undo, Redo testen.
- [ ] Teilnehmerrechte fuer mindestens einen Nutzer aendern.
- Ergebnis: Auszufuellen

### Moderator

- [ ] Moderator sieht Owner-nahe Call Controls.
- [ ] Moderator kann berechtigte Teilnehmer in der Call App verwalten, wenn die
  Call-Rolle das erlaubt.
- [ ] Moderator kann selbst im Whiteboard arbeiten, wenn Grant `allowed` ist.
- Ergebnis: Auszufuellen

### Participant

- [ ] Participant sieht Whiteboard im Call App Workspace.
- [ ] Participant kann schreiben, wenn Grant `allowed` ist.
- [ ] Participant sieht read-only Zustand, wenn nur Lesezugriff besteht.
- Ergebnis: Auszufuellen

### Guest

- [ ] Guest kann ueber den Call Link beitreten.
- [ ] Guest erhaelt keine primaeren Session-Token im Iframe.
- [ ] Guest-Zugriff folgt Default Policy oder explizitem Guest Grant.
- Ergebnis: Auszufuellen

### Revoked Participant

- [ ] Bereits geoeffnetes Whiteboard verliert Schreibrecht nach Revocation.
- [ ] Weitere CRDT Append Requests werden abgelehnt.
- [ ] Reconnect mit altem Launch Token wird verweigert.
- Ergebnis: Auszufuellen

### Reconnect

- [ ] Teilnehmer verlaesst den Call und tritt erneut bei.
- [ ] Whiteboard bootstrapped Snapshot und Replay korrekt.
- [ ] Keine doppelten Operationen werden sichtbar.
- Ergebnis: Auszufuellen

### Export

- [ ] PNG Export erzeugt eine nutzbare Datei.
- [ ] PDF Export erzeugt eine nutzbare Datei.
- [ ] Export nutzt nur Call App Daten, keine primaeren Session-Tokens.
- Ergebnis: Auszufuellen

## Offene Punkte

- Blocker: Auszufuellen
- Risiken: Auszufuellen
- Nacharbeit: Auszufuellen

## Freigabe

- Status: Auszufuellen
- Verantwortliche Person: Auszufuellen
- Zeitpunkt: Auszufuellen
