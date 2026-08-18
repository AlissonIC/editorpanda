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
            @php $evoCredsOk = (bool) ($evolutionUrl && $evolutionApiKey); @endphp
            <div class="panda-card mb-4" id="evo-card" data-creds-ok="{{ $evoCredsOk ? 1 : 0 }}">
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <h5 class="fw-bold mb-0">
                        <i class="bi bi-whatsapp text-success me-1"></i>WhatsApp
                    </h5>
                    <span class="badge {{ \App\Models\Configuracao::evolutionConfigurado() ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary-subtle text-secondary-emphasis' }}" id="evo-badge">
                        {{ \App\Models\Configuracao::evolutionConfigurado() ? 'Configurado' : 'Não configurado' }}
                    </span>
                </div>
                <p class="text-muted small mb-3">
                    Notificações via WhatsApp (compra confirmada, link de acesso, etc.) usando a Evolution API.
                    Conecte o servidor, crie a instância e escaneie o QR code — tudo aqui, sem abrir o manager.
                </p>

                {{-- Passo 1: credenciais de acesso ao servidor Evolution --}}
                {{-- Inputs SEM name de propósito: não entram no submit do form principal. --}}
                <div id="evo-credenciais" style="{{ $evoCredsOk ? 'display:none;' : '' }}">
                    <div class="mb-3">
                        <label class="form-label small">URL do Evolution</label>
                        <input type="url" id="evo-url" class="form-control"
                               value="{{ $evolutionUrl }}"
                               placeholder="https://ev.seudominio.com">
                        <small class="text-muted">Endereço do servidor Evolution, sem barra no final.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">API Key (Global)</label>
                        <div class="input-group">
                            <input type="password" id="evo-api-key" class="form-control font-monospace"
                                   value="{{ $evolutionApiKey }}"
                                   placeholder="Cole a API key global do Evolution"
                                   autocomplete="off">
                            <button type="button" class="btn btn-outline-secondary" id="evo-key-toggle" title="Mostrar/ocultar">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <small class="text-muted">É a <code>AUTHENTICATION_API_KEY</code> configurada no servidor do Evolution.</small>
                    </div>

                    <div id="evo-cred-result"></div>
                    <button type="button" class="btn btn-success" id="evo-conectar">
                        <i class="bi bi-plug me-1"></i>Conectar ao Evolution
                    </button>
                </div>

                {{-- Passo 2: gestão de instâncias (renderizado via JS) --}}
                <div id="evo-painel" style="display:none;">
                    <div class="d-flex justify-content-between align-items-center border rounded p-2 px-3 mb-3">
                        <div class="small">
                            <i class="bi bi-check-circle-fill text-success me-1"></i>
                            Conectado a <strong id="evo-url-label" class="font-monospace">{{ $evolutionUrl }}</strong>
                        </div>
                        <button type="button" class="btn btn-sm btn-link text-decoration-none" id="evo-trocar-creds">
                            Trocar credenciais
                        </button>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="fw-bold mb-0">Instâncias</h6>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="evo-refresh" title="Atualizar lista">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                    </div>
                    <div id="evo-instancias" class="mb-3">
                        <div class="text-muted small py-2"><span class="spinner-border spinner-border-sm me-1"></span>Carregando…</div>
                    </div>

                    <div class="border-top pt-3">
                        <label class="form-label small">Nova instância</label>
                        <div class="input-group" style="max-width:430px;">
                            <input type="text" id="evo-nova-nome" class="form-control"
                                   placeholder="ex.: editorpanda" pattern="[a-zA-Z0-9_-]+" maxlength="100">
                            <button type="button" class="btn btn-dark-panda" id="evo-criar">
                                <i class="bi bi-plus-lg me-1"></i>Criar e conectar
                            </button>
                        </div>
                        <small class="text-muted">Só letras, números, hífen e underline. O QR code abre na tela logo em seguida.</small>
                    </div>

                    <div class="border-top pt-3 mt-3">
                        <button type="button" class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#modal-test-wa">
                            <i class="bi bi-send me-1"></i>Testar envio
                        </button>
                    </div>
                </div>
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
                    <li><strong>WhatsApp</strong>: informe URL + API key do Evolution, crie a instância e escaneie o QR — tudo nesta tela. A instância marcada como "Em uso" é a que envia as notificações.</li>
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

{{-- ===== Modal: QR code de conexão da instância ===== --}}
<div class="modal fade" id="modal-evo-qr" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-qr-code-scan me-1"></i>Conectar <span id="evo-qr-nome" class="font-monospace"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <p class="small text-muted mb-3">
                    No celular, abra o WhatsApp → <strong>Configurações → Dispositivos conectados →
                    Conectar dispositivo</strong> e aponte a câmera para o código abaixo.
                </p>
                <div id="evo-qr-box" class="d-flex align-items-center justify-content-center mx-auto mb-2 border rounded"
                     style="width:264px;height:264px;">
                    <span class="spinner-border text-success"></span>
                </div>
                <div id="evo-qr-status" class="small text-muted">Gerando QR code…</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link" data-bs-dismiss="modal">Fechar</button>
                <button type="button" class="btn btn-outline-success" id="evo-qr-refresh">
                    <i class="bi bi-arrow-clockwise me-1"></i>Gerar novo QR
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
