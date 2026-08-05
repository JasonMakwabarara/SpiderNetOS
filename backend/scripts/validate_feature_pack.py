#!/bin/bash
echo "=== SpiderNetOS Feature Pack Validation ==="

PACKS_DIR="../packages/feature-packs"

if [ ! -d "$PACKS_DIR" ]; then
    echo "?? No feature packs directory found. Skipping validation."
    exit 0
fi

echo "?? Checking feature packs..."

for pack in "$PACKS_DIR"/*; do
    if [ -d "$pack" ]; then
        name=$(basename "$pack")
        if [ -f "$pack/pack.yaml" ]; then
            echo "? $name: pack.yaml found"
        else
            echo "? $name: pack.yaml missing"
            exit 1
        fi
    fi
done

echo "? All feature packs validated!"
exit 0
