<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'ffmpeg' => [
        'bin' => env('FFMPEG_BIN', 'ffmpeg'),
        'ffprobe' => env('FFPROBE_BIN', 'ffprobe'),

        // Preset do x264 no encode principal. É o maior fator no tempo de fila —
        // a resolução de ENTRADA quase não pesa (4K custa ~5% a mais que Full HD,
        // porque o gargalo é codificar a saída fixa de 1080x1920).
        // Medido num clipe de 17s: medium 45s · fast 34s (−24%) · veryfast 20s (−56%).
        // `fast` é o default por manter qualidade praticamente igual ao medium.
        'preset' => env('FFMPEG_PRESET', 'fast'),

        // CRF do encode principal (menor = melhor/maior). Presets mais rápidos
        // comprimem menos: se subir pra veryfast e notar perda, desça o CRF em 1.
        'crf' => (int) env('FFMPEG_CRF', 22),

        // Threads por encode. Vazio = automático: divide os cores da máquina
        // pelo número de workers declarado, pra soma saturar a CPU sem
        // sobrecarregar (workers x threads ~= cores).
        // libx264 sem -threads pega todos os cores, e aí N workers brigam entre
        // si e o context switch come o ganho.
        'threads' => env('FFMPEG_THREADS'),
    ],

    /*
     * Quantos `queue:work` você mantém rodando. O app não tem como descobrir
     * isso sozinho (são processos externos, supervisor/systemd), então declare
     * aqui — é o que permite dimensionar as threads do ffmpeg.
     */
    'queue' => [
        'workers' => (int) env('QUEUE_WORKERS', 1),
    ],

    'watermark' => [
        // Fonte TTF/OTF usada pelo drawtext no preview. Se vazio, auto-detecta
        // (Windows: arial; Linux: DejaVuSans). Sempre pode ser sobrescrito pelo .env.
        'font' => env('WATERMARK_FONT'),
        // Texto que aparece tiled no preview. Só é usado como FALLBACK, quando
        // a logo não pode ser lida.
        'texto' => env('WATERMARK_TEXTO', env('APP_NAME', 'PANDAVIDEO')),
        // PNG da logo tiled no preview. Vazio = public/img/logo-clara.png.
        'logo' => env('WATERMARK_LOGO'),
    ],

    'mercadopago' => [
        // ACCESS_TOKEN é segredo — SÓ backend. Formato: APP_USR-... (prod) ou TEST-... (sandbox).
        'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
        // PUBLIC_KEY vai pro browser (Bricks SDK). Não é segredo, mas é
        // vinculada à conta MP, então rotate exige atualizar aqui.
        'public_key' => env('MERCADOPAGO_PUBLIC_KEY'),
        // PIX expira em N minutos após criação — comprador tem esse tempo pra pagar
        // no app do banco. 30 min é o default MP; abaixo disso reduz conversão.
        'pix_expira_minutos' => (int) env('MERCADOPAGO_PIX_EXPIRA_MIN', 30),
        // Sandbox: mesma API, credenciais TEST-*. Se a access_token começa com TEST-,
        // o MP roteia automaticamente pro sandbox — a flag serve pra UI (mostrar aviso).
        'sandbox' => str_starts_with((string) env('MERCADOPAGO_ACCESS_TOKEN', ''), 'TEST-'),
    ],

];
