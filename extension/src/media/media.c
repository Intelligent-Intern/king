/*
 * Native media PHP surface for King.
 *
 * This translation unit owns the King\RTP\Socket object binding, class state,
 * and MINIT registration hooks. The RTP transport/resource runtime remains in
 * rtp.c.
 */

#ifdef HAVE_CONFIG_H
#  include "config.h"
#endif

#include "php.h"
#include "php_king.h"
#include "media/arginfo/index.h"

#include "state.inc"
#include "php_binding.inc"
#include "registration.inc"
