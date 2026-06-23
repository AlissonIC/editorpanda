<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Cliente;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\PainelController;
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
| Painel — redirecionamento por perfil
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {
    Route::get('/painel', [PainelController::class, 'redirect'])->name('painel.redirect');
});

/*
|--------------------------------------------------------------------------
| Painel Admin
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'role:admin'])
    ->prefix('painel/admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', [Admin\DashboardController::class, 'index'])->name('dashboard');

        // Usuários
        Route::get('usuarios', [Admin\UsuariosController::class, 'index'])->name('usuarios.index');
        Route::get('usuarios/data', [Admin\UsuariosController::class, 'data'])->name('usuarios.data');
        Route::post('usuarios', [Admin\UsuariosController::class, 'store'])->name('usuarios.store');
        Route::get('usuarios/{user}', [Admin\UsuariosController::class, 'show'])->name('usuarios.show');
        Route::put('usuarios/{user}', [Admin\UsuariosController::class, 'update'])->name('usuarios.update');
        Route::delete('usuarios/{user}', [Admin\UsuariosController::class, 'destroy'])->name('usuarios.destroy');

        // Financeiro
        Route::get('financeiro', [Admin\FinanceiroController::class, 'index'])->name('financeiro.index');
        Route::get('financeiro/vendas/data', [Admin\FinanceiroController::class, 'vendasData'])->name('financeiro.vendas.data');
        Route::get('financeiro/saques/data', [Admin\FinanceiroController::class, 'saquesData'])->name('financeiro.saques.data');
        Route::post('financeiro/saques/{saque}/aprovar', [Admin\FinanceiroController::class, 'aprovarSaque'])->name('financeiro.saques.aprovar');
        Route::post('financeiro/saques/{saque}/recusar', [Admin\FinanceiroController::class, 'recusarSaque'])->name('financeiro.saques.recusar');

        // Processamento
        Route::get('processamento', [Admin\ProcessamentoController::class, 'index'])->name('processamento.index');
        Route::get('processamento/data', [Admin\ProcessamentoController::class, 'data'])->name('processamento.data');
        Route::post('processamento/{video}/reprocessar', [Admin\ProcessamentoController::class, 'reprocessar'])->name('processamento.reprocessar');

        // Eventos
        Route::get('eventos', [Admin\EventosController::class, 'index'])->name('eventos.index');
        Route::get('eventos/data', [Admin\EventosController::class, 'data'])->name('eventos.data');
        Route::delete('eventos/{evento}', [Admin\EventosController::class, 'destroy'])->name('eventos.destroy');

        // Álbuns
        Route::get('albuns', [Admin\AlbunsController::class, 'index'])->name('albuns.index');
        Route::get('albuns/data', [Admin\AlbunsController::class, 'data'])->name('albuns.data');
        Route::delete('albuns/{album}', [Admin\AlbunsController::class, 'destroy'])->name('albuns.destroy');

        // Pedidos
        Route::get('pedidos', [Admin\PedidosController::class, 'index'])->name('pedidos.index');
        Route::get('pedidos/data', [Admin\PedidosController::class, 'data'])->name('pedidos.data');

        // Leads
        Route::get('leads', [Admin\LeadsController::class, 'index'])->name('leads.index');
        Route::get('leads/data', [Admin\LeadsController::class, 'data'])->name('leads.data');
        Route::delete('leads/{lead}', [Admin\LeadsController::class, 'destroy'])->name('leads.destroy');
    });

/*
|--------------------------------------------------------------------------
| Painel Cliente
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'role:cliente'])
    ->prefix('painel/cliente')
    ->name('cliente.')
    ->group(function () {
        Route::get('/', [Cliente\DashboardController::class, 'index'])->name('dashboard');

        // Eventos
        Route::get('eventos', [Cliente\EventosController::class, 'index'])->name('eventos.index');
        Route::get('eventos/data', [Cliente\EventosController::class, 'data'])->name('eventos.data');
        Route::post('eventos', [Cliente\EventosController::class, 'store'])->name('eventos.store');
        Route::get('eventos/{evento}', [Cliente\EventosController::class, 'show'])->name('eventos.show');
        Route::put('eventos/{evento}', [Cliente\EventosController::class, 'update'])->name('eventos.update');
        Route::delete('eventos/{evento}', [Cliente\EventosController::class, 'destroy'])->name('eventos.destroy');

        // Álbuns
        Route::get('albuns', [Cliente\AlbunsController::class, 'index'])->name('albuns.index');
        Route::get('albuns/data', [Cliente\AlbunsController::class, 'data'])->name('albuns.data');
        Route::post('albuns', [Cliente\AlbunsController::class, 'store'])->name('albuns.store');
        Route::get('albuns/{album}', [Cliente\AlbunsController::class, 'show'])->name('albuns.show');
        Route::put('albuns/{album}', [Cliente\AlbunsController::class, 'update'])->name('albuns.update');
        Route::delete('albuns/{album}', [Cliente\AlbunsController::class, 'destroy'])->name('albuns.destroy');
        Route::post('albuns/{album}/videos', [Cliente\AlbunsController::class, 'uploadVideo'])->name('albuns.videos.upload');

        // Pedidos
        Route::get('pedidos', [Cliente\PedidosController::class, 'index'])->name('pedidos.index');
        Route::get('pedidos/data', [Cliente\PedidosController::class, 'data'])->name('pedidos.data');

        // Relatório
        Route::get('relatorio', [Cliente\RelatorioController::class, 'index'])->name('relatorio.index');
        Route::get('relatorio/vendas-por-mes', [Cliente\RelatorioController::class, 'vendasPorMes'])->name('relatorio.vendas.mes');
        Route::get('relatorio/top-albuns', [Cliente\RelatorioController::class, 'topAlbuns'])->name('relatorio.top.albuns');
    });

require __DIR__.'/auth.php';
