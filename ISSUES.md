# King Issues

> Status: 2026-04-19
> Fokus: saubere Architektur- und Skalierungsplanung für Video-Calls, inkl. WASM-Fallback, SFU, Background-Blur, persistenter Chat-/Datei-Ablage, Speaker-Awareness und issue-basierter Umsetzung.

## Harte Anforderungen

- Frontend-Gesetz: die Produkt-UX-Referenz ist führend.
- Zielkapazität: tausende Nutzer pro Call-Session.
- Sichtbar im Call-Viewport: maximal ca. 5 Teilnehmer gleichzeitig.
- Restliche Teilnehmer: performant in User-/Participant-Listen.
- Background-Blur/Background-Swap muss sauber integriert werden.
- Dokumentation in `ISSUES.md` muss so vollständig sein, dass ein neuer Call ohne Vorwissen weiterarbeiten kann.

## Aktueller verifizierter Stand (Research Snapshot)

- [x] Historischer `demo/video-chat/frontend`-Stand enthielt primär Codec-/Media-Libraries; diese liegen jetzt konsolidiert in `demo/video-chat/frontend-vue/src/lib/**`.
- [x] Aktueller Code hat TS-Fallback: `createHybridEncoder`/`createHybridDecoder` fallen auf TS-Codec zurück (Ist-Zustand, für Zielarchitektur zu entfernen).
- [x] Das ist **kein** automatischer Fallback auf nativen WebRTC-Codec (VP8/H264); dieser Pfad muss explizit definiert/implementiert werden.
- [x] Alex-Integration im Frontend kam über Commits `78f4f5c` und `e5d65b7` (Author: `Alice-and-Bob`); `e5d65b7` brachte auch Artefakte mit, später bereinigt (`25e15e7`).
- [x] In `../intelligent-intern/services/app/compat/src/modules/videocall` existiert ein ausgereiftes Background-Filter-System (FaceDetector/MediaPipe/TFJS/Center-Mask-Fallback + Controller + Runtime-Metriken + Baseline-Gates).
- [x] Der fruehere Research-Stand referenzierte `demo/media-gateway/` (Rust) inkl. SFU-/Signaling-/AMQP-/QUIC-Skizze; im aktuellen Checkout ist dieser Pfad nicht vorhanden, deshalb werden Gateway-Vertraege bis zur Integrationsentscheidung als ausfuehrbare Backend-Contracts fixiert.

## Fallback-Verständnis (verbindlich zu klären und dann umzusetzen)

Aktuell:
- WASM nicht verfügbar -> TS-WLVC (bereits vorhanden, aber nicht Zielarchitektur).

Noch offen (muss als Vertrag implementiert werden):
- WASM nicht verfügbar/instabil -> direkter nativer WebRTC-Codec (ohne TS-WLVC-Runtime-Fallback).
- WLVC-Pfad nicht nutzbar/zu teuer -> nativer WebRTC-Codec.
- Harte Degradation bei Last -> ggf. Audio-only / reduzierte Videoqualität.

## Issue-Backlog (neu, phasenbasiert)

### #1 Architektur-Inventur und Quellenkatalog (Research)

Ziel:
- Vollständige Ist-Aufnahme für `frontend-vue` (inkl. übernommener Alex-Libraries), Backend, Extension, historische `demo/media-gateway`-Referenzen, sowie Referenzen aus `intelligent-intern`.

Checklist:
- [x] Relevante Pfade im aktuellen Repo gesichtet.
- [x] Alex-Commits mit Lib-Relevanz identifiziert und auf `frontend-vue` konsolidiert.
- [x] Blur-Referenzmodule in `intelligent-intern` identifiziert.
- [x] Architektur-Karte als kompakte Tabelle (Komponente -> Verantwortung -> Status -> Owner) ergänzen.
- [x] Abgleich gegen produktive `frontend-vue` Nutzung (welche Library-Pfade sind tatsächlich verdrahtet?).

Definition of done:
- Eine eindeutige Komponenten-/Verantwortungsmatrix liegt in diesem Dokument vor.
- Keine offenen "wo liegt das?"-Fragen für neue Sessions.

Notizen:
- `demo/video-chat/frontend-vue/src/lib/wasm/wasm-codec.ts`: Hybrid-WASM/TS-Factory.
- `demo/video-chat/frontend-vue/src/lib/sfu/sfuClient.ts`: SFU-Signaling/Track-Bookkeeping.
- `demo/media-gateway/src/sfu/*`: historische Rust-SFU-Prototyp-Referenz; im aktuellen Checkout nicht vorhanden.

Architektur-Karte (Snapshot):

| Komponente | Verantwortung | Produktionsverdrahtung | Owner/Status |
|---|---|---|---|
| `demo/video-chat/frontend-vue/src/domain/**` | Produkt-UI (Admin, Calls, Workspace) | Aktiv | Core |
| `demo/video-chat/frontend-vue/src/support/backendFetch.js` + `backendOrigin.js` | Zentrale Backend-Origin-/Fetch-Logik | Aktiv (breit genutzt) | Core |
| `demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.vue` | Realtime-Call-Orchestrierung im Frontend | Aktiv | Core |
| `demo/video-chat/frontend-vue/src/lib/sfu/sfuClient.ts` | SFU-Track-Signaling-Client (`/sfu`) | Aktiv (Import in `CallWorkspaceView.vue`) | Core |
| `demo/video-chat/frontend-vue/src/lib/wasm/wasm-codec.ts` | WLVC WASM + Hybrid-Factory | Aktiv (Import in `CallWorkspaceView.vue`) | Core; TS-Fallback aus Zielpfad entfernen |
| `demo/video-chat/frontend-vue/src/lib/wavelet/**` | TS-WLVC/Transform-Pipeline | Teilaktiv über Hybrid/Libs; als Runtime-Fallback nicht Ziel | Core |
| `demo/video-chat/frontend` | Historischer, entfernter Pfad (nach `frontend-vue/src/lib/**` übernommen) | Nicht mehr vorhanden | archival |
| `demo/video-chat/backend-king-php/**` | API/Auth/Calls/Realtime im King-PHP-Backend | Aktiv | Core |
| `extension/**` | King Runtime/Extension (native APIs) | Aktiv (plattformweit) | Core |
| `demo/media-gateway/**` | Rust SFU/Gateway Prototyp | Nicht im aktuellen Checkout vorhanden | PoC/Integrationsentscheidung offen; harte Gateway-Vertraege liegen als Backend-Contracts vor |

---

### #2 Medien-Fallback-Vertrag definieren (Research/Planning)

Ziel:
- Einen eindeutigen, testbaren Stufenvertrag definieren: WASM optional, direkter nativer WebRTC-Fallback, Degradationsregeln.

Checklist:
- [x] Stufe A definieren: WASM-WLVC.
- [x] Stufe B definieren: nativer WebRTC-Codec (kein WLVC).
- [x] Stufe C definieren: Last-/Fehler-Degradation (z. B. Audio-only).
- [x] TS-WLVC-Fallback in Produktpfad deaktivieren/entfernen (nur optionales Dev-Experiment).
- [x] Trigger je Stufe definieren (Feature-Detection, Runtime-Metriken, Fehlerklassen).
- [x] Telemetrie je Übergang definieren (warum wurde gewechselt?).

Definition of done:
- Es gibt ein normiertes Fallback-Dokument mit Zustandsmaschine und Triggern.
- Jede Stufe hat klare Ein-/Ausstiegskriterien und Testfälle.

Notizen:
- Bereits vorhanden (Ist): WASM -> TS-Fallback.
- Ziel: direkter Übergang von WLVC auf nativen WebRTC-Codec, TS-Fallback raus aus Produktpfad.

Stufe A (definiert): WASM-WLVC
- Zweck: maximal effiziente WLVC-Verarbeitung auf Clients mit verfügbarer WASM-Runtime.
- Eintrittsbedingungen:
  - WASM-Bundle erfolgreich geladen und initialisiert.
  - Encoder + Decoder in `lib/wasm/wasm-codec` sind operational.
  - Session ist für WLVC-Pfad freigegeben (kein Forced-Fallback aktiv).
- Laufzeitvertrag:
  - Outbound Video nutzt WLVC über WASM-Encoder.
  - Inbound WLVC-Frames werden über WASM-Decoder verarbeitet.
  - Fehler im Einzel-Frame führen nicht direkt zu Session-Abbruch.
- Austrittsbedingung (High-Level):
  - Wenn WASM init/runtime nicht stabil ist, Wechsel auf Stufe B (nativer WebRTC-Codec).
  - Kein Übergang auf TS-WLVC als Produktpfad.

Stufe B (definiert): nativer WebRTC-Codec
- Zweck: stabiler Betriebsmodus ohne WLVC/WASM-Abhängigkeit.
- Eintrittsbedingungen:
  - Stufe A nicht verfügbar oder nicht stabil.
  - Browser/Client bietet nutzbare native WebRTC-Codecs (z. B. VP8/H264/Opus).
- Laufzeitvertrag:
  - Video/Audio laufen über Standard-WebRTC-Pipeline.
  - Keine WLVC-Encode/Decode-Pflicht im Client.
  - Teilnehmer kann trotz WLVC-Fehlern weiter im Call bleiben.
- Austrittsbedingung (High-Level):
  - Bei extremer Last/Fehlerlage Wechsel auf Stufe C (Degradation, z. B. Audio-only).
  - Optionaler Rückwechsel auf Stufe A nur nach stabiler Requalifizierung.

Stufe C (definiert): Last-/Fehler-Degradation
- Zweck: Call unter Stress funktionsfähig halten statt Abbruch.
- Eintrittsbedingungen:
  - Schwere Paketverluste/CPU-Overload/Decode-Fehler über definierte Schwellen.
  - SFU/Client meldet Instabilität, die Stufe B nicht mehr sauber trägt.
- Laufzeitvertrag:
  - Priorität auf Verständlichkeit/Stabilität: Audio-first.
  - Video wird reduziert (fps/auflösung) oder temporär deaktiviert (Audio-only).
  - Sichtfenster bleibt strikt begrenzt, nicht-kritische Effekte/Features werden ausgesetzt.
- Austrittsbedingung (High-Level):
  - Rückkehr zu Stufe B erst nach stabiler Messphase.
  - Rückkehr zu Stufe A nur nach zusätzlicher WASM-Requalifizierung.

TS-WLVC-Fallback-Abbau (definiert)
- Ziel: `createHybridEncoder`/`createHybridDecoder` dürfen im Produktbetrieb nicht mehr automatisch auf TS-WLVC gehen.
- Scope:
  - Produktpfad (`frontend-vue` Realtime Call Workspace) nutzt nur Stufe A/B/C.
  - TS-WLVC bleibt optionaler Dev-/Experimentierpfad hinter explizitem Feature-Flag.
- Migrationsschritte:
  - Hybrid-Factories im Produktpfad durch explizite Capability-Entscheidung ersetzen.
  - Bei fehlendem/instabilem WASM direkt auf nativen WebRTC-Betrieb gehen.
  - Guardrails in CI/Test ergänzen, damit TS-WLVC nicht unbemerkt zurück in den Produktpfad rutscht.

Trigger-Matrix (definiert)
- A -> B (WASM-WLVC auf nativen WebRTC):
  - WASM init schlägt fehl oder lädt nicht innerhalb Timeout.
  - Wiederholte WLVC encode/decode runtime faults über Schwellwert.
  - Client meldet fehlende stabile Ressourcen für WLVC.
- B -> C (nativer WebRTC auf Degradation):
  - Paketverlust/Jitter/Decode-Fehler/CPU-Last über Schwellwertfenster.
  - SFU oder Client signalisiert Instabilität trotz B-Modus.
- C -> B (Recovery aus Degradation):
  - Stabilitätsfenster erfüllt (Metriken unter Schwellwert über Mindestdauer).
  - Audio/Signaling stabil, keine harten Fehler im Beobachtungsfenster.
- B -> A (Requalifizierung zurück auf WASM-WLVC):
  - Expliziter Requalify-Check erfolgreich (WASM verfügbar + stabil).
  - Rückwechsel nur kontrolliert (cooldown + keine laufende Instabilität).

Telemetrie-Matrix (definiert)
- Pflicht-Event bei jedem Stufenwechsel: `media.fallback.transition.v1`
  - Felder:
    - `call_id`, `participant_id`, `from_stage`, `to_stage`
    - `reason_code` (z. B. `wasm_init_failed`, `codec_runtime_fault`, `network_overload`, `manual_requalify`)
    - `sample_window_ms`, `cooldown_state`, `timestamp`
    - Metrik-Snapshot: `packet_loss`, `jitter_ms`, `rtt_ms`, `decode_errors`, `cpu_load`, `fps_out`, `fps_in`
- Zusätzliche Diagnoseevents:
  - `media.fallback.guardrail_hit.v1` (Schwellwertverletzung erkannt, aber noch kein Wechsel)
  - `media.fallback.recovery_candidate.v1` (Stabilitätsfenster erreicht, Recovery möglich)
  - `media.fallback.recovery_commit.v1` (Recovery tatsächlich durchgeführt)
- Auswertung:
  - Aggregation nach `reason_code`, Browser, Gerätetyp, Region, Call-Größe.
  - Ziel: Top-Ursachen für Wechsel und Rückwechselrate transparent machen.

---

### #3 Skalierungsarchitektur für tausende Teilnehmer (Research/Planning)

Ziel:
- Architektur, die große Teilnehmerzahl unterstützt, bei gleichzeitig begrenzter Sichtbarkeit im Haupt-Call (max ~5).

Checklist:
- [x] Kapazitätsziele festlegen (z. B. 1k, 5k, 10k Teilnehmer-Szenarien).
- [x] "Visible participants window" spezifizieren (max 5, Active-Speaker/Pinning-Regeln).
- [x] Teilnehmerliste als server-getriebenes, paginiertes/virtuelles Modell definieren.
- [x] Presence-/Roster-Update-Strategie definieren (Delta-Events statt Full Snapshot).
- [x] Lastgrenzen und Backpressure-Regeln für Reactions/Chat/Participant-Events definieren.
- [x] SFU-Fanout- und Admission-Policy dokumentieren.

Definition of done:
- Ein belastbares Architekturkonzept inkl. Datenflüssen, Limits, und SLOs liegt vor.
- Klarer Split zwischen "was wird gerendert" und "was ist nur im Roster".

Notizen:
- Sichtbare Teilnehmerzahl muss klein gehalten werden (Zielwert ~5).
- Liste muss für große Mengen unabhängig vom Video-Rendering skalieren.

Kapazitätsziele (definiert):
- Tier 1 (Baseline): 1.000 gleichzeitige Teilnehmer in einer Session.
- Tier 2 (Target): 5.000 gleichzeitige Teilnehmer in einer Session.
- Tier 3 (Stretch): 10.000 gleichzeitige Teilnehmer in einer Session.
- Sichtbarkeit im Main-Viewport bleibt dabei hart begrenzt (max. ~5 aktive Streams).
- Alle weiteren Teilnehmer bleiben roster-/listenbasiert (kein Voll-Video-Fanout zum Client).

Visible Participants Window (definiert):
- Größe: maximal 5 gleichzeitige Video-Slots im Hauptbereich.
- Slot-Priorität (hoch nach niedrig):
  - gepinnte Teilnehmer
  - aktueller Active Speaker
  - Host/Owner (falls nicht bereits enthalten)
  - weitere Sprecher nach Recency/Score
- Wechselregeln:
  - keine harten Flips pro Audio-Spike (stabilisierte Sprecher-Erkennung mit Hysterese).
  - Mindesthaltezeit pro Slot, außer bei Pin/Host-Override.
  - bei Bandbreiten-/Leistungsdruck zuerst Non-Pinned-Nebenstreams reduzieren.
- Garantien:
  - eigener Self-View bleibt verfügbar (als eigener Kanal, nicht zwingend im Top-5-Mainset).
  - Teilnehmer außerhalb Top-5 bleiben vollständig im Roster sichtbar und moderierbar.

All 18 leaves (`#M-1` through `#M-18`) are closed. 17 contract tests
green. The `feature/model-inference` branch is merge-ready. Post-merge
sweep ticks V/Z tracker bullets against `main`.

## R-batch: RAG Pipeline (branch `feature/rag-pipeline`)

> Parallel track, extends `demo/model-inference/` with embedding + retrieval
> surfaces. Maps to tracker sections **W** (Embeddings / Vectorization /
> Semantic Discovery, partial: W.1–W.4, W.6) and **X** (Knowledge / Retrieval,
> partial: X.1–X.3, X.7). Branched off `feature/model-inference` tip `e4aeeb7`.
> Tracker boxes NOT ticked from this branch; post-merge sweep only.

Non-negotiable direction for this batch:

- embedding engine is **llama.cpp server in `--embedding` mode** (same pinned
  binary, different GGUF, different flags). King owns the embedding contract;
  llama.cpp is the execution engine behind it.
- vector storage is **object-store brute-force cosine similarity**. Honest — no
  HNSW/IVF/ANN claim. Demo corpus sizes only.
- document format is **plain text only**. PDF/HTML/Markdown parsing fenced.
- the RAG pipeline composes embedding + retrieval + inference in-process (same
  server, no cross-service HTTP). Multi-service composition fenced.
- out of scope: hybrid retrieval (X.4), retrieval-backed MCP selection
  (X.5–X.6), graph traversal (W.8–W.9), similarity-based service resolution
  (W.5, W.7), external vector databases, large-scale indexing (>10K vectors),
  WS streaming of RAG results, multimodal embedding, concurrent RAG execution.

### Done in current branch

- [x] `#R-1` Embedding model registry — extend SQLite schema with `model_type`
  column (`chat`/`embedding`); `scripts/install-embedding-model.sh` pins a GGUF
  embedding model with committed SHA-256; autoseed on boot. Maps to `W.1`,
  `W.2`.
  - Added `model_type` column via idempotent ALTER in `registry_schema_migrate()`
  - Extended validation, create, list, and envelope functions
  - Added `model_inference_registry_list_by_type()` and `model_inference_registry_find_embedding_model()`
  - Created `scripts/install-embedding-model.sh` pinning nomic-embed-text-v1.5 Q8_0
  - Extended server autoseed for embedding model fixtures
  - Contract test: `tests/embedding-model-registry-contract.{sh,php}`

- [x] `#R-2` Embedding worker lifecycle — spawn second `LlamaCppWorker` with
  `extra_argv: ['--embedding']`; health probe on `/health`; `EmbeddingSession`
  cache (one active embedding worker, separate from inference worker). Maps to
  `W.2`.
  - Created `domain/embedding/embedding_session.php` — mirrors InferenceSession
  - Spawns worker with `extra_argv: ['--embedding']`; calls `/v1/embeddings`
  - L2 normalization on returned vectors; one-active-worker policy
  - Bootstrapped in `server.php` with shutdown drain handler
  - Contract test: `tests/embedding-worker-contract.{sh,php}`

- [x] `#R-3` `contracts/v1/embedding-request.contract.json` — typed envelope
  `{texts[], model_selector, options:{normalize, truncate}}`; validation with
  rejection codes. Maps to `W.2`, `W.4`.
  - Created `contracts/v1/embedding-request.contract.json` with full shape
  - Created `domain/embedding/embedding_request.php` — `EmbeddingRequestValidationError` + `model_inference_validate_embedding_request()`
  - 33-rule contract test: `tests/embedding-request-envelope-contract.{sh,php}`

- [x] `#R-4` `POST /api/embed` — real embedding generation via llama.cpp
  `/v1/embeddings`; returns `{embeddings[], dimensions, model, tokens_used,
  duration_ms}`. Maps to `W.1`, `W.2`.
  - Created `http/module_embed.php` — POST /api/embed endpoint
  - Router module order grew to include `embed` between registry and inference
  - Wired `$getEmbeddingSession` through router + server handler (optional param, backward-compatible)
  - Updated catalog: added `embed` surface to live API
  - Updated parity test: embed probe, shipped list, exception catch
  - Contract test: `tests/embedding-generation-contract.{sh,php}`

- [x] `#R-5` Document ingest — `POST /api/documents` accepts plain text body,
  stores in object store under flat key `doc-{16hex}`, returns `{document_id,
  byte_length, sha256_hex}`. Maps to `X.1`.
  - Created `domain/retrieval/document_store.php` — ingest, get, list, schema migration
  - Created `http/module_ingest.php` — POST /api/documents, GET /api/documents, GET /api/documents/{id}
  - Router module order grew to include `ingest` between embed and inference
  - Catalog: added `documents_list`, `documents_create`, `document_get` + error codes
  - Contract test: `tests/document-ingest-contract.{sh,php}`

- [x] `#R-6` Text chunking engine — `domain/retrieval/text_chunker.php` with
  configurable strategy (fixed-size with overlap);
  `contracts/v1/chunk-envelope.contract.json`. Maps to `X.2`.
  - Created `domain/retrieval/text_chunker.php` — `model_inference_chunk_text()` with configurable chunk_size + overlap
  - Chunk ID format: `chk-{doc_prefix_8hex}-{sequence_4digit}` (deterministic)
  - SQLite persistence: chunks table with schema migration, persist, list_by_document
  - Created `contracts/v1/chunk-envelope.contract.json`
  - 60-rule contract test: `tests/text-chunker-contract.{sh,php}`

- [x] `#R-7` Chunk persistence — embed + store chunks to object store keyed
  `chk-{doc_prefix}-{seq}`; chunk metadata in SQLite;
  `GET /api/documents/{document_id}/chunks`. Maps to `X.2`, `W.3`.
  - Auto-chunks on document ingest: `POST /api/documents` now chunks + persists
  - Chunk text stored to object store via `model_inference_chunk_store_texts()`
  - Added `GET /api/documents/{document_id}/chunks` endpoint
  - Catalog: added `document_chunks` surface
  - Contract test: `tests/chunk-persistence-contract.{sh,php}`

- [x] `#R-8` Vector store — persist embedding vectors to object store keyed
  `vec-{16hex}`; vector metadata in SQLite linking chunk_id → vector_id →
  embedding model. Maps to `W.3`.
  - Created `domain/retrieval/vector_store.php` — schema migration, store, load, list
  - Vectors stored as JSON float arrays in object store under `vec-{16hex}` keys
  - SQLite metadata: vectors table linking chunk_id → vector_id → embedding_model_id
  - `model_inference_vector_load_all()` and `_load_all_for_document()` for retrieval
  - Contract test: `tests/vector-store-contract.{sh,php}`

- [x] `#R-9` Brute-force cosine similarity — pure function
  `cosine_similarity(array $a, array $b): float`; vector search over stored
  vectors returning top-K ranked results. Maps to `W.4`.
  - Created `domain/retrieval/cosine_similarity.php` — `model_inference_cosine_similarity()` + `model_inference_vector_search()`
  - Pure functions: no database, no object store, no I/O
  - top-K ranking with min_score filtering, sorted descending by score
  - 16-rule contract test: `tests/cosine-similarity-contract.{sh,php}`

- [x] `#R-10` `POST /api/retrieve` — retrieval endpoint: embed query → scan
  vectors → return ranked chunks with scores;
  `contracts/v1/retrieval-request.contract.json`. Maps to `X.1`, `X.3`.
  - Created `domain/retrieval/retrieval_pipeline.php` — `model_inference_retrieval_search()`
  - Created `http/module_retrieve.php` — POST /api/retrieve endpoint
  - Created `contracts/v1/retrieval-request.contract.json`
  - Request validation: query, model_selector, optional document_ids/top_k/min_score
  - Router module order grew to include `retrieve` between ingest and inference
  - Catalog + parity test updated
  - Contract test: `tests/retrieval-pipeline-contract.{sh,php}`

- [x] `#R-11` `POST /api/rag` — end-to-end RAG pipeline: accept query +
  document_id → retrieve top-K context → augment prompt with context → forward
  to inference engine → return grounded completion. Maps to `X.1`.
  - Created `domain/retrieval/rag_orchestrator.php` — `model_inference_rag_execute()`, `_rag_build_prompt()`, `_validate_rag_request()`
  - Dual model_selector: separate chat + embedding model selectors
  - Prompt augmentation: context block with numbered chunks + system instruction
  - Wired as `POST /api/rag` in module_retrieve.php
  - Contract test: `tests/rag-orchestrator-contract.{sh,php}`

- [x] `#R-12` RAG telemetry — extend `InferenceMetricsRing` pattern for
  embedding + retrieval metrics (embedding_latency_ms, retrieval_latency_ms,
  chunks_scanned, vectors_scanned, context_tokens). Maps to `X.7`.
  - Created `domain/telemetry/rag_metrics.php` — `RagMetricsRing` (same bounded-FIFO pattern)
  - Tracks: embedding_ms, retrieval_ms, inference_ms, total_ms, chunks_used, vectors_scanned, tokens_in/out
  - `GET /api/telemetry/rag/recent` endpoint added to module_telemetry
  - 24-rule contract test: `tests/rag-telemetry-contract.{sh,php}`

- [x] `#R-13` Semantic-DNS: register embedding + retrieval capabilities as
  attributes on existing `king.inference.v1` service; update routing
  diagnostic. Maps to `W.6`.
  - Extended `model_inference_semantic_dns_register()` with `supports_embedding`, `supports_retrieval`, `supports_rag`, `embedding_dimensions` attributes
  - Same service type `king.inference.v1` (not a separate type)
  - Boot profile in server.php sets embedding capabilities
  - Contract test: `tests/semantic-dns-embedding-contract.{sh,php}`

- [x] `#R-14` Catalog parity update — grow `api-ws-contract.catalog.json` with
  all R-batch surfaces; update parity gate; promote relevant target-shape
  entries. Maps to `W`, `X`.
  - Catalog maintained incrementally through R-1–R-13 (no drift)
  - All R-batch surfaces in live catalog: embed, documents_list, documents_create, document_get, document_chunks, retrieve, rag, telemetry_rag_recent
  - Parity test covers all 18 live API surfaces + probes
  - Error codes: document_not_found, document_too_large added
  - Shipped list prevents R-batch surfaces from leaking into target-shape

- [x] `#R-15` `scripts/rag-smoke.sh` — end-to-end: ingest doc → verify chunks
  → embed → retrieve → RAG completion; runs in compose. Maps to `X.7`.
  - Created `scripts/rag-smoke.sh` — 10-phase end-to-end RAG smoke test
  - Phases: syntax → contract tests (R-batch + M-batch) → compose boot →
    embedding model probe → document ingest → chunk verification → embedding →
    retrieval → RAG completion → RAG telemetry
  - Graceful skip for phases 6-8 when embedding model fixture not installed
  - Runs all 23 R-batch + M-batch offline contract tests as regression gate

- [x] `#R-16` README update + target-shape fences + ISSUES section review.
  Tracker boxes remain unticked.
  - README: added R-batch "What works today" section, R-batch leaf table,
    embedding model install step, RAG smoke section, R-batch scope fences
  - Layout tree updated with all R-batch files (30 test pairs total)
  - Scope fences: 8 explicit R-batch fences (hybrid retrieval, external vector
    DBs, HNSW/IVF, PDF/HTML parsing, multimodal, large-scale, WS streaming,
    concurrent RAG)

### Next step (R-batch)

- R-batch sprint complete. All 16 leaves (#R-1 → #R-16) are closed. 30
  contract tests green. Branch `feature/rag-pipeline` is merge-ready.
  Tracker boxes remain unticked pending post-merge sweep on `main`.

## S-batch: Semantic Discovery (branch `feature/rag-pipeline`)

> Stacks directly on top of R-batch. Reuses `model_inference_embed()` +
> `model_inference_cosine_similarity()` + `model_inference_vector_search()`
> to replace keyword-only service/tool selection with vector-ranked
> discovery. Maps to tracker bullets **W.5, W.7, X.4, X.5, X.6**.
> Tracker boxes NOT ticked from this branch; post-merge sweep only.

Non-negotiable direction for this batch:

- Brute-force cosine over `service_embeddings` / `tool_embeddings`. No ANN /
  HNSW / IVF. Demo scale only (<1k services).
- BM25 parameters pinned (`k1=1.2`, `b=0.75`). No learned ranker.
- Descriptor text (name + description + capabilities + tags) is the sole
  semantic signal. No graph traversal.
- C-level Semantic-DNS surface is UNCHANGED. The semantic-query path is an
  additive PHP overlay so the existing keyword API keeps its behavior.
- `POST /api/tools/pick` fails closed with `no_semantic_match` when no tool
  scores above `min_score`. No silent default target.
- Out of scope: graph-aware metadata (W.8/W.9), retrieval-driven *document*
  hybrid (stays semantic-only in `/api/retrieve`), fleet-wide model
  placement (V.3-V.5), fine-tuning (Y), advanced extensions (AA).

### Done in current branch

- [x] `#S-1` `service-descriptor.contract.json` + validator
  (`domain/discovery/service_descriptor.php`): typed envelope
  `{service_id, service_type, name, description, capabilities[], tags[]}` +
  `model_inference_service_descriptor_embedding_text()` helper. Maps to `W.5`.
  - 40-rule test: `tests/service-descriptor-contract.{sh,php}`

- [x] `#S-2` `service_embeddings` SQLite table + `svec-{hex}` object-store
  layer (`domain/discovery/service_embedding_store.php`): schema migration,
  upsert-aware store, load_row, load_all (metadata + dense vector),
  list-by-type, delete. Maps to `W.5`, `W.7`.
  - 39-rule test: `tests/service-embedding-store-contract.{sh,php}`

- [x] `#S-3` Embedding composition layer
  (`domain/discovery/service_embedding_upsert.php`):
  `model_inference_service_embedding_upsert()` validates descriptor, assembles
  text, calls injected embedder, persists. Adapter factory converts an
  `EmbeddingSession` + worker into the callable shape. Maps to `W.5`.
  - 15-rule test: `tests/service-embedding-upsert-contract.{sh,php}`

- [x] `#S-4` `POST /api/discover` + envelope parser
  (`http/module_discover.php`). Modes: `keyword | semantic | hybrid`. Wired
  into dispatcher; `discover` added to deterministic module order. Maps to `X.5`.
  - 43-rule envelope test: `tests/discover-envelope-contract.{sh,php}`

- [x] `#S-5` Semantic scorer (`domain/discovery/semantic_discover.php`):
  brute-force cosine over `service_embeddings` with deterministic `service_id`
  tie-break. Maps to `W.7`, `X.5`.
  - 11-rule test: `tests/semantic-discover-contract.{sh,php}`

- [x] `#S-6` Hybrid scorer (`domain/discovery/hybrid_discover.php`):
  normalized BM25 (k1=1.2, b=0.75) + cosine fusion with `alpha`, min-max
  normalization before fusion, deterministic tie-break on zero scores. Maps
  to `X.4`.
  - 30-rule test: `tests/hybrid-discover-contract.{sh,php}`

- [x] `#S-7` `tool-descriptor.contract.json` + `tool_embeddings` store +
  `model_inference_validate_tool_descriptor()` with full mcp_target shape.
  Maps to `W.7`.
  - 43-rule test: `tests/tool-descriptor-contract.{sh,php}`

- [x] `#S-8` Tool embedding upsert
  (`model_inference_tool_embedding_upsert()`): same pattern as S-3 for tools,
  persists to `tvec-{hex}`. Maps to `W.7`. (Tested in `tool-descriptor-contract`.)

- [x] `#S-9` `POST /api/tools/discover` + semantic/hybrid tool scorers
  (`domain/discovery/tool_discover.php`): same shape as `/api/discover`,
  returns ranked tools with `mcp_target`. Maps to `X.6`.
  - 11-rule test: `tests/tool-discover-contract.{sh,php}`

- [x] `#S-10` `POST /api/tools/pick` + `model_inference_mcp_pick()`
  wrapper (`domain/discovery/mcp_pick.php`): fails closed with
  `McpPickNoMatchException` / `no_semantic_match` when no tool scores above
  `min_score`. Maps to `W.7`, `X.6`.
  - 5-rule test: `tests/mcp-pick-contract.{sh,php}`

- [x] `#S-11` `DiscoveryMetricsRing` + `GET /api/telemetry/discovery/recent`
  (`domain/telemetry/discovery_metrics.php`): bounded-FIFO ring with
  `embedding_ms`, `search_ms`, `total_ms`, `candidates_scanned`, `mode`,
  `alpha`, `query_length`, `service_type`, `top_k`, `min_score`. Maps to `X.5`.
  - 32-rule test: `tests/discovery-telemetry-contract.{sh,php}`

- [x] `#S-12` `dns_semantic_query.php` overlay: intersects
  `king_semantic_dns_discover_service` candidates with
  `semantic_discover` results. Fails closed (empty intersection) when a
  service is only in embeddings but not registered in DNS. Maps to `W.5`, `W.7`.
  - 10-rule test: `tests/dns-semantic-query-contract.{sh,php}`

- [x] `#S-13` Catalog parity grown by 4 live surfaces (`discover`,
  `tools_discover`, `tools_pick`, `telemetry_discovery_recent`) + 4 new
  error codes (`invalid_service_descriptor`, `invalid_tool_descriptor`,
  `embedding_worker_unavailable_discovery`, `no_semantic_match`).
  `contract-catalog-parity-contract` stays green; `router-module-order`
  updated.

- [x] `#S-14` `scripts/discovery-smoke.sh` — 10-phase end-to-end
  (syntax → contract suite → compose → embedding probe → keyword
  discovery → semantic → hybrid → tools discover → tools pick
  fail-closed → telemetry). README updated with S-batch table + scope
  fences + layout entries. ISSUES updated (this section).

### Next step (S-batch)

- S-batch sprint complete. All 14 leaves (#S-1 → #S-14) are closed. 11 new
  contract tests green; no regressions in existing 30+ tests. Branch
  `feature/rag-pipeline` carries both R-batch and S-batch and is
  merge-ready. Tracker boxes **W.5, W.7, X.4, X.5, X.6** remain unticked
  pending post-merge sweep on `main`.

## T-batch: Chat Memory & Small-Model Reliability (branch `feature/rag-pipeline`)

> Follow-on to R/S batches on the same branch. Turns the model-inference
> demo from a single-turn primitive into a working multi-turn chat against
> SmolLM2-135M, including the surface-level tuning needed to make a tiny
> model actually usable for context recall. Does NOT tick any readiness
> tracker boxes — this is demo-UX hardening, not a new capability axis.

Non-negotiable direction for this batch:

- No model swap. Everything must work on the existing 135M fixture.
- Server envelope stays backward-compatible. Pre-T-batch callers that send
  only `prompt` (no `messages[]`, no penalties) must see identical behaviour.
- Shared resolver for HTTP and WS transports so behaviour can't drift.
- Honest documentation of the failure modes and the specific knobs that fix
  them — so readers know both *what to do* and *why*.

### Done in current branch

- [x] `#T-1` Multi-turn chat memory: optional `messages[]` field on the
  inference-request envelope, plumbed through HTTP + WS paths to llama.cpp
  `/v1/chat/completions`, with transcripts persisted including messages.
  Browser UI (`public/chat.html`) now keeps an in-memory `state.history`
  of `{role, content}` turns, re-sent on every submit. Pre-T-1 clients
  that send only `prompt` are unchanged.
  - Shared resolver: `domain/inference/chat_messages.php`
  - Validator + schema: `domain/inference/inference_request.php` (adds
    `messages` as optional top-level key; roles `system|user|assistant`;
    1–64 items; content 1–32768 chars)
  - Transcript round-trip: `domain/inference/transcript_store.php`
  - Contract test: `tests/chat-memory-contract.{sh,php}` (37 rules)

- [x] `#T-2` Anti-collapse surface fix: add a default system prompt,
  lower default temperature to 0.2, and cap history at 8 turns (down
  from 32). Stops SmolLM2-135M from mode-collapsing into training-data
  snippets (the observed "Croatia/beaches/colors" loop). Single-fact
  recall (`"What is my name?"`) becomes reliable.
  - Edits: `public/chat.html` (`state.systemPrompt`, `pushHistory` cap,
    temperature default 0.7 → 0.2)
  - Live proof: name-recall across a fresh Playwright session.
  - Limitation surfaced: multi-fact follow-up questions still fail
    because the model echo-copies its previous short reply. Resolved
    in #T-3.

- [x] `#T-3` Repetition penalties + stronger system prompt: extend the
  sampling envelope with optional `frequency_penalty` and
  `presence_penalty` (OpenAI-compatible, range -2.0..2.0, default 0.0).
  Plumb both through HTTP + WS paths to `/v1/chat/completions` only when
  non-zero (keeps pre-T-3 payloads identical). UI ships defaults
  `frequency_penalty=0.8, presence_penalty=0.6`. Stronger system prompt
  explicitly forbids repeating/quoting the previous reply and anchors
  the model to the LATEST question.
  - Envelope + validator: `domain/inference/inference_request.php`
    (sampling: new optional fields with float range check)
  - Plumbing: `domain/inference/inference_session.php` (HTTP) and
    `domain/inference/inference_stream.php` (WS); both include the
    penalty fields only when `!== 0.0`
  - UI: `public/chat.html` adds two new numeric inputs and the
    refined system prompt under `state.systemPrompt`
  - Contract JSON: `contracts/v1/inference-request.contract.json`
    documents the new sampling fields
  - Live proof: 4-fact Playwright stress test (name, city, job, food)
    recalled 4/4 correctly on SmolLM2-135M-Instruct/Q4_K. Same test on
    T-2 baseline scored 1/3 (first probe correct, subsequent probes all
    returned `"My name is Julius."`).

- [x] `#T-4` Demo README learnings section: `demo/model-inference/README.md`
  gets a new "Prompting a tiny model for reliable chat memory" section
  documenting the two failure modes (training-data echo, previous-reply
  echo), the three-lever recipe that fixes them (system prompt + penalties
  + short history), the capacity limits that remain (multi-fact extraction
  in one turn, chain reasoning), and pointers to every file where the
  levers live. Positioned next to the existing scope-fences section since
  it's the same *what works / what doesn't* register.

### Next step (T-batch)

- T-batch complete. No new contract tests beyond T-1's `chat-memory-contract`
  (T-2 and T-3 edits are UI defaults + backward-compatible envelope
  extensions that don't need their own test — the existing envelope test
  already covers the optional-field rules). Offline suite: 36 pass / 4 skip
  / 1 pre-existing fail (unrelated to T-batch). No readiness tracker boxes
  move; this is honest demo-UX work on top of the shipped M/R/S capabilities.

## C-batch: Conversation Persistence (branch `feature/rag-pipeline`)

> Closes readiness tracker bullet **V.8** (prompt / cache / checkpoint
> persistence). Every chat turn is now persisted server-side keyed by
> `session_id`; the browser UI writes its `session_id` into `localStorage`
> and rehydrates `state.history` on reload.

Non-negotiable direction:

- Backward-compatible: pre-C-batch callers (no UI rehydration) see
  identical behaviour. The persistence hook is best-effort and never
  fails the inference response.
- SQLite-only at this leaf. Object-store-backed durable replay stays
  fenced — V.8's "checkpoint" axis is a separate hardening axis and is
  honestly listed in the tracker as a tick on the *persistence* half, not
  a claim of full KV-cache durability.
- session_id is client-supplied and NOT authenticated; matches the
  existing inference-request contract's honesty rule.

### Done in current branch

- [x] `#C-1` `conversations` + `conversation_messages` SQLite schema with
  per-session monotonic `seq` and a unique index on (session_id, seq).
  Transactional append path that skips already-persisted client turns
  when the UI re-sends the full messages[] thread.
  - Domain: `domain/conversation/conversation_store.php`
  - Contract: `tests/conversation-store-contract.{sh,php}` (60 rules)

- [x] `#C-2` `GET /api/conversations/{session_id}/messages`,
  `GET /api/conversations/{session_id}` (meta only), and
  `DELETE /api/conversations/{session_id}`. All scoped to the regex
  `^[A-Za-z0-9_.:\-]+$` matching the inference session_id validator.
  - Module: `http/module_conversations.php`
  - Contract: `contracts/v1/conversation-message.contract.json` +
    `tests/conversations-endpoint-contract.{sh,php}` (42 rules)

- [x] `#C-3` HTTP + WS inference paths auto-append each completed turn
  via `model_inference_conversation_append_turn()`. Wrapped in try/catch
  so a persistence failure never corrupts the JSON response or WS stream.
  - Edits: `http/module_inference.php`, `http/module_realtime.php`

- [x] `#C-4` Chat UI persists `session_id` in `localStorage` and
  rehydrates prior turns as `(restored)` bubbles on load, preserving
  the state.history invariant so the next submit re-sends the full
  thread to the model.
  - Edit: `public/chat.html`

- [x] Catalog grew with `conversation_messages_list`, `conversation_meta_get`,
  `conversation_delete`; `contract-catalog-parity-contract` updated.
  Router-module-order contract updated for the new `conversations`
  module; `router-module-order-contract` green.

### Next step (C-batch)

- C-batch complete. 2 new contract tests (60 + 42 = 102 rules). Closes
  tracker bullet **V.8**.

## G-batch: Graph-aware Discovery (branch `feature/rag-pipeline`)

> Closes readiness tracker bullets **W.8** (optional graph-aware
> metadata + relationship traversal) and **W.9** (public contract
> boundary between core semantic discovery and optional graph
> integrations).

Non-negotiable direction:

- Core S-batch ranking stays authoritative and unchanged. The graph
  layer is strictly additive: an /api/discover call without
  `graph_expand` produces bit-identical output to pre-G-batch behaviour.
- Traversal is plain BFS bounded by `max_hops` (1..2 in the HTTP
  surface, 1..3 at the store layer) and a fixed 512-visit budget.
- Edges are NEVER a ranking signal. Graph only widens the candidate
  set. Weighted / shortest-path / MoE-style routing stays fenced under
  tracker V.5.

### Done in current branch

- [x] `#G-1` `service_edges` SQLite schema + unique (from, to, type)
  index + three secondary indexes for fast traversal. CRUD + BFS
  traversal with visit-budget cap.
  - Domain: `domain/discovery/graph_store.php`
  - Contract: `tests/graph-store-contract.{sh,php}` (46 rules)

- [x] `#G-2` `graph_expand` composition takes a core ranked result
  set, walks outgoing edges from the top service_ids, and appends
  unique neighbors under `expanded` tagged `source: "graph_expand"`,
  `semantic_score: null`. Descriptors rehydrated from
  `service_embeddings` when available.
  - Domain: `domain/discovery/graph_expand.php`
  - Contract: `tests/graph-expand-contract.{sh,php}` (28 rules) —
    including the W.9 boundary assertion that core `results` is
    bit-identical with or without `graph_expand`.

- [x] `#G-3` `POST /api/discover` parser accepts an optional
  `graph_expand: {edge_types?: array<string>(0..16, regex
  [a-z][a-z0-9_.-]*), max_hops?: int 1..2}` block. Wired into both the
  keyword and semantic/hybrid branches so all three modes can expand.
  - Edit: `http/module_discover.php`

- [x] `#G-4` `contracts/v1/service-graph.contract.json` pins the
  core-vs-extension boundary explicitly. Tracker W.9 is ticked against
  this file plus the `graph-expand-contract` assertion.

### Next step (G-batch)

- G-batch complete. 2 new contract tests (46 + 28 = 74 rules). Closes
  tracker bullets **W.8** and **W.9**.

## Tracker post-merge sweep (2026-04-20)

Flipped in `READYNESS_TRACKER.md` with proof citations against contract
tests shipped in this branch: **W.5**, **W.7**, **W.8**, **W.9**,
**X.4**, **X.5**, **X.6**, **V.8** — 8 bullets closed in this session.

Still honestly fenced for model-inference scope: V.3 (fleet placement),
V.4 (sharded execution), V.5 (MoE / expert routing), all of Y-batch
(fine-tuning), all of AA-batch (advanced extensions, out-of-core).

## A-batch: Simple Auth / Identity (branch `feature/rag-pipeline`)

> Demo-grade auth layer that binds model-inference conversations to a
> logged-in user for cross-device continuation. Borrows patterns from
> the video-chat auth layer (bcrypt + opaque bearer + middleware-at-
> dispatcher + idempotent demo-user autoseed) but drops the production
> complexity (session refresh, per-frame WS revalidation, full RBAC
> matrix, email-based login) to stay demo-sized.
>
> Scope map: closes readiness tracker section **AB** (4 bullets). Does
> NOT move V / W / X / Y / AA.

Non-negotiable direction:

- **Auth is OPTIONAL.** Every pre-A-batch caller that doesn't send an
  `Authorization: Bearer` header continues to work exactly as before —
  `/api/infer`, `/api/rag`, `/api/discover`, `/api/tools/*`,
  `/api/documents/*`, `/api/conversations/*` all stay open to anonymous
  use. Auth only engages when a Bearer token is present.
- **No king.so changes.** Everything is PHP-level composition on top of
  the existing HTTP primitives.
- **Reuse video-chat patterns; do not import video-chat code.** The
  video-chat auth at `demo/video-chat/backend-king-php/` is the
  reference; we mirror the shape (opaque session ids, bcrypt, middleware
  at dispatcher) but write a new, minimal store + endpoints scoped to
  the model-inference demo's conventions.
- **Honest fences**: per-frame WS revalidation, session refresh, RBAC
  path-rule matrix, SSO/OAuth, password-reset, rate-limit/brute-force
  lockout — all intentionally out of scope for the demo.

### Done in current branch

- [x] `#A-1` Auth store + users/sessions SQLite schema. Domain API:
  `model_inference_auth_create_user`, `verify_credentials`,
  `issue_session`, `validate_session`, `revoke_session`. Passwords
  bcrypt-hashed via `password_hash(PASSWORD_DEFAULT)`. Session ids are
  opaque 32-byte hex strings. Maps to AB.1, AB.2.
  - New file: `backend-king-php/domain/auth/auth_store.php`
  - Contract: `tests/auth-store-contract.{sh,php}`

- [x] `#A-2` `POST /api/auth/login` + `POST /api/auth/logout` +
  `GET /api/auth/whoami`. Login accepts `{username, password}` JSON,
  issues a session with TTL (env `MODEL_INFERENCE_SESSION_TTL_SECONDS`,
  default 12h, clamped 60s–30d), returns
  `{session: {id, expires_at, ttl_seconds}, user: {id, username, display_name, role}}`.
  Logout revokes. Whoami echoes `$request['user']`. Maps to AB.1, AB.2.
  - New file: `backend-king-php/http/module_auth.php`
  - New contract JSONs: `contracts/v1/auth-request.contract.json`,
    `contracts/v1/user-session.contract.json`
  - Contract test: `tests/auth-endpoint-contract.{sh,php}`

- [x] `#A-3` Non-blocking auth middleware. Extracts
  `Authorization: Bearer <token>`, validates against the sessions table
  (JOIN users + role). On hit: hydrates `$request['user']` and
  `$request['session']`. On miss (no header, invalid, expired, revoked):
  request proceeds **anonymously** (no 401). Called once from the
  dispatcher before module fan-out. Maps to AB.1.
  - New file: `backend-king-php/domain/auth/auth_middleware.php`
  - Edit: `backend-king-php/http/router.php` (hook insertion)
  - Contract: `tests/auth-middleware-contract.{sh,php}`

- [x] `#A-4` Conversation ownership binding. Add nullable `user_ref`
  INTEGER column to `conversations` (idempotent `ALTER TABLE`). When
  authenticated, `model_inference_conversation_append_turn()` populates
  `user_ref = user.id`. `GET /api/conversations/{session_id}/messages`
  and `DELETE /api/conversations/{session_id}` enforce ownership: if
  `user_ref` is set, requester must be the owner (403
  `ownership_denied` otherwise). Anonymous conversations
  (user_ref=NULL) remain readable by anyone with the session_id —
  pre-A-batch behavior preserved. Maps to AB.3.
  - Edits: `backend-king-php/domain/conversation/conversation_store.php`,
    `backend-king-php/http/module_inference.php`,
    `backend-king-php/http/module_realtime.php`,
    `backend-king-php/http/module_conversations.php`
  - Contract: `tests/conversation-ownership-contract.{sh,php}`

- [x] `#A-5` WebSocket handshake-time auth. In the WS upgrade path
  (`module_realtime.php`), invoke the middleware on the upgrade
  request. On valid Bearer token: bind user into the run-session
  context. On missing/invalid: stream runs anonymously. No per-frame
  revalidation (fenced). Explicit rule in the contract test: a token
  revoked mid-stream does NOT need to kill the active stream. Maps to
  AB.4.
  - Edit: `backend-king-php/http/module_realtime.php`
  - Contract: `tests/realtime-auth-contract.{sh,php}`

- [x] `#A-6` Demo user autoseed. `server.php` calls
  `model_inference_auth_seed_demo_users($pdo)` at boot. Seeds three
  fixture users idempotently from
  `backend-king-php/fixtures/demo-users.json` (admin / alice / bob).
  Passwords bcrypt-hashed at seed time. Credentials overridable via
  env vars `MODEL_INFERENCE_DEMO_{ADMIN,ALICE,BOB}_{USERNAME,PASSWORD}`.
  Also ship `scripts/seed-users.sh` for manual / CI invocation. Maps to
  AB.1.
  - Edit: `backend-king-php/server.php`
  - New files: `backend-king-php/scripts/seed-users.sh`,
    `backend-king-php/fixtures/demo-users.json`
  - Contract: `tests/auth-seed-contract.{sh,php}`

- [x] `#A-7` Chat UI login surface. Minimal additions to
  `public/chat.html`: on boot `GET /api/auth/whoami`; if 401, show an
  inline login form; on login success store
  `{token, expires_at, user}` in `localStorage` under
  `king-model-inference-auth` and attach `Authorization: Bearer` to
  all REST + WS calls. Click-on-username to logout. Anonymous mode
  unchanged when the user doesn't log in.

- [x] `#A-8` Catalog parity + router order + README + tracker tick +
  smoke. Catalog grows with `auth_login`, `auth_logout`, `auth_whoami`
  + 4 new error codes (`invalid_credentials`, `session_expired`,
  `session_revoked`, `ownership_denied`).
  `contract-catalog-parity-contract` + `router-module-order-contract`
  updated. README gains "Auth (optional, demo-grade)" section near
  the C-batch block. `scripts/auth-smoke.sh` mirrors
  `discovery-smoke.sh`. On merge to main, AB.1–AB.4 flip to `[x]` with
  contract-test citations.

### Demo user data (seeded at boot from `fixtures/demo-users.json`)

| Username | Password | Role | Display name |
|---|---|---|---|
| `admin` | `admin123` | admin | Admin |
| `alice` | `alice123` | user | Alice |
| `bob` | `bob123` | user | Bob |

Passwords bcrypt-hashed with `password_hash(PASSWORD_DEFAULT)` at seed
time. These are **demo-only** credentials — identical treatment to the
video-chat demo's `admin@intelligent-intern.com / admin123` fixtures.
Env overrides exist for CI and production-hardening runs that need to
rotate them.

### Out of scope (honestly fenced for the demo)

- Per-frame WebSocket session revalidation (handshake-time only)
- Session refresh / rotation (logout + re-login covers it)
- RBAC path-rule matrix (flat `user | admin`, no path guards)
- Multi-tenant / organization / team scoping
- SSO / OAuth / OIDC
- Password reset / account recovery / email verification
- Rate limiting / brute-force lockout
- User profile management surface beyond the demo seeds
Roster-Modell (definiert):
- Server ist Source of Truth für Teilnehmerliste (keine vollständige Client-Schattenliste als Primärquelle).
- Abfrageform: `page`, `page_size`, `search`, `sort`, optionale `status`-Filter.
- Client rendert nur sichtbaren Bereich (virtuelles Rendering), nicht die komplette Liste.
- Paging bleibt stabil über Cursor/Sort-Key (keine springende Reihenfolge bei Updates).
- Aktionen (mute/remove/role-change) laufen gegen serverseitige IDs und aktualisieren nur betroffene Rows.
- UI-Regel: Roster kann 10k Einträge verwalten, ohne Main-Video-Rendering zu blockieren.

Presence-/Roster-Update-Strategie (definiert):
- Primärpfad: Delta-Events (`join`, `leave`, `status_change`, `role_change`, `media_state_change`) statt Full Snapshot.
- Reihenfolge/Sicherheit:
  - Jeder Delta-Event trägt monotone `seq`/`version`.
  - Client verwirft alte/out-of-order Events.
- Re-Sync-Regel:
  - Nur bei Gap-Erkennung (`missing_seq`) oder Reconnect wird ein gezielter Partial/Full-Re-Sync angefordert.
  - Re-Sync bleibt serverseitig paginiert; kein erzwungener 10k-Einmaldump.
- UI-Aktualisierung:
  - nur betroffene Teilnehmerzeilen und abgeleitete Counter werden aktualisiert.
  - kein globales Re-Rendern der gesamten Roster-Struktur pro Event.

Lastgrenzen + Backpressure (definiert):
- Reactions:
  - soft-limit pro Sender: bis 20/s normal durchlassen.
  - darüber batching auf Server-Seite (z. B. 25er Batch-Event), statt Flood einzelner Broadcasts.
  - keine harte UI-Warnleiste für normalen Flood-Fall; System drosselt intern.
- Chat:
  - rate-limit pro Sender + bounded queue; bei Überlauf drop nach Policy (latest-wins oder reject mit code).
  - Nachrichtenlänge bleibt begrenzt; direkte oversized `chat/send` Frames werden serverseitig abgelehnt.
  - Frontend-Produktpfad wandelt oversized Text/Paste-Inhalte in Datei-Anhänge um (siehe #18), statt GB-große WS-Nachrichten zu senden.
- Participant-Events:
  - Join/Leave/Role/Mute Events als delta-stream mit coalescing bei Burst.
  - serverseitige fanout-throttles für nicht-kritische Events (z. B. speaking indicators).
- Backpressure-Prinzip:
  - kritisch (moderation/security) vor kosmetisch (typing/speaking/reaction bursts).
  - unter Last zuerst Sampling/Batching, erst danach harte Drops.

SFU-Fanout + Admission-Policy (definiert):
- Admission:
  - Session-Join nur mit gültigem Auth-/Room-Kontext.
  - harte Obergrenzen pro Room (gemäß Tier-Ziel) + kontrollierte Warteschlange bei Überlauf.
  - Priorisierte Aufnahme für Host/Admin/Owner-Rollen.
- Fanout-Grundsatz:
  - Server entscheidet über Forwarding-Sets, nicht der Client.
  - pro Client wird nur das notwendige Stream-Set geliefert (Top-Window + relevante Audios).
  - nicht sichtbare Teilnehmer erhalten keine unnötigen Full-Video-Streams.
- Laststeuerung:
  - adaptives Simulcast/SVC-Layer-Forwarding (falls verfügbar).
  - unter Last erst Qualität reduzieren, dann sekundäre Streams ausdünnen.
- Moderation/Sicherheit:
  - mute/remove/role-change greifen serverautoritativ und sofort im Forwarding.
  - isolierte bzw. gebannte Teilnehmer verlieren Stream-Fanout umgehend.

---

### #4 Issue-Splitter: Folge-Issues systematisch anlegen

Ziel:
- Nach Abschluss von #1-#3 automatisch konsistente Folge-Issues (#5 bis #N) erzeugen.

Checklist:
- [x] Abhängigkeiten aus #1-#3 in Workstreams übersetzen.
- [x] Pro Workstream konfliktfreie Dateizuständigkeit definieren.
- [x] Issue-Templates mit DoD, Tests, Telemetrie, Migrationspfad erzeugen.
- [x] Reihenfolge/Parallelisierung dokumentieren.

Definition of done:
- Folge-Issues sind so geschnitten, dass parallele Umsetzung ohne gegenseitige Blocker möglich ist.

Notizen:
- Diese Phase ist Pflicht, bevor große Implementierungswellen gestartet werden.

Workstreams aus #1-#3 (definiert):
- WS-A Architektur + Runtime Contracts:
  - Inputs: #1 Architekturkarte, #2 Stufenvertrag A/B/C.
  - Output-Issues: Fallback-State-Machine, Capability-Gates, Runtime-Guardrails.
- WS-B Frontend Realtime UX + Roster Scale:
  - Inputs: #3 Visible-Window, Roster-Modell, Delta-Strategie.
  - Output-Issues: Top-5-Window, Virtualized Roster, Row-level Updates.
- WS-C Backend/SFU Core:
  - Inputs: #3 Fanout/Admission + Backpressure-Regeln.
  - Output-Issues: Admission Queue, selective Fanout, coalesced event streams.
- WS-D Observability + Load Verification:
  - Inputs: #2 Telemetrie-Matrix + #3 Kapazitätsziele.
  - Output-Issues: fallback transition telemetry, SLO dashboards, load profiles.
- WS-E Migration + Hygiene:
  - Inputs: #2 TS-WLVC-Abbau, #10 Repo-Hygiene.
  - Output-Issues: remove product TS fallback path, CI guard checks, artifact policy.

Dateizuständigkeit pro Workstream (konfliktfrei, definiert):
- WS-A Architektur + Runtime Contracts (primärer Write-Scope):
  - `demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.vue`
  - `demo/video-chat/frontend-vue/src/domain/realtime/callMediaPreferences.js`
  - `demo/video-chat/frontend-vue/src/support/runtime.js`
- WS-B Frontend Realtime UX + Roster Scale (primärer Write-Scope):
  - `demo/video-chat/frontend-vue/src/domain/calls/AdminCallsView.vue`
  - `demo/video-chat/frontend-vue/src/domain/calls/UserDashboardView.vue`
  - `demo/video-chat/frontend-vue/src/domain/users/AdminUsersView.vue`
- WS-C Backend/SFU Core (primärer Write-Scope):
  - `demo/video-chat/backend-king-php/http/module_realtime.php`
  - `demo/video-chat/backend-king-php/http/module_calls.php`
  - `demo/video-chat/backend-king-php/domain/realtime/realtime_reaction.php`
  - `demo/video-chat/backend-king-php/domain/calls/call_management.php`
- WS-D Observability + Load Verification (primärer Write-Scope):
  - `demo/video-chat/contracts/v1/api-ws-contract.catalog.json`
  - `demo/video-chat/scripts/smoke.sh`
  - `demo/video-chat/backend-king-php/tests/*.php`
- WS-E Migration + Hygiene (primärer Write-Scope):
  - `demo/video-chat/frontend-vue/src/lib/wasm/wasm-codec.ts`
  - `demo/video-chat/frontend-vue/src/lib/wavelet/*`
  - `.gitignore`, CI workflow files

Issue-Template (Standard, definiert):
- Titel:
  - `[WS-<A..E>] <kurzer Ergebnisname>`
- Pflichtfelder:
  - Ziel/Business-Nutzen
  - Scope (inkl. explizit Out-of-Scope)
  - Write-Scope (Dateipfade)
  - Abhängigkeiten (blockers/blocked-by)
- DoD (muss vollständig erfüllt sein):
  - Funktionale Akzeptanzkriterien erfüllt
  - Keine Abweichung vom UX-Vertrag im betroffenen UI-Bereich
  - Keine Regression in Auth/Realtime/Moderation
- Tests:
  - Unit/Component-Tests (wo sinnvoll)
  - Contract-/API-Tests (bei Backend/Realtime-Änderungen)
  - Smoke-Szenario (Happy Path + mind. 1 Failure Path)
- Telemetrie:
  - neue/angepasste Events benannt
  - reason-codes + minimale Metrikfelder dokumentiert
  - Dashboard/Query-Hinweis für Verifikation hinterlegt
- Migrationspfad:
  - Rollout-Strategie (feature-flag/canary/gradual)
  - Rollback-Regel (wann + wie)
  - Daten-/Konfig-Migration explizit benannt (falls nötig)

Reihenfolge + Parallelisierung (definiert):
- Phase 1 (seriell, blocker-first):
  - WS-A (Runtime-Contracts/Fallback-State-Machine) zuerst finalisieren.
  - Grund: WS-B/WS-C brauchen stabile Runtime-Entscheidungen.
- Phase 2 (parallel möglich, disjunkte Write-Scopes):
  - WS-B (Frontend Roster/Top-5 UX) parallel zu WS-C (Backend/SFU Core).
  - WS-D (Telemetry/Load) kann parallel anlaufen, sobald erste Events/Contracts stehen.
- Phase 3 (seriell vor Release):
  - WS-E (Migration/Hygiene) nach WS-A/B/C, damit finale Pfade bereinigt werden.
  - Abschluss mit integrierter Smoke/Load-Verifikation aus WS-D.
- Merge-/Integrationsregel:
  - nur fast-forward in Integrationsbranch, wenn jeweiliger WS-DoD + Smoke erfüllt ist.
  - bei Scope-Kollisionen hat der definierte Write-Scope Vorrang, sonst Re-Slice statt Hotfix-Chaos.

---

### #5 Background-Filter aus `intelligent-intern` übernehmen (Implementierung)

Ziel:
- Blur/Background-Handling in `frontend-vue` auf bewährte Bausteine heben.

Checklist:
- [x] `background_filter_controller.ts`-Pattern übernehmen.
- [x] Backend-Selection/Fallback (FaceDetector -> MediaPipe -> TFJS -> center_mask) integrieren.
- [x] Preview-Prefs-Parität für Blur/Backdrop/Quality herstellen.
- [x] Runtime-Metriken und Baseline-Gates übernehmen.
- [x] UI-Controls (Blur-Mode/Backdrop/Qualität) stabil an Stores binden.

Definition of done:
- Blur funktioniert robust auf unterschiedlichen Browsern/GPU-Profilen.
- Bei Backend-Ausfall bleibt ein funktionaler Fallback aktiv.

Quellen:
- `../intelligent-intern/services/app/compat/src/modules/videocall/background_filter_stream.ts`
- `../intelligent-intern/services/app/compat/src/modules/videocall/background_filter_backend_selector.ts`
- `../intelligent-intern/services/app/compat/src/modules/videocall/preview_prefs.ts`

---

### #6 Codec-/Transport-Fallback implementieren (Implementierung)

Ziel:
- Vertrag aus #2 produktiv implementieren.

Checklist:
- [x] Runtime-Capability-Erkennung (WASM/WLVC/WebRTC).
- [x] `createHybrid*`-Pfad für Produktbetrieb entschärfen: kein TS-WLVC-Runtime-Fallback.
- [x] Sauberer Wechsel WLVC <-> nativer WebRTC ohne Session-Abbruch.
- [x] Persistente Telemetrie-Events für Fallback-Transitions.
- [x] UI-neutrales Handling (kein störender Fehlerbanner bei erwarteten Fallbacks).

Definition of done:
- Clients können bei fehlendem WASM oder WLVC-Problemen stabil weiterarbeiten.

---

### #7 Large-Call Teilnehmermodell (Implementierung)

Ziel:
- Tausende Teilnehmer verwalten, aber nur kleines Sichtfenster rendern.

Checklist:
- [x] "Top-5 visible" Logik (Active Speaker + Pinning) implementieren.
- [x] Roster-Rendering virtualisieren/paginieren.
- [x] Serverseitige Suche/Filterung/Sortierung integrieren.
- [x] Rechte-/Moderationsaktionen auf große Listen skalieren.

Definition of done:
- UI bleibt responsiv bei hoher Teilnehmerzahl.
- Keine Full-List-Repaints bei jedem Presence-Event.

---

### #8 Media-Gateway/SFU Integrationspfad (Implementierung)

Ziel:
- Entscheidung und Integration zwischen King-Backend-SFU-Pfad und `demo/media-gateway`-Pfad.

Checklist:
- [x] Zielarchitektur festlegen: was ist Produktpfad, was ist Referenz/PoC.
- [x] Signaling-Vertrag zwischen Backend und Gateway festzurren.
- [x] Auth/JWT/Room-Bindung und Security-Hardening abgleichen.
- [x] Interop-Testmatrix erstellen (Join/Leave, Track publish/unpublish, reconnect).

Definition of done:
- SFU-Pfad ist eindeutig, reproduzierbar testbar, und ohne Architektur-Dopplung.

Architekturentscheidung (v1, festgelegt):
- Produktpfad v1: `demo/video-chat/backend-king-php` ist der aktive SFU-/Signaling-Pfad.
  - REST auf HTTP-Listener.
  - WebSocket auf WS-Listener (`/ws` + `/sfu`), server mode getrennt (`http` vs `ws`).
  - Frontend `frontend-vue` bindet an `/sfu` via `src/lib/sfu/sfuClient.ts`.
- `demo/media-gateway` bleibt in v1 als historische Referenz/PoC-Entscheidung offen (nicht produktiv verdrahtet und im aktuellen Checkout nicht vorhanden).
  - Zweck: Vorarbeit für späteren externen Gateway-Pfad (AMQP/Proto/QUIC/WebRTC-RS).
  - Kein paralleler Produktbetrieb von zwei SFU-Laufwegen in v1.
- Konsequenz:
  - Fehler wie `websocket_endpoint_disabled` auf Port `18080` sind erwartbar, wenn `/sfu` auf HTTP-Listener aufgerufen wird.
  - Für v1 ist die korrekte Betriebsannahme: `/sfu` nur auf dem WS-Listener.

Signaling-Vertrag (v1, festgezurrt):
- Kanal-Split:
  - `/ws`: WebRTC-Signaling zwischen Peers (`call/offer`, `call/answer`, `call/ice`, `call/hangup`).
  - `/sfu`: Publisher/Track/Frame-Signaling (`sfu/*`) für den aktiven SFU-Pfad.
- Kanonische Identifier:
  - `room_id` ist kanonisch.
  - `call_id` bleibt Brücken-Alias am AMQP-Envelope (`topic=call.signaling`, `payload.call_id = room_id`).
  - `peer_id` (Gateway-Protobuf) mappt auf Benutzer/Publisher-Kontext (`user_id`/`publisher_id` je Nachrichtentyp).
- Namenskonvention am Wire:
  - Primär `snake_case` für JSON-Keys an Backend/Gateway-Grenzen.
  - Übergangs-Aliase (camelCase) sind nur Kompatibilität auf Adapter-Ebene; Ziel bleibt `snake_case` als Contract-Quelle.
- Mapping Backend <-> Gateway:
  - `call/offer` <-> Protobuf `SessionDescription{ type=\"offer\" }`
  - `call/answer` <-> Protobuf `SessionDescription{ type=\"answer\" }`
  - `call/ice` <-> Protobuf `IceCandidate` (Bridge-JSON nutzt `type=\"candidate\"` + `candidate{sdpMid,sdpMLineIndex}`)
  - `call/hangup` <-> Protobuf `LeaveRequest` (optional `RoomEnd` für Host/Room-Ende)
- `/sfu` Message-Vertrag:
  - Server -> Client: `sfu/welcome`, `sfu/joined`, `sfu/published`, `sfu/tracks`, `sfu/unpublished`, `sfu/frame`, `sfu/publisher_left`.
  - Client -> Server: `sfu/publish`, `sfu/subscribe`, `sfu/unpublish`, `sfu/frame`, `sfu/leave`.
  - `sfu/join` wird im Produktpfad nicht als bindender Command benötigt (Room kommt aus WS-Query `room`, Rolle aus Query `role`); bestehende Clients dürfen ihn tolerant senden.
- Nachrichtenkonsistenz:
  - Pflichtfelder pro SFU-Frame: `track_id`, `timestamp`, `data`, `frame_type`.
  - Legacy-Key `frameType` bleibt bis Adapter-Bereinigung kompatibel, ist aber nicht kanonisch.
  - Pflichtfelder pro Call-Signaling: `type`, `target_user_id`, `payload`.
  - Ziel: kein Typ-/Key-Mix in Kernpfaden; Brücken normalisieren beim Eintritt.

Auth/JWT/Room-Bindung + Security-Hardening (v1, abgeglichen):
- Produktpfad-Auth (Backend King-PHP):
  - `/ws` und `/sfu` authentifizieren mit Session-Token (`Authorization: Bearer`, `X-Session-Id`, WS-Query `session|token|session_id`).
  - RBAC auf WS-Pfaden ist aktiv (`admin|user`), transport-spezifisch fail-closed.
  - Session-Rotation/Revoke ist vorhanden; `/ws` revalidiert Session-Liveness im Loop und trennt invalidierte Sessions.
- Access-Link/JWT-Kontext:
  - Öffentliche Join-Entry ist `GET /api/call-access/{uuid}/join` + `POST /api/call-access/{uuid}/session`.
  - Session-Issuing für Access-Link liefert `session_id`-Token, an Call/Participant-Kontext gebunden (personal/open link).
  - Open-Links erzeugen Guest-User serverseitig; Joinbarkeit wird gegen Call-Status (`scheduled|active`) geprüft.
- Room-Bindung (v1-Vertrag):
  - Kanonisch ist `room` im WS-Query beim `/sfu`-Connect.
  - `sfu/join.room_id` ist nur Kompatibilitätsfeld; falls vorhanden, muss es exakt zu Query-`room` matchen.
  - Kein stilles Defaulting auf `lobby` im produktiven `/sfu`-Pfad für Call-Workspaces.
  - `/sfu` muss vor Track-Publish prüfen, dass Session-User für den Room/Call autorisiert ist.
- Erkannte Lücke (Ist-Zustand, zu schließen):
  - Frontend sendet `roomId` aktuell primär im `sfu/join`, Backend liest Room aktuell aus Query.
  - Daraus entsteht Room-Mismatch-Risiko (inkl. implizitem `lobby`-Fallback).
  - Verbindliche Folge: Adapter vereinheitlichen (Query `room_id` + snake_case, Match-Check serverseitig erzwingen).
- Gateway-Pfad (PoC, aber Security-Vertrag fest):
  - JWT-HS256-Validation vorhanden (`sub|effective_id` + `room|call_id` + `exp`).
  - `JWT_SECRET` darf in produktiven Deployments nie auf `dev-secret-unsafe` fallen (strict vault mode).
  - Join-Rate-Limit + Token-Längenlimit bleibt Pflicht im Gateway-Pfad.

Interop-Testmatrix (v1):

| ID | Flow | Transport/Pfad | Erwartung | Automatisierung (Ist) | Status |
|---|---|---|---|---|---|
| I-01 | WS Handshake strict | WS `/ws` | ungültiger Handshake fail-closed (`400/405/426`), Auth-Callback nicht vor Handshake-Validierung | `tests/realtime-websocket-gateway-contract.sh`, `tests/videochat-integration-matrix-realtime-contract.sh` | abgedeckt |
| I-02 | Session-Liveness/Revoke | WS `/ws` | revoked/expired Session wird erkannt; strukturierter Close-Descriptor | `tests/realtime-session-revocation-contract.sh`, `tests/videochat-integration-matrix-realtime-contract.sh` | abgedeckt |
| I-03 | Presence Join/Leave/Reconnect | WS `/ws` (`room/*`) | Snapshot + Join/Leave-Deltas korrekt; Reconnect-Resync vorhanden; Room-Move konsistent | `tests/realtime-presence-contract.sh` | abgedeckt |
| I-04 | Directed Signaling | WS `/ws` (`call/offer|answer|ice|hangup`) | nur target-user im selben Room; self-target/invalid target fail-closed | `tests/realtime-signaling-contract.sh` | abgedeckt |
| I-05 | Reactions Backpressure | WS `/ws` (`reaction/*`) | Flood -> Batch-Fanout, room-scoped, ohne cross-room leak | `tests/realtime-reaction-contract.sh` | abgedeckt |
| I-06 | SFU Auth-Handshake | WS `/sfu` | Auth + RBAC + WS-Handshake für `/sfu` (wie `/ws`) | `tests/realtime-sfu-contract.sh`, `tests/videochat-integration-matrix-realtime-contract.sh` | abgedeckt |
| I-07 | SFU Publish/Subscribe | WS `/sfu` (`sfu/publish|subscribe|unpublish`) | `sfu/tracks`, `sfu/unpublished`, `sfu/publisher_left` korrekt pro Room | `tests/realtime-sfu-contract.sh` | abgedeckt |
| I-08 | SFU Frame Relay | WS `/sfu` (`sfu/frame`) | Frames nur an Subscriber im selben Room, kein self-echo | `tests/realtime-sfu-contract.sh` | abgedeckt |
| I-09 | SFU Reconnect | WS `/sfu` | Reconnect -> Join/Publisher-Liste/Resubscribe stabil | `tests/realtime-sfu-contract.sh` | abgedeckt |
| I-10 | Room-Binding Enforcement | WS `/sfu` | Query-`room` und optionales Join-Payload müssen matchen; kein stilles Fallback auf `lobby` | `tests/realtime-sfu-contract.sh` | abgedeckt |
| I-11 | Gateway JWT-Bindung | Gateway Join (`SignalMessage.Join`) | JWT `sub/effective_id` == `peer_id`, `room/call_id` == `room_id` | `backend-king-php/tests/gateway-jwt-binding-contract.sh` | abgedeckt |
| I-12 | Gateway <-> Backend Mapping | AMQP `call.signaling` + Backend Signaling | `room_id <-> call_id` mapping konsistent, `offer/answer/ice/hangup` interoperabel | `backend-king-php/tests/gateway-backend-mapping-contract.sh` | abgedeckt |
| I-13 | Access-Link Join-Session | REST `/api/call-access/{id}/join|session` -> WS | Access-gebundene Session führt nur in erlaubten Call/Room-Kontext | `backend-king-php/tests/call-access-session-contract.sh` | abgedeckt |

v1-Ausführungsreihenfolge (empfohlen):
- Phase A (muss grün sein): I-01 bis I-05.
- Phase B (vor produktivem SFU-Rollout verpflichtend): I-06 bis I-10.
- Phase C (Gateway-Aktivierung): I-11 + I-12.
- Phase D (Public Join Hardening): I-13.

Konkrete neue Test-Artefakte für #8-Folgearbeit:
- `backend-king-php/tests/realtime-sfu-contract.sh|php` für I-06 bis I-10.
- `backend-king-php/tests/call-access-session-contract.sh|php` für I-13.
- `backend-king-php/tests/gateway-backend-mapping-contract.sh|php` für I-12.

---

### #9 Last-/SLO-/Observability-Paket (Implementierung)

Ziel:
- Messbar machen, ob "tausende Nutzer" erreichbar ist.

Checklist:
- [x] KPIs festlegen: Join-Zeit, Crash-Rate, FPS, encode/decode ms, packet loss, SFU CPU/RAM.
- [x] Lastprofile definieren (Burst joins, steady-state, emoji/chat flood, reconnect storms).
- [x] Alerting-Schwellen definieren.
- [x] Smoke + Load + Chaos-Tests in CI/ops dokumentieren.

Definition of done:
- Es gibt reproduzierbare Lastnachweise statt nur Funktions-Demos.

KPI-Katalog (v1, verbindlich):

| KPI-ID | KPI | Definition (formal) | Messquelle | Zielwert v1 |
|---|---|---|---|---|
| K-01 | Join API Latency | `t(join_session_200) - t(join_click)` für `/api/call-access/{id}/session` bzw. Enter-Flow | Frontend Event + Backend Request-Timing | p95 <= 800 ms |
| K-02 | First Media Time | `t(first_remote_video_frame)` oder `t(first_remote_audio_packet)` minus `t(join_click)` | Frontend Realtime/Media Hooks | p95 <= 3.5 s |
| K-03 | Join Success Rate | `successful_joins / join_attempts` (Window 5 min) | Backend + Frontend Join Events | >= 99.0% |
| K-04 | Reconnect Recovery Rate | `recovered_sessions / reconnect_attempts` | WS/SFU reconnect events | >= 95.0% |
| K-05 | Client Crash/Error Rate | `fatal_call_workspace_errors / active_call_minutes` | Frontend global/runtime errors | <= 0.20 pro 1000 call-min |
| K-06 | Outbound FPS | gemittelter Video-Out FPS pro Publisher | Client Media Stats | p50 >= 20 fps, p95 >= 15 fps |
| K-07 | Inbound FPS (visible set) | gemittelter Video-In FPS für Top-5 sichtbare Teilnehmer | Client Render/Track Stats | p50 >= 20 fps, p95 >= 12 fps |
| K-08 | Encode Time | pro Frame `encode_end - encode_start` (WLVC/WebRTC-Pfad) | Client Codec Stats | p95 <= 20 ms |
| K-09 | Decode Time | pro Frame `decode_end - decode_start` (WLVC/WebRTC-Pfad) | Client Codec Stats | p95 <= 16 ms |
| K-10 | Packet Loss | inbound/outbound packet loss ratio pro Peer | WebRTC getStats / SFU counters | p95 <= 3.0% |
| K-11 | Jitter | WebRTC jitter in ms | WebRTC getStats | p95 <= 30 ms |
| K-12 | RTT | round-trip time in ms | WebRTC getStats | p95 <= 180 ms |
| K-13 | SFU CPU | CPU je SFU-Instanz | Host/Container metrics | <= 70% steady, <= 85% peak |
| K-14 | SFU RAM | RSS/Working Set je SFU-Instanz | Host/Container metrics | <= 75% steady, <= 90% peak |
| K-15 | Signaling Error Rate | `failed_signaling_events / signaling_events` (`/ws` + `/sfu`) | Backend error envelopes + SFU logs | <= 0.5% |

Messregeln (v1):
- Alle KPIs mit Labels `build`, `env`, `room_size_tier`, `client_device_class`, `codec_stage` erfassen.
- Percentile-Basis: p50/p95 über rolling 5-min und 1-h Fenster.
- Sichtfenster-KPIs (`K-07`) beziehen sich nur auf das aktive Top-5-Window aus #3.
- Bei Fallback-Übergängen (`WLVC -> native`, `native -> degraded`) müssen KPI-Samples `transition_reason` mitführen.

Lastprofile (v1, verbindlich):

| Profil-ID | Szenario | Lastmodell | Dauer | Pass/Fail Kernkriterien |
|---|---|---|---|---|
| L-01 | Burst Join (Tier 1) | 1.000 Teilnehmer treten innerhalb 60s bei (linear + 2x Spike in den ersten 10s) | 15 min | `K-03 >= 99%`, `K-01 p95 <= 800ms`, `K-02 p95 <= 3.5s` |
| L-02 | Burst Join (Tier 2) | 5.000 Teilnehmer innerhalb 180s, 20% mobile Clients | 20 min | `K-03 >= 98.5%`, `K-02 p95 <= 4.5s`, `K-13 peak <= 85%` |
| L-03 | Steady-State Tier 1 | 1.000 gleichzeitig aktiv, 5 sichtbare Streams, rest roster-only | 60 min | `K-06/K-07` Zielwerte halten, `K-15 <= 0.5%`, keine Memory-Drift |
| L-04 | Steady-State Tier 2 | 5.000 gleichzeitig aktiv, Active-Speaker-Wechsel alle 5-10s | 60 min | `K-07 p95 >= 12fps`, `K-10 p95 <= 3%`, `K-14 steady <= 75%` |
| L-05 | Steady-State Tier 3 (Stretch) | 10.000 gleichzeitig aktiv, hartes Top-5-Viewport-Limit | 45 min | Keine instabilen Crash-Loops, `K-04 >= 90%`, kontrollierte Degradation erlaubt |
| L-06 | Emoji/Reaction Flood | 10% der User senden 25-60 Reactions/s über 5 min | 15 min | Batch-Mechanik greift, UI bleibt bedienbar, `K-15 <= 1.0%` |
| L-07 | Chat Flood | 10% der User senden 5-10 Chat-Events/s | 15 min | Priorisierung kritisch vor kosmetisch, kein globaler UI-Lock, bounded queue stabil |
| L-08 | Reconnect Storm | 30% der Clients disconnecten in 10s und reconnecten innerhalb 60s | 20 min | `K-04 >= 95%` (Tier 1/2), keine room-cross leaks, Snapshot-Resync korrekt |
| L-09 | Mixed-Adverse Network | 25% Clients mit hoher RTT/Jitter/packet-loss (simuliert) | 30 min | Fallback/Degradation kontrolliert, Audio bleibt stabil, keine Massenabbrüche |
| L-10 | Public Access Burst | 2.000 Access-Link Session-Issues in 2 min (`/api/call-access/*/session`) | 15 min | Session-Issuing stabil, keine ungebundenen Room-Joins, `K-01` unter Ziel |
| L-11 | Activity Flood | 10% der User senden 4-8 `participant/activity` Updates/s plus 2% Gesture-Spikes | 15 min | Server-Coalescing greift, `layout/*` bleibt bedienbar, `K-15 <= 1.0%`, keine Snapshot-Aufblähung |

Profilparameter (global):
- Client-Mix: 50% desktop, 30% laptop/tablet, 20% phone.
- Codec-Stufen: primär WLVC-WASM; bei Faults nativer WebRTC-Fallback gemäß #2.
- Sichtbarkeit: maximal 5 aktive Video-Slots pro Client, rest roster/presence-only.
- Moderation/Role-Events: in jedem Profil mindestens 1 Event pro Minute je 100 Teilnehmer.

Mess-/Ausführungsregeln:
- Jedes Profil separat mit Warmup (5 min), Messphase, Cooldown (3 min) fahren.
- Ergebnis pro Profil als `PASS`, `PASS_WITH_DEGRADATION`, `FAIL` klassifizieren.
- `PASS_WITH_DEGRADATION` ist nur für L-05 und L-09 zulässig, wenn Audio+Signaling stabil bleiben.

Alerting-Schwellen (v1, verbindlich):

| Alert-ID | KPI/Signal | Warning | Critical | Bewertungsfenster | Aktion |
|---|---|---|---|---|---|
| A-01 | `K-03 Join Success Rate` | < 99.0% | < 98.0% | rolling 5 min | Warning: on-call informieren; Critical: Incident + Join-Rate-Limiter prüfen |
| A-02 | `K-01 Join API Latency p95` | > 900 ms | > 1200 ms | rolling 5 min | Backend/API-Triage, DB-/Session-Pfad prüfen |
| A-03 | `K-02 First Media Time p95` | > 4.0 s | > 6.0 s | rolling 5 min | SFU/Signaling/Client-Media Startup prüfen |
| A-04 | `K-04 Reconnect Recovery Rate` | < 95% | < 90% | rolling 10 min | WS/SFU stability incident, reconnect storm mitigation |
| A-05 | `K-06/K-07 FPS` | p95 < 12 fps | p95 < 8 fps | rolling 10 min | Degradation policy prüfen, active-window pressure reduzieren |
| A-06 | `K-08 Encode p95` | > 22 ms | > 30 ms | rolling 5 min | Codec-stage audit, fallback transitions prüfen |
| A-07 | `K-09 Decode p95` | > 18 ms | > 25 ms | rolling 5 min | Decoder pressure, rendering hot path analysieren |
| A-08 | `K-10 Packet Loss p95` | > 4.0% | > 7.0% | rolling 5 min | Network/SFU routing incident, quality downshift erlauben |
| A-09 | `K-11 Jitter p95` | > 40 ms | > 70 ms | rolling 5 min | Path quality incident, retransmit/fec strategy prüfen |
| A-10 | `K-12 RTT p95` | > 220 ms | > 350 ms | rolling 5 min | Regional routing/backhaul check |
| A-11 | `K-13 SFU CPU` | > 80% sustained | > 90% sustained | 5 min sustained | Autoscale/overflow routing, hot room rebalance |
| A-12 | `K-14 SFU RAM` | > 85% sustained | > 92% sustained | 5 min sustained | Memory pressure incident, pod recycle policy |
| A-13 | `K-15 Signaling Error Rate` | > 0.8% | > 1.5% | rolling 5 min | signaling contract regression triage |
| A-14 | Session endpoint failures (`/api/auth/session`, `/api/call-access/*/session`) | > 1.0% 5xx | > 3.0% 5xx | rolling 5 min | auth/session incident, deploy rollback check |
| A-15 | WS endpoint errors (`websocket_*`, `system/error` spikes) | > 2x baseline | > 4x baseline | rolling 10 min vs 24h baseline | realtime gateway incident |

Alerting-Regeln (Betrieb):
- Severity-Mapping: `Warning` => Ticket + On-call Ping, `Critical` => Incident-Channel + Eskalation.
- Mute/Recover-Hysterese:
  - Alert feuert erst nach 2 aufeinanderfolgenden verletzten Fenstern.
  - Recovery erst nach 3 aufeinanderfolgenden gesunden Fenstern.
- Noise-Guard:
  - Während geplanter Lasttests (`env=loadtest`) nur sammeln, kein Pager.
- Bei bekannten externen Provider-Störungen nur einmalige Sammelmeldung pro 30 min.
- Pflichtfelder pro Alert-Event:
  - `alert_id`, `severity`, `kpi_id`, `window`, `build`, `env`, `room_size_tier`, `codec_stage`, `region`.

Smoke + Load + Chaos in CI/Ops (v1, dokumentiert):

CI-Stufenmodell:
- Stage 0: Fast Smoke (pro Push/PR)
  - Quelle: `.github/workflows/ci.yml` + `demo/video-chat/scripts/smoke.sh`.
  - Enthält: Syntax-Gates, Compose-Boot, `/health`, `/api/runtime`, Auth-Login/Session-Sanity, Kern-Contract-Tests.
  - Ziel: schneller Fail bei Build-/Runtime-/Contract-Bruch.
- Stage 1: Nightly Load (geplant, `main` + optional release branches)
  - Ausführung der Lastprofile L-01 bis L-04 als Pflicht, L-05 optional als Stretch.
  - Ergebnis: KPI-Report (p50/p95, Success-Rates) + PASS/PASS_WITH_DEGRADATION/FAIL.
- Stage 2: Chaos Campaign (geplant, nightly/weekly)
  - Fault-Injection auf SFU/WS/API/Netzpfad, inkl. Reconnect-Storm-Szenarien.
  - Ziel: Recovery und Failover-Verhalten gegen A-Alerts absichern.

Smoke-Gates (Ist, bereits vorhanden):
- Canonical CI-Job ruft Compose-Smoke mit festen Ports auf (`VIDEOCHAT_SMOKE_COMPOSE_ONLY=1`).
- `smoke.sh` validiert:
  - backend/frontend launcher syntax,
  - compose up/down + migrations/auth sanity,
  - contract checks (`session-auth`, `refresh`, `logout`, `rbac`, `realtime-*`, `wlvc-wire`, invite/call signaling),
  - frontend WLVC contract + dev boot check.

Load-Test-Dokumentation (v1 Soll):
- Trigger:
  - nightly workflow_dispatch + schedule (UTC nachts),
  - manuell vor Release-Cut verpflichtend.
- Inputs:
  - Profil-ID (`L-01..L-10`), Zielumgebung, Dauer, Ramp-Pattern.
- Outputs (Artefakte):
  - `load-summary.json` (profilweise KPI p50/p95, pass/fail),
  - `alerts-during-load.json`,
  - komprimierte Rohmetriken (`metrics.ndjson.gz`).
- Gate-Regel:
  - Release-Kandidat nur, wenn Pflichtprofile (L-01..L-04, L-08) nicht `FAIL` sind.

Chaos-Test-Dokumentation (v1 Soll):
- Fault-Typen:
  - SFU Instanz-Restart/kill,
  - künstliche RTT/Jitter/packet-loss-Erhöhung,
  - kurzzeitige API-5xx-Spikes (`/api/auth/session`, `/api/call-access/*/session`),
  - WS connection churn (disconnect/reconnect bursts).
- Erfolgskriterien:
  - Reconnect-Recovery gemäß `K-04`,
  - keine room-cross leaks,
  - keine dauerhaften Critical-Alerts > 15 min.
- Pflichtnachweise:
  - Incident-Timeline, betroffene KPIs, Recovery-Zeit, Postmortem-Notiz.

Ops-Ausführung/Runbook:
- Vor Teststart:
  - `env` label auf `loadtest`/`chaos` setzen (Pager-Muting nach Alerting-Regeln).
  - Baseline-Snapshot (24h Vergleich) erfassen.
- Während Test:
  - Live-Dashboard für K-01..K-15 + A-01..A-15 überwachen.
  - Abbruchkriterien:
    - Datenverlust-/Security-Anomalie,
    - Critical-Alert > 30 min ohne Recovery.
- Nach Test:
  - Ergebnis klassifizieren (PASS/PASS_WITH_DEGRADATION/FAIL),
  - offene Maßnahmen als Folge-Issues aufnehmen.

---

### #10 Repo-Hygiene und Artefakt-Grenzen (Implementierung)

Ziel:
- Keine Build-/Tool-Artefakte mehr im Produktpfad.

Checklist:
- [x] `.gitignore` für generierte WASM/Build-Outputs prüfen und härten.
- [x] CI-Check einbauen: keine `.vite`, keine CMake-Buildtrees, keine transienten Binary-Artefakte.
- [x] Dokumentieren, welche WASM-Artefakte versioniert sein dürfen.

WASM-Versionierungsregel (verbindlich):
- Erlaubt zu versionieren (produktrelevant):
  - Quellcode/Headers unter:
    - `demo/video-chat/frontend-vue/src/lib/wasm/**/*.cpp`
    - `demo/video-chat/frontend-vue/src/lib/wasm/**/*.h`
  - Laufzeitartefakte (vom Frontend direkt geladen):
    - `demo/video-chat/frontend-vue/src/lib/wasm/wlvc.wasm`
    - `demo/video-chat/frontend-vue/src/lib/wasm/wlvc.js`
    - `demo/video-chat/frontend-vue/src/lib/wasm/wlvc.d.ts`
- Nicht versionieren (immer generiert/transient):
  - `demo/video-chat/**/src/lib/wasm/build/**`
  - `demo/video-chat/**/dist/**` (inkl. `dist/assets/wlvc-*.wasm`)
  - `.vite`-Caches, CMake-Buildtrees, Objekt-/Library-Binaries (`*.o`, `*.a`, `*.so`, ...)
- Release-Regel:
  - Änderungen an `wlvc.wasm` müssen immer zusammen mit passendem `wlvc.js` und den zugehörigen C++-Quellen committed werden (kein Einzel-Blob-Update).

Notizen:
- 2026-04-15: Root-`.gitignore` gehärtet für `frontend-vue` Build-Outputs (`.vite`, `dist`) sowie generische CMake-Buildtree-Artefakte (`CMakeFiles`, `CMakeCache.txt`, `cmake-build-*`, `cmake_install.cmake`, `compile_commands.json`).
- 2026-04-15: CI-Guard `infra/scripts/check-repo-artifact-hygiene.sh` ergänzt und in `.github/workflows/ci.yml` (Shard 1) verdrahtet.

Definition of done:
- Branch bleibt sauber, Merges bringen keine Build-Leichen zurück.

---

### #11 CI Smoke Stabilisierung (Compose + Runtime Readiness) (Implementierung)

Ziel:
- `VIDEOCHAT_SMOKE_COMPOSE_ONLY=1` darf nicht mehr mit frühem `curl`-Fehler (`22/500`) abbrechen, solange der Stack noch hochfährt.

Checklist:
- [x] Root-Cause dokumentiert: bisheriger Fail war bei frühen HTTP-Probes ohne Retry auf `/api/runtime`/Auth-Endpoints.
- [x] `smoke.sh` gehärtet: Retry-Windows für `/health`, Frontend `/`, `/api/runtime`, Login und Session-Probe.
- [x] Einheitlicher Compose-Debug-Dump bei Fehlern ergänzt (`compose ps` + Backend-Logs), statt nacktem `curl`-Exitcode.
- [x] PHP-Image-Autowahl robust gemacht: nicht mehr `strings | head -1`, sondern API-Kandidaten + Host-PHP-API Matching.
- [x] Log-Ausgabe erweitert (`king_extension_api_candidates`, `host_php_api`) zur schnelleren CI-Diagnose.

Definition of done:
- Compose-Smoke läuft stabiler unter langsamem Start.
- Failures liefern deterministische Diagnose statt unklarer `curl`-Codes.

Notizen:
- Reproduktionskommando: `VIDEOCHAT_SMOKE_COMPOSE_ONLY=1 bash demo/video-chat/scripts/smoke.sh`.
- Bei erzwungenem API-Mismatch (`VIDEOCHAT_SMOKE_COMPOSE_BACKEND_PHP_IMAGE=php:8.5-cli-trixie` mit 8.4-`king.so`) wird jetzt sauber mit Container-Diagnose abgebrochen.

---

### #12 Security Findings Triage (2026-04-15)

Ziel:
- Neue Findings aus Security-Scan dokumentiert bewerten und in klare Folgeschritte überführen.

Checklist:
- [x] **Tar option injection** (`verify-release-supply-chain.sh`) verifiziert als bereits mitigiert.
- [x] **Demo backend userId spoofing** als Demo-scope Risiko einsortiert (nicht Core-Runtime).
- [x] **McpHost STOP ohne Auth** als Demo/userland-scope Risiko einsortiert.
- [x] Für Demo-scope Findings explizite Hardening-Policy ergänzen (akzeptiert vs. absichern).

Status je Finding:
- `Tar option injection in supply-chain verification script`:
  - Status: **geschlossen/mitigiert**.
  - Beleg: `infra/scripts/verify-release-supply-chain.sh` nutzt Pfadvalidierung (`archive_entry_path_is_safe`) und extrahiert mit `tar -xOf "${archive}" -- "${manifest_entry}"`.
- `Demo backend accepts client-supplied userId enabling spoofing`:
  - Status: **informational / demo-scope**.
  - Scope: `demo/video-chat/dev-backend.mjs` (legacy demo backend, nicht aktiver `backend-king-php` Produktpfad).
  - Folge: Hardening-Entscheidung dokumentieren (z. B. explizit nur localhost/dev, oder verbindliches Token/Server-assigned identity).
- `McpHost accepts unauthenticated STOP/shutdown commands`:
  - Status: **informational / demo-scope**.
  - Scope: `demo/userland/flow-php/src/McpHost.php` (repo-lokale Helper).
  - Folge: STOP-Gate optional absichern (token/allowlist) oder Demo-only im Vertrag explizit markieren.

Hardening-Policy:
- `demo/video-chat/SECURITY_HARDENING.md` definiert die Entscheidungsklassen `geschlossen/mitigiert`, `absichern`, `akzeptiert/demo-only` und `entfernen`.
- `demo/video-chat/scripts/check-security-hardening-policy.sh` prueft statisch, dass die Policy und die vorhandenen Kontrollen fuer Tar-Extraction, entfernten Legacy-`dev-backend.mjs` und loopback-only `McpHost STOP` nicht auseinanderlaufen.

---

### #13 Server Edge Deployment Baseline (TLS + WS Routing) (verworfen)

Ziel:
- War: die TLS/Reverse-Proxy-Lücke für `/ws` und `/sfu` als lauffähiges Edge-Baseline schließen.
- Aktuell: `demo/video-chat/deploy` wurde verworfen/entfernt; kein Edge-Deploy-Pack im Repo.

Checklist:
- [x] Entscheidung dokumentiert: Edge-Deploy-Pack aus dem Repo entfernt.
- [x] Falls erneut benötigt: neue Deploy-Implementierung separat und bewusst einführen.

Definition of done:
- Der aktive Demo-Scope bleibt auf `frontend-vue` + `backend-king-php` ohne internes Edge-Deploy.

Abschluss:
- `demo/video-chat/EDGE_DEPLOYMENT_DECISION.md` dokumentiert, dass #13 verworfen bleibt und eine spaetere Produktions-Edge-Implementierung ein eigenes Issue mit TLS-/WS-/SFU-/Secret-/Rollback-Anforderungen braucht.
- `demo/video-chat/scripts/check-edge-deployment-decision.sh` prueft, dass kein internes `deploy/`, `docker-compose.edge.yml` oder top-level Reverse-Proxy-Artefakt als Demo-Default zurueckkommt.
- `demo/video-chat/scripts/smoke.sh` fuehrt diesen Gate vor dem Compose-Smoke aus.

---

### #14 TURN Integration Baseline (zurückgestellt)

Ziel:
- STUN-only-Status beenden und TURN-Relay als baselinefähig bereitstellen.

Checklist:
- [x] TURN-Service/Deployment aktuell nicht im Repo verdrahtet (deploy-Pfad entfernt).
- [x] Frontend-ICE-Server-Konfiguration über `VITE_VIDEOCHAT_ICE_SERVERS` (JSON) verdrahtet.
- [x] TURN Credential Rotation + Secret Manager Binding (Vault/KMS) erzwingen.
- [x] End-to-end NAT-Matrix-Tests (mobile/restrictive NAT) ergänzen.

Definition of done:
- TURN ist nicht nur dokumentiert, sondern als deploybare Basis konfigurierbar.

Abschluss:
- `demo/video-chat/docker-compose.v1.yml` enthaelt den optionalen `turn`-Profile-Service `videochat-turn-v1` auf Basis von `coturn/coturn`, ohne Default-Secret und ohne Aktivierung im Standard-Compose.
- `demo/video-chat/scripts/generate-turn-ice-servers.php` erzeugt zeitlich rotierende TURN-REST-Credentials fuer `VITE_VIDEOCHAT_ICE_SERVERS`.
- `VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET_FILE` ist der Secret-Manager-Binding-Punkt fuer Vault-/KMS-Agenten, Docker/Kubernetes Secrets oder CI-Secret-Mounts.
- `demo/video-chat/scripts/turn-nat-matrix-contract.sh` validiert opt-in reale NAT-Matrix-Ergebnisse fuer `mobile-lte`, `restrictive-nat`, `udp-blocked-turn-tcp` und `corporate-firewall`.
- `demo/video-chat/scripts/check-turn-baseline.sh` prueft Baseline, Rotation und Gate-Verdrahtung statisch im Smoke.

---

### #15 Secret-Management + Config Hardening (Plan/Implementierung)

Ziel:
- Keine Demo-Secrets in realen Deployments und klare Secret-Übergabepfade.

Checklist:
- [x] Keine Edge-Deploy-Vorlage mehr im Repo; Secret-Management bei künftigem Deploy-Pfad neu definieren.
- [x] Mandatory Secret Sources (Vault/KMS/CI-Secret) in Deploy-Skripten erzwingen.
- [x] Fail-closed Start, falls Demo-/Default-Secrets erkannt werden.
- [x] Rotations-Runbook für JWT/Session/TURN-Credentials dokumentieren.

Definition of done:
- Deployment startet nicht mehr mit unsicheren Defaults.

Abschluss:
- `demo/video-chat/backend-king-php/support/config_hardening.php` erzwingt hardened Start bei `VIDEOCHAT_KING_ENV=production|staging` oder `VIDEOCHAT_REQUIRE_SECRET_SOURCES=1`.
- `backend-king-php/server.php` bricht vor SQLite-Bootstrap ab, wenn Demo-Defaults wie `admin123`/`user123`, identische Passwoerter, zu kurze Secrets oder aktive Demo-Seed-Calls erkannt werden.
- `VIDEOCHAT_DEMO_ADMIN_PASSWORD_FILE` und `VIDEOCHAT_DEMO_USER_PASSWORD_FILE` sind als Secret-Manager-Bindings fuer gemountete Secrets unterstuetzt.
- `demo/video-chat/SECRET_MANAGEMENT.md` dokumentiert Secret-Quellen und Rotation fuer Session-IDs, Demo-Credentials, TURN und zukuenftige JWT-Keys.
- `demo/video-chat/scripts/check-secret-management.sh` und `backend-king-php/tests/config-hardening-contract.sh` pruefen Guard, Runbook und Fail-closed-Verhalten.

---

### #16 Multi-Node Runtime Architektur (Planung)

Ziel:
- Übergang von Single-Node/SQLite auf skalierbare Multi-Node-Architektur.

Checklist:
- [x] Zustandsmodell splitten: Session/Auth, Call State, Roster/Presence, Realtime Fanout.
- [x] Persistenzstrategie definieren (SQLite-Ersatz + Migrationspfad).
- [x] Inter-Node Signaling/Coordination (Bus/Queue) für `/ws` + `/sfu` festlegen.
- [x] Rolloutplan mit Zero-Downtime-Migration dokumentieren.

Definition of done:
- Es gibt einen verbindlichen Architektur- und Migrationsvertrag für Multi-Node.

Abschluss:
- `demo/video-chat/MULTI_NODE_RUNTIME_ARCHITECTURE.md` definiert den verbindlichen Multi-Node-Vertrag fuer Session/Auth, Call State, Roster/Presence, Realtime Fanout und Media/SFU.
- SQLite ist darin nur noch als Dev-/Single-Node-Fallback erlaubt; Multi-Node verlangt shared SQL, King Object Store, presence TTL Store und inter-node bus.
- Der Migrationspfad deckt Schema-Freeze, Shadow-Import, dual-write, read switch, Canary, Rollback und SQLite-Abbau ab.
- `demo/video-chat/scripts/check-multi-node-runtime-architecture.sh` prueft die Architekturmarker statisch und ist in `demo/video-chat/scripts/smoke.sh` verdrahtet.

---

### #17 Ops Hardening: Metrics/Alerts/Backup/Rollback (Implementierung)

Ziel:
- Operative Mindesthärtung für Betrieb und Incident-Recovery liefern.

Checklist:
- [x] SQLite-Backup-Skript ergänzt (`demo/video-chat/scripts/backup-sqlite.sh`).
- [x] Restore-Skript + Restore-Drill dokumentieren.
- [x] Zentrale Metrics/Logs/Alerts Pipeline verdrahten (mind. K-01..K-15, A-01..A-15).
- [x] Rollout/Rollback-Runbook pro Deployment-Variante ergänzen.

Definition of done:
- Backup/Restore ist reproduzierbar und Kern-Operational-Metriken sind zentral sichtbar.

Abschluss:
- `demo/video-chat/scripts/restore-sqlite.sh` stellt SQLite-Backups checksum- und integrity-geprueft wieder her und schuetzt existierende Ziele per `VIDEOCHAT_RESTORE_OVERWRITE=1`.
- `demo/video-chat/scripts/check-ops-hardening.sh` fuehrt einen temporaeren Backup/Restore-Drill aus und validiert den Ops-Katalog.
- `demo/video-chat/ops/metrics-alerts.catalog.json` verdrahtet `K-01..K-15` und `A-01..A-15` als maschinenlesbaren Metrics/Alerts-Katalog mit Pflichtlabels und Artefakt-Outputs.
- `demo/video-chat/backend-king-php/run-dev.sh` und `demo/video-chat/docker-compose.v1.yml` akzeptieren OTLP-Bindings ueber `VIDEOCHAT_OTEL_EXPORTER_ENDPOINT`, Service-Namen, Metrics- und Logs-Schalter.
- `demo/video-chat/OPS_HARDENING.md` dokumentiert Restore-Drill, zentrale Pipeline und Rollout/Rollback fuer Local Compose, hardened Single-Node Staging und zukuenftige Multi-Node-Produktion.
- `demo/video-chat/scripts/smoke.sh` fuehrt den Ops-Hardening-Gate vor dem Compose-Smoke aus.

---

### #18 Chat-Limits, Auto-Datei-Konvertierung und sichere Attachments (Implementierung)

Ziel:
- Chat darf keine riesigen WebSocket-Payloads akzeptieren, muss aber lange Inhalte und Dateien nutzbar machen.
- Große Text-/Paste-Inhalte werden automatisch als Datei-Anhang gespeichert und als Download im Chat angeboten.
- Uploads laufen nicht als Base64-Blob über `/ws`, sondern bounded über HTTP/King Object Store plus realtime Metadaten-Fanout.

Vertragsentscheidung:
- Inline-Chat bleibt kurz: maximal `2.000` Unicode-Zeichen und maximal `8 KiB` UTF-8 Payload.
- Größere Texte werden nicht verworfen, sondern clientseitig in einen Text-Anhang umgewandelt.
- Auto-Dateiformat:
  - Default: `.txt`
  - CSV-Erkennung: mehrzeilige tabellarische Struktur mit konsistenten Trennzeichen -> `.csv`
  - Markdown-Erkennung: Überschriften/Listen/Code-Fences/Links -> `.md`
  - User kann vor dem Senden den vorgeschlagenen Dateinamen/Typ ändern.
- Backend bleibt fail-closed: direkte oversized `chat/send` Frames werden weiterhin abgelehnt, wenn sie nicht als Attachment referenziert sind.

Checklist:
- [x] Frontend-Composer limitiert Inline-Text auf `2.000` Zeichen und `8 KiB` Bytes.
- [x] Paste-Handler erkennt oversized Text und wandelt ihn automatisch in einen Attachment-Draft um.
- [x] Auto-Datei-Draft zeigt Dateiname, Typ (`txt`, `csv`, `md`), Größe und Preview-Ausschnitt.
- [x] Chat-Send unterstützt Nachrichten mit Text plus Attachment-Refs.
- [x] Backend-Contract für `chat/send` um `attachments[]` erweitern, ohne alte reine Textnachrichten zu brechen.
- [x] HTTP Upload-Endpunkt für Chat-Dateien ergänzt: `POST /api/calls/{call_id}/chat/attachments`.
- [x] Uploads schreiben in den King Object Store; SQLite speichert Metadaten/Refs/Status und die Teilnehmer-ACL wird serverseitig aus dem Call geprüft.
- [x] Object-Keys sind call- und room-scoped als flache King-Object-IDs ohne Slash, weil der Store Pfadseparatoren ablehnt.
- [x] Download-Endpunkt mit Auth/Call-Teilnehmerprüfung ergänzt: `GET /api/calls/{call_id}/chat/attachments/{attachment_id}`.
- [x] Dateien werden erst nach erfolgreichem Object-Store-Commit als Chat-Message referenziert.
- [x] Fehlgeschlagene/stornierte Upload-Drafts werden bereinigt und nicht im Chat sichtbar; unveröffentlichte Drafts können per `DELETE` entfernt werden.
- [x] Realtime-Event `chat/message` trägt Attachment-Metadaten (`id`, `name`, `content_type`, `size_bytes`, `kind`, `extension`, `download_url`).
- [x] Attachments sind downloadbar, aber niemals inline ausführbar.

Erlaubte Dateitypen:
- [x] Bilder: `jpg`, `jpeg`, `png`, `webp`, `gif` bis maximal 10 Bilder pro Nachricht.
- [x] Text/Tabellen/Markdown: `txt`, `csv`, `md`.
- [x] PDF: `pdf`.
- [x] Office/OpenDocument: `doc`, `docx`, `xls`, `xlsx`, `ppt`, `pptx`, `odt`, `ods`, `odp`.
- [x] Serverseitige MIME-/Magic-Byte-Prüfung; Extension allein reicht nicht.
- [x] OOXML/ODF Container prüfen, weil sie ZIP-basiert sind; beliebige ZIPs bleiben verboten.
- [x] Explizite Blocklist: `exe`, `dll`, `com`, `bat`, `cmd`, `ps1`, `sh`, `js`, `msi`, `jar`, `app`, `deb`, `rpm`, rohe Archive (`zip`, `rar`, `7z`, `tar`, `gz`) und sonstige unbekannte Binaries.

Limits und Quotas:
- [x] Maximal 10 Bilder pro Nachricht.
- [x] Maximal 10 Attachments pro Nachricht insgesamt.
- [x] Vorschlag v1: Bilder maximal `8 MiB` je Datei, Office/PDF maximal `25 MiB` je Datei.
- [x] Vorschlag v1: `100 MiB` Gesamtlimit pro Chat-Nachricht.
- [x] Vorschlag v1: call-weite Soft-Quota und harte Quota konfigurieren, damit ein Call nicht den Object Store flutet.
- [x] Backend liefert strukturierte Fehlercodes: `chat_inline_too_large`, `attachment_type_not_allowed`, `attachment_too_large`, `attachment_count_exceeded`, `chat_storage_quota_exceeded`.

Definition of done:
- Große Pastedaten führen nicht zu Browser-/WS-Freeze und nicht zu oversized WS Frames.
- Dateien sind sicher typvalidiert, persistent gespeichert, downloadbar und room-/call-scoped autorisiert.
- Keine ausführbaren oder unbekannten Binärdateien können über den Chat verteilt werden.

Tests:
- [x] Backend Contract: Inline-Limit, oversized direct send reject, Attachment-Metadaten, Download-ACL.
- [x] Backend Contract: Allowlist/Blocklist/Magic-Byte-Erkennung inkl. OOXML/ODF.
- [x] Frontend/Playwright: Paste > Limit erzeugt Datei-Draft statt Chat-Text.
- [x] Playwright + Backend-Fanout-Contract: großer Paste wird zu `.txt`/`.md`/`.csv` Attachment und Attachment-Metadaten erreichen andere Room-Teilnehmer.
- [x] Playwright: bis zu 10 Bilder werden clientseitig akzeptiert, das 11. Attachment wird sichtbar abgelehnt.
- [x] Playwright: PDF/Office Upload-Drafts funktionieren, `.exe`/unbekannte Binary wird sichtbar abgelehnt.
- [x] Backend Contract: Download-Link funktioniert nur für eingeloggte berechtigte Call-Teilnehmer.

---

### #19 Persistenter Chat-Verlauf und Read-only Archivmodal (Implementierung)

Ziel:
- Chat und Dateien bleiben nach dem Call für registrierte Teilnehmer verfügbar.
- Die Call-/Chatübersicht erhält ein zusätzliches Icon, über das registrierte User den Chat read-only öffnen.
- Das Archivmodal zeigt links den Chatverlauf und rechts die Dateien/Attachments.

Checklist:
- [x] Chat-Events append-only persistieren: Message-Metadaten in DB, Payload/Transcript-Snapshots im King Object Store.
- [x] Attachment-Refs aus #18 in denselben Archivindex aufnehmen.
- [x] Persistenzmodell für `call_chat_messages`, `call_chat_attachments`, `call_chat_acl` definieren.
- [x] Read-only API ergänzen, z. B. `GET /api/calls/{call_id}/chat-archive`.
- [x] Archivantwort paginieren/cursorbasiert laden; keine vollständigen Monster-Verläufe auf einmal.
- [x] Dateien rechts im Modal gruppieren: Bilder, PDFs, Office, Text/CSV/MD.
- [x] Linke Modalspalte: chronologischer Chat, Sender, Zeit, Text, Attachment-Chips.
- [x] Rechte Modalspalte: Datei-Liste mit Name, Typ, Größe, Sender, Timestamp und Download-Aktion.
- [x] Suche/Filter im Archiv definieren: Textsuche, Senderfilter, Dateitypfilter.
- [x] Nur registrierte User mit Call-Teilnahme/Call-Berechtigung dürfen das Archiv sehen.
- [x] Gäste ohne Account erhalten keinen dauerhaften Archivzugriff.
- [x] Admin/Owner/Moderator Berechtigungen für Archivzugriff und ggf. Retention/Export definieren.
- [x] Retention-Policy definieren: Standard-Aufbewahrung, Löschpfad bei Call-Löschung/DSGVO-Anfrage.
- [x] Exportoption optional vorbereiten: `.json`/`.md` Transcript plus Attachment-Liste.

Definition of done:
- Nach Call-Ende kann ein registrierter berechtigter User aus der Call-/Chatübersicht das Archiv öffnen.
- Modal ist read-only, stabil paginiert und zeigt Chat links sowie Dateien rechts.
- Downloads nutzen dieselben ACLs wie Live-Chat-Attachments.

Tests:
- [x] Backend Contract: Archiv-API nur für berechtigte registrierte User.
- [x] Backend Contract: Gast/Unbeteiligter/Admin-RBAC-Grenzen.
- [x] Backend Contract: Pagination, Attachment-Gruppierung, Download-ACL.
- [x] Playwright: User öffnet Chatübersicht, klickt Chat-Archiv-Icon, Modal zeigt Chat links und Dateien rechts.
- [x] Playwright: Read-only Verhalten; keine Eingabe-/Sendeaktion im Archiv möglich.
- [x] Playwright: Datei-Download aus Archiv funktioniert für berechtigten User und scheitert für unberechtigten User.

---

### #20 Speaker-/Movement-Awareness und Admin-Layoutstrategien (Implementierung)

Ziel:
- Das Video-Layout reagiert sinnvoll auf Sprecher und sichtbare Aktivität.
- Admin/Owner kann im Call Layout-Modus und Aktivitätsstrategie wählen.
- Große Calls bleiben kontrolliert: maximal definierte Video-Slots, Activity-Score statt chaotischer Client-Entscheidungen.

Activity-Index Vertrag:
- [x] Pro Teilnehmer wird ein `activity_score` geführt.
- [x] Audio-Quelle: WebRTC Audio-Level/VAD/Speaking-State mit kurzer Glättung.
- [x] Bewegung-Quelle: lokale Frame-Differenz/Motion-Metrik für sichtbare Gesten/Bewegung.
- [x] Optionaler Gesture-Hinweis: Winken/Handbewegung erhöht Score stärker als normale Hintergrundbewegung.
- [x] Privacy-Regel: keine Rohframes für Activity-Erkennung an Backend senden; nur normalisierte Scores/Events.
- [x] Score hat Zeitzerfall, z. B. Fenster `2s`, `5s`, `15s`.
- [x] Server/SFU aggregiert Score serverautoritativ und verteilt nur kompakte `participant/activity` Updates.
- [x] Scores werden rate-limited/coalesced, damit Activity nicht den Realtime-Kanal flutet.

Admin-Layoutmodi:
- [x] Linke Sidebar erhält Layout-Icons für:
  - Grid: bis zu 8 gleich große Videos.
  - Main + Mini: ein Hauptvideo plus vertikale/Story-Mini-Videos.
  - Main only: nur Hauptvideo.
- [x] Layoutmodus wird als Call-State persistiert und an Teilnehmer synchronisiert.
- [x] Pinned User überschreiben automatische Activity-Auswahl.
- [x] Host/Admin kann Layoutstrategie je Call setzen.

Aktivitätsstrategien:
- [x] `manual_pinned`: Admin/Pinning entscheidet; Activity beeinflusst nur Hinweise/Badges.
- [x] `most_active_window`: aktivste Teilnehmer im aktuellen Fenster besetzen Main + Mini-Videos.
- [x] `active_speaker_main`: aktivster Teilnehmer der letzten ca. `2s` wechselt ins Main-Video, vorheriger Main rückt in den Mini-Stack.
- [x] `round_robin_active`: bei ähnlich aktiven Teilnehmern wird fair rotiert, damit nicht eine Person dauerhaft alles blockiert.
- [x] Hysterese/Cooldown definieren, damit Main-Video nicht bei jedem Audio-Spike flackert.
- [x] Admin kann automatische Wechsel pausieren.
- [x] Teilnehmerliste zeigt optional Activity-Indikator, aber ohne störendes Dauerblinken.

Backend/SFU Aufgaben:
- [x] WS/SFU Eventvertrag für `participant/activity`, `layout/mode`, `layout/strategy`, `layout/selection`.
- [x] Serverseitige Validierung: nur Admin/Owner/Moderator darf Layoutmodus/Strategie ändern.
- [x] Activity-Score darf nicht von beliebigen Clients gefälscht werden; SFU/WebRTC-Metriken bevorzugen.
- [x] Reconnect/Join bekommt aktuellen Layout-State im Snapshot.
- [x] Lastprofil aus #9 um Activity-Flood ergänzen.

Frontend Aufgaben:
- [x] Sidebar-Icons für Layoutmodi ergänzen.
- [x] Admin-Strategie-Auswahl sichtbar und verständlich machen.
- [x] Video-Renderer kann Grid bis 8, Main+Mini und Main-only sauber darstellen.
- [x] Mini-Stack aktualisiert sich ohne Track-Leaks.
- [x] Main-Video-Wechsel animieren/debouncen, ohne Schwarzbild-Flicker.

Definition of done:
- Admin kann Layout und Strategie im Call ändern; alle Teilnehmer sehen konsistent dieselbe Auswahl.
- Activity-basierte Umschaltung ist stabil, nachvollziehbar und flackert nicht.
- Bewegung/Sprechen erhöht den Activity-Index, aber Privatsphäre bleibt gewahrt.

Tests:
- [x] Backend Contract: Layout-/Strategy-Commands RBAC-geschützt.
- [x] Backend Contract: Activity-Events werden normalisiert, rate-limited und snapshot-fähig.
- [x] Frontend Unit: Layout-Auswahl priorisiert Pinning vor Activity.
- [x] Playwright: Admin schaltet Grid/Main+Mini/Main-only, User sieht Layoutwechsel.
- [x] Playwright: simulierte Speaking-/Activity-Events verschieben Main/Mini nach Strategie.
- [x] Playwright: Pinning verhindert automatische Verdrängung aus Main.
- [x] Playwright: Activity-Pause stoppt automatische Main-Wechsel.

---

### #21 Playwright E2E Matrix für Chat, Dateien und Activity (Implementierung)

Ziel:
- Alle neuen Chat-/Attachment-/Archiv-/Activity-Funktionen erhalten browsernahe Regressionstests.
- Tests laufen deterministisch mit zwei Accounts und kontrollierten Fake-Medien/Fake-Dateien.

Checklist:
- [x] Test-fixtures für erlaubte Dateien: `txt`, `csv`, `md`, `pdf`, `docx`, `xlsx`, `odt`, `png`, `jpg`, `webp`.
- [x] Test-fixtures für verbotene Dateien: `exe`, `sh`, unbekannter Binary-Blob, umbenannte Binary mit erlaubter Extension.
- [x] Fake-Media Setup für Audio-Level/Speaking und Motion-Events definieren.
- [x] E2E: Paste > Limit -> Auto-Datei -> Senden -> zweiter Teilnehmer sieht Datei.
- [x] E2E: Drag-and-drop 10 Bilder -> Senden -> zweiter Teilnehmer sieht 10 Bildattachments.
- [x] E2E: 11 Bilder -> sichtbare Ablehnung.
- [x] E2E: PDF/Office Upload -> Download funktioniert.
- [x] E2E: verbotener Dateityp -> Upload blockiert, keine Chatmessage.
- [x] E2E: Chatarchiv nach Call-Ende öffnen, read-only prüfen, Dateien rechts prüfen.
- [x] E2E: unberechtigter User kann Archiv/Downloads nicht öffnen.
- [x] E2E: Admin Layout-Icons + Strategiewechsel.
- [x] E2E: Activity-Score beeinflusst Main/Mini nach Strategie.
- [x] E2E: Pinning/Manual-Modus überschreibt Activity.
- [x] CI-Gate ergänzen, damit neue Chat-/Activity-Flows nicht nur manuell getestet sind.

Definition of done:
- Relevante User-Journeys sind als Playwright-Spezifikationen abgedeckt.
- Tests laufen gegen `docker-compose.v1.yml` stabil und ohne externe Services.
- Fehlerfälle sind sichtbar geprüft, nicht nur Happy Path.

---

### #22 SFU Room-Binding und Relay-Contracts (Implementierung)

Ziel:
- Offene #8-Interop-Findings I-06 bis I-10 schließen: `/sfu` muss denselben fail-closed Anspruch wie `/ws` haben und darf keine impliziten Lobby-/Room-Fallbacks erzeugen.

Checklist:
- [x] `/sfu` verlangt einen gültigen Query-Room (`room_id`, kompatibel zusätzlich `room`) und fällt nicht mehr still auf `lobby` zurück.
- [x] Wenn `room_id` und Legacy-`room` parallel gesendet werden, müssen beide auf denselben Room normalisieren.
- [x] Optionales `sfu/join.room_id` / Legacy-`roomId` muss exakt zum gebundenen Query-Room passen.
- [x] Mismatches senden `sfu/error` und beenden die gebundene SFU-Verbindung fail-closed.
- [x] Frontend-SFU-Client sendet kanonisch `room_id` in Query und `sfu/join`, hält Legacy-`room` aber als kompatibles Query-Feld.
- [x] Dedizierter Backend-Contract `realtime-sfu-contract` deckt Auth-Handshake, Room-Binding, Publish/Subscribe, Unpublish, Frame-Relay ohne Self-Echo/Cross-Room-Leak und Reconnect-Publisher-/Track-Recovery ab.
- [x] Smoke-Gate führt den SFU-Contract aus.

Definition of done:
- I-06 bis I-10 sind nicht mehr nur Tabellen-Findings, sondern durch einen ausführbaren Contract abgedeckt.
- `/sfu` kann keinen Call-Workspace mehr durch fehlende Room-Angabe in `lobby` einsortieren.
- Publisher, Tracks und Frames bleiben room-scoped und reconnect-fähig.

Abschluss:
- `demo/video-chat/backend-king-php/http/module_realtime.php` erzwingt `room_id`/`room`-Binding vor Upgrade und validiert `sfu/join` gegen diesen gebundenen Room.
- `demo/video-chat/frontend-vue/src/lib/sfu/sfuClient.ts` sendet `room_id` kanonisch in Query und Join-Frame.
- `demo/video-chat/backend-king-php/tests/realtime-sfu-contract.php|sh` deckt I-06 bis I-10 ab.
- `demo/video-chat/scripts/smoke.sh` ruft `realtime-sfu-contract.sh` in der Backend-Contract-Strecke auf.

---

### #23 Access-Link Join-Session Binding (Implementierung)

Ziel:
- I-13 schließen: Public Access-Link-Join und Session-Issuing müssen bis in den WebSocket-Room-Kontext nachweisbar an den erlaubten Call gebunden sein.

Checklist:
- [x] Access-Link-Session-Bindung in `call_access_sessions` persistieren: `session_id`, `access_id`, `call_id`, `room_id`, `user_id`, `link_kind`, Laufzeitdaten.
- [x] `POST /api/call-access/{id}/session` schreibt diese Bindung atomar mit dem Session-Token.
- [x] Open-Link-Sessions erzeugen weiterhin Guest-User, verlangen aber `guest_name` und speichern denselben Room/Call-Bindungsvertrag.
- [x] `WS /ws` löst access-gebundene Sessions über die persistierte Bindung auf.
- [x] Fehlende Room-/Call-Query fällt für access-gebundene Sessions auf den gebundenen Call zurück, nicht auf `lobby`.
- [x] Fremde Room-/Call-Query bleibt fail-closed in der Waiting-Room-Ansicht ohne Pending-Ziel auf den fremden Raum.
- [x] Eingeladene access-gebundene User bleiben vor Admission in `waiting-room`; `allowed` darf in den gebundenen Call-Room.
- [x] Dedizierter Contract `call-access-session-contract` deckt Join, Session-Issuing, Binding-Persistenz, Personal-Link, Open-Link-Guest und WS-Room-Resolution ab.
- [x] Smoke-Gate führt den Contract aus.

Definition of done:
- Access-Link-Sessions können keinen fremden Call-Room öffnen, auch wenn die WebSocket-Query auf einen anderen Call zeigt.
- Der Join-/Session-Vertrag ist als ausführbarer PHP-Contract abgedeckt und in README/Smoke dokumentiert.

Abschluss:
- `demo/video-chat/backend-king-php/domain/calls/call_access.php` persistiert und liest `call_access_sessions`.
- `demo/video-chat/backend-king-php/http/module_realtime.php` erzwingt die Access-Session-Bindung bei der Room-Auflösung.
- `demo/video-chat/backend-king-php/tests/call-access-session-contract.php|sh` deckt I-13 ab.
- `demo/video-chat/scripts/smoke.sh` ruft `call-access-session-contract.sh` in der Backend-Contract-Strecke auf.

---

### #24 Gateway JWT-Bindung (Implementierung)

Ziel:
- I-11 schließen: Ein spaeterer Gateway-Join darf nur mit einem JWT akzeptiert werden, dessen `sub` und `effective_id` exakt zum `peer_id` passen und dessen `room`/`call_id` exakt zum `room_id` passt.

Checklist:
- [x] HS256-JWT-Vertragshelfer fuer Gateway-Join in PHP ergaenzen, ohne den aktiven Session-Pfad auf JWT umzustellen.
- [x] Unsichere oder zu kurze Gateway-Secrets fail-closed abweisen, inkl. `dev-secret-unsafe`.
- [x] Nur `alg=HS256` akzeptieren; `none`/falsche Algorithmen fail-closed.
- [x] Signatur, JSON-Header/-Claims und `exp` pruefen.
- [x] `sub` und `effective_id` muessen vorhanden sein und beide exakt `peer_id` entsprechen.
- [x] `room` und `call_id` duerfen nicht auseinanderlaufen; mindestens einer der Claims muss vorhanden sein und exakt `room_id` entsprechen.
- [x] Token-Laengenlimit fuer Gateway-Join pruefen.
- [x] Join-Rate-Limit als deterministischen Contract-Helfer abdecken.
- [x] Dedizierter Contract `gateway-jwt-binding-contract` deckt Happy Path, `call_id`-Alias, Peer-/Room-Mismatch, Split-Claims, Expiry, Algorithmen, Signatur, Secret-Policy, Tokenlaenge und Rate-Limit ab.
- [x] Smoke-Gate fuehrt den Contract aus.

Definition of done:
- Die Gateway-JWT-Bindung ist nicht mehr nur Research-Notiz, sondern als ausfuehrbarer Contract pruefbar.
- Der aktive Backend-Pfad bleibt serverseitig persistierte Session-ID; JWT bleibt ein harter Vertrag fuer den spaeteren Gateway-Pfad.

Abschluss:
- `demo/video-chat/backend-king-php/domain/realtime/gateway_jwt.php` implementiert die Gateway-JWT-Bindungs- und Rate-Limit-Helfer.
- `demo/video-chat/backend-king-php/tests/gateway-jwt-binding-contract.php|sh` deckt I-11 ab.
- `demo/video-chat/scripts/smoke.sh` ruft `gateway-jwt-binding-contract.sh` in der Backend-Contract-Strecke auf.

### #25 Gateway <-> Backend Signaling-Mapping (Implementierung)

Ziel:
- I-12 schließen: Backend-Signaling (`call/offer`, `call/answer`, `call/ice`, `call/hangup`) muss deterministisch auf den spaeteren Gateway-AMQP-Topic `call.signaling` abbildbar sein und wieder zur Backend-Form zurueckfuehren.

Checklist:
- [x] `room_id` ist kanonisch; `call_id` bleibt Gateway-/AMQP-Alias und muss exakt auf `room_id` passen.
- [x] Backend-Events werden auf ein `king.videochat.gateway.signaling.v1`-Payload fuer Topic `call.signaling` und Routing-Key `call.signaling.{room_id}` normalisiert.
- [x] `call/offer` und `call/answer` werden auf Gateway-`SessionDescription` mit `offer`/`answer` abgebildet.
- [x] `call/ice` wird auf Gateway-`IceCandidate` abgebildet; Backend-CamelCase-Felder und Gateway-Snake-Case-Felder werden beidseitig normalisiert.
- [x] `call/hangup` wird auf Gateway-`LeaveRequest` abgebildet.
- [x] Sender-/Target-Peer-IDs bleiben strikt numerisch, positiv und duerfen nicht identisch sein.
- [x] Room-/Call-Mismatch, Payload-Room-Mismatch, ungueltiger Topic und unbekannte Gateway-Kinds fail-closed.
- [x] Round-Trip-Ergebnis erfuellt weiterhin den bestehenden Backend-Signaling-Decoder.
- [x] Smoke-Gate fuehrt den Mapping-Contract aus.

Definition of done:
- I-12 ist als ausfuehrbarer Contract pruefbar, obwohl `demo/media-gateway` im aktuellen Checkout nicht vorhanden ist.
- Das Gateway kann spaeter gegen denselben Mapping-Vertrag implementiert werden, ohne Backend-Signaling-Semantik zu erraten.

Abschluss:
- `demo/video-chat/backend-king-php/domain/realtime/gateway_backend_mapping.php` implementiert die beidseitige Backend-/AMQP-Mapping-Schicht.
- `demo/video-chat/backend-king-php/tests/gateway-backend-mapping-contract.php|sh` deckt I-12 ab.
- `demo/video-chat/scripts/smoke.sh` ruft `gateway-backend-mapping-contract.sh` in der Backend-Contract-Strecke auf.

### #26 Readiness-Tracker mit Video-Chat-Abschlüssen abgleichen (Dokumentation)

Ziel:
- Der Langform-Tracker darf keine bereits belegten Video-Chat-Arbeiten weiter als offen anzeigen.

Checklist:
- [x] Aktuelle Abschlüsse #18 bis #25 als Recent-Closure-Notizen in `READYNESS_TRACKER.md` nachgetragen.
- [x] Bereits belegte Z1/Z2/Z3/Z4/Z5/Z6-Checkboxen anhand vorhandener Contracts und Dokumentation auf erledigt gesetzt.
- [x] Nicht belegte oder weiterhin fragliche Punkte bewusst offen gelassen, z. B. statische Admin-Overview-Metriken und breitere UI-Parity-Restarbeiten.
- [x] Keine Produktsemantik reduziert und keine dirty Frontend-/Backend-Arbeitsdateien angefasst.

Definition of done:
- `ISSUES.md` und `READYNESS_TRACKER.md` widersprechen sich nicht mehr bei bereits geschlossenen Video-Chat-Contracts.
- Offene Tracker-Punkte bleiben nur dort offen, wo der aktuelle Code noch keinen eindeutigen Nachweis liefert.

Abschluss:
- `READYNESS_TRACKER.md` enthaelt die Closure-Hinweise zu Chat-Attachments, Chat-Archiv, Activity-Layout, Playwright-Matrix, SFU-Binding, Access-Session, Gateway-JWT und Gateway-Mapping.
- Stale Checkboxen fuer belegte Backend-/Realtime-/Routing-/Smoke-Arbeiten sind aktualisiert.

### #27 Gemeinsames REST/WS Error-Envelope (Implementierung)

Ziel:
- REST-Fehler und realtime `system/error` Frames muessen denselben typisierten Fehlervertrag tragen, damit Clients Fehler einheitlich auswerten koennen.

Checklist:
- [x] Gemeinsamen Helper `videochat_error_envelope()` fuer `status: error`, `error.code`, `error.message`, optionale `error.details` und `time` ergaenzt.
- [x] REST-`$errorResponse` nutzt denselben Helper statt eine eigene Envelope-Struktur zu bauen.
- [x] Realtime-`system/error` Frames werden zentral vor dem Versand normalisiert.
- [x] Realtime-Kompatibilitaet bleibt erhalten: `type`, `code`, `message`, `details`, `time` bleiben top-level verfuegbar.
- [x] Realtime-Frames tragen zusaetzlich dieselbe REST-Envelope `status: error` und `error{code,message,details}`.
- [x] Dedizierter Contract deckt REST-Envelope, direkte realtime Error-Frames und Legacy-Frame-Normalisierung ueber `videochat_presence_send_frame()` ab.
- [x] Smoke-Gate fuehrt den Contract aus.

Definition of done:
- Ein Client kann REST- und WS-Fehler ueber dieselbe `status/error/time` Struktur lesen.
- Alte realtime Clients brechen nicht, weil die bisherigen Top-Level-Felder erhalten bleiben.

Abschluss:
- `demo/video-chat/backend-king-php/support/error_envelope.php` enthaelt den gemeinsamen Fehlervertrag.
- `demo/video-chat/backend-king-php/server.php` nutzt den gemeinsamen REST-Envelope.
- `demo/video-chat/backend-king-php/domain/realtime/realtime_presence.php` normalisiert `system/error` Frames zentral.
- `demo/video-chat/backend-king-php/tests/error-envelope-contract.php|sh` deckt den Vertrag ab.
- `demo/video-chat/scripts/smoke.sh` ruft den Contract in der Backend-Contract-Strecke auf.

### #28 Versionierte Contract-Schema-Tests fuer REST/WS DTOs (Implementierung)

Ziel:
- Z1 schliessen: Request-/Response-/Event-DTOs muessen im kanonischen `contracts/v1` Katalog versioniert und per Contract-Test gegen Schema-Drift geschuetzt sein.

Checklist:
- [x] `api.error_response` und `ws.system_error` sind als DTO-Schemas im kanonischen `contracts/v1/api-ws-contract.catalog.json` ergaenzt.
- [x] Der Contract erzwingt den Katalogpfad unter `contracts/v1` und eine `v1.x.y[-suffix]` Katalogversion.
- [x] Pflicht-DTOs fuer REST (`runtime_health`, `bootstrap`, `error_response`) und WS (`system_error`, `room_snapshot`, `chat_message`, `chat_ack`, `typing_start`, `typing_stop`, `reaction_event`, `reaction_batch`, `lobby_snapshot`, `signaling_event`) werden geprueft.
- [x] Schema-Metadaten werden rekursiv validiert: `type`, `one_of`, Objekt-Buckets, Arrays, Patterns, Enums und numerische Grenzen.
- [x] Beispielpayloads aus dem gemeinsamen REST/WS Error-Envelope werden gegen `api.error_response` und `ws.system_error` validiert.
- [x] Smoke-Gate fuehrt den Contract in der Backend-Contract-Strecke aus.
- [x] Unrelated dirty Frontend-/Realtime-Arbeitsdateien bleiben unangetastet.

Definition of done:
- Der versionierte API/WS-Katalog bleibt maschinenlesbar und kann nicht stillschweigend DTOs verlieren oder ungueltige Schema-Metadaten aufnehmen.
- Der gemeinsame Fehlervertrag aus #27 ist zugleich ueber den versionierten REST/WS-Katalog belegbar.

Abschluss:
- `demo/video-chat/contracts/v1/api-ws-contract.catalog.json` enthaelt die fehlenden Error-DTO-Schemas.
- `demo/video-chat/backend-king-php/tests/contract-schema-versioning-contract.php|sh` deckt Versionierung, Pflicht-DTOs, Schema-Metadaten und Beispielpayloads ab.
- `demo/video-chat/scripts/smoke.sh` ruft den Contract in der Backend-Contract-Strecke auf.

### #29 UI-Parity-Acceptance-Matrix mit ausfuehrbaren Gates (Implementierung)

Ziel:
- Z1 schliessen: UI-Parity darf nicht mehr als Prosa-Behauptung existieren, sondern muss als versionierte Matrix konkrete Playwright-/Backend-Checks und offene release-blocking Gaps ausweisen.

Checklist:
- [x] `contracts/v1/ui-parity-acceptance.matrix.json` als kanonische Matrix ergaenzt.
- [x] Covered Slices verweisen auf existierende Playwright-Specs, Backend-Contracts und npm-/Shell-Kommandos.
- [x] Offene UI-Gaps bleiben explizit als `release_blocking: true` in der Matrix: Admin-Overview-Metriken, Teilnehmermoderation, Control-Bar-Actions, Avatar/Branding-Crop und globale Theme-/Zeitformat-Anwendung.
- [x] `npm run test:e2e:ui-parity` als ausfuehrbarer UI-Parity-Suite-Einstieg ergaenzt.
- [x] PHP-Contract validiert Matrix-Version, Release-Policy, npm-Scripts, Pfade, Slice-Status, Covered Checks und Gap-Ehrlichkeit.
- [x] Strikter Release-Modus `VIDEOCHAT_UI_PARITY_REQUIRE_COVERED=1` blockiert solange Gaps offen sind.
- [x] Smoke-Gate fuehrt den Matrix-Contract in der Backend-Contract-Strecke aus.
- [x] Unrelated dirty Frontend-/Realtime-Arbeitsdateien bleiben unangetastet.

Definition of done:
- UI-Parity ist als versionierter, maschinenlesbarer Vertrag vorhanden.
- Covered UI-Flaechen sind an existierende Checks gebunden.
- Offene UI-Flaechen werden nicht als erledigt kaschiert, sondern blockieren spaetere Release-Strictness.

Abschluss:
- `demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json` enthaelt die Acceptance-Slices.
- `demo/video-chat/backend-king-php/tests/ui-parity-acceptance-matrix-contract.php|sh` prueft die Matrix.
- `demo/video-chat/frontend-vue/package.json` enthaelt `test:e2e:ui-parity`.
- `demo/video-chat/scripts/smoke.sh` ruft den Matrix-Contract auf.

### #30 Protected-API Forbidden-/Conflict-Semantikmatrix (Implementierung)

Ziel:
- Z2 schliessen: Geschuetzte Video-APIs muessen eine explizite, versionierte Semantik fuer Auth/RBAC, Validation, Forbidden und fachliche Conflicts haben, statt dass 403/409 nur verstreut in Endpoint-Code stehen.

Checklist:
- [x] `contracts/v1/protected-api-semantics.matrix.json` als kanonische Semantikmatrix ergaenzt.
- [x] Globale REST/WS-RBAC-Grenze (`auth_failed`, `rbac_forbidden`, `websocket_forbidden`) ist als eigene geschuetzte API-Familie erfasst.
- [x] Admin-User-Create/Update/Delete/Status, Calls-List/Create/View/Update/Delete/Cancel, Participant-Roles, Call-Access, Invite-Codes und Chat-Archive/Attachments sind abgedeckt.
- [x] Matrix unterscheidet fachliche 409-Conflicts von 400/422-Validation und dokumentiert `not_applicable_*`, wo ein Conflict fachlich nicht passt.
- [x] Validator prueft Version, Policies, relative Pfade, vorhandene Source-/Contract-Dateien, Error-Code-Evidence und Mindestabdeckung fuer Forbidden/Conflict/Validation.
- [x] Smoke-Gate fuehrt den Contract in der Backend-Contract-Strecke aus.
- [x] Unrelated dirty Frontend-/Realtime-Arbeitsdateien bleiben unangetastet.

Definition of done:
- Jede geschuetzte Video-API-Familie hat eine maschinenlesbare Zuordnung zu Auth/RBAC-, Validation-, Forbidden- und Conflict-Semantik.
- Einzelne Endpoints muessen keine kuenstliche 409-Semantik vortaeuschen; nicht anwendbare Conflicts werden explizit begruendet.

Abschluss:
- `demo/video-chat/contracts/v1/protected-api-semantics.matrix.json` enthaelt die Semantikmatrix.
- `demo/video-chat/backend-king-php/tests/protected-api-semantics-contract.php|sh` prueft die Matrix und Source-/Contract-Evidence.
- `demo/video-chat/scripts/smoke.sh` ruft den Contract in der Backend-Contract-Strecke auf.

## Persistente Research-Notizen (für Folgesessions)

- Alex-Relevanz (historisch, inzwischen nach `frontend-vue/src/lib/**` konsolidiert):
  - `78f4f5c` fügt `src/lib/sfuClient.ts` hinzu.
  - `e5d65b7` brachte SFU/WASM-Inhalte plus Artefakte; später bereinigt.
- WASM-Fallback heute:
  - vorhanden auf TS-WLVC-Ebene (Ist, nicht Ziel).
  - Ziel: nativer WebRTC-Fallback ohne TS-WLVC-Runtime-Fallback.
- Blur-Referenz (`intelligent-intern`) ist weit fortgeschritten:
  - Controller + Backend-Selector + Stream-Processing + Gates + Prefs + Tests.
- `demo/media-gateway` ist im aktuellen Checkout nicht vorhanden; die Rust-SFU-/AMQP-/QUIC-Skizze bleibt eine historische Research-Referenz, harte Gateway-Vertraege werden bis zur Integrationsentscheidung als Backend-Contracts gepflegt.

### #31 Persistente Schedule-Metadaten fuer Call-Kalenderprojektionen (Implementierung)

Ziel:
- Z3 schliessen: Call-Zeitplanung darf nicht nur aus `starts_at`/`ends_at` im Frontend geraten werden, sondern muss im Backend persistierte Schedule-Metadaten und eine gemeinsame Projektion liefern.

Checklist:
- [x] SQLite-Schema ergaenzt `calls` um `schedule_timezone`, `schedule_date`, `schedule_duration_minutes` und `schedule_all_day` inklusive Rueckfuell-Migration.
- [x] Create-Call persistiert Schedule-Metadaten und akzeptiert optional `schedule_timezone` sowie `schedule_all_day`.
- [x] Update-Call kann Zeitfenster, Zeitzone und All-Day-Marker aktualisieren und schreibt die abgeleitete Kalenderprojektion neu.
- [x] Call-Create/Update/List/Fetch-Antworten liefern ein einheitliches `schedule` Objekt mit Zeitzone, lokalem Start/Ende, Kalenderdatum, Dauer und All-Day-Flag.
- [x] Demo-Seed-Calls schreiben Schedule-Spalten deterministisch statt die Kalenderprojektion offen zu lassen.
- [x] Contract-Test deckt reine Projektion, ungueltige Zeitzonen, Persistenz, List-Projektion und Update-Reprojektion ab.
- [x] Smoke-Gate fuehrt den Schedule-Metadaten-Contract in der Backend-Contract-Strecke aus.
- [x] Unrelated dirty Frontend-/Realtime-Arbeitsdateien bleiben unangetastet.

Definition of done:
- Kalenderansichten koennen `schedule` aus der API verwenden und muessen lokale Daten, Dauer oder Zeitzone nicht aus Rohzeitstempeln rekonstruieren.
- Persistierte Rows bleiben migrationssicher und neue Calls schreiben die Metadaten sofort mit.

Abschluss:
- `calls` besitzt persistierte Schedule-Metadaten plus Migration `0017_call_schedule_metadata`.
- `demo/video-chat/backend-king-php/tests/call-schedule-metadata-contract.php|sh` prueft den Vertrag.
- `demo/video-chat/scripts/smoke.sh` ruft den Contract auf.

### #32 Invite-Preview-/Copy-Boundary fuer rohe Invite-Codes (Implementierung)

Ziel:
- Z3 schliessen: Rohe Invite-Codes duerfen nicht mehr als Defaultdaten aus Create-/Tabellenfluesse herausfallen. Preview und Copy brauchen eine klare API-Grenze.

Checklist:
- [x] `POST /api/invite-codes` liefert nur noch preview-sichere `invite_code` Metadaten ohne `code` und markiert `secret_available = false`.
- [x] `POST /api/invite-codes/{id}/copy` als expliziter Copy-Endpoint ergaenzt, der den rohen Code nur in `result.copy` ausliefert.
- [x] Copy-Berechtigung prueft Admin, Aussteller und Call-Editor/Owner statt den Secret-Code ueber generische Row-Daten freizugeben.
- [x] Abgelaufene Invite-Codes werden im Copy-Flow mit `410 invite_codes_expired` blockiert.
- [x] Runtime-/Bootstrap-Katalog und Protected-API-Semantikmatrix kennen die Copy-Boundary.
- [x] Existing Create-Endpoint-Contract wurde auf Preview-only angepasst und kopiert Codes nur ueber den neuen Copy-Endpoint.
- [x] Neuer Contract prueft Preview-/Copy-Helfer ohne SQLite und den persistenten Endpoint-Pfad mit SQLite, falls lokal vorhanden.
- [x] Smoke-Gate fuehrt den Invite-Preview-/Copy-Boundary-Contract in der Backend-Contract-Strecke aus.
- [x] Unrelated dirty Frontend-/Realtime-Arbeitsdateien bleiben unangetastet.

Definition of done:
- Frontend-Tabellen/Listendaten koennen Invite-Previews konsumieren, ohne rohe Invite-Secrets zu leaken.
- Der Secret-Code ist nur noch ueber eine explizite, auditierbare Copy-API mit eigener Autorisierung erreichbar.

Abschluss:
- `demo/video-chat/backend-king-php/tests/invite-code-copy-boundary-contract.php|sh` prueft den neuen Vertrag.
- `demo/video-chat/backend-king-php/http/module_invites.php` trennt Create-Preview und Copy-Secret.
- `demo/video-chat/contracts/v1/protected-api-semantics.matrix.json` und `demo/video-chat/contracts/v1/api-ws-contract.catalog.json` dokumentieren die neue Boundary.
