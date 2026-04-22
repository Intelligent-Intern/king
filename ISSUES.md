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

### #Q-1 Quiche-/Rust-/Cargo-Inventur

Ziel:
- Alle Quiche-, Rust-, Cargo- und HTTP/3-Testhelper-Abhaengigkeiten im aktuellen Baum erfassen und klassifizieren.

Checklist:
- [x] `quiche`-Referenzen in Source, Headers, Tests, Docs, CI, Release-Scripts und Provenance erfassen.
- [x] Rust-/Cargo-Pfade im HTTP/3-Kontext erfassen: `Cargo.toml`, `Cargo.lock`, `.rs`, Workspace-Locks, Bootstrap-Scripts.
- [x] Jede Fundstelle klassifizieren: `remove`, `replace`, `rename`, `keep-temporary`, `keep-unrelated`.
- [x] Unrelated Rust im Repo vom Quiche-/HTTP3-Rust trennen.
- [x] Generierte oder lokale Artefakte markieren, die nicht versioniert bleiben duerfen.

Quiche-Fundstellen:

| Bereich | Fundstellen | Folge |
|---|---|---|
| CI | `.github/workflows/ci.yml`, `.github/workflows/release-merge-publish.yml` | In #Q-10 auf neuen Stack und neue Gates umstellen. |
| Provenance | `DEPENDENCY_PROVENANCE.md`, `infra/scripts/quiche-bootstrap.lock`, `infra/scripts/quiche-workspace.Cargo.lock`, `infra/scripts/check-dependency-provenance-doc.sh` | In #Q-3 ersetzen, danach in #Q-9 alte Quiche-Provenance entfernen. |
| Build-Konfiguration | `extension/Makefile.frag`, `extension/config.m4`, `extension/config.m4.full`, `extension/config.h`, `extension/config.h.in`, `extension/include/config/config.h`, `Makefile` | In #Q-4 auf portable neue Detection ohne Quiche/Cargo umbauen. |
| Runtime Source, Client | `extension/src/client/http3.c`, `extension/src/client/http3/*.inc`, besonders `extension/src/client/http3/quiche_loader.inc`, plus `extension/src/client/index.c` | In #Q-5 Loader und Quiche-Symbolnutzung ersetzen; Stats/Options in #Q-7 mappen. |
| Runtime Source, Server | `extension/src/server/http3.c`, `extension/src/server/http3/*.inc`, besonders `extension/src/server/http3/quiche_loader.inc` | In #Q-6 Listener/Serverpfad ersetzen; Stats/Options in #Q-7 mappen. |
| Runtime Metadaten/Stubs | `stubs/king.php`, `extension/src/php_king.c`, `extension/src/php_king/lifecycle.inc`, `extension/src/config/internal/snapshot.inc` | Nach Runtime-Migration in #Q-9 auf neuen Backendnamen aktualisieren. |
| HTTP/3 Tests | `extension/tests/*http3*.phpt`, `extension/tests/366-quiche-bootstrap-contract.phpt`, `extension/tests/668-ensure-quiche-toolchain-lockfile-v4-branch-contract.phpt`, `extension/tests/http3_test_helper/**`, `extension/tests/http3_*.rs`, `extension/tests/http3_ticket_server/**` | In #Q-8 Harness migrieren, in #Q-11 voll gegen neuen Stack laufen lassen. |
| Benchmarks/Smoke/Release-Scripts | `benchmarks/README.md`, `benchmarks/run-canonical.sh`, `infra/scripts/build-profile.sh`, `infra/scripts/package-release.sh`, `infra/scripts/package-pie-source.sh`, `infra/scripts/smoke-profile.sh`, `infra/scripts/soak-runtime.sh`, `infra/scripts/test-extension.sh`, `infra/scripts/check-stub-parity.sh`, `infra/scripts/verify-release-package.sh`, `infra/scripts/verify-release-supply-chain.sh`, `infra/scripts/prebuild-http3-test-helpers.sh`, `infra/scripts/bootstrap-quiche.sh`, `infra/scripts/check-quiche-bootstrap.sh`, `infra/scripts/ensure-quiche-toolchain.sh`, `infra/scripts/cargo-build-compat.sh` | In #Q-4/#Q-10 ersetzen oder entfernen. |
| Dokumentation | `README.md`, `CONTRIBUTE`, `PROJECT_ASSESSMENT.md`, `READYNESS_TRACKER.md`, `documentation/quic-and-tls.md`, `documentation/operations-and-release.md`, `documentation/pie-install.md` | In #Q-9 nach der technischen Migration aktualisieren. |

Suchbasis:
- `rg -l -i "quiche" --glob '!ISSUES.md' --glob '!extension/build/**' --glob '!compat-artifacts/**' --glob '!quiche/**' --glob '!extension/quiche/**'`
- `git ls-files | rg -i 'quiche'`

Rust-/Cargo-Fundstellen im HTTP/3-Kontext:

| Bereich | Fundstellen | Folge |
|---|---|---|
| HTTP/3 Rust-Testclients | `extension/tests/http3_abort_client.rs`, `extension/tests/http3_delayed_body_client.rs`, `extension/tests/http3_failure_peer.rs`, `extension/tests/http3_multi_peer.rs` | In #Q-8 durch neuen reproduzierbaren Test-Harness ersetzen oder temporaer mit Ablauf-Issue markieren. |
| HTTP/3 Ticket-Server-Testprojekt | `extension/tests/http3_ticket_server/Cargo.toml`, `extension/tests/http3_ticket_server/Cargo.lock`, `extension/tests/http3_ticket_server/src/main.rs` | In #Q-8 migrieren, weil dieser Harness Quiche/Cargo fuer Session-Ticket- und 0-RTT-Belege nutzt. |
| Quiche Workspace Lock | `infra/scripts/quiche-workspace.Cargo.lock` | In #Q-3/#Q-9 durch neue Provenance ersetzen und danach entfernen. |
| Cargo-/Rust-Bootstrap-Scripts | `infra/scripts/bootstrap-quiche.sh`, `infra/scripts/build-profile.sh`, `infra/scripts/cargo-build-compat.sh`, `infra/scripts/check-quiche-bootstrap.sh`, `infra/scripts/ensure-quiche-toolchain.sh`, `infra/scripts/package-pie-source.sh`, `infra/scripts/package-release.sh`, `infra/scripts/prebuild-http3-test-helpers.sh` | In #Q-4/#Q-10 entfernen oder auf neuen nicht-Cargo-HTTP3-Pfad umbauen. |
| Extension Build Hook | `extension/Makefile.frag` | In #Q-4 entfernen oder ersetzen, weil dort `cargo build` fuer `quiche` und `quiche-server` verdrahtet ist. |
| CI Rust/Cargo Cache/Toolchain | `.github/workflows/ci.yml`, `.github/workflows/release-merge-publish.yml` | In #Q-10 entfernen, falls Rust nur noch Quiche/HTTP3 dient; andernfalls auf unrelated Rust begrenzen. |
| Docs/Tracker | `README.md`, `CONTRIBUTE`, `DEPENDENCY_PROVENANCE.md`, `PROJECT_ASSESSMENT.md`, `READYNESS_TRACKER.md`, `documentation/pie-install.md` | In #Q-9 nach technischer Migration aktualisieren. |

Suchbasis:
- `git ls-files '*.rs' '**/Cargo.toml' '**/Cargo.lock' '*Cargo.lock'`
- `git ls-files | rg '(^|/)(cargo-build-compat|ensure-quiche-toolchain|bootstrap-quiche|check-quiche-bootstrap|prebuild-http3-test-helpers|build-profile|package-release|package-pie-source|quiche-workspace\.Cargo\.lock)'`

Klassifizierung:

| Aktion | Fundstellen | Begruendung |
|---|---|---|
| `rename` | `extension/src/client/http3/quiche_loader.inc`, `extension/src/server/http3/quiche_loader.inc` | Loader bleiben als Konzept erhalten, aber Backendname und Symbolbindung wechseln auf den neuen Stack. |
| `replace` | `extension/src/client/http3.c`, `extension/src/client/http3/*.inc`, `extension/src/server/http3.c`, `extension/src/server/http3/*.inc`, `extension/src/client/index.c` | HTTP/3 Client/Server bleiben Produktpfad; Quiche-Typen, Funktionszeiger und Error-Mapping werden durch neue Backend-Abstraktion ersetzt. |
| `replace` | `extension/Makefile.frag`, `extension/config.m4`, `extension/config.m4.full`, `extension/config.h`, `extension/config.h.in`, `extension/include/config/config.h` | Build bleibt erhalten, aber Quiche-/Cargo-Detection wird durch portable neue Stack-Detection ersetzt. |
| `replace` | `.github/workflows/ci.yml`, `.github/workflows/release-merge-publish.yml` | CI bleibt Pflicht, aber Rust/Cargo-/Quiche-Caches und Bootstrap-Gates werden auf neue Dependency-/Build-Gates umgestellt. |
| `replace` | `DEPENDENCY_PROVENANCE.md`, `infra/scripts/check-dependency-provenance-doc.sh` | Provenance bleibt Pflicht, aber Quiche-Pins werden durch neue Stack-Pins ersetzt. |
| `remove` | `infra/scripts/quiche-bootstrap.lock`, `infra/scripts/quiche-workspace.Cargo.lock` | Quiche- und Cargo-Locks duerfen nach der Migration nicht mehr aktiver Produktpfad sein. |
| `remove` | `infra/scripts/bootstrap-quiche.sh`, `infra/scripts/check-quiche-bootstrap.sh`, `infra/scripts/ensure-quiche-toolchain.sh`, `infra/scripts/cargo-build-compat.sh` | Quiche-/Cargo-Bootstrap verschwindet aus dem HTTP/3-Produktpfad. |
| `replace` | `infra/scripts/build-profile.sh`, `infra/scripts/package-release.sh`, `infra/scripts/package-pie-source.sh`, `infra/scripts/prebuild-http3-test-helpers.sh`, `infra/scripts/smoke-profile.sh`, `infra/scripts/soak-runtime.sh`, `infra/scripts/test-extension.sh`, `infra/scripts/check-stub-parity.sh`, `infra/scripts/verify-release-package.sh`, `infra/scripts/verify-release-supply-chain.sh` | Script-Zweck bleibt, aber Runtime-Artefakte, Manifeste, Checks und Env-Variablen wechseln vom Quiche-Pfad auf den neuen Stack. |
| `replace` | `benchmarks/README.md`, `benchmarks/run-canonical.sh` | Benchmarks bleiben, aber `libquiche.so`/`quiche-server` werden durch neue Runtime-Artefakte ersetzt. |
| `replace` | `extension/tests/*http3*.phpt`, `extension/tests/http3_test_helper/**` | HTTP/3-Vertrag bleibt; Test-Erwartungen, Env-Variablen und Helper-Binaries wechseln auf neuen Stack. |
| `remove` | `extension/tests/366-quiche-bootstrap-contract.phpt`, `extension/tests/668-ensure-quiche-toolchain-lockfile-v4-branch-contract.phpt` | Diese Contracts pruefen ausschliesslich Quiche/Cargo-Bootstrap und werden durch neue Dependency-/Build-Gates ersetzt. |
| `keep-temporary` | `extension/tests/http3_abort_client.rs`, `extension/tests/http3_delayed_body_client.rs`, `extension/tests/http3_failure_peer.rs`, `extension/tests/http3_multi_peer.rs`, `extension/tests/http3_ticket_server/**` | Bis #Q-8 ersetzt ist, bleiben sie nur als Test-Harness-Belege; nicht Produkt-Bootstrap. |
| `replace` | `stubs/king.php`, `extension/src/php_king.c`, `extension/src/php_king/lifecycle.inc`, `extension/src/config/internal/snapshot.inc` | Oeffentliche Metadaten bleiben, aber Backendnamen/Diagnostics duerfen nicht mehr Quiche melden. |
| `replace` | `README.md`, `CONTRIBUTE`, `PROJECT_ASSESSMENT.md`, `READYNESS_TRACKER.md`, `documentation/quic-and-tls.md`, `documentation/operations-and-release.md`, `documentation/pie-install.md` | Dokumentation bleibt, aber aktive Quiche-/Cargo-Aussagen werden auf den neuen Stack umgeschrieben oder als Historie markiert. |
| `keep-unrelated` | `infra/scripts/toolchain-lock.sh`, `infra/scripts/toolchain.lock`, `infra/php-matrix-runner.Dockerfile`, `infra/scripts/php-version-docker-matrix.sh` und nicht-aktive Rust-/Cargo-Dokumentation ohne HTTP/3-Produktbezug | Nicht automatisch loeschen; in #Q-10 nur Rust-Anteile entfernen, wenn nach Quiche kein anderer Verbraucher bleibt. |

Rust-/Cargo-Abgrenzung:

| Klasse | Fundstellen | Sprint-Behandlung |
|---|---|---|
| HTTP/3-/Quiche-Rust | `extension/tests/http3_*.rs`, `extension/tests/http3_ticket_server/**`, `infra/scripts/quiche-workspace.Cargo.lock`, `extension/tests/*http3*.phpt` mit `cargo`-Skip/Build, `extension/tests/http3_test_helper/**` | Q-Scope; in #Q-8 ersetzen oder temporaer mit Ablauf-Issue markieren. |
| HTTP/3-/Quiche-Bootstrap | `extension/Makefile.frag`, `infra/scripts/bootstrap-quiche.sh`, `infra/scripts/check-quiche-bootstrap.sh`, `infra/scripts/ensure-quiche-toolchain.sh`, `infra/scripts/cargo-build-compat.sh`, Quiche/Cargo-Passagen in `build-profile.sh`, `package-release.sh`, `package-pie-source.sh`, `prebuild-http3-test-helpers.sh` | Q-Scope; in #Q-4/#Q-10 entfernen oder auf neuen nicht-Cargo-Pfad umbauen. |
| Shared Toolchain-Infrastruktur | `infra/scripts/toolchain-lock.sh`, `infra/scripts/toolchain.lock`, `infra/php-matrix-runner.Dockerfile`, `infra/scripts/php-version-docker-matrix.sh`, Rust/Cargo Setup-Blöcke in CI | Nicht automatisch loeschen; in #Q-10 nur Rust-Anteile entfernen, wenn nach Quiche kein anderer Verbraucher bleibt. |
| Dokumentation ohne aktive Buildwirkung | allgemeine Rust-/Cargo-Erwaehnungen in Docs/Tracker, sofern sie nicht aktive HTTP/3-/Quiche-Anleitung sind | In #Q-9 als Historie markieren oder auf neuen Stack anpassen; keine Source-Loeschaktion. |

Abgrenzungsergebnis:
- Getrackte Rust-Source-Dateien und Cargo-Manifeste sind aktuell HTTP/3-Testharness-bezogen.
- Shared Toolchain-Dateien sind keine HTTP/3-Source, duerfen aber nach Quiche nicht mehr unnoetig Rust als Build-Voraussetzung erzwingen.

Lokale/generierte Artefakte:

| Klasse | Fundstellen | Sprint-Behandlung |
|---|---|---|
| Lokaler Cargo-Cache | `.cargo/**` | Nicht versionieren; darf nicht als Sprint-Output oder Build-Voraussetzung im Repo landen. |
| Lokale Quiche-Checkouts und Buildtrees | `quiche/**`, `extension/quiche/**`, `extension/quiche/target/**` | Nicht versionieren; in #Q-12 als Cleanup-/Ignore-Guard behandeln. |
| Profil-/Release-Runtime-Outputs | `extension/build/profiles/**`, `extension/build/profiles/release/libquiche.so`, `extension/build/profiles/release/quiche-server` | Nicht versionieren; in #Q-10 durch neue Release-Artefakte des Zielstacks ersetzen. |
| Kompatibilitaetsartefakte | `compat-artifacts/**/runtime/libquiche.so`, `compat-artifacts/**/runtime/quiche-server` | Nicht versionieren; in #Q-10 aus Manifesten und Paketchecks entfernen oder migrieren. |
| HTTP/3-Ticket-Server-Buildtree | `extension/tests/http3_ticket_server/target/**`, inklusive `king-http3-ticket-server`, `*.rlib`, `*.rmeta`, `*.d`, `.fingerprint/**` | Nicht versionieren; Quell-Harness bleibt nur `keep-temporary` bis #Q-8. |
| Compiler-/Cargo-Nebenausgaben | `target/**`, `*.d`, `*.rlib`, `*.rmeta`, `.fingerprint/**` | Nicht versionieren; falls sie in Status oder Package auftauchen, ist das ein Cleanup-Fehler. |
| Externe lokale Checkout-Spuren | `libcurl/**/quiche*`, `libcurl/CMake/FindQuiche.cmake`, `libcurl/lib/vquic/curl_quiche.*` | Nicht in diesen Sprint ziehen, solange nicht explizit als Dependency entschieden; aktuell nur als lokale/ignorierte Fremdbaum-Spur behandeln. |
| Getrackte Lockfiles mit Ablauf | `infra/scripts/quiche-workspace.Cargo.lock`, `extension/tests/http3_ticket_server/Cargo.lock` | Nicht als generierte Artefakte loeschen: ersteres wird in #Q-3/#Q-9 entfernt, letzteres bleibt nur bis zur Harness-Migration in #Q-8. |

Artefakt-Suchbasis:
- `git status --short --ignored -uall | rg -i 'quiche|cargo|target|libquiche|quiche-server|compat-artifacts|\\.rlib|\\.d$'`
- `git ls-files | rg -i '(libquiche\\.so|quiche-server|target/|compat-artifacts|extension/quiche|^quiche/|\\.rlib$|/\\.fingerprint/|Cargo\\.lock$|quiche-workspace\\.Cargo\\.lock)'`

Done:
- [x] Eine Fundstellen-Tabelle mit Aktion pro Datei liegt vor.
- [ ] Es ist klar, welche Dateien bei der Quiche-Entfernung betroffen sind.

---

### #Q-2 Backend-Auswahl und Feature-Parity

Ziel:
- Ersatz fuer Quiche verbindlich festlegen und gegen den bestehenden HTTP/3-Vertrag pruefen.

Checklist:
- [ ] Entscheidung dokumentieren: `LSQUIC + BoringSSL` oder begruendeter Alternativstack.
- [ ] Feature-Parity fuer Client, Server, Listener, TLS, Session-Tickets, 0-RTT, Stream-Reset, Stop-Sending, Flow-Control, Congestion-Control, Stats und Cancel pruefen.
- [ ] Unsupported Features als Blocker oder Umsetzungsaufgabe erfassen, nicht still entfernen.
- [ ] Public API und Exception-Mapping gegen den bestehenden Vertrag pruefen.
- [ ] Lizenz-, Security- und Maintenance-Risiko dokumentieren.

Done:
- [ ] Der Zielstack ist entschieden.
- [ ] Kein bestehendes King-v1-Transportversprechen wird reduziert.

---

### #Q-3 Dependency-Provenance fuer neuen Stack

Ziel:
- Reproduzierbare Pins fuer den neuen QUIC/HTTP3-Stack schaffen.

Checklist:
- [ ] Release-/Commit-Pins fuer den neuen Stack festlegen.
- [ ] Checksums und Quellen in `DEPENDENCY_PROVENANCE.md` dokumentieren.
- [ ] Neues Bootstrap-Lockfile erstellen, z. B. `infra/scripts/lsquic-bootstrap.lock`.
- [ ] Alte `quiche-bootstrap.lock` und `quiche-workspace.Cargo.lock` als zu entfernende Quiche-Artefakte erfassen.
- [ ] Offline-/CI-Validierung fuer Pins und Checksums ergaenzen.

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
