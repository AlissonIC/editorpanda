<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Assinatura;
use App\Models\LogPagamento;
use App\Models\Plano;
use App\Models\User;
use App\Services\MercadoPagoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Checkout da assinatura — PIX e cartão via Mercado Pago.
 *
 * Mesmo desenho do checkout de vídeos (PagamentoController): cria o payment
 * no MP, o front mostra QR ou tokeniza o cartão no Bricks, e o status é
 * resolvido por polling + notificação. A diferença é o que se compra.
 *
 * Regra central: a assinatura nasce PENDENTE e só vira ativa quando o MP
 * aprova. Nada de acesso adiantado — `scopeAtivas` ignora pendente.
 */
class AssinaturaCheckoutController extends Controller
{
    private const DURACAO_DIAS = 30;

    /**
     * Resolvido só quando vai falar com o gateway — de propósito.
     *
     * O MercadoPagoService estoura no construtor se o access_token não estiver
     * configurado. Injetando na classe, um ambiente sem credencial derrubaria
     * até o /resumo, que é só leitura do plano. Assim a tela abre normal e só
     * o passo de pagar avisa que a cobrança está indisponível.
     */
    private function mp(): MercadoPagoService
    {
        return app(MercadoPagoService::class);
    }

    /**
     * Tudo que o modal precisa mostrar ANTES de cobrar: o que está sendo
     * comprado, se é renovação ou troca, o que muda, e o que se perde.
     */
    public function resumo(Plano $plano): JsonResponse
    {
        $user = auth()->user();
        abort_if($user->isAdmin(), 403);
        abort_unless($plano->ativo, 422, 'Este plano não está disponível.');

        $atual = $user->assinaturaAtiva();
        $tipo = $this->definirTipo($atual, $plano);

        $diasRestantes = $atual
            ? max(0, (int) ceil(now()->diffInDays($atual->expira_em, false)))
            : 0;

        // Renovação empilha no vencimento atual; troca e nova começam hoje.
        $inicio = $tipo === Assinatura::TIPO_RENOVACAO && $atual && $atual->expira_em->isFuture()
            ? $atual->expira_em->copy()
            : now();
        $fim = $inicio->copy()->addDays(self::DURACAO_DIAS);

        $avisos = [];
        if ($tipo === Assinatura::TIPO_TROCA && $diasRestantes > 0) {
            $avisos[] = "A troca cancela o plano {$atual->plano_nome} imediatamente. "
                . "Você perde os {$diasRestantes} dia(s) restantes dele.";
        }

        // Trocar pra um plano menor que o espaço já ocupado deixa o cliente
        // estourado no dia seguinte — melhor ele saber antes de pagar.
        $usadoBytes = (int) $user->armazenamento_bytes;
        $novoLimite = $plano->armazenamento_gb * 1073741824;
        if ($novoLimite > 0 && $usadoBytes > $novoLimite) {
            $avisos[] = 'Você já usa ' . $this->formatarBytes($usadoBytes) . ' e este plano oferece '
                . $plano->armazenamento_gb . ' GB. Será preciso liberar espaço antes de enviar novos arquivos.';
        }

        return response()->json([
            'tipo' => $tipo,
            'tipo_label' => match ($tipo) {
                Assinatura::TIPO_RENOVACAO => 'Renovação',
                Assinatura::TIPO_TROCA => 'Troca de plano',
                default => 'Nova assinatura',
            },
            'plano' => [
                'id' => $plano->id,
                'nome' => $plano->nome,
                'descricao' => $plano->descricao,
                'preco' => (float) $plano->preco,
                'armazenamento_gb' => $plano->armazenamento_gb,
                'taxa_por_venda' => (float) $plano->taxa_por_venda,
            ],
            'atual' => $atual ? [
                'nome' => $atual->plano_nome,
                'armazenamento_gb' => $atual->plano?->armazenamento_gb,
                'taxa_por_venda' => $atual->plano ? (float) $atual->plano->taxa_por_venda : null,
                'expira_em' => $atual->expira_em->format('d/m/Y'),
                'dias_restantes' => $diasRestantes,
            ] : null,
            'vigencia' => [
                'inicio' => $inicio->format('d/m/Y'),
                'fim' => $fim->format('d/m/Y'),
                'dias' => self::DURACAO_DIAS,
            ],
            'total' => (float) $plano->preco,
            'avisos' => $avisos,
            // Pré-preenche o formulário — na segunda cobrança o CPF já vem.
            'cobranca' => [
                'nome' => $user->name,
                'email' => $user->email,
                'cpf' => $user->cpf,
            ],
        ]);
    }

    /**
     * Cria (ou reaproveita) a cobrança pendente do plano escolhido e guarda os
     * dados de cobrança no cadastro.
     *
     * Reaproveitar importa: fechar o modal e reabrir não pode deixar um rastro
     * de assinaturas pendentes órfãs no histórico.
     */
    public function iniciar(Request $request, Plano $plano): JsonResponse
    {
        $user = auth()->user();
        abort_if($user->isAdmin(), 403);
        abort_unless($plano->ativo, 422, 'Este plano não está disponível.');

        // Só o CPF: é o único dado de pagador que o MP exige além do e-mail,
        // que já vem do cadastro.
        $dados = $request->validate([
            'cpf' => ['required', 'string', 'max:14'],
        ]);

        if (! $this->cpfValido($dados['cpf'])) {
            return response()->json([
                'message' => 'CPF inválido.',
                'errors' => ['cpf' => ['Confira o CPF — o Mercado Pago recusa a cobrança sem um documento válido.']],
            ], 422);
        }

        $user->fill($dados)->save();

        $atual = $user->assinaturaAtiva();
        $tipo = $this->definirTipo($atual, $plano);

        $assinatura = DB::transaction(function () use ($user, $plano, $tipo) {
            User::whereKey($user->id)->lockForUpdate()->first();

            // Já existe cobrança pendente pro mesmo plano? Reusa. O valor é
            // reatualizado caso o admin tenha mexido no preço nesse meio tempo.
            $pendente = Assinatura::where('user_id', $user->id)
                ->where('status', Assinatura::STATUS_PENDENTE)
                ->where('plano_id', $plano->id)
                ->lockForUpdate()
                ->first();

            if ($pendente) {
                // Preço mudou → o PIX antigo cobra o valor errado, descarta.
                if ((float) $pendente->preco_pago !== (float) $plano->preco) {
                    $pendente->update([
                        'preco_pago' => $plano->preco,
                        'tipo' => $tipo,
                        'gateway_id' => null,
                        'gateway_status' => null,
                        'pix_qr_code' => null,
                        'pix_qr_code_base64' => null,
                        'pix_expires_at' => null,
                    ]);
                } elseif ($pendente->tipo !== $tipo) {
                    $pendente->update(['tipo' => $tipo]);
                }

                return $pendente;
            }

            // Pendentes de OUTROS planos viram lixo assim que ele escolhe outro.
            Assinatura::where('user_id', $user->id)
                ->where('status', Assinatura::STATUS_PENDENTE)
                ->update(['status' => Assinatura::STATUS_CANCELADA, 'cancelado_em' => now()]);

            return Assinatura::create([
                'user_id' => $user->id,
                'plano_id' => $plano->id,
                'plano_nome' => $plano->nome,
                'preco_pago' => $plano->preco,
                'duracao_dias' => self::DURACAO_DIAS,
                'status' => Assinatura::STATUS_PENDENTE,
                'tipo' => $tipo,
            ]);
        });

        return response()->json([
            'assinatura_id' => $assinatura->id,
            'total' => (float) $assinatura->preco_pago,
            'public_key' => (string) config('services.mercadopago.public_key'),
            'payer_email' => $user->email,
        ], 201);
    }

    public function criarPix(Assinatura $assinatura): JsonResponse
    {
        $this->autorizar($assinatura);

        // PIX ainda válido → devolve o mesmo, não gera um segundo payment.
        if ($assinatura->payment_method === 'pix' && $assinatura->gateway_id
            && $assinatura->pix_expires_at && $assinatura->pix_expires_at->isFuture()) {
            return response()->json($this->respostaPix($assinatura));
        }

        try {
            $resultado = $this->mp()->criarPagamentoPix($assinatura);
        } catch (\Throwable $e) {
            LogPagamento::error($assinatura, 'pix.exception', $e->getMessage());

            return response()->json(['message' => 'Não foi possível gerar o PIX. Tente novamente.'], 502);
        }

        $assinatura->update([
            'payment_method' => 'pix',
            'gateway_id' => $resultado['payment_id'],
            'gateway_status' => $resultado['status'],
            'pix_qr_code' => $resultado['qr_code'],
            'pix_qr_code_base64' => $resultado['qr_code_base64'],
            'pix_expires_at' => $resultado['expires_at'] ? \Carbon\Carbon::parse($resultado['expires_at']) : null,
            'gateway_metadata' => $resultado['raw'],
        ]);

        return response()->json($this->respostaPix($assinatura));
    }

    public function pagarCartao(Request $request, Assinatura $assinatura): JsonResponse
    {
        $this->autorizar($assinatura);

        $dados = $request->validate([
            'token' => ['required', 'string'],
            'installments' => ['required', 'integer', 'min:1', 'max:12'],
            'payment_method_id' => ['nullable', 'string', 'max:60'],
            'issuer_id' => ['nullable', 'string', 'max:60'],
            'identification' => ['nullable', 'string', 'max:20'],
        ]);

        try {
            $resultado = $this->mp()->criarPagamentoCartao($assinatura, $dados);
        } catch (\Throwable $e) {
            LogPagamento::error($assinatura, 'cartao.exception', $e->getMessage());

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $assinatura->update([
            'payment_method' => 'credit_card',
            'gateway_id' => $resultado['payment_id'],
            'gateway_status' => $resultado['status'],
            'gateway_metadata' => $resultado['raw'],
        ]);

        if ($resultado['status'] === 'approved') {
            $this->ativar($assinatura, $resultado['raw']);
        }

        return response()->json([
            'status' => $resultado['status'],
            'status_detail' => $resultado['status_detail'],
            'aprovado' => $assinatura->fresh()->status === Assinatura::STATUS_ATIVA,
        ]);
    }

    /** Polling do front até o status virar final. */
    public function status(Assinatura $assinatura): JsonResponse
    {
        $this->autorizar($assinatura, permitirAtiva: true);

        if ($assinatura->status === Assinatura::STATUS_ATIVA) {
            return response()->json(['status' => 'approved', 'aprovado' => true]);
        }

        if (! $assinatura->gateway_id) {
            return response()->json(['status' => 'pending', 'aprovado' => false]);
        }

        try {
            $consulta = $this->mp()->consultarPagamento($assinatura->gateway_id, $assinatura);
        } catch (\Throwable) {
            // Falha de rede no polling não é erro do usuário — tenta de novo.
            return response()->json([
                'status' => $assinatura->gateway_status ?? 'pending',
                'aprovado' => false,
                'transient_error' => true,
            ]);
        }

        if ($consulta['status'] !== $assinatura->gateway_status) {
            LogPagamento::info($assinatura, 'status.mudou',
                "MP: {$assinatura->gateway_status} → {$consulta['status']}", null, $consulta['raw']);
            $assinatura->update([
                'gateway_status' => $consulta['status'],
                'gateway_metadata' => $consulta['raw'],
            ]);
        }

        if ($consulta['status'] === 'approved') {
            $this->ativar($assinatura, $consulta['raw']);
        } elseif (in_array($consulta['status'], ['cancelled', 'rejected'], true)) {
            $assinatura->update(['status' => Assinatura::STATUS_CANCELADA, 'cancelado_em' => now()]);
            LogPagamento::warning($assinatura, 'assinatura.cancelada', "MP: {$consulta['status']}");
        }

        return response()->json([
            'status' => $consulta['status'],
            'status_detail' => $consulta['status_detail'],
            'aprovado' => $assinatura->fresh()->status === Assinatura::STATUS_ATIVA,
        ]);
    }

    // ---------------------------------------------------------------
    // Núcleo
    // ---------------------------------------------------------------

    /**
     * Paga → vale. Cancela o plano anterior (ou empilha, se for renovação),
     * calcula a vigência e libera o acesso no user.
     *
     * Idempotente por natureza: polling e notificação do MP costumam chegar
     * quase juntos, e os dois chamam aqui.
     */
    public function ativar(Assinatura $assinatura, array $rawMp): void
    {
        DB::transaction(function () use ($assinatura, $rawMp) {
            $lock = Assinatura::whereKey($assinatura->id)->lockForUpdate()->first();
            if ($lock->status === Assinatura::STATUS_ATIVA) {
                return; // já processado
            }

            $user = User::whereKey($lock->user_id)->lockForUpdate()->first();

            $anterior = Assinatura::where('user_id', $user->id)
                ->where('status', Assinatura::STATUS_ATIVA)
                ->lockForUpdate()
                ->first();

            // Renovação do MESMO plano soma ao que ainda resta; troca começa
            // do zero (o cliente foi avisado disso no modal).
            $inicio = ($lock->tipo === Assinatura::TIPO_RENOVACAO
                && $anterior
                && $anterior->plano_id === $lock->plano_id
                && $anterior->expira_em->isFuture())
                ? $anterior->expira_em->copy()
                : now();

            $expira = $inicio->copy()->addDays($lock->duracao_dias ?: self::DURACAO_DIAS);

            if ($anterior) {
                $anterior->update([
                    'status' => Assinatura::STATUS_CANCELADA,
                    'cancelado_em' => now(),
                ]);
            }

            $lock->update([
                'status' => Assinatura::STATUS_ATIVA,
                'iniciado_em' => $inicio,
                'expira_em' => $expira,
                'pago_em' => now(),
                'gateway_status' => 'approved',
                'gateway_metadata' => $rawMp,
            ]);

            $user->update([
                'plano_id' => $lock->plano_id,
                'plano_expira_em' => $expira,
            ]);

            LogPagamento::info($lock, 'assinatura.ativada',
                "Plano {$lock->plano_nome} válido até {$expira->format('d/m/Y')}");
        });

        $assinatura->refresh();
    }

    private function definirTipo(?Assinatura $atual, Plano $plano): string
    {
        if (! $atual) {
            return Assinatura::TIPO_NOVA;
        }

        return $atual->plano_id === $plano->id
            ? Assinatura::TIPO_RENOVACAO
            : Assinatura::TIPO_TROCA;
    }

    private function respostaPix(Assinatura $assinatura): array
    {
        return [
            'status' => $assinatura->gateway_status,
            'qr_code' => $assinatura->pix_qr_code,
            'qr_code_base64' => $assinatura->pix_qr_code_base64,
            'expires_at' => $assinatura->pix_expires_at?->toIso8601String(),
        ];
    }

    /**
     * A cobrança tem que ser DESTE usuário. 404 e não 403 pra não confirmar
     * que o id existe.
     */
    private function autorizar(Assinatura $assinatura, bool $permitirAtiva = false): void
    {
        abort_unless($assinatura->user_id === auth()->id(), 404);

        if ($permitirAtiva && $assinatura->status === Assinatura::STATUS_ATIVA) {
            return;
        }

        abort_unless($assinatura->status === Assinatura::STATUS_PENDENTE, 422,
            'Esta cobrança não está mais aberta.');
    }

    /** Dígitos verificadores — pega erro de digitação antes de o MP recusar. */
    private function cpfValido(string $cpf): bool
    {
        $cpf = preg_replace('/\D/', '', $cpf);
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        foreach ([9, 10] as $pos) {
            $soma = 0;
            for ($i = 0; $i < $pos; $i++) {
                $soma += (int) $cpf[$i] * ($pos + 1 - $i);
            }
            $digito = ((10 * $soma) % 11) % 10;
            if ((int) $cpf[$pos] !== $digito) {
                return false;
            }
        }

        return true;
    }

    private function formatarBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2, ',', '.') . ' GB';
        }

        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }
}
