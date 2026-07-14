<?php
namespace App\Services;
use App\Models\AgentMemory;
use Illuminate\Support\Facades\Log;

class MemoryService
{
    public function storeMemory($agentId, $userId, $conversation, $sentiment = 'neutral')
    {
        try { AgentMemory::create(['agent_id' => $agentId, 'user_id' => $userId, 'summary' => substr($conversation, 0, 200), 'key_facts' => ['last_topic' => $conversation], 'sentiment' => $sentiment]); }
        catch (\Exception $e) { Log::error('Memory store error: ' . $e->getMessage()); }
    }
    public function recallMemory($agentId, $userId, $limit = 5)
    {
        try { return AgentMemory::where('agent_id', $agentId)->where('user_id', $userId)->orderBy('created_at', 'desc')->take($limit)->get(); }
        catch (\Exception $e) { return collect(); }
    }
    public function recallSummary($agentId, $userId)
    {
        $memories = $this->recallMemory($agentId, $userId);
        if ($memories->isEmpty()) return '';
        return $memories->pluck('summary')->implode("\n");
    }
}
