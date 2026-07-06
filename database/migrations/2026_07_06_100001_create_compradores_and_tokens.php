<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compradores', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('nome')->nullable();
            $table->string('whatsapp', 20)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('acesso_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('token_hash', 64)->unique(); // sha256 do token público
            $table->timestamp('expira_em');
            $table->timestamp('usado_em')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['email', 'usado_em']);
            $table->index('expira_em');
        });

        Schema::table('pedidos', function (Blueprint $table) {
            $table->foreignId('comprador_id')
                ->nullable()
                ->after('user_id')
                ->constrained('compradores')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('comprador_id');
        });
        Schema::dropIfExists('acesso_tokens');
        Schema::dropIfExists('compradores');
    }
};
