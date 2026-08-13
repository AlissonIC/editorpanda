<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `saques.dados_bancarios` era JSON, mas o model grava com cast
 * `encrypted:array` — ou seja, uma STRING cifrada, que não é JSON válido.
 *
 * Resultado: todo INSERT de saque estourava no banco.
 *   - MySQL 8:  "Invalid JSON text ... at position 0"
 *   - MariaDB:  CONSTRAINT `saques.dados_bancarios` failed (o json_valid)
 *
 * Nenhum saque chegou a ser gravado (tabela vazia em produção), então não há
 * dado pra converter — é só trocar o tipo.
 *
 * TEXT e não JSON de propósito: conteúdo cifrado é opaco pro banco. Não dá
 * pra indexar, filtrar nem usar JSON_EXTRACT nele de qualquer jeito, então
 * declarar JSON só criaria a validação que quebra a escrita.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('saques')) {
            return;
        }

        // MariaDB implementa JSON como LONGTEXT + CHECK json_valid(). Trocar o
        // tipo não remove o CHECK — ele continua barrando o texto cifrado.
        $this->removerCheckJsonMariaDb();

        DB::statement('ALTER TABLE saques MODIFY dados_bancarios TEXT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('saques')) {
            return;
        }

        // Volta pro tipo antigo. Só funciona com a tabela vazia — se já houver
        // saque gravado, o conteúdo cifrado não passa na validação de JSON.
        DB::statement('ALTER TABLE saques MODIFY dados_bancarios JSON NULL');
    }

    private function removerCheckJsonMariaDb(): void
    {
        if (! str_contains(strtolower((string) DB::selectOne('SELECT VERSION() AS v')->v), 'mariadb')) {
            return;
        }

        // O CHECK nasce com o mesmo nome da coluna.
        try {
            DB::statement('ALTER TABLE saques DROP CONSTRAINT dados_bancarios');
        } catch (\Throwable) {
            // Versão/branch sem o CHECK automático — nada a remover.
        }
    }
};
