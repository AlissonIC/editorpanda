<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assinatura passa a ser cobrada de verdade (Mercado Pago), em vez de nascer
 * ativa de graça.
 *
 * Espelha as colunas de gateway que `pedidos` já usa — é o mesmo fluxo de
 * checkout transparente (PIX + cartão + polling), só que a "mercadoria" é o
 * plano. Manter os nomes iguais deixa o MercadoPagoService atender os dois.
 *
 * `tipo` guarda o que a cobrança significou pro cliente (primeira assinatura,
 * renovação ou troca de plano). É o que o modal precisa dizer com clareza, e
 * o histórico fica legível depois.
 *
 * TUDO AQUI É IDEMPOTENTE, de propósito. São várias alterações de schema e o
 * MySQL não faz DDL dentro de transação: se uma falha no meio, as anteriores
 * já foram gravadas mas a migração não é registrada. Rodar de novo então
 * morria em "Duplicate column name" e o banco ficava travado num estado
 * parcial, sem caminho pra frente nem pra trás. Com as guardas, basta rodar de
 * novo que ela retoma de onde parou.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 'pendente' é o estado de quem gerou o PIX e ainda não pagou. Fica
        // FORA de scopeAtivas, então não dá acesso a nada até aprovar.
        // MODIFY pro mesmo valor é inofensivo, então não precisa de guarda.
        DB::statement("ALTER TABLE assinaturas MODIFY status ENUM('pendente','ativa','expirada','cancelada') NOT NULL DEFAULT 'pendente'");

        $this->adicionar('assinaturas', [
            'tipo' => fn (Blueprint $t) => $t->enum('tipo', ['nova', 'renovacao', 'troca'])->default('nova')->after('plano_nome'),
            'gateway_status' => fn (Blueprint $t) => $t->string('gateway_status', 32)->nullable()->after('gateway_id'),
            'payment_method' => fn (Blueprint $t) => $t->string('payment_method', 32)->nullable()->after('gateway_status'),
            'pix_qr_code' => fn (Blueprint $t) => $t->text('pix_qr_code')->nullable()->after('payment_method'),
            'pix_qr_code_base64' => fn (Blueprint $t) => $t->longText('pix_qr_code_base64')->nullable()->after('pix_qr_code'),
            'pix_expires_at' => fn (Blueprint $t) => $t->timestamp('pix_expires_at')->nullable()->after('pix_qr_code_base64'),
            // longText e não json: o gateway_metadata de pedidos também é, e
            // resposta de gateway não tem schema estável pra validar.
            'gateway_metadata' => fn (Blueprint $t) => $t->longText('gateway_metadata')->nullable()->after('pix_expires_at'),
            'pago_em' => fn (Blueprint $t) => $t->timestamp('pago_em')->nullable()->after('gateway_metadata'),
        ]);

        // Assinaturas que já existiam foram criadas como ativas e pagas fora do
        // gateway — marca como pagas pra não caírem no fluxo de cobrança.
        // `whereNull` já torna isso repetível.
        DB::table('assinaturas')
            ->whereNull('pago_em')
            ->whereNotNull('iniciado_em')
            ->update([
                'pago_em' => DB::raw('iniciado_em'),
                'gateway_status' => 'approved',
            ]);

        // Único dado de pagador que o MP exige além do e-mail: o CPF é
        // obrigatório no PIX e é o que o Bricks pede no cartão. Guardamos no
        // cadastro pra não perguntar de novo a cada cobrança.
        //
        // Endereço NÃO entra aqui de propósito — o MP aceita pagamento sem ele
        // (o exemplo oficial do SDK manda só `payer.email`), e campo a mais no
        // checkout é conversão a menos.
        $this->adicionar('users', [
            'cpf' => fn (Blueprint $t) => $t->string('cpf', 14)->nullable()->after('email'),
        ]);

        // O log de pagamento passa a cobrir assinatura também.
        $this->adicionar('logs_pagamento', [
            'assinatura_id' => fn (Blueprint $t) => $t->unsignedBigInteger('assinatura_id')->nullable()->after('pedido_id'),
        ]);

        if (! $this->temIndice('logs_pagamento', 'logs_pagamento_assinatura_id_index')) {
            Schema::table('logs_pagamento', fn (Blueprint $t) => $t->index('assinatura_id'));
        }

        // `pedido_id` tem FK pra `pedidos` e era NOT NULL. Precisa aceitar nulo
        // agora que existe log de assinatura, que não tem pedido nenhum.
        // Alterar o tipo não mexe na FK — ela continua valendo pros valores
        // preenchidos, e nulo é sempre aceito por FK.
        DB::statement('ALTER TABLE logs_pagamento MODIFY pedido_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        $this->remover('assinaturas', [
            'tipo', 'gateway_status', 'payment_method', 'pix_qr_code',
            'pix_qr_code_base64', 'pix_expires_at', 'gateway_metadata', 'pago_em',
        ]);
        DB::statement("ALTER TABLE assinaturas MODIFY status ENUM('ativa','expirada','cancelada') NOT NULL DEFAULT 'ativa'");

        $this->remover('users', ['cpf']);

        if ($this->temIndice('logs_pagamento', 'logs_pagamento_assinatura_id_index')) {
            Schema::table('logs_pagamento', fn (Blueprint $t) => $t->dropIndex('logs_pagamento_assinatura_id_index'));
        }
        $this->remover('logs_pagamento', ['assinatura_id']);

        // Só volta a ser NOT NULL se não sobrou log órfão de assinatura.
        if (DB::table('logs_pagamento')->whereNull('pedido_id')->doesntExist()) {
            DB::statement('ALTER TABLE logs_pagamento MODIFY pedido_id BIGINT UNSIGNED NOT NULL');
        }
    }

    /**
     * Cria só as colunas que ainda não existem.
     *
     * @param  array<string, callable(Blueprint): mixed>  $colunas
     */
    private function adicionar(string $tabela, array $colunas): void
    {
        $faltando = array_filter(
            $colunas,
            fn ($_, $nome) => ! Schema::hasColumn($tabela, $nome),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($faltando === []) {
            return;
        }

        Schema::table($tabela, function (Blueprint $table) use ($faltando) {
            foreach ($faltando as $definir) {
                $definir($table);
            }
        });
    }

    /** @param  list<string>  $colunas */
    private function remover(string $tabela, array $colunas): void
    {
        $existentes = array_values(array_filter(
            $colunas,
            fn ($c) => Schema::hasColumn($tabela, $c),
        ));

        if ($existentes !== []) {
            Schema::table($tabela, fn (Blueprint $t) => $t->dropColumn($existentes));
        }
    }

    private function temIndice(string $tabela, string $indice): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$tabela, $indice],
        ) !== [];
    }
};
