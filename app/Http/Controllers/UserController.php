<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function agents(): JsonResponse
    {
        $user = auth('api')->user();

        abort_unless(
            $user instanceof User && $user->hasRole(UserRole::Manager),
            403,
        );

        return response()->json([
            'data' => User::query()
                ->where('role', UserRole::Agent->value)
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role']),
        ]);
    }
}
