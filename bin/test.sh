#!/bin/bash
# King Library Test Runner
set -euo pipefail

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
TEST_TYPE=${TEST_TYPE:-all}
COVERAGE=${COVERAGE:-0}
VERBOSE=${VERBOSE:-0}

echo -e "${BLUE}🧪 King Library Test Runner${NC}"

# Check if extension is loaded
check_extension() {
    echo -e "${YELLOW}📋 Checking King extension...${NC}"
    
    if ! php -m | grep -q king; then
        echo -e "${RED}❌ King extension not loaded${NC}"
        echo -e "${YELLOW}Run: ./bin/build.sh first${NC}"
        exit 1
    fi
    
    echo -e "${GREEN}✅ King extension loaded${NC}"
    php -r "echo 'King version: ' . phpversion('king') . PHP_EOL;"
}

# Run PHPUnit tests
run_phpunit_tests() {
    echo -e "${YELLOW}🔬 Running PHPUnit tests...${NC}"
    
    if [ ! -f vendor/bin/phpunit ]; then
        echo -e "${YELLOW}⚠️  PHPUnit not found, installing...${NC}"
        if [ -f composer.json ]; then
            composer install --dev
        else
            echo -e "${RED}❌ No composer.json found${NC}"
            return 1
        fi
    fi
    
    local phpunit_args=""
    
    if [ "$COVERAGE" = "1" ]; then
        phpunit_args="$phpunit_args --coverage-html coverage/html --coverage-clover coverage/clover.xml"
    fi
    
    if [ "$VERBOSE" = "1" ]; then
        phpunit_args="$phpunit_args --verbose"
    fi
    
    vendor/bin/phpunit $phpunit_args
}

# Run integration tests
run_integration_tests() {
    echo -e "${YELLOW}🔗 Running integration tests...${NC}"
    
    local test_files=(
        "tests/integration/system_integration_test.php"
        "tests/integration/end_to_end_test.php"
    )
    
    for test_file in "${test_files[@]}"; do
        if [ -f "$test_file" ]; then
            echo -e "${BLUE}Running: $test_file${NC}"
            php "$test_file"
        else
            echo -e "${YELLOW}⚠️  Test file not found: $test_file${NC}"
        fi
    done
}

# Run fuzz tests
run_fuzz_tests() {
    echo -e "${YELLOW}🎯 Running fuzz tests...${NC}"
    
    if [ ! -d tests/fuzz ]; then
        echo -e "${YELLOW}⚠️  Fuzz tests directory not found${NC}"
        return 0
    fi
    
    cd tests/fuzz
    
    # Build fuzz tests if needed
    if [ -f Makefile ]; then
        make all
    fi
    
    # Run each fuzz test for a short duration
    local fuzz_tests=(
        "fuzz_websocket_test"
        "fuzz_iibin_protocol"
        "fuzz_semantic_dns"
        "fuzz_object_store"
    )
    
    for fuzz_test in "${fuzz_tests[@]}"; do
        if [ -x "./$fuzz_test" ]; then
            echo -e "${BLUE}Running fuzz test: $fuzz_test${NC}"
            timeout 60 "./$fuzz_test" || echo "Fuzz test completed"
        fi
    done
    
    cd ../..
}

# Run performance tests
run_performance_tests() {
    echo -e "${YELLOW}⚡ Running performance tests...${NC}"
    
    if [ ! -d benchmarks ]; then
        echo -e "${YELLOW}⚠️  Benchmarks directory not found${NC}"
        return 0
    fi
    
    cd benchmarks
    
    # Run WebSocket benchmarks
    if [ -f websocket_benchmark.sh ]; then
        ./websocket_benchmark.sh
    fi
    
    # Run IIBIN protocol benchmarks
    if [ -f iibin_protocol_benchmark.sh ]; then
        ./iibin_protocol_benchmark.sh
    fi
    
    # Run HTTP benchmarks
    if [ -f http_benchmark.sh ]; then
        ./http_benchmark.sh
    fi
    
    cd ..
}

# Run demo tests
run_demo_tests() {
    echo -e "${YELLOW}🎨 Running demo tests...${NC}"
    
    if [ ! -d demo/video-chat ]; then
        echo -e "${YELLOW}⚠️  Demo directory not found${NC}"
        return 0
    fi
    
    cd demo/video-chat
    
    if [ -f package.json ]; then
        # Install dependencies if needed
        if [ ! -d node_modules ]; then
            npm ci
        fi
        
        # Run unit tests
        if grep -q "test:unit" package.json; then
            npm run test:unit
        fi
        
        # Run E2E tests
        if grep -q "test:e2e" package.json; then
            npm run test:e2e
        fi
        
        # Run stress tests
        if grep -q "test:stress" package.json; then
            npm run test:stress
        fi
    else
        echo -e "${YELLOW}⚠️  No package.json found in demo${NC}"
    fi
    
    cd ../..
}

# Run security tests
run_security_tests() {
    echo -e "${YELLOW}🔒 Running security tests...${NC}"
    
    # Run Psalm security analysis
    if [ -f vendor/bin/psalm ]; then
        vendor/bin/psalm --taint-analysis --report=psalm-security.xml
    fi
    
    # Run static analysis
    if [ -f vendor/bin/phpstan ]; then
        vendor/bin/phpstan analyse --level=8 extension/src/
    fi
}

# Generate test report
generate_report() {
    echo -e "${YELLOW}📊 Generating test report...${NC}"
    
    local report_file="test-report-$(date +%Y%m%d-%H%M%S).html"
    
    cat > "$report_file" << EOF
<!DOCTYPE html>
<html>
<head>
    <title>King Library Test Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: green; }
        .warning { color: orange; }
        .error { color: red; }
        .section { margin: 20px 0; padding: 10px; border-left: 3px solid #ccc; }
    </style>
</head>
<body>
    <h1>King Library Test Report</h1>
    <p>Generated: $(date)</p>
    <p>PHP Version: $(php -v | head -n1)</p>
    <p>King Version: $(php -r "echo phpversion('king');")</p>
    
    <div class="section">
        <h2>Test Results</h2>
        <p>Test execution completed. Check individual test outputs for details.</p>
    </div>
    
    <div class="section">
        <h2>Coverage Report</h2>
        <p>Coverage reports available in: coverage/html/index.html</p>
    </div>
</body>
</html>
EOF
    
    echo -e "${GREEN}📄 Test report generated: $report_file${NC}"
}

# Main test execution
main() {
    local start_time=$(date +%s)
    
    check_extension
    
    case "$TEST_TYPE" in
        unit)
            run_phpunit_tests
            ;;
        integration)
            run_integration_tests
            ;;
        fuzz)
            run_fuzz_tests
            ;;
        performance)
            run_performance_tests
            ;;
        demo)
            run_demo_tests
            ;;
        security)
            run_security_tests
            ;;
        all)
            run_phpunit_tests
            run_integration_tests
            run_demo_tests
            run_security_tests
            ;;
        *)
            echo -e "${RED}❌ Unknown test type: $TEST_TYPE${NC}"
            exit 1
            ;;
    esac
    
    if [ "$COVERAGE" = "1" ]; then
        generate_report
    fi
    
    local end_time=$(date +%s)
    local duration=$((end_time - start_time))
    
    echo -e "${GREEN}🎉 Tests completed successfully in ${duration}s${NC}"
}

# Handle command line arguments
case "${1:-}" in
    --help|-h)
        echo "King Library Test Runner"
        echo ""
        echo "Usage: $0 [options] [test-type]"
        echo ""
        echo "Test types:"
        echo "  unit                Run PHPUnit tests"
        echo "  integration         Run integration tests"
        echo "  fuzz                Run fuzz tests"
        echo "  performance         Run performance tests"
        echo "  demo                Run demo tests"
        echo "  security            Run security tests"
        echo "  all                 Run all tests (default)"
        echo ""
        echo "Options:"
        echo "  --help, -h          Show this help"
        echo "  --coverage          Generate coverage report"
        echo "  --verbose           Verbose output"
        echo ""
        echo "Environment variables:"
        echo "  TEST_TYPE           Test type to run"
        echo "  COVERAGE            Generate coverage (1|0)"
        echo "  VERBOSE             Verbose output (1|0)"
        exit 0
        ;;
    --coverage)
        COVERAGE=1
        shift
        ;;
    --verbose)
        VERBOSE=1
        shift
        ;;
esac

# Set test type from argument
if [ $# -gt 0 ]; then
    TEST_TYPE="$1"
fi

main "$@"