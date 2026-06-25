/*
 * Native inference PHP surface for King.
 *
 * This translation unit owns the model, stream, OpenAI-compatible router,
 * tensor, tokenizer, paging, and registration bindings for inference. The
 * core php_king bootstrap only calls the exported registration functions.
 */

#ifdef HAVE_CONFIG_H
#  include "config.h"
#endif

#include "php.h"
#include "php_king.h"
#include "php_king/arginfo.h"

#include "state.inc"
#include "php_binding.inc"
#include "registration.inc"
