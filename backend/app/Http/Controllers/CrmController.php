<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\CrmRecord;
use Illuminate\Support\Str;

class CrmController extends Controller
{
    public function index()
    {
        return response()->json(CrmRecord::all());
    }

    public function store(Request $request)
    {
        try {
            $crm = CrmRecord::create([
                'id' => (string) Str::uuid(),
                'tenant_id' => '00000000-0000-0000-0000-000000000001',
                'field' => $request->field,
                'value' => $request->value
            ]);
            return response()->json($crm, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        return response()->json(CrmRecord::findOrFail($id));
    }

    public function destroy($id)
    {
        CrmRecord::destroy($id);
        return response()->json(['message' => 'CRM record deleted']);
    }
}
