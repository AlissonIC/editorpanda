<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('leads');
    }

    public function down(): void
    {
        // Recria estrutura idêntica ao create_leads_table (2026_06_23) caso rollback seja necessário
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('email')->nullable();
            $table->string('whatsapp', 20)->nullable();
            $table->string('origem')->default('landing');
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }
};
