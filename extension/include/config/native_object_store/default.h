/*
 * =========================================================================
 * FILENAME:   include/config/native_object_store/default.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header declares the function for loading the module's hardcoded
 * default values for the Native Object Store.
 * =========================================================================
 */

#ifndef KING_CONFIG_NATIVE_OBJECT_STORE_DEFAULT_H
#define KING_CONFIG_NATIVE_OBJECT_STORE_DEFAULT_H

/**
 * @brief Loads the hardcoded, default values into the module's config struct.
 */
void kg_config_native_object_store_defaults_load(void);

#endif /* KING_CONFIG_NATIVE_OBJECT_STORE_DEFAULT_H */
