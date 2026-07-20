<?php

namespace App\Http\Controllers;

use App\Models\Territory;

class TerritoryController extends Controller
{
    public function index()
    {
        return response()->json(['data' => Territory::where('is_active', true)->get()]);
    }
}
