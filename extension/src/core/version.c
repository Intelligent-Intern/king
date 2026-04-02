/*
 * =========================================================================
 * FILENAME:   src/core/version.c
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Implements king_version(). This is the small public leaf that exposes the
 * shipped extension version string from `PHP_KING_VERSION`.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"

/*
 * PHP_FUNCTION(king_version)
 *
 * PHP signature: king_version(): string
 *
 * Returns the public extension version string for the shipped v1 runtime.
 */
PHP_FUNCTION(king_version)
{
    ZEND_PARSE_PARAMETERS_NONE();

    RETURN_STRING(PHP_KING_VERSION);
}
