/*
 * =========================================================================
 * FILENAME:   src/client/tls.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Active skeleton TLS helpers for the client runtime. These functions do not
 * yet configure a live TLS backend, but they own validated CA/cert defaults,
 * session-ticket import/export, and ticket-ring integration.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/client/session.h"
#include "include/client/tls.h"
#include "include/config/tls_and_crypto/base_layer.h"

#include "main/php_streams.h"
#include <stdio.h>

#include "tls/support.inc"
#include "tls/config.inc"
#include "tls/ticket.inc"
#include "tls/api.inc"
