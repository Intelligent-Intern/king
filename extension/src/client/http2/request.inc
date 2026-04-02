/*
 * =========================================================================
 * FILENAME:   src/client/http2/request.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * Aggregator for the HTTP/2 request-side slices. The detailed request
 * behavior lives in the nested `request/` fragments; this root include
 * wires the header normalization, dispatch, multi-request, and public API
 * leaves into the HTTP/2 client surface.
 * =========================================================================
 */

#include "request/headers.inc"
#include "request/dispatch.inc"
#include "request/multi.inc"
#include "request/api.inc"
