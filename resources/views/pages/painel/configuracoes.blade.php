@extends('theme::layouts.painel')

@section('titulo', 'Configurações')

@section('conteudo')
<x-theme::page-header
    titulo="Configurações"
    subtitulo="Ajustes globais da plataforma"
/>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="panda-card">
            <h5 class="fw-bold mb-1">Armazenamento de vídeos</h5>
            <p class="text-muted small mb-4">
                Escolha onde os vídeos enviados a partir de agora serão armazenados.
                Registros antigos continuam apontando para o disco em que foram salvos originalmente.
            </p>

            <form id="form-config" novalidate>
                @csrf
                <div class="storage-options">
                    <label class="storage-option {{ $storageDisk === 'local' ? 'is-active' : '' }}">
                        <input type="radio" name="storage_disk" value="local" @checked($storageDisk === 'local')>
                        <div class="storage-option-icon bg-success-subtle text-success-emphasis">
                            <i class="bi bi-hdd"></i>
                        </div>
                        <div class="storage-option-body">
                            <div class="fw-semibold">Armazenamento local</div>
                            <small class="text-muted">
                                Salva no servidor da aplicação (<code>storage/app/private/videos</code>). Bom para desenvolvimento e volumes pequenos.
                            </small>
                        </div>
                    </label>

                    <label class="storage-option {{ $storageDisk === 's3' ? 'is-active' : '' }}">
                        <input type="radio" name="storage_disk" value="s3" @checked($storageDisk === 's3')>
                        <div class="storage-option-icon bg-info-subtle text-info-emphasis">
                            <i class="bi bi-cloud"></i>
                        </div>
                        <div class="storage-option-body">
                            <div class="fw-semibold d-flex align-items-center gap-2">
                                Amazon S3
                                @if(! $s3Configurado)
                                    <span class="badge bg-warning text-dark">Credenciais ausentes</span>
                                @endif
                            </div>
                            <small class="text-muted">
                                Salva em um bucket S3 (ou compatível). Requer <code>AWS_ACCESS_KEY_ID</code>, <code>AWS_SECRET_ACCESS_KEY</code>, <code>AWS_BUCKET</code> e <code>AWS_DEFAULT_REGION</code> no arquivo <code>.env</code>.
                            </small>
                        </div>
                    </label>
                </div>

                @if(! $s3Configurado)
                    <div class="alert alert-warning small mt-3 mb-0">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        O disco S3 pode ser selecionado, mas os envios só funcionarão após preencher as credenciais AWS no <code>.env</code>.
                    </div>
                @endif

                <div class="d-flex justify-content-end mt-4">
                    <button type="submit" class="btn btn-dark-panda">
                        <i class="bi bi-check-lg me-1"></i> Salvar configurações
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="panda-card">
            <h6 class="fw-bold mb-2">Como funciona</h6>
            <ul class="small text-muted ps-3 mb-0">
                <li>A alteração vale apenas para <strong>novos uploads</strong>.</li>
                <li>Cada vídeo grava o disco em que foi salvo, para que sempre saibamos onde buscá-lo.</li>
                <li>Trocar de disco não migra os arquivos existentes.</li>
            </ul>
        </div>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/painel/configuracoes.js')
@endpush
