<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

class SlotController extends Controller
{
    public function index()
    {
        return response()->json(['data' => []]);
    }

    public function show()
    {
        return response()->json(['data' => null]);
    }

    public function store()
    {
        return response()->json(['data' => null], 201);
    }

    public function update()
    {
        return response()->json(['data' => null]);
    }

    public function destroy()
    {
        return response()->json(['message' => 'stub']);
    }

    public function block()
    {
        return response()->json(['message' => 'stub']);
    }

    public function unblock()
    {
        return response()->json(['message' => 'stub']);
    }
}
