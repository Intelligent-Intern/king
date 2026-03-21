#!/bin/bash
# King Library Clean Script
set -euo pipefail

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}🧹 Cleaning King Library${NC}"

# Clean extension build
clean_extension() {
    echo -e "${YELLOW}🔨 Cleaning extension build...${NC}"
    
    if [ -d extension ]; then
        cd extension
        
        # Clean make files
        if [ -f Makefile ]; then
            make clean || true
        fi
        
        # Remove generated files
        rm -f \
            Makefile \
            Makefile.fragments \
            Makefile.global \
            Makefile.objects \
            acinclude.m4 \
            aclocal.m4 \
            autom4te.cache \
            build \
            config.guess \
            config.h \
            config.h.in \
            config.log \
            config.nice \
            config.status \
            config.sub \
            configure \
            configure.ac \
            configure.in \
            install-sh \
            libtool \
            ltmain.sh \
            missing \
            mkinstalldirs \
            modules \
            run-tests.php \
            .deps \
            .libs
        
        # Remove object files
        find . -name "*.o" -delete
        find . -name "*.lo" -delete
        find . -name "*.la" -delete
        find . -name "*.so" -delete
        
        # Remove temporary files
        find . -name "*~" -delete
        find . -name "*.tmp" -delete
        
        cd ..
        echo -e "${GREEN}✅ Extension cleaned${NC}"
    fi
}

# Clean demo build
clean_demo() {
    echo -e "${YELLOW}🎨 Cleaning demo build...${NC}"
    
    if [ -d demo/video-chat ]; then
        cd demo/video-chat
        
        # Remove build artifacts
        rm -rf \
            dist \
            node_modules/.cache \
            .vite \
            .nuxt \
            .output
        
        # Remove logs
        rm -f \
            npm-debug.log* \
            yarn-debug.log* \
            yarn-error.log*
        
        cd ../..
        echo -e "${GREEN}✅ Demo cleaned${NC}"
    fi
}

# Clean test artifacts
clean_tests() {
    echo -e "${YELLOW}🧪 Cleaning test artifacts...${NC}"
    
    # Remove coverage reports
    rm -rf coverage
    
    # Remove test reports
    rm -f test-report-*.html
    rm -f psalm-security.xml
    
    # Remove PHPUnit cache
    rm -rf .phpunit.cache
    
    # Remove fuzz test artifacts
    if [ -d tests/fuzz ]; then
        cd tests/fuzz
        if [ -f Makefile ]; then
            make clean || true
        fi
        find . -name "fuzz_*" -executable -delete
        cd ../..
    fi
    
    echo -e "${GREEN}✅ Test artifacts cleaned${NC}"
}

# Clean benchmark results
clean_benchmarks() {
    echo -e "${YELLOW}⚡ Cleaning benchmark results...${NC}"
    
    if [ -d benchmarks ]; then
        cd benchmarks
        rm -rf results
        rm -f *.log
        rm -f *.txt
        cd ..
        echo -e "${GREEN}✅ Benchmark results cleaned${NC}"
    fi
}

# Clean Docker artifacts
clean_docker() {
    echo -e "${YELLOW}🐳 Cleaning Docker artifacts...${NC}"
    
    # Remove dangling images
    if command -v docker >/dev/null 2>&1; then
        docker image prune -f || true
        docker container prune -f || true
        echo -e "${GREEN}✅ Docker artifacts cleaned${NC}"
    else
        echo -e "${YELLOW}⚠️  Docker not found, skipping${NC}"
    fi
}

# Clean logs
clean_logs() {
    echo -e "${YELLOW}📝 Cleaning logs...${NC}"
    
    # Remove log files
    find . -name "*.log" -not -path "./node_modules/*" -delete
    
    # Remove var/log if exists
    if [ -d var/log ]; then
        rm -rf var/log/*
    fi
    
    echo -e "${GREEN}✅ Logs cleaned${NC}"
}

# Clean ccache
clean_ccache() {
    echo -e "${YELLOW}⚡ Cleaning ccache...${NC}"
    
    if command -v ccache >/dev/null 2>&1; then
        ccache -C
        echo -e "${GREEN}✅ ccache cleaned${NC}"
    else
        echo -e "${YELLOW}⚠️  ccache not found, skipping${NC}"
    fi
}

# Deep clean - remove all generated files
deep_clean() {
    echo -e "${YELLOW}🔥 Performing deep clean...${NC}"
    
    # Remove node_modules
    find . -name "node_modules" -type d -exec rm -rf {} + 2>/dev/null || true
    
    # Remove vendor
    rm -rf vendor
    
    # Remove composer lock
    rm -f composer.lock
    
    # Remove package lock
    find . -name "package-lock.json" -delete
    find . -name "yarn.lock" -delete
    
    echo -e "${GREEN}✅ Deep clean completed${NC}"
}

# Main clean process
main() {
    local start_time=$(date +%s)
    
    case "${1:-all}" in
        extension)
            clean_extension
            ;;
        demo)
            clean_demo
            ;;
        tests)
            clean_tests
            ;;
        benchmarks)
            clean_benchmarks
            ;;
        docker)
            clean_docker
            ;;
        logs)
            clean_logs
            ;;
        ccache)
            clean_ccache
            ;;
        deep)
            clean_extension
            clean_demo
            clean_tests
            clean_benchmarks
            clean_logs
            clean_ccache
            deep_clean
            ;;
        all)
            clean_extension
            clean_demo
            clean_tests
            clean_benchmarks
            clean_logs
            clean_ccache
            ;;
        --help|-h)
            echo "King Library Clean Script"
            echo ""
            echo "Usage: $0 [target]"
            echo ""
            echo "Targets:"
            echo "  all                 Clean everything (default)"
            echo "  extension           Clean extension build"
            echo "  demo                Clean demo build"
            echo "  tests               Clean test artifacts"
            echo "  benchmarks          Clean benchmark results"
            echo "  docker              Clean Docker artifacts"
            echo "  logs                Clean log files"
            echo "  ccache              Clean ccache"
            echo "  deep                Deep clean (removes dependencies)"
            echo ""
            echo "Options:"
            echo "  --help, -h          Show this help"
            exit 0
            ;;
        *)
            echo -e "${RED}❌ Unknown target: $1${NC}"
            echo -e "${YELLOW}Use --help for available targets${NC}"
            exit 1
            ;;
    esac
    
    local end_time=$(date +%s)
    local duration=$((end_time - start_time))
    
    echo -e "${GREEN}🎉 Clean completed in ${duration}s${NC}"
}

main "$@"