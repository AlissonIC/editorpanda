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

    // ==================== Gestão de instâncias ====================
    // Usados pela UI de /painel/configuracoes pra criar/conectar/excluir
    // instâncias sem precisar abrir o manager do Evolution.

    /**
     * Lista instâncias do servidor. Aceita credenciais explícitas pra validar
     * URL/API key ANTES de salvar (fluxo "Conectar" da UI).
     * @return array ['ok' => bool, 'message' => string, 'instancias' => array[{nome,status,numero,perfil}]]
     */
    public function listarInstancias(?string $url = null, ?string $apiKey = null): array
    {
        $url = $url !== null ? rtrim($url, '/') : Configuracao::evolutionUrl();
        $apiKey = $apiKey ?? Configuracao::evolutionApiKey();
        if (! $url || ! $apiKey) {
            return ['ok' => false, 'message' => 'URL ou API key não configurada.', 'instancias' => []];
        }

        try {
            $response = Http::timeout(self::TIMEOUT)->acceptJson()
                ->withHeaders(['apikey' => $apiKey])
                ->get($url . '/instance/fetchInstances');
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Não foi possível acessar o servidor Evolution: ' . $e->getMessage(), 'instancias' => []];
        }

        if ($response->status() === 401) {
            return ['ok' => false, 'message' => 'API key inválida (401 do Evolution).', 'instancias' => []];
        }
        if (! $response->successful()) {
            return ['ok' => false, 'message' => "Evolution retornou HTTP {$response->status()}.", 'instancias' => []];
        }

        $instancias = collect($response->json() ?? [])
            ->map(fn ($i) => $this->normalizarInstancia($i))
            ->filter(fn ($i) => $i['nome'] !== null)
            ->values()
            ->all();

        return ['ok' => true, 'message' => 'OK', 'instancias' => $instancias];
    }

    /** Cria uma instância (sem conectar — o QR vem depois via conectarInstancia). */
    public function criarInstancia(string $nome): array
    {
        try {
            $response = $this->httpClient()->post($this->urlPara('/instance/create'), [
                'instanceName' => $nome,
                'qrcode' => false,
                'integration' => 'WHATSAPP-BAILEYS',
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Falha de rede: ' . $e->getMessage()];
        }

        if ($response->successful()) {
            return ['ok' => true, 'message' => "Instância '{$nome}' criada."];
        }

        return ['ok' => false, 'message' => $this->extrairErro($response, 'Falha ao criar instância.')];
    }

    /**
     * Pede o QR code de conexão. O Evolution renova o QR a cada chamada —
     * a UI rechama quando o código expira (~40s).
     * @return array ['ok','message','qrcode' => data-uri|null, 'pairing_code' => string|null, 'conectada' => bool]
     */
    public function conectarInstancia(string $nome): array
    {
        try {
            $response = $this->httpClient()->get($this->urlPara('/instance/connect/' . rawurlencode($nome)));
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Falha de rede: ' . $e->getMessage(), 'qrcode' => null, 'pairing_code' => null, 'conectada' => false];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => $this->extrairErro($response, 'Falha ao gerar QR code.'), 'qrcode' => null, 'pairing_code' => null, 'conectada' => false];
        }

        $body = $response->json() ?? [];
        // Já conectada → Evolution devolve o state em vez do QR
        $state = $body['instance']['state'] ?? null;
        if ($state === 'open') {
            return ['ok' => true, 'message' => 'Instância já conectada.', 'qrcode' => null, 'pairing_code' => null, 'conectada' => true];
        }

        // v2 devolve {base64, code, pairingCode}; algumas versões aninham em {qrcode:{...}}
        $qr = $body['base64'] ?? $body['qrcode']['base64'] ?? null;
        $pairing = $body['pairingCode'] ?? $body['qrcode']['pairingCode'] ?? null;
        if (! $qr && ! $pairing) {
            return ['ok' => false, 'message' => 'Evolution não devolveu QR code — tente novamente em alguns segundos.', 'qrcode' => null, 'pairing_code' => null, 'conectada' => false];
        }

        return ['ok' => true, 'message' => 'QR gerado.', 'qrcode' => $qr, 'pairing_code' => $pairing, 'conectada' => false];
    }

    /** Estado da conexão: open (conectada), connecting, close. */
    public function statusInstancia(string $nome): array
    {
        try {
            $response = $this->httpClient()->get($this->urlPara('/instance/connectionState/' . rawurlencode($nome)));
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Falha de rede: ' . $e->getMessage(), 'state' => null];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => $this->extrairErro($response, 'Falha ao consultar status.'), 'state' => null];
        }

        $body = $response->json() ?? [];
        return ['ok' => true, 'message' => 'OK', 'state' => $body['instance']['state'] ?? $body['state'] ?? null];
    }

    /** Desconecta do WhatsApp (logout) sem excluir a instância. */
    public function desconectarInstancia(string $nome): array
    {
        try {
            $response = $this->httpClient()->delete($this->urlPara('/instance/logout/' . rawurlencode($nome)));
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Falha de rede: ' . $e->getMessage()];
        }

        return $response->successful()
            ? ['ok' => true, 'message' => "Instância '{$nome}' desconectada."]
            : ['ok' => false, 'message' => $this->extrairErro($response, 'Falha ao desconectar.')];
    }

    /** Exclui a instância. Faz logout antes — o Evolution recusa excluir instância conectada. */
    public function excluirInstancia(string $nome): array
    {
        try {
            // Logout best-effort: instância já desconectada devolve erro aqui, e tudo bem.
            $this->httpClient()->delete($this->urlPara('/instance/logout/' . rawurlencode($nome)));
            $response = $this->httpClient()->delete($this->urlPara('/instance/delete/' . rawurlencode($nome)));
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Falha de rede: ' . $e->getMessage()];
        }

        return $response->successful()
            ? ['ok' => true, 'message' => "Instância '{$nome}' excluída."]
            : ['ok' => false, 'message' => $this->extrairErro($response, 'Falha ao excluir instância.')];
    }

    /** Achata os formatos v1/v2 do fetchInstances num shape único pra UI. */
    private function normalizarInstancia(mixed $i): array
    {
        if (! is_array($i)) {
            return ['nome' => null, 'status' => null, 'numero' => null, 'perfil' => null];
        }
        $nome = $i['name'] ?? $i['instance']['instanceName'] ?? null;
        $status = $i['connectionStatus'] ?? $i['instance']['status'] ?? null;
        $jid = $i['ownerJid'] ?? $i['instance']['owner'] ?? null;
        $perfil = $i['profileName'] ?? $i['instance']['profileName'] ?? null;

        return [
            'nome' => $nome,
            'status' => $status,
            'numero' => $jid ? preg_replace('/\D/', '', explode('@', $jid)[0]) : null,
            'perfil' => $perfil,
        ];
    }

    private function extrairErro(Response $response, string $fallback): string
    {
        $body = $response->json() ?? [];
        $msg = $body['response']['message'][0] ?? $body['response']['message'] ?? $body['message'] ?? null;
        if (is_array($msg)) {
            $msg = implode(' ', array_map(fn ($m) => is_string($m) ? $m : json_encode($m), $msg));
        }
        return is_string($msg) && $msg !== '' ? $msg : $fallback . " (HTTP {$response->status()})";
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
