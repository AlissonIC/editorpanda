<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Vídeo não pode ser excluído por regra de negócio (ex.: já foi vendido).
 *
 * Tem render() próprio pra virar 422 com a mensagem em vez de estourar 500
 * — o painel exibe `response.data.message` direto pro usuário.
 */
class VideoProtegidoException extends RuntimeException
{
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return response()->json(['message' => $this->getMessage()], 422);
    }
}
