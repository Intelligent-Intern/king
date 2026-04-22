# King Sprint Issues

> Sprint: Q-Batch, 2026-04-22
> Fokus: Quiche aus dem aktiven HTTP/3-/QUIC-Pfad entfernen und durch einen sauberen, reproduzierbaren C-basierten Stack ersetzen.

## Sprint-Regeln

- Dieses Dokument enthaelt nur offene Sprint-Arbeit.
- Erledigte Issues werden in dem Commit geloescht, der sie abschliesst.
- Quiche wird aus dem aktiven HTTP/3-/QUIC-Produktpfad entfernt.
- Der bestehende King-v1-Vertrag bleibt erhalten: HTTP/3, QUIC, TLS, Session-Tickets, 0-RTT, Stream-Lifecycle, Cancel, Stats, Listener und WebSocket-over-HTTP3.
- Kein Stub-Ersatz: ein neuer Loader muss echte Runtime-Symbole laden, initialisieren und durch Wire-Tests belegt sein.
- Keine lokalen Pfade, besonders keine Homebrew-Pfade wie `/opt/homebrew/Cellar/...`.
- Kein Quiche-getriebener Rust-/Cargo-Bootstrap im HTTP/3-Produktpfad.
- Keine generierten Test-Resultate, Buildtrees, libtool/phpize-Churn oder lokale Lockfiles als Sprint-Output.

## Offene Issues

### #Q-3 Dependency-Provenance fuer neuen Stack

Ziel:
- Reproduzierbare Pins fuer den neuen QUIC/HTTP3-Stack schaffen.

Checklist:
- [x] Release-/Commit-Pins fuer den neuen Stack festlegen.
- [ ] Checksums und Quellen in `DEPENDENCY_PROVENANCE.md` dokumentieren.
- [ ] Neues Bootstrap-Lockfile erstellen, z. B. `infra/scripts/lsquic-bootstrap.lock`.
- [ ] Alte `quiche-bootstrap.lock` und `quiche-workspace.Cargo.lock` als zu entfernende Quiche-Artefakte erfassen.
- [ ] Offline-/CI-Validierung fuer Pins und Checksums ergaenzen.

Pin-Entscheidung:

| Komponente | Quelle | Pin | Commit | Zweck |
| --- | --- | --- | --- | --- |
| LSQUIC | `https://github.com/litespeedtech/lsquic.git` | `v4.6.1` | `c1ca7980107b1495298c93ab54e798fa050c3c7b` | C-basierter QUIC/HTTP3-Stack fuer den aktiven Produktpfad; aktueller Release-Pin oberhalb der dokumentierten `v4.3.1`-Mindestlinie. |
| BoringSSL | `https://github.com/google/boringssl.git` | `0.20260413.0` | `e1acfa3193d44166ce77df74c5285afea983fc63` | Reproduzierbarer TLS-Backend-Pin ohne System-ABI- oder Homebrew-Abhaengigkeit. |
| LSQUIC-Submodule | rekursiv aus LSQUIC `v4.6.1` | wird im neuen Lockfile fixiert | wird im neuen Lockfile fixiert | Keine floating Submodule; `git submodule status --recursive` muss in `lsquic-bootstrap.lock` einfliessen. |

Pin-Regeln:

- Keine floating Branches wie `master`, `main` oder lokale Checkout-Pfade.
- Das neue Bootstrap-Lockfile muss URLs, Tags, Commits, rekursive Submodule, Checksums und Lizenzquellen festhalten.
- Wenn `v4.6.1` die King-v1-Paritaet fuer 0-RTT, STOP_SENDING, Stream-Lifecycle, Stats oder WebSocket-over-HTTP3 nicht traegt, ist das ein Blocker und kein Grund fuer Vertragsabbau.
- Die Pins wurden am 2026-04-22 per `git ls-remote --tags --refs` gegen die Upstream-Repositories verifiziert.

Done:
- [ ] Dependencies koennen aus Repo-eigenen Pins reproduzierbar bezogen werden.
- [ ] Aktive Provenance nennt keine Quiche-Pins mehr fuer den Produktpfad.

---

### #Q-4 Build-System ohne lokale Pfade und ohne Cargo-Bootstrap

Ziel:
- `config.m4`, Build-Scripts, CI und Release-Builds auf den neuen C-basierten Stack umstellen.

Checklist:
- [ ] `extension/config.m4` auf portable Detection umstellen: pkg-config, env overrides, system paths oder vendored build outputs.
- [ ] Quiche-Scripts entfernen oder ersetzen: `bootstrap-quiche.sh`, `check-quiche-bootstrap.sh`, `ensure-quiche-toolchain.sh`.
- [ ] Cargo-/Rust-Bootstrap aus dem HTTP/3-Buildpfad entfernen.
- [ ] CI fuer Linux amd64 und arm64 reproduzierbar bauen lassen.
- [ ] macOS/dev nur ueber dokumentierte env/pkg-config Pfade unterstuetzen.
- [ ] Release-Package-Manifeste auf neue Artefakte und neue Provenance umstellen.

Done:
- [ ] Frischer HTTP/3-Build braucht keine lokale Rust-/Cargo-Konfiguration.
- [ ] Frischer HTTP/3-Build braucht keine lokalen Homebrew-Pfade.
- [ ] CI blockiert Quiche-/Cargo-Bootstrap im aktiven HTTP/3-Pfad.

---

### #Q-5 Client-HTTP/3 Loader ersetzen

Ziel:
- `extension/src/client/http3/quiche_loader.inc` durch einen echten Loader fuer den neuen Stack ersetzen.

Checklist:
- [ ] Neuen Loader mit echter Symbolbindung und Initialisierung implementieren.
- [ ] Failure-Stub oder vorgetaeuschte Feature-Checks verhindern.
- [ ] Fehlerpfade auf bestehende King-Exceptions mappen.
- [ ] Runtime-Init, Request/Response, Multi-Request, Ticket-Reuse und Stats anbinden.
- [ ] Alte Quiche-Symbole, Handles und Runtime-Namen entfernen oder migrieren.

Done:
- [ ] `king_http3_request_send()` nutzt den neuen Stack in echten Wire-Tests.
- [ ] OO-HTTP3-Client nutzt den neuen Stack in echten Wire-Tests.
- [ ] Alter Quiche-Loader wird von keinem aktiven Include mehr referenziert.

---

### #Q-6 Server-HTTP/3 Listener ersetzen

Ziel:
- Serverseitige Quiche-Annahmen durch den neuen Stack ersetzen.

Checklist:
- [ ] Server-Loader mit echter Initialisierung implementieren.
- [ ] `king_http3_server_listen_once` und Listener-Pfade auf neuen Runtime-Kontext umstellen.
- [ ] Request-Header, Body-Drain, Early-Hints, Response-Normalisierung und CORS-Verhalten unveraendert beweisen.
- [ ] TLS-Reload-, Cancel- und Shutdown-Pfade erhalten.
- [ ] WebSocket-over-HTTP3-Honesty-Slices weiter abdecken.

Done:
- [ ] HTTP/3 Server-Listener laufen auf dem neuen Stack gegen reale Clients/Peers.
- [ ] Kein Serverpfad benoetigt Quiche-Code.

---

### #Q-7 QUIC-Optionen, Stats und Semantik-Mapping

Ziel:
- Bestehende `quic.*` Konfigurationen und Live-Stats korrekt auf den neuen Stack abbilden.

Checklist:
- [ ] Alle `quic.*` Optionen inventarisieren und mappen.
- [ ] Unsupported Optionen fail-closed oder mit expliziter Diagnose behandeln.
- [ ] Live-Stats an echte Runtime-Counter binden.
- [ ] Congestion-Control, pacing, flow-control und idle-timeout verifizieren.
- [ ] Stale bookkeeping fields oder dauerhaft nullende Zaehler verhindern.

Done:
- [ ] Bestehende Stats- und Config-Tests bleiben gruen oder haben gleich starken neuen Nachweis.
- [ ] Dokumentation nennt den neuen Stack, nicht Quiche-Counter.

---

### #Q-8 HTTP/3 Test-Peer-Harness ohne Quiche-/Cargo-Abhaengigkeit

Ziel:
- HTTP/3-Tests auf den neuen Stack migrieren, ohne Quiche/Cargo-Bootstrap.

Checklist:
- [ ] Rust-Test-Peers und Cargo-Locks im HTTP/3-Kontext klassifizieren.
- [ ] Ersatzstrategie festlegen: C-Helfer, King-eigene Listener, CI-Artefakte mit Provenance oder anderer reproduzierbarer Pfad.
- [ ] Tests fuer Handshake-Failure, Transport-Close, Timeout, Flow-Control, Packet-Loss, 0-RTT, Session-Tickets und Multi-Stream-Fairness erhalten.
- [ ] Helper-Binaries deterministisch bauen und nicht als Build-Leichen committen.
- [ ] Skip-Regeln auditieren, damit fehlender neuer Stack nicht als Erfolg zaehlt.

Done:
- [ ] HTTP/3-Tests beweisen den neuen Stack ohne Quiche- oder Cargo-Bootstrap.
- [ ] Temporaere Rust-Helfer sind nicht Produkt-Bootstrap und haben ein Ablauf-Issue.

---

### #Q-9 Quiche-Entfernung aus Source, Scripts und Docs

Ziel:
- Quiche als aktive Dependency vollstaendig entfernen.

Checklist:
- [ ] `extension/src/**/quiche_loader.inc` entfernen.
- [ ] Quiche-spezifische Build-Scripts, Locks und Docs entfernen oder ersetzen.
- [ ] `README.md`, `PROJECT_ASSESSMENT.md`, `READYNESS_TRACKER.md`, `DEPENDENCY_PROVENANCE.md` und `documentation/quic-and-tls.md` aktualisieren.
- [ ] Verbleibende Quiche-Erwaehnungen als historische Notiz markieren oder entfernen.
- [ ] Artifact-Hygiene-Gate um Quiche-/Cargo-Artefakte erweitern.

Done:
- [ ] `rg -n "quiche|QUICHE"` findet keine aktiven Produktpfad-Referenzen mehr.
- [ ] Verbleibende Treffer sind ausschliesslich historische Migrationsnotizen oder Release-History.

---

### #Q-10 CI-, Release- und Supply-Chain-Gates

Ziel:
- Die Migration dauerhaft durch CI und Release-Gates absichern.

Checklist:
- [ ] CI baut den neuen Stack.
- [ ] CI fuehrt HTTP/3 Client/Server Contract-Suites aus.
- [ ] CI blockiert lokale absolute Pfade, Homebrew-Pfade, Cargo-HTTP3-Bootstrap und Quiche-Locks.
- [ ] Release-Supply-Chain-Verification prueft neue Provenance-Pins.
- [ ] Package-Manifeste enthalten neue Dependency-Hashes und keine Quiche-Manifeste.

Done:
- [ ] Ein PR kann Quiche oder lokale Pfade nicht unbemerkt zurueckbringen.
- [ ] Release-Artefakte sind fuer den neuen Stack nachvollziehbar reproduzierbar.

---

### #Q-11 Vollstaendige HTTP/3 Regression gegen neuen Stack

Ziel:
- Beweisen, dass der neue Stack den bisherigen HTTP/3-/QUIC-Vertrag traegt.

Checklist:
- [ ] Client one-shot request/response Tests gruen.
- [ ] OO `Http3Client` Exception-Matrix gruen.
- [ ] Server one-shot listener Tests gruen.
- [ ] Session-ticket und 0-RTT Tests gruen.
- [ ] Stream lifecycle, reset, stop-sending, cancel und timeout Tests gruen.
- [ ] Packet-loss, retransmit, congestion-control, flow-control und long-duration soak gruen.
- [ ] WebSocket-over-HTTP3 relevante Slices gruen.
- [ ] Performance-Baseline gegen vorherigen Quiche-Stand dokumentiert.

Done:
- [ ] Neuer Stack ist auf bestehendem Contract-Level nachgewiesen.
- [ ] Abweichungen sind gefixt oder als neue Blocker-Issues erfasst.

---

### #Q-12 Migrationsabschluss und Repo-Cleanup

Ziel:
- Sprint sauber abschliessen: keine Restartefakte, keine halben Namen, keine alten Buildannahmen.

Checklist:
- [ ] `rg`-Sweep fuer Quiche, Cargo, Rust-HTTP3, lokale Pfade und Stub-Loader abgeschlossen.
- [ ] `git status` enthaelt keine generierten Build- oder Test-Artefakte.
- [ ] Docs, tests, CI und Release-Manifeste referenzieren denselben neuen Stack.
- [ ] Abschlussnotiz in `PROJECT_ASSESSMENT.md` und `READYNESS_TRACKER.md` mit Testbelegen ergaenzt.
- [ ] Migrationsarbeit ist in logische Commits geteilt: Inventur, Build, Client, Server, Tests, Docs/Cleanup.

Done:
- [ ] Quiche ist aus dem aktiven Produktpfad entfernt.
- [ ] HTTP/3/QUIC ist auf dem neuen Stack voll belegt.
- [ ] Repository-Zustand ist artifact-clean und release-faehig.
