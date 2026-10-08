<?php

return [
    /*
    | Sandbox is the only driver that completes a submission in this release.
    | fdms authorises the live gate, then refuses without an HTTP call.
    */
    'driver' => env('ZIMRA_FISCAL_DRIVER', 'sandbox'),
];
