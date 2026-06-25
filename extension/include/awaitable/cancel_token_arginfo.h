/*
 * include/awaitable/cancel_token_arginfo.h - King\CancelToken OO arginfo
 */

#ifndef KING_AWAITABLE_CANCEL_TOKEN_ARGINFO_H
#define KING_AWAITABLE_CANCEL_TOKEN_ARGINFO_H

#include <php.h>

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_CancelToken_cancel, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_CancelToken_isCancelled, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

#endif /* KING_AWAITABLE_CANCEL_TOKEN_ARGINFO_H */
