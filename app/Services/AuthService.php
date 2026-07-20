<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function register(array $data)
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'] ?? 'citizen',
        ]);

        return $user;
    }

    public function login(string $email, string $password)
    {
        $credentials = ['email' => $email, 'password' => $password];

        if (! $token = auth('api')->attempt($credentials)) {
            return null;
        }

        return [
            'token' => $token,
            'user' => auth('api')->user(),
        ];
    }

    public function logout()
    {
        auth('api')->logout();
    }

    public function getCurrentUser()
    {
        return auth('api')->user();
    }
}
