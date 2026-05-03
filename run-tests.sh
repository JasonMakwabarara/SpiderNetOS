#!/bin/bash
# Test Runner Script for SpiderNet OS
# Run comprehensive test suite with coverage

set -e

echo "🧪 Running SpiderNet OS Test Suite"
echo "=================================="

# Run unit tests
echo "📋 Running unit tests..."
python -m pytest tests/unit/ -v --tb=short --cov=services --cov-report=html --cov-report=term-missing

# Run integration tests (if services are running)
if [ "$RUN_INTEGRATION" = "true" ]; then
    echo "🔗 Running integration tests..."
    python -m pytest tests/integration/ -v --tb=short
fi

# Run security tests
if [ "$RUN_SECURITY" = "true" ]; then
    echo "🔒 Running security tests..."
    python -m pytest tests/unit/ -k security -v
fi

# Generate test report
echo "📊 Generating test report..."
python -c "
import json
import os
from datetime import datetime

# Collect test results
results = {
    'timestamp': datetime.utcnow().isoformat(),
    'environment': os.getenv('ENV', 'local'),
    'test_run': {
        'unit_tests': 'passed',  # Would be determined by pytest exit code
        'integration_tests': 'skipped',
        'security_tests': 'skipped',
        'coverage': '75% target'
    }
}

with open('test-results.json', 'w') as f:
    json.dump(results, f, indent=2)
"

echo "✅ Test suite completed"
echo "📄 Results saved to test-results.json"
echo "🌐 Coverage report: htmlcov/index.html"

# Optional: training data quality pipeline
if [ "$RUN_TRAINING_GATE" = "true" ]; then
    echo "🧠 Running training data quality gate..."
    if [ -z "$TRAINING_INPUT" ]; then
        echo "❌ TRAINING_INPUT is required when RUN_TRAINING_GATE=true"
        echo "   Example: RUN_TRAINING_GATE=true TRAINING_INPUT=/path/to/export.md ./run-tests.sh"
        exit 1
    fi

    make training-gate \
        TRAINING_INPUT="$TRAINING_INPUT" \
        TRAINING_OUT_DIR="${TRAINING_OUT_DIR:-training_data}" \
        TRAINING_MIN_QUALITY="${TRAINING_MIN_QUALITY:-2}" \
        TRAINING_MIN_QUALITY_SFT="${TRAINING_MIN_QUALITY_SFT:-2}" \
        TRAINING_MIN_SFT_ROWS="${TRAINING_MIN_SFT_ROWS:-20}" \
        TRAINING_MIN_PREF_ROWS="${TRAINING_MIN_PREF_ROWS:-20}" \
        TRAINING_MIN_DISTINCT_PREF_RATIO="${TRAINING_MIN_DISTINCT_PREF_RATIO:-0.95}"
fi