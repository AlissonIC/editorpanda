<?php

namespace App\Console\Commands;

use App\Models\Assinatura;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Marca assinaturas com expira_em < now() e status='ativa' como 'expirada'.
 * Para cada usuário que perdeu a única ativa, limpa users.plano_id.
 *
 * php artisan panda:expirar-assinaturas
 * php artisan panda:expirar-assinaturas --dry-run
 */
class ExpirarAssinaturasCommand extends Command
{
    protected $signature = 'panda:expirar-assinaturas
                            {--dry-run : só mostra o que seria expirado}';

    protected $description = 'Marca assinaturas vencidas como expiradas e limpa users.plano_id';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $vencidas = Assinatura::where('status', Assinatura::STATUS_ATIVA)
            ->where('expira_em', '<', now())
            ->with('user:id')
            ->get();

        $this->info("Encontradas: {$vencidas->count()} assinatura(s) vencida(s)");

        if ($dryRun) {
            foreach ($vencidas as $a) {
                $this->line("[dry-run] user #{$a->user_id} plano '{$a->plano_nome}' venceu em {$a->expira_em->format('d/m/Y')}");
            }
            return self::SUCCESS;
        }

        DB::transaction(function () use ($vencidas) {
            foreach ($vencidas as $a) {
                $a->update(['status' => Assinatura::STATUS_EXPIRADA]);
                $this->line("✓ #{$a->id} · user #{$a->user_id} · {$a->plano_nome}");
            }

            // Para cada usuário afetado, verifica se sobrou alguma ativa; senão limpa plano_id
            $userIds = $vencidas->pluck('user_id')->unique()->all();
            User::whereIn('id', $userIds)->each(function (User $u) {
                if (! $u->assinaturaAtiva()) {
                    $u->update(['plano_id' => null, 'plano_expira_em' => null]);
                }
            });
        });

        return self::SUCCESS;
    }
}
