<?php

namespace App\Services\Projections;

use App\Models\Event;
use Illuminate\Support\Facades\DB;

class FlowProjection
{
    public function accepts(Event $event): bool
    {
        return in_array($event->event_type, [
            'flow.created',
            'flow.updated',
            'flow.published',
            'flow.archived',
            'flow.deleted',
            'flow.node_added',
            'flow.node_updated',
            'flow.node_removed',
            'flow.edge_added',
            'flow.edge_removed',
        ]);
    }
    
    public function handle(Event $event): void
    {
        match ($event->event_type) {
            'flow.created' => $this->handleCreated($event),
            'flow.updated' => $this->handleUpdated($event),
            'flow.published' => $this->handlePublished($event),
            'flow.archived' => $this->handleArchived($event),
            'flow.deleted' => $this->handleDeleted($event),
            'flow.node_added' => $this->handleNodeAdded($event),
            'flow.node_updated' => $this->handleNodeUpdated($event),
            'flow.node_removed' => $this->handleNodeRemoved($event),
            'flow.edge_added' => $this->handleEdgeAdded($event),
            'flow.edge_removed' => $this->handleEdgeRemoved($event),
            default => null,
        };
    }
    
    private function handleCreated(Event $event): void
    {
        $p = $event->payload;
        DB::table('flows')->insert([
            'id' => $event->aggregate_id,
            'tenant_id' => $event->tenant_id,
            'name' => $p['name'],
            'slug' => $p['slug'],
            'description' => $p['description'] ?? null,
            'dag' => json_encode(['nodes' => [], 'edges' => []]),
            'triggers' => json_encode($p['triggers'] ?? []),
            'status' => 'draft',
            'created_at' => $event->occurred_at,
            'updated_at' => $event->occurred_at,
        ]);
    }
    
    private function handleUpdated(Event $event): void
    {
        $p = $event->payload;
        DB::table('flows')
            ->where('id', $event->aggregate_id)
            ->update([
                'name' => $p['name'] ?? DB::raw('name'),
                'description' => $p['description'] ?? DB::raw('description'),
                'triggers' => isset($p['triggers']) ? json_encode($p['triggers']) : DB::raw('triggers'),
                'updated_at' => $event->occurred_at,
            ]);
    }
    
    private function handlePublished(Event $event): void
    {
        DB::table('flows')
            ->where('id', $event->aggregate_id)
            ->update([
                'status' => 'published',
                'published_at' => $event->occurred_at,
                'updated_at' => $event->occurred_at,
            ]);
    }
    
    private function handleArchived(Event $event): void
    {
        DB::table('flows')
            ->where('id', $event->aggregate_id)
            ->update([
                'status' => 'archived',
                'updated_at' => $event->occurred_at,
            ]);
    }
    
    private function handleDeleted(Event $event): void
    {
        DB::table('flows')
            ->where('id', $event->aggregate_id)
            ->delete();
    }
    
    private function handleNodeAdded(Event $event): void
    {
        $p = $event->payload;
        DB::table('dag_nodes')->insert([
            'id' => $p['node_id'],
            'flow_id' => $event->aggregate_id,
            'node_type' => $p['node_type'],
            'agent_id' => $p['agent_id'] ?? null,
            'config' => json_encode($p['config'] ?? []),
            'position_x' => $p['x'] ?? 0,
            'position_y' => $p['y'] ?? 0,
            'created_at' => $event->occurred_at,
            'updated_at' => $event->occurred_at,
        ]);
    }
    
    private function handleNodeUpdated(Event $event): void
    {
        $p = $event->payload;
        DB::table('dag_nodes')
            ->where('id', $p['node_id'])
            ->update([
                'config' => isset($p['config']) ? json_encode($p['config']) : DB::raw('config'),
                'position_x' => $p['x'] ?? DB::raw('position_x'),
                'position_y' => $p['y'] ?? DB::raw('position_y'),
                'updated_at' => $event->occurred_at,
            ]);
    }
    
    private function handleNodeRemoved(Event $event): void
    {
        DB::table('dag_nodes')
            ->where('id', $event->payload['node_id'])
            ->delete();
    }
    
    private function handleEdgeAdded(Event $event): void
    {
        $p = $event->payload;
        DB::table('dag_edges')->insert([
            'id' => $p['edge_id'],
            'flow_id' => $event->aggregate_id,
            'source_node_id' => $p['source_id'],
            'target_node_id' => $p['target_id'],
            'condition' => $p['condition'] ?? null,
            'created_at' => $event->occurred_at,
            'updated_at' => $event->occurred_at,
        ]);
    }
    
    private function handleEdgeRemoved(Event $event): void
    {
        DB::table('dag_edges')
            ->where('id', $event->payload['edge_id'])
            ->delete();
    }
}
