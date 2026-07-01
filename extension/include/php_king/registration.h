/*
 * include/php_king/registration.h - Core class-registration bootstrap
 */

#ifndef KING_PHP_KING_REGISTRATION_H
#define KING_PHP_KING_REGISTRATION_H

#include <php.h>

zend_class_entry *king_register_class_with_flags(
    const char *name,
    zend_class_entry *parent,
    const zend_function_entry *functions,
    uint32_t flags);
zend_class_entry *king_register_exception(
    const char *name,
    zend_class_entry *parent);
void king_register_php_classes_and_handlers(void);

#endif /* KING_PHP_KING_REGISTRATION_H */
