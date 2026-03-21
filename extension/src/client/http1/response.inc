/*
 * Keeps HTTP/1 response handling grouped while splitting header utilities,
 * header parsing and socket I/O loops into bounded subfragments.
 */

#include "response/util.inc"
#include "response/header_parse.inc"
#include "response/send.inc"
#include "response/receive.inc"
