#ifndef KING_HRTIME_H
#define KING_HRTIME_H

#include <stdint.h>
#include <time.h>
#include <sys/time.h>

#if defined(__has_include)
# if __has_include("Zend/zend_hrtime.h")
#  include "Zend/zend_hrtime.h"
# elif __has_include(<Zend/zend_hrtime.h>)
#  include <Zend/zend_hrtime.h>
# endif
#endif

static inline uint64_t king_hrtime_ns(void)
{
#if defined(ZEND_HRTIME_AVAILABLE) && ZEND_HRTIME_AVAILABLE
    return (uint64_t) zend_hrtime();
#elif defined(CLOCK_MONOTONIC)
    struct timespec ts;

    if (clock_gettime(CLOCK_MONOTONIC, &ts) == 0) {
        return ((uint64_t) ts.tv_sec * 1000000000ULL) + (uint64_t) ts.tv_nsec;
    }
#endif

    {
        struct timeval tv;

        if (gettimeofday(&tv, NULL) == 0) {
            return ((uint64_t) tv.tv_sec * 1000000000ULL) + ((uint64_t) tv.tv_usec * 1000ULL);
        }
    }

    return 0;
}

static inline uint64_t king_hrtime_ms(void)
{
    return king_hrtime_ns() / 1000000ULL;
}

#endif
