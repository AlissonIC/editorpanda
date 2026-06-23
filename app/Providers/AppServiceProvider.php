<?php

namespace App\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $theme = config('theme.active', 'default');
        $themePath = resource_path('views/themes/' . $theme);

        if (! is_dir($themePath)) {
            throw new \RuntimeException(
                "Tema [{$theme}] não encontrado em {$themePath}. Verifique a chave APP_THEME no .env."
            );
        }

        View::addNamespace('theme', $themePath);

        Blade::anonymousComponentNamespace('themes.' . $theme . '.components', 'theme');
    }
}
