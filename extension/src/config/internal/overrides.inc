/*
 * =========================================================================
 * FILENAME:   src/config/internal/overrides.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * Aggregator for the config-override routing and application pipeline. The
 * nested `overrides/` fragments decide which config family owns a key, track
 * explicit override state, collect exported overrides, and apply them onto a
 * composed `king_cfg_t` snapshot.
 * =========================================================================
 */

#include "overrides/routing.inc"
#include "overrides/state.inc"
#include "overrides/collect.inc"
#include "overrides/apply.inc"
