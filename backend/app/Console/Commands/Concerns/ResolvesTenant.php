<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Shared slug-or-UUID tenant lookup for the outreach ops commands. */
trait ResolvesTenant
{
    protected function resolveTenant(string $target): ?Tenant
    {
        $target = trim($target);
        if ($target === '') {
            return null;
        }

        return Str::isUuid($target) ? Tenant::find($target) : Tenant::where('slug', $target)->first();
    }

    /** @return Collection<int, Tenant> */
    protected function resolveTenants(string $target)
    {
        if ($target !== '') {
            $tenant = $this->resolveTenant($target);

            return $tenant === null ? collect() : collect([$tenant]);
        }

        return Tenant::where('status', 'active')->whereNotNull('onboarding_completed_at')
            ->get()
            ->filter(fn (Tenant $t) => ! empty(((array) ($t->settings ?? []))['outreach'] ?? null))
            ->values();
    }
}
