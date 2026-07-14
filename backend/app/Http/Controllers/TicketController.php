<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Ticket;
use Illuminate\Support\Str;

class TicketController extends Controller
{
    public function index()
    {
        return response()->json(Ticket::all());
    }

    public function store(Request $request)
    {
        try {
            $ticket = Ticket::create([
                'id' => (string) Str::uuid(),
                'tenant_id' => '00000000-0000-0000-0000-000000000001',
                'title' => $request->title,
                'description' => $request->description,
                'status' => $request->status ?? 'open',
                'priority' => $request->priority ?? 'medium'
            ]);
            return response()->json($ticket, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        return response()->json(Ticket::findOrFail($id));
    }

    public function update(Request $request, $id)
    {
        $ticket = Ticket::findOrFail($id);
        $ticket->update($request->all());
        return response()->json($ticket);
    }

    public function destroy($id)
    {
        Ticket::destroy($id);
        return response()->json(['message' => 'Ticket deleted']);
    }
}
