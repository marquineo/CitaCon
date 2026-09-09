<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

class UserController extends Controller
{
    public function quota()
    {
        return response()->json(['data' => null]);
    }

    public function updateWeeklyHours()
    {
        return response()->json(['data' => null]);
    }
}
