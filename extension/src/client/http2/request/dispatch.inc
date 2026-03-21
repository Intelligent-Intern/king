/*
 * Keeps the HTTP/2 libcurl request-dispatch path grouped while splitting
 * request URL validation, handle setup, transfer handling and response
 * materialization into bounded subfragments.
 */

#include "dispatch/validate.inc"
#include "dispatch/configure.inc"
#include "dispatch/response.inc"
#include "dispatch/api.inc"
