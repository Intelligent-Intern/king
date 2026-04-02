/*
 * =========================================================================
 * FILENAME:   src/client/http2/request/dispatch.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * Aggregator for the HTTP/2 one-shot dispatch path. The nested `dispatch/`
 * fragments own URL validation, easy-handle setup, transfer execution, and
 * response shaping; this root include keeps that request pipeline grouped
 * without collapsing it back into a single oversized source file.
 * =========================================================================
 */

#include "dispatch/validate.inc"
#include "dispatch/configure.inc"
#include "dispatch/response.inc"
#include "dispatch/api.inc"
