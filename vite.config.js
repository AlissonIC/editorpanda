import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/scss/app.scss',
                'resources/js/app.js',
                'resources/js/pages/public/home.js',
                'resources/js/pages/admin/usuarios.js',
                'resources/js/pages/admin/financeiro.js',
                'resources/js/pages/admin/processamento.js',
                'resources/js/pages/admin/eventos.js',
                'resources/js/pages/admin/albuns.js',
                'resources/js/pages/admin/pedidos.js',
                'resources/js/pages/admin/leads.js',
                'resources/js/pages/cliente/eventos.js',
                'resources/js/pages/cliente/albuns.js',
                'resources/js/pages/cliente/pedidos.js',
                'resources/js/pages/cliente/relatorio.js',
            ],
            refresh: true,
        }),
    ],
});
