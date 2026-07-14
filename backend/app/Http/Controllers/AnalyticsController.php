<?php
namespace App\Http\Controllers;
use App\Models\Agent;
use App\Models\Flow;
use App\Models\Ticket;
use App\Models\CrmRecord;

class AnalyticsController extends Controller
{
    public function agents()
    {
        return response()->json([
            'total_agents' => Agent::count(),
            'active_agents' => Agent::where('status', 'active')->count(),
            'inactive_agents' => Agent::where('status', '!=', 'active')->count()
        ]);
    }

    public function flows()
    {
        return response()->json([
            'total_flows' => Flow::count(),
            'published_flows' => Flow::where('status', 'published')->count(),
            'draft_flows' => Flow::where('status', 'draft')->count()
        ]);
    }

    public function tickets()
    {
        return response()->json([
            'total_tickets' => Ticket::count(),
            'open_tickets' => Ticket::where('status', 'open')->count(),
            'closed_tickets' => Ticket::where('status', 'closed')->count()
        ]);
    }

    public function crm()
    {
        return response()->json([
            'total_records' => CrmRecord::count()
        ]);
    }

    public function generateReport()
    {
        try {
            $data = [
                'agents' => [
                    'total' => Agent::count(),
                    'active' => Agent::where('status', 'active')->count(),
                    'inactive' => Agent::where('status', '!=', 'active')->count()
                ],
                'flows' => [
                    'total' => Flow::count(),
                    'published' => Flow::where('status', 'published')->count(),
                    'draft' => Flow::where('status', 'draft')->count()
                ],
                'tickets' => [
                    'total' => Ticket::count(),
                    'open' => Ticket::where('status', 'open')->count(),
                    'closed' => Ticket::where('status', 'closed')->count()
                ],
                'crm' => [
                    'total' => CrmRecord::count()
                ],
                'generated_at' => now()->toISOString()
            ];
            
            return response()->json([
                'message' => 'Report generated successfully',
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
