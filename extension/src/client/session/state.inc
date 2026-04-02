/*
 * =========================================================================
 * FILENAME:   src/client/session/state.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * Aggregator for the internal client-session state slices. The nested
 * `state/` fragments own base initialization, config capture, resource
 * binding, local cancel bookkeeping, and TLS/session-ticket state; this
 * root include keeps that state model grouped at the session layer.
 * =========================================================================
 */

#include "state/init.inc"
#include "state/config.inc"
#include "state/resource.inc"
#include "state/cancel.inc"
#include "state/tls.inc"
