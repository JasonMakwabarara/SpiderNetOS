<?php

/**
 * VAPID keys for browser web-push (RFC 8292). Generate a P-256 keypair and set
 * these; the public key is exposed to the cockpit PWA for subscription. Until
 * set, push send is a no-op (in-app real-time still works via broadcast).
 */
return [
    'public_key' => env('VAPID_PUBLIC_KEY'),
    'private_key' => env('VAPID_PRIVATE_KEY'),
    'subject' => env('VAPID_SUBJECT', 'mailto:ops@apexsynchronia.com'),
];
