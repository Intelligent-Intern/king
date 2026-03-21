/*
 * =========================================================================
 * FILENAME:   src/core/version.c
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Implements King\version(). First real (non-stub) implementation.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"

/*
 * PHP_FUNCTION(king_version)
 *
 * PHP signature: king_version(): string
 *
 * Returns the extension version string, suffixed with "-skeleton" to
 * clearly signal that this is not a production build.
 */
PHP_FUNCTION(king_version)
{
    ZEND_PARSE_PARAMETERS_NONE();

    RETURN_STRING(PHP_KING_VERSION "-skeleton");
}
