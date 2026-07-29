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
    ],

    'watermark' => [
        // Fonte TTF/OTF usada pelo drawtext no preview. Se vazio, auto-detecta
        // (Windows: arial; Linux: DejaVuSans). Sempre pode ser sobrescrito pelo .env.
        'font' => env('WATERMARK_FONT'),
        // Texto que aparece tiled no preview. Default = APP_NAME.
        'texto' => env('WATERMARK_TEXTO', env('APP_NAME', 'PANDAVIDEO')),
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
