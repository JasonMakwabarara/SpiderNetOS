<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class BriefController extends Controller
{
    public function latest()
    {
        return response()->json(['brief' => null], 200);
    }

    public function history()
    {
        return response()->json(['briefs' => []], 200);
    }
}
