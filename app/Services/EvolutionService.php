<?php

namespace App\Services;

use App\Models\Configuracao;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cliente do Evolution API (WhatsApp).
 *
 * URL, API key e instância vêm de Configuracao (configuráveis via UI admin
 * em /painel/configuracoes). Se algum estiver em branco, os métodos de envio
 * ficam no-op (retornam false) — nunca lançam exception que quebre o job/
 * request principal.
 */
class EvolutionService
{
    private const TIMEOUT = 15;

    /**
     * Envia texto simples pra um número.
     * @param string $phone  Número no formato internacional (55 + DDD + 9 dígitos, sem +/espaços/traços).
     *                       Passe qualquer coisa — o método limpa e valida.
     * @param string $text   Mensagem a enviar. Suporta emojis, quebras de linha (\n).
     * @return array  ['ok' => bool, 'message' => string, 'response' => array|null]
     */
    public function sendText(string $phone, string $text): array
    {
        if (! Configuracao::evolutionConfigurado()) {
            return ['ok' => false, 'message' => 'WhatsApp não configurado nas configurações do painel.'];
        }

        $numero = $this->normalizarNumero($phone);
        if (! $numero) {
            return ['ok' => false, 'message' => 'Número de WhatsApp inválido.'];
        }

        $endpoint = $this->urlPara("/message/sendText/" . Configuracao::evolutionInstance());

        try {
            $response = $this->httpClient()->post($endpoint, [
                'number' => $numero,
                'text' => $text,
            ]);

            if ($response->successful()) {
                return ['ok' => true, 'message' => 'Mensagem enviada.', 'response' => $response->json()];
            }

            $body = $response->json() ?? [];
            $msg = $body['response']['message'][0]
                ?? $body['message']
                ?? "Evolution retornou HTTP {$response->status()}";

            Log::warning('Evolution sendText falhou', [
                'phone' => $numero,
                'status' => $response->status(),
                'body' => $body,
            ]);

            return ['ok' => false, 'message' => is_string($msg) ? $msg : 'Falha ao enviar WhatsApp.', 'response' => $body];
        } catch (\Throwable $e) {
            Log::warning('Evolution sendText exception', [
                'phone' => $numero,
                'erro' => $e->getMessage(),
            ]);
            return ['ok' => false, 'message' => 'Falha de rede ao contactar Evolution: ' . $e->getMessage()];
        }
    }

    /**
     * Verifica se o servidor Evolution responde na URL configurada, se a API
     * key é aceita, e se a instância está conectada ao WhatsApp.
     * Retorna resumo diagnóstico pra UI.
     */
    public function checkConnection(): array
    {
        if (! Configuracao::evolutionUrl() || ! Configuracao::evolutionApiKey()) {
            return ['ok' => false, 'stage' => 'config', 'message' => 'URL ou API key não configurada.'];
        }

        // 1) Servidor responde?
        try {
            $ping = Http::timeout(self::TIMEOUT)->get($this->urlPara('/'));
        } catch (\Throwable $e) {
            return ['ok' => false, 'stage' => 'network', 'message' => 'Não foi possível acessar o servidor Evolution: ' . $e->getMessage()];
        }
        if (! $ping->successful()) {
            return ['ok' => false, 'stage' => 'server', 'message' => "Servidor Evolution retornou HTTP {$ping->status()}."];
        }

        // 2) API key funciona? (lista instâncias exige auth)
        try {
            $fetch = $this->httpClient()->get($this->urlPara('/instance/fetchInstances'));
        } catch (\Throwable $e) {
            return ['ok' => false, 'stage' => 'auth', 'message' => 'Erro contactando a API: ' . $e->getMessage()];
        }
        if ($fetch->status() === 401) {
            return ['ok' => false, 'stage' => 'auth', 'message' => 'API key inválida (401 do Evolution).'];
        }
        if (! $fetch->successful()) {
            return ['ok' => false, 'stage' => 'auth', 'message' => "Erro consultando instâncias: HTTP {$fetch->status()}."];
        }

        // 3) Instância existe? Se sim, está conectada ao WhatsApp?
        $instance = Configuracao::evolutionInstance();
        if (! $instance) {
            return ['ok' => false, 'stage' => 'instance', 'message' => 'Nome da instância não configurado.'];
        }

        $instancias = collect($fetch->json() ?? []);
        // O Evolution v2 retorna array de instâncias com estrutura {name, connectionStatus, ...}
        $achada = $instancias->first(function ($i) use ($instance) {
            $nome = $i['name'] ?? $i['instance']['instanceName'] ?? null;
            return $nome === $instance;
        });
        if (! $achada) {
            return [
                'ok' => false,
                'stage' => 'instance',
                'message' => "Instância '{$instance}' não existe no Evolution. Crie via manager antes de testar.",
            ];
        }

        $connStatus = $achada['connectionStatus'] ?? $achada['instance']['status'] ?? null;
        if ($connStatus !== 'open') {
            return [
                'ok' => false,
                'stage' => 'whatsapp',
                'message' => "Instância existe mas não está conectada ao WhatsApp (status: {$connStatus}). Abra o manager e escaneie o QR code.",
            ];
        }

        return ['ok' => true, 'message' => "Conectado. Instância '{$instance}' está ativa."];
    }

    /**
     * Remove tudo que não é dígito. Se sobrarem 10-11 dígitos (BR sem DDI),
     * prepende '55'. Fora disso, retorna null.
     */
    private function normalizarNumero(string $phone): ?string
    {
        $numero = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($numero) >= 10 && strlen($numero) <= 11) {
            return '55' . $numero;
        }
        if (strlen($numero) >= 12 && strlen($numero) <= 15) {
            return $numero; // já tem DDI
        }
        return null;
    }

    private function urlPara(string $path): string
    {
        return Configuracao::evolutionUrl() . '/' . ltrim($path, '/');
    }

    private function httpClient()
    {
        return Http::timeout(self::TIMEOUT)
            ->acceptJson()
            ->asJson()
            ->withHeaders(['apikey' => Configuracao::evolutionApiKey()]);
    }
}
