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

### #Q-2 Backend-Auswahl und Feature-Parity

Ziel:
- Ersatz fuer Quiche verbindlich festlegen und gegen den bestehenden HTTP/3-Vertrag pruefen.

Checklist:
- [x] Entscheidung dokumentieren: `LSQUIC + BoringSSL` oder begruendeter Alternativstack.
- [x] Feature-Parity fuer Client, Server, Listener, TLS, Session-Tickets, 0-RTT, Stream-Reset, Stop-Sending, Flow-Control, Congestion-Control, Stats und Cancel pruefen.
- [x] Unsupported Features als Blocker oder Umsetzungsaufgabe erfassen, nicht still entfernen.
- [x] Public API und Exception-Mapping gegen den bestehenden Vertrag pruefen.
- [ ] Lizenz-, Security- und Maintenance-Risiko dokumentieren.

Backend-Entscheidung:
- Zielstack ist `LSQUIC + BoringSSL`.
- Begruendung: LSQUIC ist eine C-basierte QUIC-/HTTP/3-Bibliothek fuer Client- und Serverpfade, nutzt BoringSSL, ist ueber CMake reproduzierbar pinbar und entfernt den aktiven Rust-/Cargo-/Quiche-Bootstrap aus dem King-HTTP/3-Produktpfad.
- King bindet nicht die LSQUIC-Beispielprogramme als Produktpfad ein. `http_client`, `http_server` und libevent bleiben hoechstens Referenz- oder Testharness-Material; der Runtime-Loader muss echte LSQUIC-/BoringSSL-Symbole direkt fuer King initialisieren.
- `ngtcp2 + nghttp3` bleibt nur Fallback, falls die offene Feature-Parity-Pruefung LSQUIC als ungeeignet beweist. Ein Wechsel braucht dann ein eigenes Issue, weil QUIC-, HTTP/3-, TLS- und Runtime-Semantik getrennt gemappt werden muessten.
- `MsQuic` ist fuer diesen Sprint nicht Zielstack, weil der King-v1-Vertrag HTTP/3-Client und -Server plus bestehende H3-API-Semantik erhalten muss; ein reiner QUIC-Transport-Ersatz waere ohne zusaetzliche HTTP/3-Schicht kein gleichwertiger Drop-in.

Primaerquellen fuer die Entscheidung:
- LSQUIC README: `https://github.com/litespeedtech/lsquic`
- LSQUIC Getting Started: `https://lsquic.readthedocs.io/en/stable/gettingstarted.html`

Feature-Parity-Pruefung:

| Vertragsbereich | King-v1-Vertrag | LSQUIC-/BoringSSL-Anker | Ergebnis / Folge |
|---|---|---|---|
| Client HTTP/3 | `king_http3_request_send()`, Dispatcher, OO-Client und `king_http3_request_send_multi()` bleiben echte HTTP/3-over-QUIC Pfade. | LSQUIC bietet HTTP/3-Clientbetrieb, `lsquic_engine_connect()`, `lsquic_conn_make_stream()`, Stream-Callbacks und pending streams. | Parity-Ziel ist tragfaehig; Umsetzung in #Q-5, Wire-Beweis in #Q-11. |
| Server / Listener | `king_http3_server_listen_once()` bleibt realer UDP-/QUIC-/HTTP/3-Accept-Pfad mit normalisiertem Request/Response-Verhalten. | LSQUIC erzeugt Server-Verbindungen aus eingehenden Paketen, ruft `on_new_conn` nach erfolgreichem Handshake und erzeugt Request-Streams im Servermodus. | Tragfaehig; Umsetzung in #Q-6, Harness-Beweis in #Q-8/#Q-11. |
| TLS / Handshake | TLS-Fehler, Zertifikatspruefung, Reload-Verhalten und stabile Exceptions bleiben erhalten. | LSQUIC nutzt BoringSSL, exposes Handshake-Callback `on_hsk_done` und Zertifikatszugriff wie `lsquic_conn_get_server_cert_chain()`. | Tragfaehig, aber King-eigenes Error-Mapping bleibt #Q-2/#Q-5/#Q-6 Arbeit. |
| Session-Tickets / Retry-Token | Shared Ticket Ring, stale-ticket Recovery und Token-Reuse duerfen nicht verschwinden. | LSQUIC exposes `on_sess_resume_info`, `on_new_token`, `sess_resume` und `token` in `lsquic_engine_connect()`. | Tragfaehig; Mapping in #Q-5 und Testersatz in #Q-8. |
| 0-RTT | Akzeptierte 0-RTT Requests und server-disabled fallback replay muessen weiter bewiesen werden. | LSQUIC/BoringSSL-Resumption ist vorhanden; konkrete early-data Steuerung muss am gepinnten Zielstand gegen Wire-Tests bestaetigt werden. | Bedingte Parity: kein Vertragsabbau erlaubt; falls early-data nicht sauber abbildbar ist, wird es Blocker statt Silent-Drop. |
| Stream-Reset / Stop-Sending | Peer reset, stop-sending, lokale Cancel-Abbrueche und Stream-Lifecycle bleiben unterscheidbar. | LSQUIC bietet `on_reset`, `lsquic_stream_shutdown()`, `lsquic_stream_close()`, `ECONNRESET`-Lesefehler und Connection close/abort. | Tragfaehig; exaktes Error-/Cancel-Mapping in #Q-5/#Q-7/#Q-8. |
| Flow-Control / Multiplexing | `quic.initial_max_*`, Backpressure, stalled peer recovery und multi-stream fairness bleiben sichtbar. | LSQUIC exposes `es_init_max_data`, `es_init_max_stream_data_*`, `es_init_max_streams_*`, stream read/write backpressure und pending stream management. | Tragfaehig; Config-Mapping und Tests in #Q-7/#Q-11. |
| Congestion-Control / Pacing | `cubic`, `bbr`, pacing, loss/retransmit und recovery counters bleiben echte Runtime-Werte. | LSQUIC exposes `es_cc_algo` mit Cubic, BBRv1 und adaptive CC sowie pacing settings. | Tragfaehig; BBR-Semantik als BBRv1 markieren und in #Q-7 exakt mappen. |
| Stats | `quic_packets_*`, lost bytes, retransmit bytes, RTT/Pacing und Backendname duerfen nicht zu stale bookkeeping werden. | LSQUIC exposes `lsquic_conn_get_info()` mit bytes, packets, loss, retransmit, RTT, cwnd, pacing und bandwidth estimates. | Tragfaehig; fehlende King-Felder muessen in #Q-7 aus echten LSQUIC-Werten oder expliziten Adapterzaehlern kommen. |
| Cancel / Timeout | `CancelToken`, Timeouts, application close und cleanup bleiben echte aktive Transportaktionen. | LSQUIC exposes connection close/abort, stream shutdown/close, progress/no-progress timeout settings und event-loop callbacks. | Tragfaehig; King-Cancel darf nur als echter stream/connection operation gelten, nicht als lokales Flag. |

Parity-Suchbasis:
- King-Vertrag: `PROJECT_ASSESSMENT.md`, `READYNESS_TRACKER.md`, `documentation/quic-and-tls.md`, `documentation/runtime-configuration.md`, `stubs/king.php`, `extension/tests/*http3*.phpt`.
- LSQUIC API: `https://lsquic.readthedocs.io/en/stable/apiref.html`.

Unsupported-/Risiko-Register:

| Bereich | Status | Pflichtbehandlung |
|---|---|---|
| 0-RTT accepted early-data + server-disabled fallback | Blocker bis am gepinnten LSQUIC-/BoringSSL-Stand per Wire-Test bewiesen. | Kein Fallback auf normale Resumption als Ersatz; #Q-5/#Q-8/#Q-11 muessen beide Phasen wieder nachweisen. |
| QUIC `STOP_SENDING` vs. Stream-Reset | Umsetzungsaufgabe. | `STOP_SENDING` darf nicht in generisches Reset/Close verschwinden; Mapping aus LSQUIC callbacks/read-write errors in #Q-5/#Q-8 explizit testen. |
| Live-Stats `quic_lost_bytes` und `quic_stream_retransmitted_bytes` | Umsetzungsaufgabe mit Blocker-Fallback. | Wenn LSQUIC die Werte nicht direkt liefert, muss der Adapter sie aus echten packet-/stream-accounting Daten bilden; stale zeroes oder Dummy-Counter sind unzulaessig. |
| Congestion-Control `bbr` | Umsetzungsaufgabe. | LSQUIC-BBR ist als BBRv1-Semantik zu dokumentieren; falls King bisher staerkere BBR-Semantik verspricht, wird das in #Q-7 Blocker statt stiller Aenderung. |
| Feingranulare `quic.*` Optionen | Umsetzungsaufgabe. | Jede Option wird in #Q-7 gemappt; nicht abbildbare Optionen fail-closed mit Diagnose statt Ignorieren. |
| Datagram-Optionen | Umsetzungsaufgabe. | `quic.datagrams_enable` und Queue-Limits muessen auf LSQUIC-Datagram-Callbacks/Settings abgebildet oder in #Q-7 fail-closed markiert werden. |
| TLS reload und Server-0-RTT-Cache | Blocker bis Server-Wire-Test. | #Q-6/#Q-11 muessen zeigen, dass Reload und Cache-Verhalten aktive Listener nicht brechen. |
| WebSocket-over-HTTP3 Honesty | Umsetzungsaufgabe. | Lokale und on-wire H3-Honesty-Slices bleiben Pflicht; keine Reduktion auf HTTP/1-/HTTP/2-WebSocket. |
| Runtime-Backendname | Umsetzungsaufgabe. | `quiche_h3` verschwindet aus neuen Runtime-Antworten; neuer Name muss echte LSQUIC-Nutzung bedeuten, kein Alias. |

Regel fuer alle Eintraege:
- Wenn ein Punkt am Zielstack nicht korrekt belegbar ist, wird er in diesem Sprint zum Blocker oder zu einem neuen Issue. Er wird nicht aus Tests, Docs, Stubs oder Public API entfernt, um Gruen zu bekommen.

Public-API- und Exception-Mapping:

| Bereich | Bestehender Vertrag | LSQUIC-Migrationsregel |
|---|---|---|
| Direct HTTP/3 one-shot | `king_http3_request_send(string $url, ?string $method, ?array $headers, mixed $body, ?array $options): array|false` bleibt bestehen. | Signatur, Rueckgabeform und Response-Metadaten bleiben stabil; nur `transport_backend` wechselt von `quiche_h3` auf einen echten LSQUIC-Backendnamen. |
| Direct HTTP/3 multi | `king_http3_request_send_multi(array $requests, ?array $options): array|false` bleibt ein gemeinsamer HTTP/3-/QUIC-Multiplex-Pfad. | Keine Aufspaltung in mehrere Einzelrequests; Stream-Pending/Fairness muss in #Q-5/#Q-11 wieder auf einem echten LSQUIC-Conn-Kontext laufen. |
| Dispatcher | `king_send_request()` / interner Client-Dispatcher behalten HTTP/3-Fehlertexte und `king_get_last_error()`-Semantik. | Dispatcher darf nicht auf HTTP/1/2 downgraden, wenn HTTP/3 explizit gewaehlt oder per URL/Config verlangt ist. |
| Server Listener | `king_http3_server_listen_once(string $host, int $port, mixed $config, callable $handler): bool` bleibt realer on-wire HTTP/3 Listener. | Handler-Requestshape, Response-Normalisierung, CORS/Header-Verhalten und Stats bleiben #Q-6/#Q-11 Pflicht. |
| OO Client | `King\Client\Http3Client extends HttpClient` behaelt Constructor, `request()` und `close()`. | Kein neuer LSQUIC-spezifischer OO-Typ und kein Public-API-Bruch; Backendwechsel bleibt intern. |
| CancelToken | `King\CancelToken` bleibt die oeffentliche aktive Abbruchsteuerung. | Cancel muss in aktive LSQUIC stream/connection close/abort Operationen mappen, nicht nur lokale Flags setzen. |
| Response-Metadaten | `protocol`, `transport_backend`, `effective_url`, TLS-/Ticket-/0-RTT-Felder und `quic_*` Stats bleiben sichtbar. | Fehlende Felder sind Adapterarbeit in #Q-5/#Q-7, nicht Anlass zum Entfernen. |

Exception-Mapping:

| Fehlerfall | Oeffentliche Klasse | Muss erhalten bleiben |
|---|---|---|
| QUIC/TLS Handshake-Failure | `King\TlsException` | Ja; belegt durch `extension/tests/377-http3-handshake-failure-contract.phpt` und `536-oo-http3-client-error-mapping-matrix-contract.phpt`. |
| QUIC `transport_close` | `King\QuicException` | Ja; darf nicht zu `ProtocolException` oder generischer Runtime werden. |
| QUIC `application_close` / HTTP/3 Protocol Close | `King\ProtocolException` | Ja; bleibt getrennt von Transport-Close. |
| Connect- und Response-Timeouts | `King\TimeoutException` | Ja; Timeout bleibt nicht `false` und nicht generische Runtime. |
| Aktiver `CancelToken` | `King\RuntimeException` mit Cancel-Text | Ja; muss aktive Transport-Cancel-Operation ausloesen. |
| Congestion-Control-Blocker | `King\CongestionControlException extends King\QuicException` | Ja; falls LSQUIC-CC-Mapping scheitert, muss #Q-7 fail-closed statt still ignorieren. |
| Validation / unsupported config | bestehende King Validation-/Runtime-Ausnahmen | Ja; nicht unterstuetzte `quic.*` Optionen muessen diagnostisch scheitern. |

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
