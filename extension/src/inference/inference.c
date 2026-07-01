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
#include "inference/arginfo/index.h"

#include "core/bootstrap/state.inc"
#include "binding/php_binding.inc"
#include "core/bootstrap/registration.inc"
