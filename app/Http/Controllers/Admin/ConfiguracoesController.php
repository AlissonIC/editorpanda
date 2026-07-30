<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Configuracao;
use App\Services\EvolutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class ConfiguracoesController extends Controller
{
    public function index(): View
    {
        $storageDisk = Configuracao::storageDisk();

        $s3Configurado = ! empty(config('filesystems.disks.s3.key'))
            && ! empty(config('filesystems.disks.s3.secret'))
            && ! empty(config('filesystems.disks.s3.bucket'));

        return view('pages.painel.configuracoes', [
            'storageDisk' => $storageDisk,
            's3Configurado' => $s3Configurado,
            // WhatsApp
            'evolutionUrl' => Configuracao::evolutionUrl(),
            'evolutionApiKey' => Configuracao::evolutionApiKey(),
            'evolutionInstance' => Configuracao::evolutionInstance(),
            // Email — SMTP fica em .env (só o remetente é editável na UI)
            'emailFromAddress' => Configuracao::emailFromAddress(),
            'emailFromName' => Configuracao::emailFromName(),
            'mailDriver' => (string) config('mail.default'),
            'mailHost' => (string) config('mail.mailers.smtp.host', ''),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'storage_disk' => ['required', 'in:local,s3'],
            'evolution_url' => ['nullable', 'url', 'max:255'],
            'evolution_api_key' => ['nullable', 'string', 'max:255'],
            'evolution_instance' => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'email_from_address' => ['nullable', 'email', 'max:180'],
            'email_from_name' => ['nullable', 'string', 'max:120'],
        ]);

        Configuracao::set(Configuracao::CHAVE_STORAGE_DISK, $data['storage_disk']);
        // Nulos passam string vazia — trim + null pra manter DB limpo
        Configuracao::set(Configuracao::CHAVE_EVOLUTION_URL, trim($data['evolution_url'] ?? '') ?: null);
        Configuracao::set(Configuracao::CHAVE_EVOLUTION_API_KEY, trim($data['evolution_api_key'] ?? '') ?: null);
        Configuracao::set(Configuracao::CHAVE_EVOLUTION_INSTANCE, trim($data['evolution_instance'] ?? '') ?: null);
        Configuracao::set(Configuracao::CHAVE_EMAIL_FROM_ADDRESS, trim($data['email_from_address'] ?? '') ?: null);
        Configuracao::set(Configuracao::CHAVE_EMAIL_FROM_NAME, trim($data['email_from_name'] ?? '') ?: null);

        return response()->json(['message' => 'Configurações salvas.']);
    }

    /**
     * Testa a conexão com o Evolution E envia uma mensagem de teste.
     * Retorna cada etapa (config → server → auth → instance → whatsapp → sent)
     * pra facilitar diagnóstico quando algo dá errado.
     */
    public function testarWhatsApp(Request $request, EvolutionService $evolution): JsonResponse
    {
        $data = $request->validate([
            'numero' => ['required', 'string', 'max:20'],
            'mensagem' => ['nullable', 'string', 'max:500'],
        ]);

        // 1) Checa configuração/conexão antes de tentar enviar
        $check = $evolution->checkConnection();
        if (! $check['ok']) {
            return response()->json([
                'ok' => false,
                'stage' => $check['stage'] ?? 'unknown',
                'message' => $check['message'],
            ], 422);
        }

        // 2) Envia mensagem de teste
        $texto = $data['mensagem'] ?: "🧪 Teste de conexão do Editor Panda enviado em " . now()->format('d/m/Y H:i') . ".";
        $resultado = $evolution->sendText($data['numero'], $texto);

        return response()->json([
            'ok' => $resultado['ok'],
            'stage' => $resultado['ok'] ? 'sent' : 'send',
            'message' => $resultado['message'],
        ], $resultado['ok'] ? 200 : 422);
    }

    /**
     * Envia e-mail de teste pra caixa informada. Usa a config atual (SMTP do
     * .env + remetente do banco).
     */
    public function testarEmail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'destino' => ['required', 'email', 'max:180'],
        ]);

        try {
            Mail::raw(
                "Este é um e-mail de teste do Editor Panda enviado em " . now()->format('d/m/Y H:i') . ".\n\n"
                . "Se você recebeu, o servidor SMTP está OK e o remetente configurado é o correto.",
                function ($message) use ($data) {
                    $from = Configuracao::emailFromAddress();
                    $fromName = Configuracao::emailFromName() ?: config('app.name');
                    if ($from) {
                        $message->from($from, $fromName);
                    }
                    $message->to($data['destino'])
                        ->subject('Teste — Editor Panda');
                }
            );
            return response()->json(['ok' => true, 'message' => "E-mail enviado para {$data['destino']}."]);
        } catch (\Throwable $e) {
            Log::warning('Teste de e-mail falhou', ['erro' => $e->getMessage()]);
            return response()->json([
                'ok' => false,
                'message' => 'Falha ao enviar: ' . $e->getMessage(),
            ], 422);
        }
    }
}
