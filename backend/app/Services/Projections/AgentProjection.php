<?php

namespace App\Services\Projections;

use App\Models\Event;
use Illuminate\Support\Facades\DB;

class AgentProjection
{
    public function accepts(Event $event): bool
    {
        return in_array($event->event_type, [
            'agent.registered',
            'agent.activated',
            'agent.deactivated',
            'agent.capability_added',
            'agent.capability_removed',
            'agent.delegation_updated',
        ]);
    }
    
    public function handle(Event $event): void
    {
        match ($event->event_type) {
            'agent.registered' => $this->handleRegistered($event),
            'agent.activated' => $this->handleActivated($event),
            'agent.deactivated' => $this->handleDeactivated($event),
            'agent.capability_added' => $this->handleCapabilityAdded($event),
            'agent.capability_removed' => $this->handleCapabilityRemoved($event),
            'agent.delegation_updated' => $this->handleDelegationUpdated($event),
            default => null,
        };
    }
    
    private function handleRegistered(Event $event): void
    {
        $payload = $event->payload;
        
        DB::table('agents')->insert([
            'id' => $event->aggregate_id,
            'tenant_id' => $event->tenant_id,
            'name' => $payload['name'],
            'slug' => $payload['slug'],
            'description' => $payload['description'] ?? null,
            'type' => $payload['type'],
            'status' => 'inactive',
            'capabilities' => json_encode($payload['capabilities'] ?? []),
            'config' => json_encode($payload['config'] ?? []),
            'created_at' => $event->occurred_at,
            'updated_at' => $event->occurred_at,
        ]);
    }
    
    private function handleActivated(Event $event): void
    {
        DB::table('agents')
            ->where('id', $event->aggregate_id)
            ->update([
                'status' => 'active',
                'activated_at' => $event->occurred_at,
                'updated_at' => $event->occurred_at,
            ]);
    }
    
    private function handleDeactivated(Event $event): void
    {
        DB::table('agents')
            ->where('id', $event->aggregate_id)
            ->update([
                'status' => 'inactive',
                'updated_at' => $event->occurred_at,
            ]);
    }
    
    private function handleCapabilityAdded(Event $event): void
    {
        $agent = DB::table('agents')->where('id', $event->aggregate_id)->first();
        if (!$agent) return;
        
        $capabilities = json_decode($agent->capabilities, true);
        $capabilities[] = $event->payload['capability'];
        
        DB::table('agents')
            ->where('id', $event->aggregate_id)
            ->update([
                'capabilities' => json_encode($capabilities),
                'updated_at' => $event->occurred_at,
            ]);
    }
    
    private function handleCapabilityRemoved(Event $event): void
    {
        $agent = DB::table('agents')->where('id', $event->aggregate_id)->first();
        if (!$agent) return;
        
        $capabilities = json_decode($agent->capabilities, true);
        $capabilities = array_diff($capabilities, [$event->payload['capability']]);
        
        DB::table('agents')
            ->where('id', $event->aggregate_id)
            ->update([
                'capabilities' => json_encode(array_values($capabilities)),
                'updated_at' => $event->occurred_at,
            ]);
    }
    
    private function handleDelegationUpdated(Event $event): void
    {
        DB::table('agent_delegations')->updateOrInsert(
            [
                'agent_id' => $event->aggregate_id,
                'delegate_id' => $event->payload['delegate_id'],
            ],
            [
                'permission' => $event->payload['permission'],
                'conditions' => json_encode($event->payload['conditions'] ?? []),
                'updated_at' => $event->occurred_at,
            ]
        );
    }
}
