<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
*/

Broadcast::routes(['middleware' => ['auth:sanctum']]);

Broadcast::channel('tenant.{tenantId}', function ($user, string $tenantId) {
    return (string) ($user->tenant_id ?? '') === (string) $tenantId;
});
