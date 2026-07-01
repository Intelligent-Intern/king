/*
 * include/php_king/interrupt_helpers.h - Zend VM interrupt helper functions
 */

#ifndef KING_PHP_KING_INTERRUPT_HELPERS_H
#define KING_PHP_KING_INTERRUPT_HELPERS_H

#include <php.h>
#include <Zend/zend_execute.h>
#include <stdbool.h>
#include <stdatomic.h>

static inline bool king_vm_interrupt_pending(void)
{
#if PHP_VERSION_ID >= 80200
    return zend_atomic_bool_load_ex(&EG(vm_interrupt));
#else
    return EG(vm_interrupt);
#endif
}

static inline void king_process_pending_interrupts(void)
{
    if (UNEXPECTED(king_vm_interrupt_pending())) {
        if (zend_interrupt_function != NULL) {
            zend_interrupt_function(EG(current_execute_data));
        }
    }
}

#endif /* KING_PHP_KING_INTERRUPT_HELPERS_H */
