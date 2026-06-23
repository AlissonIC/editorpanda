<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\Painel;
use App\Http\Controllers\PublicController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas públicas
|--------------------------------------------------------------------------
*/

Route::get('/', [PublicController::class, 'home'])->name('home');
Route::post('/leads', [LeadController::class, 'store'])->name('leads.store');

/*
|--------------------------------------------------------------------------
| Painel — único, comum a admin e cliente
| Controllers fazem scope automático por role.
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->prefix('painel')->name('painel.')->group(function () {

    Route::get('/', [Painel\DashboardController::class, 'index'])->name('dashboard');

    // Recursos compartilhados (admin vê tudo, cliente vê o seu)
    Route::get('eventos', [Painel\EventosController::class, 'index'])->name('eventos.index');
    Route::get('eventos/data', [Painel\EventosController::class, 'data'])->name('eventos.data');
    Route::post('eventos', [Painel\EventosController::class, 'store'])->name('eventos.store');
    Route::get('eventos/{evento}', [Painel\EventosController::class, 'show'])->name('eventos.show');
    Route::put('eventos/{evento}', [Painel\EventosController::class, 'update'])->name('eventos.update');
    Route::delete('eventos/{evento}', [Painel\EventosController::class, 'destroy'])->name('eventos.destroy');

    Route::get('albuns', [Painel\AlbunsController::class, 'index'])->name('albuns.index');
    Route::get('albuns/data', [Painel\AlbunsController::class, 'data'])->name('albuns.data');
    Route::post('albuns', [Painel\AlbunsController::class, 'store'])->name('albuns.store');
    Route::get('albuns/{album}', [Painel\AlbunsController::class, 'show'])->name('albuns.show');
    Route::put('albuns/{album}', [Painel\AlbunsController::class, 'update'])->name('albuns.update');
    Route::delete('albuns/{album}', [Painel\AlbunsController::class, 'destroy'])->name('albuns.destroy');
    Route::post('albuns/{album}/videos', [Painel\AlbunsController::class, 'uploadVideo'])->name('albuns.videos.upload');

    Route::get('pedidos', [Painel\PedidosController::class, 'index'])->name('pedidos.index');
    Route::get('pedidos/data', [Painel\PedidosController::class, 'data'])->name('pedidos.data');

    // Relatório — só cliente (admin tem o dashboard)
    Route::middleware('role:cliente')->group(function () {
        Route::get('relatorio', [Painel\RelatorioController::class, 'index'])->name('relatorio.index');
        Route::get('relatorio/vendas-por-mes', [Painel\RelatorioController::class, 'vendasPorMes'])->name('relatorio.vendas.mes');
        Route::get('relatorio/top-albuns', [Painel\RelatorioController::class, 'topAlbuns'])->name('relatorio.top.albuns');
    });

    // Admin-only
    Route::middleware('role:admin')->group(function () {

        Route::get('usuarios', [Admin\UsuariosController::class, 'index'])->name('usuarios.index');
        Route::get('usuarios/data', [Admin\UsuariosController::class, 'data'])->name('usuarios.data');
        Route::post('usuarios', [Admin\UsuariosController::class, 'store'])->name('usuarios.store');
        Route::get('usuarios/{user}', [Admin\UsuariosController::class, 'show'])->name('usuarios.show');
        Route::put('usuarios/{user}', [Admin\UsuariosController::class, 'update'])->name('usuarios.update');
        Route::delete('usuarios/{user}', [Admin\UsuariosController::class, 'destroy'])->name('usuarios.destroy');

        Route::get('financeiro', [Admin\FinanceiroController::class, 'index'])->name('financeiro.index');
        Route::get('financeiro/vendas/data', [Admin\FinanceiroController::class, 'vendasData'])->name('financeiro.vendas.data');
        Route::get('financeiro/saques/data', [Admin\FinanceiroController::class, 'saquesData'])->name('financeiro.saques.data');
        Route::post('financeiro/saques/{saque}/aprovar', [Admin\FinanceiroController::class, 'aprovarSaque'])->name('financeiro.saques.aprovar');
        Route::post('financeiro/saques/{saque}/recusar', [Admin\FinanceiroController::class, 'recusarSaque'])->name('financeiro.saques.recusar');

        Route::get('processamento', [Admin\ProcessamentoController::class, 'index'])->name('processamento.index');
        Route::get('processamento/data', [Admin\ProcessamentoController::class, 'data'])->name('processamento.data');
        Route::post('processamento/{video}/reprocessar', [Admin\ProcessamentoController::class, 'reprocessar'])->name('processamento.reprocessar');

        Route::get('leads', [Admin\LeadsController::class, 'index'])->name('leads.index');
        Route::get('leads/data', [Admin\LeadsController::class, 'data'])->name('leads.data');
        Route::delete('leads/{lead}', [Admin\LeadsController::class, 'destroy'])->name('leads.destroy');
    });
});

require __DIR__.'/auth.php';
