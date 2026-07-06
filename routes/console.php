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

Schedule::command('panda:limpar-uploads-abandonados --horas=24')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('panda:limpar-orfaos')
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->runInBackground();

// Scan reverso é mais pesado — só uma vez por semana (domingo às 04:00)
Schedule::command('panda:scan-armazenamento --disk=local --apagar')
    ->weeklyOn(0, '04:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('panda:scan-armazenamento --disk=public --apagar')
    ->weeklyOn(0, '04:15')
    ->withoutOverlapping()
    ->runInBackground();
