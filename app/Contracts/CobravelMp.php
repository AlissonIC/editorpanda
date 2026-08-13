<?php

namespace App\Contracts;

/**
 * Algo que pode virar uma cobrança no Mercado Pago.
 *
 * Existem duas coisas cobráveis no sistema e elas não têm nada em comum além
 * disso: `Pedido` (comprador levando vídeos) e `Assinatura` (dono do evento
 * pagando o plano). Em vez de duplicar o MercadoPagoService, ele passou a
 * falar com esta interface.
 *
 * O que cada implementação precisa entregar é só o que o MP pergunta: quanto,
 * do quê, de quem, e como amarrar a resposta de volta.
 */
interface CobravelMp
{
    /** Valor a cobrar, em reais. */
    public function mpValor(): float;

    /** Texto que aparece na fatura/extrato do pagador. */
    public function mpDescricao(): string;

    /**
     * Volta na notificação do MP como `external_reference` — é por ele que
     * reencontramos o registro.
     *
     * Pedido usa o id puro ("42") por compatibilidade: payments criados antes
     * desta interface existir já estão no MP com esse formato e ainda podem
     * gerar notificação. Assinatura usa prefixo ("assinatura-42").
     */
    public function mpReferenciaExterna(): string;

    /**
     * Dados do pagador aceitos pelo MP.
     *
     * @return array{email:string, first_name?:string, last_name?:string, identification?:array{type:string,number:string}, address?:array<string,string>}
     */
    public function mpPagador(): array;

    /**
     * Chave de idempotência — o MP dedupe requests iguais por 24h. Precisa ser
     * estável pro mesmo registro+método (retry de rede não pode cobrar 2x) e
     * diferente entre registros.
     */
    public function mpChaveIdempotencia(string $metodo): string;
}
