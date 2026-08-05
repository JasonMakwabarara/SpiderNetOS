<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Agent;

class OrchestrationController extends Controller
{
    public function orchestrate(Request $request)
    {
        try {
            $task = $request->input('task', '');
            $agentIds = $request->input('agent_ids', []);
            
            if (empty($agentIds)) {
                return response()->json(['error' => 'Please select at least one agent'], 400);
            }
            
            $agents = Agent::whereIn('id', $agentIds)->get();
            if ($agents->isEmpty()) {
                return response()->json(['error' => 'No valid agents selected'], 400);
            }
            
            $results = [];
            foreach ($agents as $agent) {
                $results[$agent->name] = "{$agent->name} processed: '{$task}'";
            }
            
            $final = "Orchestration complete:\n" . implode("\n", $results);
            return response()->json(['response' => $final]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
