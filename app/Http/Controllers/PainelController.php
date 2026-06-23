<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;

class PainelController extends Controller
{
    public function redirect(): RedirectResponse
    {
        $user = auth()->user();

        return $user?->role === User::ROLE_ADMIN
            ? redirect()->route('admin.dashboard')
            : redirect()->route('cliente.dashboard');
    }
}
