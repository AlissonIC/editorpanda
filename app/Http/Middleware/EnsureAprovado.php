<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Se admin bloquear um usuário durante a sessão, esse middleware
 * derruba imediatamente na próxima request.
 */
class EnsureAprovado
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && ! $user->isAprovado()) {
            $motivo = $user->isBloqueado()
                ? 'Sua conta foi bloqueada.'
                : 'Sua conta ainda está em análise.';

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => $motivo]);
        }

        return $next($request);
    }
}
