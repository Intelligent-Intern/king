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
 * Returns the public extension version string for the shipped v1 runtime.
 */
PHP_FUNCTION(king_version)
{
    ZEND_PARSE_PARAMETERS_NONE();

    RETURN_STRING(PHP_KING_VERSION);
}
