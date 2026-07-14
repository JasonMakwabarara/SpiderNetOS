<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Services\MemoryService;

class MemoryController extends Controller
{
    public function store(Request $request) {
        $service = new MemoryService();
        $service->storeMemory($request->agent_id, $request->user_id, $request->conversation);
        return response()->json(['message' => 'Memory stored']);
    }
    public function recall(Request $request) {
        $service = new MemoryService();
        $memories = $service->recallMemory($request->agent_id, $request->user_id);
        return response()->json($memories);
    }
}
