<?php

namespace App\Support;

/**
 * Tradução dos códigos do Mercado Pago para português.
 *
 * O gateway devolve coisas como `cc_rejected_insufficient_amount` e
 * `pix`/`account_money`. Isso é ótimo pra máquina e inútil pra quem está
 * olhando a tela do pedido tentando entender por que a venda não entrou.
 *
 * Códigos desconhecidos não somem: caem no fallback e aparecem crus, porque
 * na hora de investigar é melhor ver um código estranho do que um "—".
 */
class MercadoPagoStatus
{
    /** Motivos de recusa/pendência mais comuns no Brasil. */
    private const DETALHES = [
        // Aprovado
        'accredited' => 'Pagamento aprovado e creditado.',

        // Recusas de cartão
        'cc_rejected_bad_filled_card_number' => 'Número do cartão digitado errado.',
        'cc_rejected_bad_filled_date' => 'Data de validade digitada errada.',
        'cc_rejected_bad_filled_other' => 'Algum dado do cartão foi digitado errado.',
        'cc_rejected_bad_filled_security_code' => 'Código de segurança (CVV) errado.',
        'cc_rejected_blacklist' => 'Cartão recusado por segurança do Mercado Pago.',
        'cc_rejected_call_for_authorize' => 'O banco exige que o titular autorize esta compra.',
        'cc_rejected_card_disabled' => 'Cartão desativado — o titular precisa ativar no banco.',
        'cc_rejected_card_error' => 'O banco não conseguiu processar o cartão.',
        'cc_rejected_duplicated_payment' => 'Pagamento igual já feito há pouco — o banco barrou a repetição.',
        'cc_rejected_high_risk' => 'Recusado pela análise antifraude.',
        'cc_rejected_insufficient_amount' => 'Saldo ou limite insuficiente.',
        'cc_rejected_invalid_installments' => 'O cartão não aceita esse número de parcelas.',
        'cc_rejected_max_attempts' => 'Muitas tentativas seguidas — o cartão foi bloqueado temporariamente.',
        'cc_rejected_other_reason' => 'O banco recusou sem informar o motivo.',
        'cc_rejected_card_type_not_allowed' => 'Tipo de cartão não aceito para esta cobrança.',
        'cc_amount_rate_limit_exceeded' => 'Valor acima do limite permitido para este meio de pagamento.',

        // Pendências
        'pending_contingency' => 'Em processamento pelo Mercado Pago — aguarde alguns minutos.',
        'pending_review_manual' => 'Em análise manual do Mercado Pago.',
        'pending_waiting_transfer' => 'Aguardando o pagamento do PIX.',
        'pending_waiting_payment' => 'Aguardando o pagamento.',
        'pending_challenge' => 'Aguardando a confirmação do titular no banco (3-D Secure).',

        // Encerramentos
        'expired' => 'O prazo de pagamento expirou.',
        'by_payer' => 'Cancelado pelo comprador.',
        'by_collector' => 'Cancelado pelo vendedor.',
        'refunded' => 'Valor estornado ao comprador.',
        'partially_refunded' => 'Valor parcialmente estornado.',
        'charged_back' => 'Chargeback aberto pelo comprador.',
    ];

    private const METODOS = [
        'pix' => 'PIX',
        'credit_card' => 'Cartão de crédito',
        'debit_card' => 'Cartão de débito',
        'ticket' => 'Boleto',
        'bolbradesco' => 'Boleto',
        'account_money' => 'Saldo Mercado Pago',
    ];

    private const STATUS = [
        'approved' => 'Aprovado',
        'pending' => 'Pendente',
        'in_process' => 'Em análise',
        'in_mediation' => 'Em disputa',
        'authorized' => 'Autorizado (ainda não capturado)',
        'rejected' => 'Recusado',
        'cancelled' => 'Cancelado',
        'refunded' => 'Estornado',
        'charged_back' => 'Chargeback',
    ];

    public static function detalhe(?string $codigo): ?string
    {
        if (! $codigo) {
            return null;
        }

        return self::DETALHES[$codigo] ?? "Código do gateway: {$codigo}";
    }

    /** Rótulo curto do meio de pagamento, pra listagem. */
    public static function metodo(?string $codigo): string
    {
        if (! $codigo) {
            return '—';
        }

        return self::METODOS[$codigo] ?? ucfirst(str_replace('_', ' ', $codigo));
    }

    public static function status(?string $codigo): ?string
    {
        if (! $codigo) {
            return null;
        }

        return self::STATUS[$codigo] ?? $codigo;
    }

    /**
     * Erros que o MP devolve dentro de `cause` quando recusa a própria
     * requisição (payload inválido, conta sem permissão, etc.). São diferentes
     * da recusa de pagamento e costumam ser o que trava uma integração nova.
     *
     * @return list<string>
     */
    public static function causas(?array $gatewayMetadata): array
    {
        $causas = $gatewayMetadata['cause'] ?? [];
        if (! is_array($causas)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($c) {
            if (is_string($c)) {
                return $c;
            }
            $codigo = $c['code'] ?? null;
            $desc = $c['description'] ?? null;

            return trim(($codigo ? "[{$codigo}] " : '') . (string) $desc) ?: null;
        }, $causas)));
    }
}
