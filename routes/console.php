<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Schedule — Cleanup de arquivos
|--------------------------------------------------------------------------
| Roda diariamente às 03:00 (horário de baixa carga):
|   - Cancela uploads abandonados > 24h
|   - Retenta órfãos registrados em arquivos_orfaos
|   - Scan reverso do storage (semanal — mais pesado)
|
| Ative com: php artisan schedule:work  (local)
| Em produção: cron rodando `php artisan schedule:run` a cada minuto.
*/

// Expira assinaturas vencidas — diário às 00:05 pra pegar viragens de meia-noite
Schedule::command('panda:expirar-assinaturas')
    ->dailyAt('00:05')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('panda:limpar-uploads-abandonados --horas=24')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('panda:limpar-orfaos')
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->runInBackground();

// Retenção de logs — 7 dias
// - failed_jobs: prune built-in do Laravel (--hours=168 = 7d)
// - arquivos_orfaos resolvidos há > 7 dias somem
// - laravel-YYYY-MM-DD.log: rotação automática já é feita pelo channel `daily`
//   com LOG_DAILY_DAYS=7 (config/logging.php)
Schedule::command('queue:prune-failed --hours=168')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::call(function () {
    \Illuminate\Support\Facades\DB::table('arquivos_orfaos')
        ->where('updated_at', '<', now()->subDays(7))
        ->whereNull('ultimo_erro')
        ->delete();
})->dailyAt('03:35')->name('panda:prune-orfaos-resolvidos');

// Logs do pipeline de processamento: retenção 7 dias
Schedule::call(function () {
    \App\Models\LogProcessamento::where('created_at', '<', now()->subDays(7))->delete();
})->dailyAt('03:40')->name('panda:prune-logs-processamento');

// Scan reverso é mais pesado — só uma vez por semana (domingo às 04:00)
Schedule::command('panda:scan-armazenamento --disk=local --apagar')
    ->weeklyOn(0, '04:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('panda:scan-armazenamento --disk=public --apagar')
    ->weeklyOn(0, '04:15')
    ->withoutOverlapping()
    ->runInBackground();
