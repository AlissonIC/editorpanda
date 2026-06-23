import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/scss/app.scss',
                'resources/js/app.js',
                'resources/js/pages/public/home.js',
                'resources/js/pages/painel/usuarios.js',
                'resources/js/pages/painel/financeiro.js',
                'resources/js/pages/painel/processamento.js',
                'resources/js/pages/painel/eventos.js',
                'resources/js/pages/painel/albuns.js',
                'resources/js/pages/painel/pedidos.js',
                'resources/js/pages/painel/leads.js',
                'resources/js/pages/painel/relatorio.js',
            ],
            refresh: true,
        }),
    ],
});
