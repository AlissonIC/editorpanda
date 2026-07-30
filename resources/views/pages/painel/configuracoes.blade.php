@extends('theme::layouts.painel')

@section('titulo', 'Configurações')

@section('conteudo')
<x-theme::page-header
    titulo="Configurações"
    subtitulo="Ajustes globais da plataforma"
/>

<form id="form-config" novalidate>
    @csrf

    <div class="row g-4">
        <div class="col-lg-8">
            {{-- ===== Armazenamento ===== --}}
            <div class="panda-card mb-4">
                <h5 class="fw-bold mb-1">Armazenamento de vídeos</h5>
                <p class="text-muted small mb-4">
                    Escolha onde os vídeos enviados a partir de agora serão armazenados.
                    Registros antigos continuam apontando para o disco em que foram salvos originalmente.
                </p>

                <div class="storage-options">
                    <label class="storage-option {{ $storageDisk === 'local' ? 'is-active' : '' }}">
                        <input type="radio" name="storage_disk" value="local" @checked($storageDisk === 'local')>
                        <div class="storage-option-icon bg-success-subtle text-success-emphasis">
                            <i class="bi bi-hdd"></i>
                        </div>
                        <div class="storage-option-body">
                            <div class="fw-semibold">Armazenamento local</div>
                            <small class="text-muted">
                                Salva no servidor da aplicação. Bom para desenvolvimento e volumes pequenos.
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
                                Salva em um bucket S3 (ou compatível). Requer <code>AWS_*</code> no <code>.env</code>.
                            </small>
                        </div>
                    </label>
                </div>
            </div>

            {{-- ===== WhatsApp (Evolution API) ===== --}}
            <div class="panda-card mb-4">
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <h5 class="fw-bold mb-0">
                        <i class="bi bi-whatsapp text-success me-1"></i>WhatsApp
                    </h5>
                    <span class="badge {{ \App\Models\Configuracao::evolutionConfigurado() ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary-subtle text-secondary-emphasis' }}">
                        {{ \App\Models\Configuracao::evolutionConfigurado() ? 'Configurado' : 'Não configurado' }}
                    </span>
                </div>
                <p class="text-muted small mb-3">
                    Integração com Evolution API para disparo de notificações via WhatsApp (compra confirmada, link de acesso, etc.).
                </p>

                <div class="mb-3">
                    <label class="form-label small">URL do Evolution</label>
                    <input type="url" name="evolution_url" class="form-control"
                           value="{{ $evolutionUrl }}"
                           placeholder="https://ev.editorpanda.com">
                    <small class="text-muted">Sem barra no final.</small>
                </div>

                <div class="mb-3">
                    <label class="form-label small">API Key (Global)</label>
                    <div class="input-group">
                        <input type="password" name="evolution_api_key" class="form-control font-monospace"
                               value="{{ $evolutionApiKey }}"
                               placeholder="Cole a API key do Evolution"
                               autocomplete="off"
                               id="evo-api-key">
                        <button type="button" class="btn btn-outline-secondary" id="evo-key-toggle" title="Mostrar/ocultar">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small">Nome da instância</label>
                    <input type="text" name="evolution_instance" class="form-control"
                           value="{{ $evolutionInstance }}"
                           placeholder="editorpanda"
                           pattern="[a-zA-Z0-9_-]+">
                    <small class="text-muted">Precisa existir no manager do Evolution e estar conectada ao WhatsApp (QR code escaneado).</small>
                </div>

                <button type="button" class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#modal-test-wa">
                    <i class="bi bi-send me-1"></i>Testar envio
                </button>
            </div>

            {{-- ===== E-mail ===== --}}
            <div class="panda-card mb-4">
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <h5 class="fw-bold mb-0">
                        <i class="bi bi-envelope-fill text-primary me-1"></i>E-mail
                    </h5>
                    <span class="badge bg-secondary-subtle text-secondary-emphasis">
                        SMTP: {{ $mailDriver }}
                    </span>
                </div>
                <p class="text-muted small mb-3">
                    Remetente que aparece nos e-mails enviados pelo sistema. Configurações SMTP (servidor, usuário, senha) ficam no <code>.env</code> por segurança.
                </p>

                <div class="row g-2 mb-3">
                    <div class="col-md-7">
                        <label class="form-label small">E-mail remetente</label>
                        <input type="email" name="email_from_address" class="form-control"
                               value="{{ $emailFromAddress }}"
                               placeholder="nao-responder@editorpanda.com">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small">Nome remetente</label>
                        <input type="text" name="email_from_name" class="form-control"
                               value="{{ $emailFromName }}"
                               placeholder="Editor Panda">
                    </div>
                </div>

                @if($mailDriver === 'log' || ! $mailHost)
                    <div class="alert alert-warning small mb-3">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        SMTP não está configurado — atualmente <code>MAIL_MAILER={{ $mailDriver }}</code>.
                        Ajuste <code>MAIL_*</code> no <code>.env</code> para enviar e-mails reais.
                    </div>
                @endif

                <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-test-email">
                    <i class="bi bi-send me-1"></i>Testar envio
                </button>
            </div>

            <div class="d-flex justify-content-end mt-4">
                <button type="submit" class="btn btn-dark-panda">
                    <i class="bi bi-check-lg me-1"></i> Salvar configurações
                </button>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="panda-card mb-3">
                <h6 class="fw-bold mb-2">Como funciona</h6>
                <ul class="small text-muted ps-3 mb-0">
                    <li><strong>Storage</strong>: alteração vale apenas para novos uploads.</li>
                    <li><strong>WhatsApp</strong>: precisa criar a instância no <em>manager</em> do Evolution e escanear QR antes de testar aqui.</li>
                    <li><strong>E-mail</strong>: só o remetente é editável — SMTP fica no .env.</li>
                </ul>
            </div>

            @if(\App\Models\Configuracao::evolutionUrl())
                <div class="panda-card">
                    <h6 class="fw-bold mb-2">Atalho</h6>
                    <a href="{{ \App\Models\Configuracao::evolutionUrl() }}/manager/" target="_blank" rel="noopener"
                       class="btn btn-sm btn-outline-secondary w-100">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Abrir manager do Evolution
                    </a>
                    <small class="text-muted d-block mt-2">Use pra criar instância, escanear QR ou depurar mensagens.</small>
                </div>
            @endif
        </div>
    </div>
</form>

{{-- ===== Modal: Testar WhatsApp ===== --}}
<div class="modal fade" id="modal-test-wa" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-whatsapp text-success me-1"></i>Testar WhatsApp</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-3">
                    Uma mensagem de teste será enviada para o número informado usando as configurações acima.
                    <strong>Salve antes de testar</strong> se alterou algo.
                </p>
                <div class="mb-3">
                    <label class="form-label small">Número (com DDD)</label>
                    <input type="tel" id="wa-test-numero" class="form-control" placeholder="(17) 99775-5598">
                    <small class="text-muted">DDI 55 (Brasil) é adicionado automaticamente se você não informar.</small>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Mensagem <span class="text-muted">(opcional)</span></label>
                    <textarea id="wa-test-msg" class="form-control" rows="3" maxlength="500"
                              placeholder="Deixe vazio pra usar uma mensagem padrão de teste."></textarea>
                </div>
                <div id="wa-test-result"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success" id="btn-wa-test">
                    <i class="bi bi-send me-1"></i>Enviar teste
                </button>
            </div>
        </div>
    </div>
</div>

{{-- ===== Modal: Testar E-mail ===== --}}
<div class="modal fade" id="modal-test-email" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-envelope-fill text-primary me-1"></i>Testar e-mail</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-3">
                    Envia um e-mail de teste usando o SMTP do <code>.env</code> e o remetente configurado.
                    <strong>Salve antes de testar</strong> se alterou algo.
                </p>
                <div class="mb-3">
                    <label class="form-label small">E-mail de destino</label>
                    <input type="email" id="email-test-destino" class="form-control"
                           value="{{ auth()->user()->email ?? '' }}"
                           placeholder="voce@dominio.com">
                </div>
                <div id="email-test-result"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="btn-email-test">
                    <i class="bi bi-send me-1"></i>Enviar teste
                </button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/painel/configuracoes.js')
@endpush
