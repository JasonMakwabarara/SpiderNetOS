<?php

declare(strict_types=1);

namespace Tests\Unit\Outreach;

use App\Models\Tenant;
use App\Services\Outreach\OutreachSettings;
use Tests\TestCase;

/** Pure merge semantics of the settings blob; no database. */
class OutreachSettingsTest extends TestCase
{
    public function test_defaults_apply_when_nothing_is_stored(): void
    {
        $tenant = new Tenant(['settings' => []]);
        $s = (new OutreachSettings)->for($tenant);

        $this->assertSame(30, $s['program']['commission_pct']);
        $this->assertSame(12, $s['program']['months']);
        $this->assertSame('approve', $s['replies']['mode']);
        $this->assertCount(3, $s['sequence']);
        $this->assertSame('partner.invite', $s['sequence'][0]['template']);
    }

    public function test_stored_scalars_override_and_untouched_keys_keep_defaults(): void
    {
        $tenant = new Tenant(['settings' => ['outreach' => [
            'program' => ['commission_pct' => 25, 'postal_address' => '1 Example Street'],
            'sending' => ['quiet_hours' => ['start' => '21:00']],
        ]]]);
        $s = (new OutreachSettings)->for($tenant);

        $this->assertSame(25, $s['program']['commission_pct']);
        $this->assertSame('1 Example Street', $s['program']['postal_address']);
        $this->assertSame(12, $s['program']['months']);
        // Nested associative merge: only start was overridden.
        $this->assertSame('21:00', $s['sending']['quiet_hours']['start']);
        $this->assertSame('08:00', $s['sending']['quiet_hours']['end']);
    }

    public function test_lists_are_replaced_whole_not_merged(): void
    {
        $tenant = new Tenant(['settings' => ['outreach' => [
            'sequence' => [['step' => 1, 'template' => 'partner.invite', 'wait_days' => 0]],
            'sending' => ['warmup' => [['from_day' => 1, 'cap' => 2]]],
        ]]]);
        $s = (new OutreachSettings)->for($tenant);

        $this->assertCount(1, $s['sequence']);
        $this->assertSame([['from_day' => 1, 'cap' => 2]], $s['sending']['warmup']);
    }
}
