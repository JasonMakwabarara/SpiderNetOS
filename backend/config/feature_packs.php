<?php

/**
 * Feature-pack install policy + publisher trust.
 *
 * `require_signed` — when true, only packs with a verifiable Ed25519 signature
 * from a known publisher install (production stance). Off by default so the
 * bundled first-party packs (still carrying placeholder signatures) install in
 * dev without a signing pipeline.
 *
 * `publishers` — map of publisher name → base64 Ed25519 public key.
 */
return [
    'require_signed' => (bool) env('FEATURE_PACKS_REQUIRE_SIGNED', false),

    'publishers' => array_filter([
        'spidernet' => env('FEATURE_PACKS_KEY_SPIDERNET'),
    ]),
];
