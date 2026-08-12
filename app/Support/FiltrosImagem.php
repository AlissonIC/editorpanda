<?php

namespace App\Support;

/**
 * Presets de filtro que o comprador pode aplicar sobre uma FOTO comprada.
 *
 * A aparência (curvas, vinheta) vive no navegador — resources/js/lib/imagem-filtros.js.
 * Aqui guardamos só as chaves, pra validar o que é gravado no banco: a lista
 * precisa de dono no servidor, senão qualquer string entra em pedido_itens.
 *
 * IMPORTANTE: nós NUNCA gravamos a imagem filtrada. O que persiste é a escolha
 * do comprador; o arquivo com o filtro é gerado no navegador na hora de baixar.
 * Assim uma foto não vira 7 arquivos no disco.
 */
class FiltrosImagem
{
    /** Espelha FILTRO_KEYS do módulo JS — mudou lá, muda aqui. */
    public const KEYS = [
        'original',
        'vivido',
        'suave',
        'dramatico',
        'mono',
        'filme',
        'dourado',
    ];

    public static function valido(?string $key): bool
    {
        return $key !== null && in_array($key, self::KEYS, true);
    }

    /** Normaliza pra gravar: chave inválida ou 'original' viram null (sem preset). */
    public static function normalizar(?string $key): ?string
    {
        if (! self::valido($key) || $key === 'original') {
            return null;
        }

        return $key;
    }
}
