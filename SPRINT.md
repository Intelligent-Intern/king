# King Active Issues

Purpose:
- This file contains only the active sprint extraction from `BACKLOG.md`.
- The complete open backlog is in `BACKLOG.md`.
- Completion notes go to `READYNESS_TRACKER.md`.

Active GitHub issue:
- #147 Batch 2: E2E, Encryption, And Security Claims (`1.0.7-beta`)

Rules:
- Keep active work small enough for clean commits and bisectable reviews.
- Do not mix ownership lanes unless the backlog item explicitly requires coordination.
- Do not weaken King v1 contracts to close a task faster.
- Preserve contributor credit when porting experiment-branch work.
- No path may be labeled secure, encrypted, E2EE, or post-quantum unless implementation, contracts, negative tests, and runtime/UI state prove the claim.

## Batch 2: E2E, Encryption, And Security Claims (`1.0.7-beta`)

### #Q-22 Video-Chat E2EE Threat Model, Contracts, And Runtime Honesty

Goal:
- Define the real media-security contract for video-chat so transport security, media-E2EE state, capability policy, and failure behavior are explicit and testable.

Checklist:
- [ ] Publish `demo/video-chat/contracts/v1/e2ee-session.contract.json`.
- [ ] Publish `demo/video-chat/contracts/v1/protected-media-frame.contract.json`.
- [ ] Pin participant key state, epoch semantics, sender key id, receiver expectations, replay inputs, error codes, and rekey transitions.
- [ ] Explicitly distinguish `transport_only`, `protected_not_ready`, `media_e2ee_active`, `blocked_capability`, `rekeying`, and `decrypt_error`.
- [ ] Define one deterministic capability negotiation policy for `required | preferred | disabled`.
- [ ] Define one shared security model across native WebRTC and WLVC/SFU paths.
- [ ] Add negative tests for unsupported capability, mixed rooms, invalid control state, downgrade attempts, and malformed protected frames.
- [ ] Make README, runtime notes, UI state, and telemetry wording match the contract exactly.
- [ ] Remove any “E2EE” wording from paths that are only DTLS/TLS protected.

Done:
- [ ] Media security claims are contract-first, runtime-honest, and testable.

### #Q-23 Video-Chat Native And SFU Media E2EE Implementation

Goal:
- Implement real media E2EE for both the native WebRTC path and the WLVC/SFU path so the server cannot decrypt protected media payloads.

Checklist:
- [ ] Implement client-side session key establishment and media epoch state.
- [ ] Keep raw media keys client-side only in normal operation.
- [ ] Implement sender-side media encryption for the native WebRTC path before remote delivery.
- [ ] Implement receiver-side decryption and integrity validation for the native WebRTC path.
- [ ] Implement sender-side media encryption for the WLVC/SFU path before `sfu/frame` transit.
- [ ] Implement receiver-side decryption and integrity validation for the WLVC/SFU path.
- [ ] Add participant join/leave/admission/removal/reconnect rekey behavior.
- [ ] Reject wrong epoch, wrong key id, replayed units, tampered payloads, and stale post-removal material.
- [ ] Add wire/packet-path verification proving the SFU forwards ciphertext and bounded public metadata only.
- [ ] Add CI coverage for native sender->receiver success, tamper rejection, and WLVC/SFU ciphertext-only transit.

Done:
- [ ] The E2EE path protects media end-to-end and the server cannot decode call content.

### #Q-24 Video-Chat Protected Media Transport Cleanup

Goal:
- Clean up the media transport layer so protected media is carried in a pinned typed/binary envelope rather than ad-hoc plaintext-oriented payload conventions.

Checklist:
- [ ] Separate codec-frame, transport-envelope, and protected-media contracts.
- [ ] Replace any ad-hoc JSON byte-array carriage for protected media with a pinned typed or binary envelope.
- [ ] Add bounded parse rules, malformed-frame rejection, and size ceilings for protected media transit.
- [ ] Ensure `/sfu` never needs raw media keys and never accepts unauthenticated plaintext in E2EE mode.
- [ ] Add contract tests for envelope parse/serialize parity and malformed-frame rejection.
- [ ] Add relay-visible-field tests so only intentionally public metadata crosses the SFU.
- [ ] Keep compatibility behavior explicit: no implicit fallback from protected envelope to plaintext media in `required` mode.

Done:
- [ ] Protected media transit is pinned, bounded, and ready for stable E2EE rollout.

### #Q-25 Video-Chat Algorithm-Agile And Hybrid Post-Quantum Key Agreement

Goal:
- Make the media-E2EE design algorithm-agile and able to support hybrid classical + post-quantum key establishment without redesigning the media-protection layer.

Checklist:
- [ ] Add a KEX abstraction independent from the protected-media frame format.
- [ ] Pin the negotiated KEX suite in capability negotiation and session state.
- [ ] Ship one production classical KEX path first on the shared abstraction.
- [ ] Add hybrid classical + PQ suite negotiation behind explicit policy.
- [ ] Bind transcript, room, participants, and selected suite into derived media epoch material.
- [ ] Add downgrade rejection across KEX suites.
- [ ] Add rejoin, reconnect, participant churn, and forced-rekey coverage under hybrid mode.
- [ ] Add telemetry that distinguishes classical vs hybrid sessions without leaking secrets.
- [ ] Document exactly what “post-quantum” means in this stack: key-establishment posture, not blanket secrecy of metadata, topology, or signaling.
- [ ] Keep post-quantum wording out of README/security claims until suite agreement, transcript binding, and downgrade tests are green.

Done:
- [ ] Media-key derivation is algorithm-agile and hybrid PQ works under the same pinned session-state contract as the classical path.
- [ ] Downgrade across KEX suites fails closed and is CI-covered.

### #E2E-1 Video-Chat End-To-End Acceptance Matrix

Goal:
- Prove the demo as a user journey, not only as isolated endpoint contracts.

Checklist:
- [ ] Add Playwright journey: owner creates call, invited user logs in from link, waits in join modal, owner admits, both see media and roster.
- [ ] Add Playwright journey: chat text, emoji, unread badge, attachment, and post-call read-only archive.
- [ ] Add Playwright journey: mobile call creation/editing with internal participant add.
- [ ] Add Playwright journey: websocket interruption, reconnect, room resync, and media/control recovery.
- [ ] Add release gate that fails when UI parity matrix or core video journeys are not covered.

Done:
- [ ] E2E journeys are deterministic enough to gate release readiness.

