<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Comprador;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\Painel;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\Publico;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas públicas
|--------------------------------------------------------------------------
*/

Route::get('/', [PublicController::class, 'home'])->name('home');
Route::post('/leads', [LeadController::class, 'store'])->name('leads.store');

// -------- Vitrines públicas (evento + álbum + checkout) --------
Route::name('publico.')->group(function () {
    // Prefixo /e/{uuid} = evento; /a/{uuid} = álbum
    Route::get('/e/{evento:slug}', [Publico\EventoPublicoController::class, 'show'])->name('evento.show');
    Route::get('/a/{album:slug}', [Publico\AlbumPublicoController::class, 'show'])->name('album.show');
    // Thumbnails de vídeos em álbuns públicos — sem autenticação
    Route::get('/v/{video}/thumb', [Publico\AlbumPublicoController::class, 'servirThumbnail'])->name('video.thumb');
    Route::post('/a/{album:slug}/checkout', [Publico\CheckoutController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('checkout.store');
    Route::get('/pedido/{pedido}', [Publico\CheckoutController::class, 'confirmacao'])->name('checkout.confirmacao');

    // Autenticação passwordless do comprador
    Route::get('/acessar', [Publico\AcessoController::class, 'form'])->name('acesso');
    Route::post('/acessar', [Publico\AcessoController::class, 'solicitar'])
        ->middleware('throttle:10,10')
        ->name('acesso.solicitar');
    Route::get('/acessar/{token}', [Publico\AcessoController::class, 'validar'])->name('acesso.validar');
    Route::post('/sair', [Publico\AcessoController::class, 'logout'])->name('acesso.logout');

    // Área do comprador (auth guard = comprador)
    Route::middleware('auth:comprador')->group(function () {
        Route::get('/minhas-compras', [Comprador\ComprasController::class, 'index'])->name('minhas-compras');
        Route::get('/videos/{video}/baixar', [Comprador\ComprasController::class, 'baixarVideo'])
            ->middleware('throttle:30,1')
            ->name('videos.baixar');
    });
});

/*
|--------------------------------------------------------------------------
| Painel — único, comum a admin e cliente
| Controllers fazem scope automático por role.
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->prefix('painel')->name('painel.')->group(function () {

    Route::get('/', [Painel\DashboardController::class, 'index'])->name('dashboard');

    // Perfil (todo usuário logado)
    Route::get('perfil', [Painel\PerfilController::class, 'edit'])->name('perfil.edit');
    Route::put('perfil/dados', [Painel\PerfilController::class, 'updateDados'])->name('perfil.dados');
    Route::put('perfil/senha', [Painel\PerfilController::class, 'updateSenha'])->name('perfil.senha');
    Route::post('perfil/foto', [Painel\PerfilController::class, 'updateFoto'])->name('perfil.foto.upload');
    Route::delete('perfil/foto', [Painel\PerfilController::class, 'deleteFoto'])->name('perfil.foto.delete');

    // Recursos compartilhados (admin vê tudo, cliente vê o seu)
    Route::get('eventos', [Painel\EventosController::class, 'index'])->name('eventos.index');
    Route::get('eventos/data', [Painel\EventosController::class, 'data'])->name('eventos.data');
    Route::post('eventos', [Painel\EventosController::class, 'store'])->name('eventos.store');
    Route::get('eventos/{evento}', [Painel\EventosController::class, 'show'])->name('eventos.show');
    Route::put('eventos/{evento}', [Painel\EventosController::class, 'update'])->name('eventos.update');
    Route::delete('eventos/{evento}', [Painel\EventosController::class, 'destroy'])->name('eventos.destroy');
    Route::post('eventos/{evento}/logo', [Painel\EventosController::class, 'uploadLogo'])->name('eventos.logo.upload');
    Route::delete('eventos/{evento}/logo', [Painel\EventosController::class, 'deleteLogo'])->name('eventos.logo.delete');
    Route::get('eventos/{evento}/logo', [Painel\EventosController::class, 'serveLogo'])->name('eventos.logo.serve');

    Route::get('albuns', [Painel\AlbunsController::class, 'index'])->name('albuns.index');
    Route::get('albuns/data', [Painel\AlbunsController::class, 'data'])->name('albuns.data');
    Route::post('albuns', [Painel\AlbunsController::class, 'store'])->name('albuns.store');
    Route::get('albuns/{album}', [Painel\AlbunsController::class, 'show'])->name('albuns.show');
    Route::put('albuns/{album}', [Painel\AlbunsController::class, 'update'])->name('albuns.update');
    Route::delete('albuns/{album}', [Painel\AlbunsController::class, 'destroy'])->name('albuns.destroy');
    Route::get('albuns/{album}/enviar', [Painel\AlbunsController::class, 'uploadPage'])->name('albuns.enviar');
    Route::post('albuns/{album}/videos', [Painel\AlbunsController::class, 'uploadVideo'])->name('albuns.videos.upload');

    // Upload multipart (S3 nativo) + fallback local
    Route::post('albuns/{album}/videos/init', [Painel\VideosUploadController::class, 'init'])->name('albuns.videos.init');
    Route::post('videos/{video}/sign', [Painel\VideosUploadController::class, 'signParts'])->name('videos.sign');
    Route::post('videos/{video}/parts', [Painel\VideosUploadController::class, 'registerPart'])->name('videos.parts');
    Route::post('videos/{video}/local-part', [Painel\VideosUploadController::class, 'uploadLocalPart'])->name('videos.local-part');
    Route::post('videos/{video}/complete', [Painel\VideosUploadController::class, 'complete'])->name('videos.complete');
    Route::post('videos/{video}/abort', [Painel\VideosUploadController::class, 'abort'])->name('videos.abort');
    Route::post('videos/{video}/thumbnail', [Painel\VideosUploadController::class, 'uploadThumbnail'])->name('videos.thumbnail');
    Route::get('videos/{video}/thumbnail', [Painel\VideosUploadController::class, 'serveThumbnail'])->name('videos.thumbnail.serve');
    Route::delete('videos/{video}', [Painel\VideosUploadController::class, 'destroy'])
        ->middleware('throttle:60,1')
        ->name('videos.destroy');
    Route::post('videos/bulk-delete', [Painel\VideosUploadController::class, 'bulkDelete'])
        ->middleware('throttle:20,1')
        ->name('videos.bulk-delete');
    Route::get('albuns/{album}/videos/ids', [Painel\VideosUploadController::class, 'listAllVideoIds'])
        ->name('albuns.videos.ids');
    Route::get('albuns/{album}/videos', [Painel\VideosUploadController::class, 'listByAlbum'])->name('albuns.videos.list');

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

        Route::get('configuracoes', [Admin\ConfiguracoesController::class, 'index'])->name('configuracoes.index');
        Route::put('configuracoes', [Admin\ConfiguracoesController::class, 'update'])->name('configuracoes.update');

        Route::get('planos', [Admin\PlanosController::class, 'index'])->name('planos.index');
        Route::get('planos/data', [Admin\PlanosController::class, 'data'])->name('planos.data');
        Route::post('planos', [Admin\PlanosController::class, 'store'])->name('planos.store');
        Route::get('planos/{plano}', [Admin\PlanosController::class, 'show'])->name('planos.show');
        Route::put('planos/{plano}', [Admin\PlanosController::class, 'update'])->name('planos.update');
        Route::delete('planos/{plano}', [Admin\PlanosController::class, 'destroy'])->name('planos.destroy');
    });
});

require __DIR__.'/auth.php';
