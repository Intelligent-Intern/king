/*
 * Native Awaitable PHP surface for King.
 *
 * This translation unit owns the Awaitable and CancelToken PHP bindings,
 * class method tables, and MINIT registration hooks. The core php_king
 * bootstrap only calls the exported registration functions.
 */

#ifdef HAVE_CONFIG_H
#  include "config.h"
#endif

#include "php.h"
#include "php_king.h"
#include "awaitable/arginfo/index.h"

#include "state.inc"
#include "php_binding.inc"
#include "registration.inc"
