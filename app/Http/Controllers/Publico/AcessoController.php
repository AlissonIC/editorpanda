<?php

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Models\AcessoToken;
use App\Models\Comprador;
use App\Notifications\MagicLinkNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class AcessoController extends Controller
{
    public function form(): View
    {
        return view('pages.publico.acesso');
    }

    public function solicitar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:180'],
        ]);
        $email = strtolower(trim($data['email']));

        // Rate limit: max 3 links/hora por email + 5/hora por IP
        $keyEmail = 'acesso:email:' . $email;
        $keyIp = 'acesso:ip:' . $request->ip();
        if (RateLimiter::tooManyAttempts($keyEmail, 3) || RateLimiter::tooManyAttempts($keyIp, 5)) {
            return response()->json([
                'message' => 'Muitas solicitações. Tente novamente em alguns minutos.',
            ], 429);
        }
        // Hit ANTES do lookup pra manter tempo de resposta constante (mitiga timing attack)
        RateLimiter::hit($keyEmail, 3600);
        RateLimiter::hit($keyIp, 3600);

        // Envia magic link se o email é conhecido (silenciosamente ignora se não)
        $comprador = Comprador::where('email', $email)->first();
        if ($comprador) {
            [$tokenPlano] = AcessoToken::gerarPara($email, $request->ip(), $request->userAgent());
            $url = route('publico.acesso.validar', ['token' => $tokenPlano]);
            try {
                $comprador->notify(new MagicLinkNotification($url));
            } catch (\Throwable $e) {
                \Log::warning('Falha ao enviar magic link', ['email' => $email, 'erro' => $e->getMessage()]);
            }
        }

        // Retorna sempre 200 (não confirma se o email existe — anti-enumeração)
        return response()->json([
            'message' => 'Se este e-mail estiver cadastrado, um link de acesso foi enviado.',
        ]);
    }

    public function validar(Request $request, string $token)
    {
        $email = AcessoToken::consumir($token);
        if (! $email) {
            return view('pages.publico.acesso', [
                'erro' => 'Link inválido ou expirado. Solicite um novo.',
            ]);
        }

        $comprador = Comprador::where('email', $email)->first();
        if (! $comprador) {
            return view('pages.publico.acesso', ['erro' => 'Cadastro não encontrado.']);
        }

        auth('comprador')->login($comprador, true);
        $request->session()->regenerate();

        // Reset dos rate limiters — login bem-sucedido libera a fila
        RateLimiter::clear('acesso:email:' . $email);
        RateLimiter::clear('acesso:ip:' . $request->ip());

        return redirect()->route('publico.minhas-compras');
    }

    public function logout(Request $request)
    {
        auth('comprador')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('publico.acesso');
    }
}
