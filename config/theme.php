<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tema ativo
    |--------------------------------------------------------------------------
    |
    | Define qual pasta dentro de resources/views/themes/{nome} é usada
    | para layouts, partials e componentes visuais. Trocando essa chave
    | (via .env APP_THEME) muda todo o visual sem mexer nas views de
    | negócio em resources/views/pages/.
    |
    */

    'active' => env('APP_THEME', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Temas disponíveis
    |--------------------------------------------------------------------------
    */

    'available' => [
        'default' => [
            'name' => 'Default',
            'asset_path' => 'themes/default',
        ],
        // 'vuexy' => ['name' => 'Vuexy', 'asset_path' => 'themes/vuexy'],
    ],

];
