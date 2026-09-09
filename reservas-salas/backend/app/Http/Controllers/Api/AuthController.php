<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

class AuthController extends Controller
{
    public function login()
    {
        return response()->json(['message' => 'stub']);
    }

    public function logout()
    {
        return response()->json(['message' => 'stub']);
    }
}
