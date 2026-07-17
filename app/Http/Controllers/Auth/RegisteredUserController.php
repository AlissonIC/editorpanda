<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\NovoClientePendenteNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'whatsapp' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'nome' => $request->nome,
            'email' => $request->email,
            'whatsapp' => $request->whatsapp,
            'password' => Hash::make($request->password),
            'role' => User::ROLE_CLIENTE,
            'status' => User::STATUS_PENDENTE,   // aguarda aprovação do admin
        ]);

        event(new Registered($user));

        // Notifica admins sobre o novo cadastro pendente (best-effort — falha de SMTP não bloqueia)
        try {
            $admins = User::admins()->get();
            if ($admins->isNotEmpty()) {
                Notification::send($admins, new NovoClientePendenteNotification($user));
            }
        } catch (\Throwable $e) {
            Log::warning('Falha ao notificar admins do novo cadastro', [
                'user_id' => $user->id,
                'erro' => $e->getMessage(),
            ]);
        }

        // NÃO faz auto-login — cliente precisa esperar aprovação manual.
        return redirect()->route('cadastro.aguardando')->with('email', $user->email);
    }
}
