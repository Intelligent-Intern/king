/*
 * Keeps the HTTP/1 request path grouped while splitting request-target,
 * header, dispatch and entry-point logic into bounded fragments.
 */

#include "request/target.inc"
#include "request/headers.inc"
#include "request/dispatch.inc"
#include "request/api.inc"
