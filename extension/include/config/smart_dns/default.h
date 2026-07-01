/*
 * =========================================================================
 * FILENAME:   include/config/smart_dns/default.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header declares the function for loading the module's hardcoded
 * default values for the Smart DNS server.
 * =========================================================================
 */

#ifndef KING_CONFIG_SMART_DNS_DEFAULT_H
#define KING_CONFIG_SMART_DNS_DEFAULT_H

/**
 * @brief Loads the hardcoded, default values into the module's config struct.
 */
void kg_config_smart_dns_defaults_load(void);

#endif /* KING_CONFIG_SMART_DNS_DEFAULT_H */
