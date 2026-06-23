<?php

if (! function_exists('theme_asset')) {
    function theme_asset(string $path): string
    {
        $base = config('theme.available.' . config('theme.active') . '.asset_path', 'themes/default');

        return asset(rtrim($base, '/') . '/' . ltrim($path, '/'));
    }
}

if (! function_exists('theme_active')) {
    function theme_active(): string
    {
        return config('theme.active', 'default');
    }
}
