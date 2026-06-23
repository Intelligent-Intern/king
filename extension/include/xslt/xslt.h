/*
 * include/xslt/xslt.h - Native SaxonC-backed XSLT runtime surface
 * =========================================================================
 *
 * This subsystem exposes a small PHP-visible XSLT 2.0/3.0 execution primitive
 * backed by SaxonC through the same optional runtime-loader pattern used by
 * the transport and cloud adapter leaves.
 */

#ifndef KING_XSLT_H
#define KING_XSLT_H

#include <php.h>

PHP_FUNCTION(king_xslt_engine_status);
PHP_FUNCTION(king_xslt_transform_file);
PHP_FUNCTION(king_xslt_transform_to_file);

void king_xslt_shutdown_system(void);
void king_xslt_add_component_info(zval *configuration);

#endif
