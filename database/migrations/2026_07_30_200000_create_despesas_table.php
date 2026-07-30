<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Despesas operacionais da plataforma (só admin cadastra/vê).
     *
     * Recorrentes: campo `frequencia` = mensal|anual|semanal. `data_gasto`
     * representa a primeira ocorrência OU a última data efetiva — o admin
     * decide como usa. Não geramos ocorrências futuras automaticamente
     * (seria overkill pra MVP; cron pode gerar depois se necessário).
     *
     * Não-recorrentes: `frequencia` fica NULL. `data_gasto` = quando aconteceu.
     */
    public function up(): void
    {
        Schema::create('despesas', function (Blueprint $table) {
            $table->id();
            $table->string('descricao', 255);
            $table->decimal('valor', 12, 2);
            $table->string('categoria', 60)->nullable()->index(); // livre: 'servidor', 'marketing', 'mp taxa', etc.
            $table->date('data_gasto')->index();
            $table->boolean('recorrente')->default(false);
            $table->string('frequencia', 20)->nullable(); // 'mensal' | 'anual' | 'semanal'
            $table->text('observacao')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['recorrente', 'data_gasto']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('despesas');
    }
};
