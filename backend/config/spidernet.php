<?php

return [
    /*
    | Comma-separated exact origins allowed to call GET /api/public/traces|approvals
    | from a browser (Origin header). Leave empty to rely on config/cors.php only.
    */
    'public_share_allowed_origins' => env('PUBLIC_SHARE_ALLOWED_ORIGINS', ''),
];
