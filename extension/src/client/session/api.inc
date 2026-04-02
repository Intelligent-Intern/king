/*
 * =========================================================================
 * FILENAME:   src/client/session/api.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * Aggregator for the public client-session API slices. The nested `api/`
 * fragments carry the constructor/connect path, poll loop, and stats
 * surface; this root include keeps the top-level Session entry points
 * grouped without flattening them back into one large source file.
 * =========================================================================
 */

#include "api/connect.inc"
#include "api/poll.inc"
#include "api/stats.inc"
