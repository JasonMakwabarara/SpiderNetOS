<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Report;
use App\Models\Agent;
use App\Models\Flow;
use App\Models\Ticket;
use App\Models\CrmRecord;

class ReportController extends Controller
{
    public function index()
    {
        return response()->json(Report::all());
    }

    public function store(Request $request)
    {
        try {
            $report = Report::create([
                'title' => $request->title ?? 'Untitled Report',
                'type' => $request->type ?? 'daily',
                'data' => $request->data ?? [],
                'generated_at' => now()
            ]);
            return response()->json($report, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        return response()->json(Report::findOrFail($id));
    }

    public function destroy($id)
    {
        Report::destroy($id);
        return response()->json(['message' => 'Report deleted']);
    }

    public function generate()
    {
        try {
            $report = Report::create([
                'title' => 'System Report - ' . now()->format('Y-m-d H:i'),
                'type' => 'system',
                'data' => [
                    'agents' => Agent::count(),
                    'flows' => Flow::count(),
                    'tickets' => Ticket::count(),
                    'crm_records' => CrmRecord::count(),
                    'generated_at' => now()->toISOString()
                ],
                'generated_at' => now()
            ]);
            return response()->json([
                'message' => 'Report generated successfully',
                'report' => $report
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
